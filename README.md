# PolicyFlow

PolicyFlow is an enterprise travel insurance policy intake and issuance engine built with **Laravel 11**, **PHP 8.2+**, and **MySQL 8.0+**. It is designed with financial-grade precision, deterministic idempotency, strict state machine transitions, and automated reconciliation for external insurer integrations.

---

## Table of Contents
1. [Exact Setup Steps](#exact-setup-steps)
2. [Architecture Overview](#architecture-overview)
3. [API Contract & Status Codes](#api-contract--status-codes)
4. [Duplicate-Prevention Strategy](#duplicate-prevention-strategy)
5. [Reliability Cases Implemented](#reliability-cases-implemented)
6. [Scaling Retry-After-Temporary-Failure](#scaling-retry-after-temporary-failure-at-scale)
7. [Assumptions](#assumptions)
8. [Known Limitations](#known-limitations)
9. [Running Tests & Examples](#running-tests--examples)

---

## Exact Setup Steps

### 1. Prerequisites
- **PHP 8.2 or 8.3** with extensions: `pdo_mysql`, `bcmath`, `pcntl` (for concurrent tests), `mbstring`, `json`, `xml`, `tokenizer`
- **Composer 2.x**
- **MySQL 8.0+** running locally or via Docker

### 2. Clone and Install Dependencies
```bash
git clone <repo-url> policyflow
cd policyflow

composer install
```

### 3. Environment Configuration
Copy the example environment configuration and generate the application key:
```bash
cp .env.example .env
php artisan key:generate
```

Configure your MySQL credentials in `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=policyflow
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 4. Database Creation
Create both the application database and the dedicated test database in MySQL:
```sql
CREATE DATABASE IF NOT EXISTS policyflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS policyflow_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Run Database Migrations
Run migrations on the primary database:
```bash
php artisan migrate
```

To run migrations on the test database prior to testing:
```bash
php artisan migrate --database=mysql --env=testing
```

### 6. Run the Test Suite
Run the full test suite via PHPUnit:
```bash
./vendor/bin/phpunit
# Or using Laravel's Artisan test runner:
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
│ - policy_state_history  (Full audit trail of all transitions)              │
│ - issuance_attempts     (Every provider invocation & outcome)              │
│ - provider_events       (UNIQUE: provider_event_id for webhooks)           │
│ - mock_policies         (Persisted mock provider state per idempotency key)│
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
   - Implements arbitrary-precision math using `brick/math` (`BigDecimal`), eliminating IEEE 754 binary floating-point rounding errors.
   - Calculates age strictly as of `trip_start` and calculates duration inclusive of both start and end days ($(\text{end} - \text{start}) + 1$).
   - Computes base rate, destination multiplier, age factor, and coverage surcharge, saving both the total `premium_amount` and the granular `premium_breakdown` JSON snapshot.
   - **Zero-recalculation rule**: Premium snapshots are immutable; retries and submissions never recompute the premium.

2. **`PolicyStateMachine` Service**:
   - Governed by the backed enum `App\Enums\PolicyStatus`:
     - `RECEIVED`: Payload stored, initial record created.
     - `VALIDATED`: Premium calculated and snapshotted; ready for submission.
     - `SUBMITTED`: Undergoing provider issuance.
     - `ISSUED`: Terminal success with active `policy_number`.
     - `PENDING_CONFIRMATION`: Asynchronous issuance awaiting callback.
     - `NEEDS_RECONCILIATION`: Inconclusive provider state (e.g. timeout) requiring status query.
     - `FAILED`: Terminal failure.
   - Enforces an explicit, immutable allowed-transition map:
     - `RECEIVED` $\rightarrow$ `VALIDATED`
     - `VALIDATED` $\rightarrow$ `SUBMITTED`
     - `SUBMITTED` $\rightarrow$ `ISSUED` | `PENDING_CONFIRMATION` | `FAILED` | `NEEDS_RECONCILIATION` | `VALIDATED` (retriable failure)
     - `PENDING_CONFIRMATION` $\rightarrow$ `ISSUED` | `FAILED` | `NEEDS_RECONCILIATION`
     - `NEEDS_RECONCILIATION` $\rightarrow$ `ISSUED` | `FAILED`
     - Terminal states (`ISSUED`, `FAILED`): No outgoing transitions permitted.
   - All state transitions execute inside a database transaction and create an immutable entry in `policy_state_history`. Illegal transitions throw `InvalidTransitionException`.

3. **`IssuanceService`**:
   - Coordinates issuance against `InsurerClient` (`MockInsurer`).
   - Uses pessimistic database row locking (`lockForUpdate()`) to serialize concurrent submissions.
   - Handles retries, timeout reconciliation, and idempotency guarantees.

4. **`MockInsurer` & Webhook Engine**:
   - Provides realistic external insurer behavior, with persistent state stored in `mock_policies`.
   - Supports pluggable test scenarios via request payload or `X-Mock-Scenario` header: `SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, and `DUPLICATE_CALLBACK`.

---

## API Contract & Status Codes

### 1. Create Policy Request (Intake)
- **Endpoint**: `POST /api/policy-requests`
- **Request Body**:
  ```json
  {
    "client_request_id": "req-1001",
    "agent_id": "agent-007",
    "customer_name": "Alice Smith",
    "date_of_birth": "1996-06-01",
    "destination": "INTERNATIONAL",
    "trip_start": "2024-06-01",
    "trip_end": "2024-06-05",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }
  ```
- **Responses**:
  - `201 Created`: First successful intake request. Returns computed `premium_amount`, `premium_breakdown`, and status `VALIDATED`.
  - `200 OK`: Idempotent replay of existing `(agent_id, client_request_id)`. Returns the stored snapshot without duplicate DB rows.
  - `422 Unprocessable Entity`: Validation failure (e.g. `trip_end < trip_start`, `date_of_birth` in future, unsupported plan code or coverage tier).

### 2. Get Single Policy Request
- **Endpoint**: `GET /api/policy-requests/{id}`
- **Responses**:
  - `200 OK`: Returns policy record including complete state transition audit trail (`state_history`).
  - `404 Not Found`: Policy UUID does not exist.

### 3. List Policy Requests
- **Endpoint**: `GET /api/policy-requests?status=VALIDATED&per_page=20`
- **Query Parameters**:
  - `status`: Optional filter (`RECEIVED`, `VALIDATED`, `SUBMITTED`, `ISSUED`, `PENDING_CONFIRMATION`, `NEEDS_RECONCILIATION`, `FAILED`).
  - `per_page`: Pagination size (1 to 100, default 20).
- **Responses**:
  - `200 OK`: Paginated resource list.

### 4. Submit Policy Request for Issuance
- **Endpoint**: `POST /api/policy-requests/{id}/submit`
- **Headers / Body**: Optional `{"scenario": "SUCCESS"}` or `X-Mock-Scenario: SUCCESS`
- **Responses**:
  - `200 OK`: Successfully issued (`status: ISSUED`, with `policy_number` and `provider_reference`). If already `ISSUED`, returns the current record with zero additional provider calls.
  - `202 Accepted`: Asynchronous provider flow (`status: PENDING_CONFIRMATION`, with `provider_reference`).
  - `503 Service Unavailable`: Retriable provider failure (`scenario=TEMPORARY_FAILURE`). Reverts status to `VALIDATED` with attempt recorded, ready for subsequent retry.
  - `504 Gateway Timeout`: Inconclusive provider timeout unresolved by status lookup (`status: NEEDS_RECONCILIATION`).
  - `409 Conflict`: Invalid state for submission (e.g. attempting to submit a `FAILED` policy).
  - `404 Not Found`: Policy UUID does not exist.

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
  - `200 OK {"status": "processed"}`: First arrival of event; transitions policy to `ISSUED`.
  - `200 OK {"status": "already processed"}`: Event with duplicate `provider_event_id` received; no duplicate state transition applied.
  - `200 OK {"status": "ignored"}`: Event received for policy already in terminal state (`ISSUED` or `FAILED`); event logged but policy unchanged.
  - `404 Not Found`: Unknown `provider_reference`.
  - `422 Unprocessable Entity`: Missing required webhook payload fields.

### 6. Provider Status Lookup
- **Endpoint**: `GET /api/mock-insurer/status/{idempotency_key}`
- **Responses**:
  - `200 OK`: Returns provider policy status, policy number, and reference.
  - `404 Not Found`: No policy issued for the given idempotency key.

---

## Duplicate-Prevention Strategy

PolicyFlow guarantees strict duplicate prevention across all layers:

1. **Database Unique Constraints**:
   - `policy_requests`: `UNIQUE KEY (agent_id, client_request_id)`.
   - `provider_events`: `UNIQUE KEY (provider_event_id)`.
   - `mock_policies`: `UNIQUE KEY (idempotency_key)`.

2. **Optimistic Insert Pattern (No Check-Then-Insert TOCTOU Races)**:
   - Rather than executing `SELECT ... WHERE agent_id = ? AND client_request_id = ?` followed by `INSERT`, PolicyFlow executes the `INSERT` directly inside a database transaction.
   - If two identical requests race concurrently, the second triggers a `UniqueConstraintViolationException` at the database engine level. PolicyFlow catches this exception and returns the existing row with HTTP `200 OK`.

3. **Pessimistic Row Locking (`lockForUpdate`) During Submission**:
   - `IssuanceService::submit()` begins a transaction and queries the policy record with `lockForUpdate()`.
   - Any concurrent submission for the same policy request blocks until the first transaction commits or rolls back, preventing double-submission races.
   - If the locked row is already `ISSUED`, the service returns it immediately without making any provider calls.

4. **Deterministic Provider Idempotency Keys**:
   - The provider `issue()` method takes the policy request UUID as its `idempotency_key`.
   - Even if network anomalies cause retried requests to reach the provider, `MockInsurer` looks up the `mock_policies` table by `idempotency_key` and returns the existing policy without issuing a second policy.

5. **Webhook Deduplication**:
   - Every incoming provider event is inserted into `provider_events` with `provider_event_id`. Duplicate webhook deliveries hit the unique constraint and return `already processed` without triggering duplicate state machine transitions.

---

## Reliability Cases Implemented

### 1. Temporary Failure & Resilient Retry (`TEMPORARY_FAILURE`)
- **Problem**: External insurer experiences transient network hiccups or 5xx downtime during issuance.
- **Implementation**:
  - Provider throws retriable exception (`503 Service Unavailable`).
  - `IssuanceService` records the failure in `issuance_attempts` with `is_success = false` and increments `attempt_count`.
  - `PolicyStateMachine` transitions status from `SUBMITTED` back to `VALIDATED`.
  - The calculated `premium_amount` and `premium_breakdown` snapshot are completely untouched.
  - Subsequent submissions succeed with HTTP `200 OK` and assign a single policy number.

### 2. Inconclusive Timeout with Immediate Reconciliation (`TIMEOUT_AFTER_ISSUE`)
- **Problem**: Provider successfully creates and commits the policy, but the connection drops before the HTTP response arrives back at PolicyFlow. A naive client might retry and risk double-issuance.
- **Implementation**:
  - The provider issues the policy in `mock_policies` and raises a connection timeout exception.
  - `IssuanceService` catches the timeout and transitions the request to `NEEDS_RECONCILIATION`.
  - The service immediately queries the provider's status lookup endpoint (`GET /api/mock-insurer/status/{idempotency_key}`).
  - Because the provider has the policy, PolicyFlow retrieves the `policy_number` and `provider_reference`, smoothly transitioning the state from `NEEDS_RECONCILIATION` to `ISSUED`.
  - Exactly one `mock_policies` row exists.

### 3. Asynchronous Callbacks, Replays & Late Overwrite Protection (`DUPLICATE_CALLBACK`)
- **Problem**: Webhooks can be duplicated, delayed, or arrive after a policy was already resolved via manual reconciliation.
- **Implementation**:
  - **Delayed Callbacks**: `DELAYED_CALLBACK` returns `202 Accepted` and status `PENDING_CONFIRMATION`. When the webhook arrives, it transitions the policy to `ISSUED`.
  - **Duplicate Callbacks**: Multiple deliveries of the same `provider_event_id` are caught by the database unique index. The first returns `processed`; subsequent arrivals return `already processed` and produce exactly one `ISSUED` state history transition.
  - **Late Callback Protection**: If a webhook arrives with a different payload or policy number after a policy has already reached `ISSUED`, the event is recorded for audit purposes, but the transition is ignored (`{"status": "ignored"}`). The existing policy number is never overwritten.

---

## Scaling Retry-After-Temporary-Failure at Scale

In a high-throughput production environment, synchronous retries can lead to thread exhaustion and "thundering herd" problems against external insurer APIs. Here is the architectural blueprint for scaling retries:

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
                                                                         Trigger On-Call Alert (PagerDuty/Slack)
```

1. **Decouple via Queued Background Jobs**:
   - Rather than blocking synchronous web requests, submit operations dispatch an `IssuePolicyJob` to a resilient queue worker cluster (e.g. **Laravel Horizon** backed by Redis or AWS SQS).

2. **Exponential Backoff with Full Jitter**:
   - Configure exponential retry delays with randomized jitter to prevent synchronized retries from overwhelming the insurer:
     $$\text{delay} = \min(\text{max\_delay}, 2^{\text{attempt}} \times \text{base\_delay}) + \text{random\_jitter}$$
   - Example delays: 2s $\rightarrow$ 5s $\rightarrow$ 12s $\rightarrow$ 30s $\rightarrow$ 60s $\rightarrow$ 120s.

3. **Circuit Breaker Pattern**:
   - Employ a circuit breaker (e.g., Redis-backed sliding window via Guzzle middleware).
   - If the provider's failure rate exceeds 50% over a 1-minute window, the circuit "trips" to **OPEN**. Subsequent jobs are automatically delayed without making network calls until a **HALF-OPEN** probe verifies recovery.

4. **Dead-Letter Queues (DLQ) & Reconciliation Alarms**:
   - After a maximum retry threshold (e.g., 5 attempts), the job moves to a Dead-Letter Queue.
   - The policy moves to `NEEDS_RECONCILIATION`, alerting operations staff or automated reconciliation cron jobs to inspect provider status before failing the policy.

5. **Token Bucket Rate Limiting**:
   - Enforce rate limiters matching provider SLA limits per API credential or underwriter.

---

## Assumptions

1. **Travel Duration Calculation**:
   - Travel duration is inclusive of both `trip_start` and `trip_end` ($(\text{trip\_end} - \text{trip\_start}) + 1\text{ days}$).
2. **Age Calculation Reference Date**:
   - The traveler's age is calculated strictly as of the `trip_start` date.
3. **Immutability of Premium Snapshots**:
   - Once intake validates a policy request and records its premium, that snapshot is final. Even if policy submission fails and is retried weeks later, the original premium is preserved.
4. **Mock Scenario Triggers**:
   - Testing scenarios (`SUCCESS`, `TEMPORARY_FAILURE`, `TIMEOUT_AFTER_ISSUE`, `DELAYED_CALLBACK`, `DUPLICATE_CALLBACK`) are triggered via request parameter or header for local and testing environments only; production environments default to live insurer behavior.
5. **Sum Insured Tiers**:
   - The supported coverage amounts are discrete integers: `100000`, `300000`, `500000`.

---

## Known Limitations

1. **Synchronous Issuance Endpoint**:
   - `POST /api/policy-requests/{id}/submit` currently executes synchronously to satisfy test specifications. In enterprise production, long-running carrier APIs should always execute via asynchronous background jobs.
2. **Single Provider Implementation**:
   - The current engine integrates directly with `MockInsurer`. A production deployment would introduce a provider factory pattern with multiple carrier adapters (e.g., Allianz, Zurich, AXA).
3. **Database Gap Locks on Multi-Master Clusters**:
   - When running high-throughput concurrent inserts hitting duplicate constraints under MySQL InnoDB `REPEATABLE READ`, gap locks can induce deadlocks in multi-master topologies (e.g., Galera or Aurora Multi-Master). `READ COMMITTED` transaction isolation is recommended for extreme write concurrency.

---

## Running Tests & Examples

### Automated Test Suite
PolicyFlow includes a comprehensive suite of unit and feature tests covering state machine transitions, premium calculation precision, idempotency, race conditions, and webhook replays:

```bash
# Run the entire test suite
./vendor/bin/phpunit

# Or run tests with Artisan
php artisan test
```

### Executing Example Shell Scripts
Executable curl scripts are provided in [`examples/`](file:///Users/raoshab/Desktop/assignment/policyflow/examples):

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
