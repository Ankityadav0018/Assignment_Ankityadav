# PolicyFlow Example Scripts

This directory contains standalone bash scripts demonstrating every workflow and scenario supported by PolicyFlow via `curl`.

## Prerequisites

Ensure your local development server is running:

```bash
# In the project root:
php artisan serve
```

By default, scripts target `http://localhost:8000`. You can override this using the `BASE_URL` environment variable:

```bash
BASE_URL="http://127.0.0.1:8000" ./examples/01_intake_create.sh
```

## Available Scripts

1. **`01_intake_create.sh`**
   - Creates a valid policy intake request (`POST /api/policy-requests`).
   - Returns HTTP `201 Created` with computed premium `225.00` and status `VALIDATED`.

2. **`02_intake_idempotent_replay.sh`**
   - Submits the initial request, then replays the identical `(agent_id, client_request_id)` payload.
   - Returns HTTP `200 OK` on replay without creating a duplicate record or recomputing premium.

3. **`03_submit_success.sh`**
   - Submits a validated policy request for insurer issuance (`POST /api/policy-requests/{id}/submit`).
   - Simulates the `SUCCESS` scenario; returns HTTP `200 OK` with a deterministic `POL-...` policy number and status `ISSUED`.

4. **`04_submit_temporary_failure_and_retry.sh`**
   - Submits with `scenario=TEMPORARY_FAILURE`.
   - Returns HTTP `503 Service Unavailable`, records the attempt, and reverts state to `VALIDATED` while keeping the original premium snapshot untouched.
   - Immediately executes a second submit with `scenario=SUCCESS`, completing the issuance to `ISSUED`.

5. **`05_submit_timeout_reconciliation.sh`**
   - Submits with `scenario=TIMEOUT_AFTER_ISSUE` (insurer issues policy, but network times out before ACK).
   - Reconciles automatically by transitioning to `NEEDS_RECONCILIATION`, querying the insurer status endpoint, and resolving to `ISSUED` with exactly one `mock_policies` row.

6. **`06_delayed_callback_flow.sh`**
   - Submits with `scenario=DELAYED_CALLBACK`.
   - Returns HTTP `202 Accepted` with status `PENDING_CONFIRMATION` and `provider_reference`.
   - Fires asynchronous webhook callback (`POST /api/mock-insurer/callback`) to resolve status to `ISSUED`.

7. **`07_duplicate_callback_replay.sh`**
   - Sends a webhook callback (`POST /api/mock-insurer/callback`) returning `200 OK {"status": "processed"}`.
   - Replays the exact same callback with the same `provider_event_id`, returning `200 OK {"status": "already processed"}` without reapplying state transitions.

8. **`08_late_callback_ignored.sh`**
   - Fires a conflicting webhook for a policy that has already reached the terminal `ISSUED` status.
   - Returns `200 OK {"status": "ignored"}` and preserves the original `policy_number` and status.
