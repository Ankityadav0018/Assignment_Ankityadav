# PolicyFlow

PolicyFlow is a travel insurance policy intake and issuance API built with **Laravel 11**, **PHP 8.2+**, and **MySQL 8.0+**. It implements arbitrary-precision decimal premium calculation, deterministic idempotency, strict state machine transitions, and automated reconciliation for external insurer integrations.

---

## Table of Contents
1. [Setup & Database Configuration](#setup--database-configuration)
2. [Architecture Overview](#architecture-overview)
3. [API Contract & Status Codes](#api-contract--status-codes)
4. [Example Responses](#example-responses)
5. [Duplicate-Prevention Strategy](#duplicate-prevention-strategy)
6. [Reliability Cases Implemented](#reliability-cases-implemented)
7. [Production Scaling: Queue-Based Retries](#production-scaling-queue-based-retries)
8. [Design Decisions](#design-decisions)
9. [Required Files](#required-files)
10. [Demo Video](#demo-video)
11. [Assumptions](#assumptions)
12. [Known Limitations](#known-limitations)
13. [Running Tests & Examples](#running-tests--examples)

---

## Setup & Database Configuration

### 1. Prerequisites
- **PHP 8.2 or 8.3** with extensions: `pdo_mysql`, `bcmath`, `pcntl` (for concurrent tests), `mbstring`, `json`, `xml`, `tokenizer`
- **Composer 2.x**
- **MySQL 8.0+**

### 2. Clone and Install Dependencies
```bash
git clone <repo-url> policyflow
cd policyflow

composer install
```

### 3. Application Environment Configuration
Copy `.env.example` to `.env` and generate the application key:
```bash
cp .env.example .env
php artisan key:generate
```

Configure your MySQL connection details in `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=policyflow
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 4. Database Creation
Create both the application database and the test database in MySQL:
```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS policyflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS policyflow_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 5. Run Database Migrations
Migrate the application database:
```bash
php artisan migrate
```

### 6. Test Database Configuration & Execution
The test suite is configured to execute against the dedicated `policyflow_test` MySQL database.

Configuration mechanisms:
- **`phpunit.xml` Defaults**: Defines environment variables (`DB_CONNECTION=mysql`, `DB_DATABASE=policyflow_test`, `DB_USERNAME=root`, `DB_PASSWORD=`) with `force="false"`, allowing custom environment variables or `.env.testing` to override them.
- **`.env.testing` (Optional Override)**: If your MySQL server requires specific credentials, create `.env.testing`:
  ```env
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=policyflow_test
  DB_USERNAME=root
  DB_PASSWORD=your_password
  ```

Run migrations on the test database:
```bash
php artisan migrate --env=testing
```

Run the automated test suite:
```bash
./vendor/bin/phpunit
# or
php artisan test
```

### 7. Start the Local Server
```bash
php artisan serve
```
The API is available at `http://localhost:8000`.

---

## Architecture Overview

```
                      ┌──────────────────────────────────────┐
                      │    Client (Broker / Web / Portal)    │
                      └──────────────────┬───────────────────┘
                                         │
                   POST /api/policy-requests  (Intake)
                   POST /api/policy-requests/{id}/submit
                                         ▼
┌────────────────────────────────────────────────────────────────────────────┐
│ PolicyRequestController                                                    │
├────────────────────────┬───────────────────────────┬───────────────────────┤
│   PremiumCalculator    │    PolicyStateMachine     │    IssuanceService    │
│  (brick/math decimal)  │ (Explicit transition map) │  (lockForUpdate &     │
│  - immutable snapshot  │ - auditable history       │   idempotency keys)   │
└───────────┬────────────┴─────────────┬─────────────┴───────────┬───────────┘
            │                          │                         │
            ▼                          ▼                         ▼
┌────────────────────────────────────────────────────────────────────────────┐
│ Database Layer (MySQL)                                                     │
│ - policy_requests       (UNIQUE: agent_id + client_request_id)             │
│ - policy_state_history  (Audit trail of all state transitions)             │
│ - issuance_attempts     (Log of all provider attempts & responses)         │
│ - provider_events       (UNIQUE: provider_event_id for webhooks)           │
│ - mock_policies         (Mock insurer state per idempotency key)           │
└──────────────────────────────────────┬─────────────────────────────────────┘
                                       │
                                       ▼
                      ┌──────────────────────────────────────┐
                      │   InsurerClient / MockInsurer API    │
                      │  (/api/mock-insurer/{issue,status})  │
                      └──────────────────┬───────────────────┘
                                         │
                               Webhook / Callback
                                         ▼
                      POST /api/mock-insurer/callback
```

### Core Domain Components

1. **`PremiumCalculator` Service**:
   - Implements decimal arithmetic using `brick/math` (`BigDecimal`), eliminating binary floating-point rounding errors.
   - Calculates age on `trip_start` and duration inclusive of start and end days ($(\text{end} - \text{start}) + 1$).
   - Computes base rate, destination multiplier, age factor, and coverage surcharge.
   - **Immutability rule**: The calculated `premium_amount` and `premium_breakdown` are snapshotted on intake and never recomputed on submission or retry.

2. **`PolicyStateMachine` Service**:
   - Governed by `App\Enums\PolicyStatus`:
     - `RECEIVED`: Payload received and initial record created.
     - `VALIDATED`: Premium calculated and snapshotted; ready for submission.
     - `SUBMITTED`: Issuance call in progress.
     - `ISSUED`: Terminal success with active `policy_number`.
     - `PENDING_CONFIRMATION`: Asynchronous issuance awaiting callback.
     - `NEEDS_RECONCILIATION`: Inconclusive provider state (e.g. timeout) awaiting status query.
     - `FAILED`: Terminal failure.
   - Explicit allowed-transition map:
     - `RECEIVED` $\rightarrow$ `VALIDATED`
     - `VALIDATED` $\rightarrow$ `SUBMITTED`
     - `SUBMITTED` $\rightarrow$ `ISSUED` | `PENDING_CONFIRMATION` | `FAILED` | `NEEDS_RECONCILIATION` | `VALIDATED` (retriable failure)
     - `PENDING_CONFIRMATION` $\rightarrow$ `ISSUED` | `FAILED` | `NEEDS_RECONCILIATION`
     - `NEEDS_RECONCILIATION` $\rightarrow$ `ISSUED` | `FAILED` | `VALIDATED` (provider confirms 404 not found)
     - `ISSUED` & `FAILED`: Terminal states (no transitions permitted).
   - Transitions execute inside database transactions and write immutable rows to `policy_state_history`.

3. **`IssuanceService`**:
   - Coordinates issuance against `InsurerClient`.
   - Uses pessimistic database row locking (`lockForUpdate()`) to serialize concurrent submissions.
   - Manages retries, reconciliation via status lookups, and idempotency guarantees.

4. **`MockInsurer` & Webhook Engine**:
   - Provides an in-process implementation of `InsurerClient` with state persisted in `mock_policies`.
   - Supports simulation scenarios in local and testing environments: `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `TIMEOUT_WITHOUT_ISSUE`, `DELAYED_CALLBACK`, and `DUPLICATE_CALLBACK`.

---

## API Contract & Status Codes

### 1. Create Policy Request (Intake)
- **Endpoint**: `POST /api/policy-requests`
- **Request Body**:
  ```json
  {
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }
  ```
- **Responses**:
  - `201 Created`: First intake request. Returns computed `premium_amount`, `premium_breakdown`, and status `VALIDATED`.
  - `200 OK`: Idempotent replay with identical payload parameters. Returns existing record.
  - `409 Conflict`: Replay of `(agent_id, client_request_id)` with mismatched payload parameters.
  - `422 Unprocessable Entity`: Validation failure (e.g. `trip_end < trip_start`, `date_of_birth` in future, unsupported plan code or sum insured).

### 2. Get Single Policy Request
- **Endpoint**: `GET /api/policy-requests/{id}`
- **Responses**:
  - `200 OK`: Returns policy record including audit trail (`state_history`).
  - `404 Not Found`: Policy ID does not exist.

### 3. List Policy Requests
- **Endpoint**: `GET /api/policy-requests?status=VALIDATED&per_page=20`
- **Responses**:
  - `200 OK`: Paginated resource list.

### 4. Submit Policy Request for Issuance
- **Endpoint**: `POST /api/policy-requests/{id}/submit`
- **Headers / Body**: Optional `{"scenario": "SUCCESS"}` or `X-Mock-Scenario: SUCCESS` (honored only in `local` and `testing`)
- **Responses**:
  - `200 OK`: Successfully issued (`status: ISSUED`, with `policy_number` and `provider_reference`). If already `ISSUED`, returns existing record with zero provider calls.
  - `202 Accepted`: Asynchronous provider flow (`status: PENDING_CONFIRMATION`, with `provider_reference`).
  - `503 Service Unavailable`: Retriable provider failure (`scenario=TEMPORARY_FAILURE` or timeout confirmed not found). Reverts status to `VALIDATED` with attempt recorded.
  - `504 Gateway Timeout`: Inconclusive provider timeout unresolved by status lookup (`status: NEEDS_RECONCILIATION`).
  - `409 Conflict`: Invalid state for submission.
  - `404 Not Found`: Policy ID does not exist.

### 5. Mock Insurer Callback (Webhook)
- **Endpoint**: `POST /api/mock-insurer/callback`
- **Request Body**:
  ```json
  {
    "provider_event_id": "EVT-9001",
    "provider_reference": "REF-POL-12345",
    "policy_number": "POL-12345",
    "event_type": "POLICY_ISSUED"
  }
  ```
- **Responses**:
  - `200 OK {"status": "processed"}`: Event processed; transitions policy to `ISSUED` (or `FAILED` on failure events).
  - `200 OK {"status": "already processed"}`: Duplicate `provider_event_id` received; state unchanged.
  - `200 OK {"status": "ignored"}`: Event received for terminal state or unknown `event_type`; event stored, state unchanged.
  - `404 Not Found`: Unknown `provider_reference`.
  - `422 Unprocessable Entity`: Missing required fields.

---

## Example Responses

The following responses were generated from the running application:

### 1. HTTP 201 Created (New Intake)
```json
{
    "data": {
        "id": "a30d59f5-56d2-4160-bbac-d7ad6a5f9fa4",
        "agent_id": "AGENT-17",
        "client_request_id": "AGT-2026-0001",
        "customer_name": "Sample Customer",
        "date_of_birth": "1998-04-10",
        "destination": "INTERNATIONAL",
        "trip_start": "2026-11-10",
        "trip_end": "2026-11-14",
        "travel_days": 5,
        "plan_code": "PLUS",
        "sum_insured": 300000,
        "premium_amount": "225.00",
        "premium_breakdown": {
            "plan_code": "PLUS",
            "base_daily_rate": "20",
            "travel_days": 5,
            "age_at_trip_start": 28,
            "age_multiplier": "1.00",
            "destination": "INTERNATIONAL",
            "destination_multiplier": "1.80",
            "sum_insured": 300000,
            "coverage_multiplier": "1.25",
            "premium_amount": "225.00"
        },
        "status": "VALIDATED",
        "attempt_count": 0,
        "provider_reference": null,
        "policy_number": null,
        "state_history": [
            {
                "from_status": null,
                "to_status": "RECEIVED",
                "reason": "Policy request received.",
                "at": "2026-10-23T17:43:11+00:00"
            },
            {
                "from_status": "RECEIVED",
                "to_status": "VALIDATED",
                "reason": "Input validated; premium calculated and snapshot stored.",
                "at": "2026-10-23T17:43:11+00:00"
            }
        ],
        "created_at": "2026-10-23T17:43:11+00:00",
        "updated_at": "2026-10-23T17:43:11+00:00"
    }
}
```

### 2. HTTP 200 OK (Repeated Intake with Identical Payload)
```json
{
    "data": {
        "id": "a30d59f5-56d2-4160-bbac-d7ad6a5f9fa4",
        "agent_id": "AGENT-17",
        "client_request_id": "AGT-2026-0001",
        "customer_name": "Sample Customer",
        "date_of_birth": "1998-04-10",
        "destination": "INTERNATIONAL",
        "trip_start": "2026-11-10",
        "trip_end": "2026-11-14",
        "travel_days": 5,
        "plan_code": "PLUS",
        "sum_insured": 300000,
        "premium_amount": "225.00",
        "premium_breakdown": {
            "plan_code": "PLUS",
            "base_daily_rate": "20",
            "travel_days": 5,
            "age_at_trip_start": 28,
            "age_multiplier": "1.00",
            "destination": "INTERNATIONAL",
            "destination_multiplier": "1.80",
            "sum_insured": 300000,
            "coverage_multiplier": "1.25",
            "premium_amount": "225.00"
        },
        "status": "VALIDATED",
        "attempt_count": 0,
        "provider_reference": null,
        "policy_number": null,
        "state_history": [
            {
                "from_status": null,
                "to_status": "RECEIVED",
                "reason": "Policy request received.",
                "at": "2026-10-23T17:43:11+00:00"
            },
            {
                "from_status": "RECEIVED",
                "to_status": "VALIDATED",
                "reason": "Input validated; premium calculated and snapshot stored.",
                "at": "2026-10-23T17:43:11+00:00"
            }
        ],
        "created_at": "2026-10-23T17:43:11+00:00",
        "updated_at": "2026-10-23T17:43:11+00:00"
    }
}
```

### 3. HTTP 409 Conflict (Same Key with Different Payload)
```json
{
    "error": "Conflict: A policy request with this agent_id and client_request_id already exists with different payload parameters.",
    "code": "IDEMPOTENCY_KEY_PAYLOAD_MISMATCH"
}
```

### 4. HTTP 422 Unprocessable Entity (Validation Error)
```json
{
    "message": "The date of birth must be in the past. (and 4 more errors)",
    "errors": {
        "date_of_birth": [
            "The date of birth must be in the past."
        ],
        "destination": [
            "The selected destination is invalid."
        ],
        "trip_end": [
            "The trip end date must be on or after the trip start date."
        ],
        "plan_code": [
            "The plan code must be one of: BASIC, PLUS, PREMIUM."
        ],
        "sum_insured": [
            "The sum insured must be one of: 100000, 300000, 500000."
        ]
    }
}
```

### 5. HTTP 200 OK (GET Policy Request with State History)
```json
{
    "data": {
        "id": "a30d59f5-56d2-4160-bbac-d7ad6a5f9fa4",
        "agent_id": "AGENT-17",
        "client_request_id": "AGT-2026-0001",
        "customer_name": "Sample Customer",
        "date_of_birth": "1998-04-10",
        "destination": "INTERNATIONAL",
        "trip_start": "2026-11-10",
        "trip_end": "2026-11-14",
        "travel_days": 5,
        "plan_code": "PLUS",
        "sum_insured": 300000,
        "premium_amount": "225.00",
        "premium_breakdown": {
            "plan_code": "PLUS",
            "base_daily_rate": "20",
            "travel_days": 5,
            "age_at_trip_start": 28,
            "age_multiplier": "1.00",
            "destination": "INTERNATIONAL",
            "destination_multiplier": "1.80",
            "sum_insured": 300000,
            "coverage_multiplier": "1.25",
            "premium_amount": "225.00"
        },
        "status": "VALIDATED",
        "attempt_count": 0,
        "provider_reference": null,
        "policy_number": null,
        "state_history": [
            {
                "from_status": null,
                "to_status": "RECEIVED",
                "reason": "Policy request received.",
                "at": "2026-10-23T17:43:11+00:00"
            },
            {
                "from_status": "RECEIVED",
                "to_status": "VALIDATED",
                "reason": "Input validated; premium calculated and snapshot stored.",
                "at": "2026-10-23T17:43:11+00:00"
            }
        ],
        "created_at": "2026-10-23T17:43:11+00:00",
        "updated_at": "2026-10-23T17:43:11+00:00"
    }
}
```

### 6. HTTP 200 OK (Submit Returning ISSUED)
```json
{
    "data": {
        "id": "a30d59f5-56d2-4160-bbac-d7ad6a5f9fa4",
        "agent_id": "AGENT-17",
        "client_request_id": "AGT-2026-0001",
        "customer_name": "Sample Customer",
        "date_of_birth": "1998-04-10",
        "destination": "INTERNATIONAL",
        "trip_start": "2026-11-10",
        "trip_end": "2026-11-14",
        "travel_days": 5,
        "plan_code": "PLUS",
        "sum_insured": 300000,
        "premium_amount": "225.00",
        "premium_breakdown": {
            "plan_code": "PLUS",
            "base_daily_rate": "20",
            "travel_days": 5,
            "age_at_trip_start": 28,
            "age_multiplier": "1.00",
            "destination": "INTERNATIONAL",
            "destination_multiplier": "1.80",
            "sum_insured": 300000,
            "coverage_multiplier": "1.25",
            "premium_amount": "225.00"
        },
        "status": "ISSUED",
        "attempt_count": 1,
        "provider_reference": "REF-C4A5ECEA41",
        "policy_number": "POL-C4DCE598B4",
        "state_history": [
            {
                "from_status": null,
                "to_status": "RECEIVED",
                "reason": "Policy request received.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "RECEIVED",
                "to_status": "VALIDATED",
                "reason": "Input validated; premium calculated and snapshot stored.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "VALIDATED",
                "to_status": "SUBMITTED",
                "reason": "Submitting policy request to insurer.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "SUBMITTED",
                "to_status": "ISSUED",
                "reason": "Policy successfully issued by insurer.",
                "at": "2026-10-23T17:43:00+00:00"
            }
        ],
        "issuance_attempts": [
            {
                "attempt_no": 1,
                "outcome": "SUCCESS",
                "error": null,
                "at": "2026-10-23T17:43:00+00:00"
            }
        ],
        "created_at": "2026-10-23T17:43:00+00:00",
        "updated_at": "2026-10-23T17:43:00+00:00"
    }
}
```

### 7. HTTP 202 Accepted (Submit Returning PENDING_CONFIRMATION)
```json
{
    "data": {
        "id": "a30d59e4-f9be-4d56-94d5-20217f16058e",
        "agent_id": "AGENT-17",
        "client_request_id": "AGT-2026-0007",
        "customer_name": "Sample Customer",
        "date_of_birth": "1998-04-10",
        "destination": "INTERNATIONAL",
        "trip_start": "2026-11-10",
        "trip_end": "2026-11-14",
        "travel_days": 5,
        "plan_code": "PLUS",
        "sum_insured": 300000,
        "premium_amount": "225.00",
        "premium_breakdown": {
            "plan_code": "PLUS",
            "base_daily_rate": "20",
            "travel_days": 5,
            "age_at_trip_start": 28,
            "age_multiplier": "1.00",
            "destination": "INTERNATIONAL",
            "destination_multiplier": "1.80",
            "sum_insured": 300000,
            "coverage_multiplier": "1.25",
            "premium_amount": "225.00"
        },
        "status": "PENDING_CONFIRMATION",
        "attempt_count": 1,
        "provider_reference": "REF-04E2F63377",
        "policy_number": null,
        "state_history": [
            {
                "from_status": null,
                "to_status": "RECEIVED",
                "reason": "Policy request received.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "RECEIVED",
                "to_status": "VALIDATED",
                "reason": "Input validated; premium calculated and snapshot stored.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "VALIDATED",
                "to_status": "SUBMITTED",
                "reason": "Submitting policy request to insurer.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "SUBMITTED",
                "to_status": "PENDING_CONFIRMATION",
                "reason": "Provider acknowledged request; pending confirmation.",
                "at": "2026-10-23T17:43:00+00:00"
            }
        ],
        "issuance_attempts": [
            {
                "attempt_no": 1,
                "outcome": "PENDING",
                "error": null,
                "at": "2026-10-23T17:43:00+00:00"
            }
        ],
        "created_at": "2026-10-23T17:43:00+00:00",
        "updated_at": "2026-10-23T17:43:00+00:00"
    }
}
```

### 8. HTTP 503 Service Unavailable (Submit Returning Temporary Failure)
```json
{
    "data": {
        "id": "a30d59e4-fc3b-4920-94b8-9a39b794e619",
        "agent_id": "AGENT-17",
        "client_request_id": "AGT-2026-0008",
        "customer_name": "Sample Customer",
        "date_of_birth": "1998-04-10",
        "destination": "INTERNATIONAL",
        "trip_start": "2026-11-10",
        "trip_end": "2026-11-14",
        "travel_days": 5,
        "plan_code": "PLUS",
        "sum_insured": 300000,
        "premium_amount": "225.00",
        "premium_breakdown": {
            "plan_code": "PLUS",
            "base_daily_rate": "20",
            "travel_days": 5,
            "age_at_trip_start": 28,
            "age_multiplier": "1.00",
            "destination": "INTERNATIONAL",
            "destination_multiplier": "1.80",
            "sum_insured": 300000,
            "coverage_multiplier": "1.25",
            "premium_amount": "225.00"
        },
        "status": "VALIDATED",
        "attempt_count": 1,
        "provider_reference": null,
        "policy_number": null,
        "state_history": [
            {
                "from_status": null,
                "to_status": "RECEIVED",
                "reason": "Policy request received.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "RECEIVED",
                "to_status": "VALIDATED",
                "reason": "Input validated; premium calculated and snapshot stored.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "VALIDATED",
                "to_status": "SUBMITTED",
                "reason": "Submitting policy request to insurer.",
                "at": "2026-10-23T17:43:00+00:00"
            },
            {
                "from_status": "SUBMITTED",
                "to_status": "VALIDATED",
                "reason": "Transient provider failure; returned to VALIDATED for retry.",
                "at": "2026-10-23T17:43:00+00:00"
            }
        ],
        "issuance_attempts": [
            {
                "attempt_no": 1,
                "outcome": "FAILED_RETRYABLE",
                "error": "TEMPORARY_SERVICE_UNAVAILABLE",
                "at": "2026-10-23T17:43:00+00:00"
            }
        ],
        "created_at": "2026-10-23T17:43:00+00:00",
        "updated_at": "2026-10-23T17:43:00+00:00"
    }
}
```

### 9. HTTP 504 Gateway Timeout (Submit Inconclusive Timeout / NEEDS_RECONCILIATION)
```json
{
    "data": {
        "id": "a30d59e4-fe9e-43ee-ae08-2f276b22f199",
        "agent_id": "AGENT-17",
        "client_request_id": "AGT-2026-0009",
        "customer_name": "Sample Customer",
        "date_of_birth": "1998-04-10",
        "destination": "INTERNATIONAL",
        "trip_start": "2026-11-10",
        "trip_end": "2026-11-14",
        "travel_days": 5,
        "plan_code": "PLUS",
        "sum_insured": 300000,
        "premium_amount": "225.00",
        "premium_breakdown": {
            "plan_code": "PLUS",
            "base_daily_rate": "20",
            "travel_days": 5,
            "age_at_trip_start": 28,
            "age_multiplier": "1.00",
            "destination": "INTERNATIONAL",
            "destination_multiplier": "1.80",
            "sum_insured": 300000,
            "coverage_multiplier": "1.25",
            "premium_amount": "225.00"
        },
        "status": "NEEDS_RECONCILIATION",
        "attempt_count": 0,
        "provider_reference": null,
        "policy_number": null,
        "created_at": "2026-10-23T17:43:00+00:00",
        "updated_at": "2026-10-23T17:43:00+00:00"
    }
}
```

---

## Duplicate-Prevention Strategy

1. **Database Unique Constraints**:
   - `policy_requests`: `UNIQUE KEY (agent_id, client_request_id)`.
   - `provider_events`: `UNIQUE KEY (provider_event_id)`.
   - `mock_policies`: `UNIQUE KEY (idempotency_key)`.

2. **Optimistic Insert Pattern (No Check-Then-Insert TOCTOU Races)**:
   - Intake executes the `INSERT` directly inside a database transaction rather than checking `SELECT` first.
   - If two identical requests race concurrently, the second triggers a `UniqueConstraintViolationException` at the database engine level. PolicyFlow catches this exception, verifies that the payload fields match, and returns the existing row with HTTP `200 OK`. If payload fields differ, it returns `409 Conflict`.

3. **Pessimistic Row Locking (`lockForUpdate`) During Submission**:
   - `IssuanceService::submit()` begins a transaction and queries the policy record with `lockForUpdate()`.
   - Concurrent submissions for the same policy request block until the active transaction finishes, preventing duplicate submission attempts.
   - If the locked row is already `ISSUED`, the service returns it immediately without calling the provider.

4. **Deterministic Provider Idempotency Keys**:
   - The provider `issue()` method uses the policy request UUID as its `idempotency_key`.
   - `MockInsurer` queries `mock_policies` by `idempotency_key` and returns the existing record rather than generating a new policy.

5. **Webhook Deduplication**:
   - Incoming provider events are inserted with `UNIQUE(provider_event_id)`. Duplicate deliveries hit the constraint and return `already processed` without reapplying state transitions.

---

## Reliability Cases Implemented

| Assignment Case | Implemented | Test File and Test Name |
|---|:---:|---|
| **Duplicate intake** | Yes | `tests/Feature/PolicyFlowRealMySQLFeatureTest.php`<br>`test_repeated_agent_and_client_request_id_returns_200_and_db_count_stays_one` |
| **Timeout after success** | Yes | `tests/Feature/PolicyFlowRealMySQLFeatureTest.php`<br>`test_timeout_after_issue_resolves_via_status_lookup_with_one_mock_policies_row` |
| **Duplicate callback** | Yes | `tests/Feature/PolicyFlowRealMySQLFeatureTest.php`<br>`test_duplicate_callback_applies_once_and_has_single_issued_history_transition` |
| **Retry after temporary failure** | Yes | `tests/Feature/PolicyFlowRealMySQLFeatureTest.php`<br>`test_temporary_failure_then_retry_yields_one_policy_and_the_same_premium` |

### 1. Temporary Failure & Resilient Retry (`TEMPORARY_FAILURE`)
- **Problem**: External insurer experiences transient network hiccups or 5xx downtime during issuance.
- **Implementation**:
  - Provider returns retriable error (HTTP 503).
  - `IssuanceService` records the failure in `issuance_attempts` with `is_success = false` and increments `attempt_count`.
  - `PolicyStateMachine` transitions status from `SUBMITTED` back to `VALIDATED`.
  - The calculated `premium_amount` and `premium_breakdown` snapshot are preserved untouched.
  - Subsequent submissions succeed with HTTP `200 OK` and assign a single policy number.

### 2. Inconclusive Timeout with Immediate Reconciliation (`TIMEOUT_AFTER_ISSUE`)
- **Problem**: Provider creates and commits the policy, but the network connection drops before the ACK reaches PolicyFlow.
- **Implementation**:
  - Provider issues the policy in `mock_policies` and raises a connection timeout exception.
  - `IssuanceService` catches the timeout and transitions the request to `NEEDS_RECONCILIATION`.
  - The service queries the provider's status lookup endpoint (`GET /api/mock-insurer/status/{idempotency_key}`).
  - PolicyFlow retrieves the `policy_number` and `provider_reference`, transitioning state from `NEEDS_RECONCILIATION` to `ISSUED`.
  - Exactly one `mock_policies` row exists.

### 3. Asynchronous Callbacks, Replays & Late Overwrite Protection (`DUPLICATE_CALLBACK`)
- **Problem**: Webhooks can be duplicated, delayed, or arrive after a policy was already resolved via manual reconciliation.
- **Implementation**:
  - **Delayed Callbacks**: `DELAYED_CALLBACK` returns `202 Accepted` and status `PENDING_CONFIRMATION`. The subsequent webhook transitions the policy to `ISSUED`.
  - **Duplicate Callbacks**: Multiple deliveries of the same `provider_event_id` are caught by the database unique index. The first returns `processed`; subsequent arrivals return `already processed` with a single `ISSUED` state history transition.
  - **Late Callback Protection**: If a webhook arrives after a policy has reached `ISSUED`, the event is recorded for audit purposes, but the transition is ignored (`{"status": "ignored"}`). The existing policy number is never overwritten.

---

## Production Scaling: Queue-Based Retries

The retry-after-temporary-failure case is already implemented synchronously in `IssuanceService`, and this section outlines the asynchronous architecture for high-throughput production deployments.

```
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│ Policy Intake   │ ----> │  Redis / SQS    │ ----> │ Worker Pipeline │
│ (HTTP 202/201)  │       │ Queue (Horizon) │       │ (with Backoff)  │
└─────────────────┘       └─────────────────┘       └────────┬────────┘
                                                             │
                                   ┌─────────────────────────┴─────────────────────────┐
                                   ▼                                                   ▼
                        Provider Succeeded (200)                            Provider Failed (503)
                                   │                                                   │
                                   ▼                                                   ▼
                         Mark Policy ISSUED                              Apply Exponential Backoff + Jitter
                                                                         Re-queue with delay = min(300s, 2^attempt * base + rand(jitter))
                                                                                       │
                                                                                       ▼
                                                                         Max Attempts Exceeded (e.g. 5)
                                                                                       │
                                                                                       ▼
                                                                         Move to Dead-Letter Queue (DLQ)
                                                                         Status: NEEDS_RECONCILIATION
                                                                         Trigger Operations Alert
```

1. **Decouple via Queued Background Jobs**:
   - Submit operations dispatch an `IssuePolicyJob` to a background worker queue (e.g. Laravel Horizon with Redis or AWS SQS).
2. **Exponential Backoff with Full Jitter**:
   - Delays retries to prevent synchronized thundering herd spikes against the insurer API:
     $$\text{delay} = \min(\text{max\_delay}, 2^{\text{attempt}} \times \text{base\_delay}) + \text{random\_jitter}$$
3. **Circuit Breaker Pattern**:
   - If the provider error rate exceeds 50% over a 1-minute window, the circuit opens, postponing new calls until a health probe verifies recovery.
4. **Dead-Letter Queues (DLQ) & Reconciliation Alarms**:
   - After a threshold (e.g., 5 attempts), failed jobs move to a DLQ and the policy remains in `NEEDS_RECONCILIATION` for scheduled or manual status lookup.
5. **Token Bucket Rate Limiting**:
   - Enforces throughput limits conforming to carrier API quotas.

---

## Design Decisions

1. **409 Conflict on Payload Mismatch**:
   - When a request is received with an existing `(agent_id, client_request_id)` pair, PolicyFlow verifies that all policy parameters (`customer_name`, `date_of_birth`, `destination`, `trip_start`, `trip_end`, `plan_code`, `sum_insured`) match the original record. If any parameter differs, it returns `409 Conflict` (`IDEMPOTENCY_KEY_PAYLOAD_MISMATCH`) rather than silently returning the original record or overwriting data.
2. **`NEEDS_RECONCILIATION` $\rightarrow$ `VALIDATED` on Provider 404**:
   - When an issuance call times out and the subsequent status lookup returns 404 (the provider never received or created the policy), PolicyFlow transitions the policy back to `VALIDATED`. This prevents requests from becoming permanently stuck in `NEEDS_RECONCILIATION` and allows safe client or automated retry.
3. **In-Process Mock Insurer via `InsurerClient` Interface**:
   - `MockInsurer` implements the `InsurerClient` interface in-process, backed by the dedicated `mock_policies` database table. This enables testing realistic carrier behaviors (timeouts, retries, webhook replays, idempotency) without external network dependencies or flaky third-party mocks.

---

## Required Files

- [`AI_USAGE.md`](AI_USAGE.md): AI disclosure document documenting tools, prompting strategies, corrections, and human verification.
- [`.env.example`](.env.example): Environment configuration template containing necessary database and application environment defaults.

---

## Demo Video

- [Demo Walkthrough Video Link](https://example.com/demo-video-placeholder)

---

## Assumptions

1. **Travel Duration Calculation**:
   - Travel duration is inclusive of both `trip_start` and `trip_end` ($(\text{trip\_end} - \text{trip\_start}) + 1\text{ days}$).
2. **Age Calculation Reference Date**:
   - The traveler's age is calculated as of the `trip_start` date.
3. **Immutability of Premium Snapshots**:
   - Once intake validates a policy request and records its premium, that snapshot is final. Even if submission is retried later, the original premium is preserved.
4. **Mock Scenario Triggers**:
   - Scenarios (`SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `TIMEOUT_WITHOUT_ISSUE`, `DELAYED_CALLBACK`, `DUPLICATE_CALLBACK`) are honored via request parameter or `X-Mock-Scenario` header in `local` and `testing` environments only; all other environments default to `SUCCESS`.
5. **Sum Insured Tiers**:
   - The supported coverage amounts are discrete integers: `100000`, `300000`, `500000`.

---

## Known Limitations

1. **Authentication & Authorization**:
   - Callback (`POST /api/mock-insurer/callback`) and mock insurer endpoints currently have no authentication, HMAC signature verification, or bearer tokens.
2. **Rate Limiting**:
   - API endpoints do not currently enforce rate limiting or throttling.
3. **On-Demand Reconciliation**:
   - Policy reconciliation is currently executed on-demand during submission or lookup; there is no scheduled background cron job to automatically sweep and reconcile stale `NEEDS_RECONCILIATION` or `PENDING_CONFIRMATION` records.
4. **Concurrency Test Platform Dependency (`pcntl`)**:
   - The database concurrency test in `PolicyFlowRealMySQLFeatureTest` uses `pcntl_fork` to test concurrent MySQL process inserts. The `pcntl` extension is available on Unix/Linux/macOS PHP CLI environments. If `pcntl` is unavailable (e.g. Windows), the test falls back to Laravel's `Concurrency::run()` facade.
5. **Synchronous Issuance Endpoint**:
   - `POST /api/policy-requests/{id}/submit` executes synchronously to satisfy test specifications. Production implementations should decouple this via background queue workers.

---

## Running Tests & Examples

### Automated Test Suite
```bash
# Run the entire test suite
./vendor/bin/phpunit

# Or run tests with Artisan
php artisan test
```

### Executing Example Shell Scripts
Executable curl scripts demonstrating every scenario are provided in [examples/](examples/):

```bash
# 1. Valid intake request (201 Created, 225.00 premium)
./examples/01_intake_create.sh

# 2. Idempotent intake replay (200 OK, single DB record)
./examples/02_intake_idempotent_replay.sh

# 3. Successful submission (200 OK, ISSUED)
./examples/03_submit_success.sh

# 4. Temporary failure and retry (503 -> 200 ISSUED)
./examples/04_submit_temporary_failure_and_retry.sh

# 5. Timeout after issue and automatic reconciliation
./examples/05_submit_timeout_reconciliation.sh

# 6. Asynchronous delayed callback webhook flow
./examples/06_delayed_callback_flow.sh

# 7. Duplicate callback replay (already processed)
./examples/07_duplicate_callback_replay.sh

# 8. Late callback ignored without overwriting ISSUED
./examples/08_late_callback_ignored.sh
```
