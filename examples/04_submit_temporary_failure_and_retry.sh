#!/usr/bin/env bash
# 04_submit_temporary_failure_and_retry.sh - Submit failure simulation and retry
# 1st call returns 503 and returns state to VALIDATED.
# 2nd call retries with SUCCESS, returning 200 ISSUED with unchanged premium snapshot.

BASE_URL="${BASE_URL:-http://localhost:8000}"
REQ_ID="req-fail-$(date +%s)-$RANDOM"
AGENT_ID="agent-007"

echo "==> 1. Creating Policy Request..."
INTAKE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "Diana Prince",
    "date_of_birth": "1992-03-20",
    "destination": "SCHENGEN",
    "trip_start": "2024-08-01",
    "trip_end": "2024-08-15",
    "plan_code": "PREMIUM",
    "sum_insured": 500000
  }')

POLICY_ID=$(echo "$INTAKE_RESP" | grep -o '"id":"[^"]*' | head -1 | cut -d'"' -f4)
echo "Created Policy ID: ${POLICY_ID}"

echo -e "\n==> 2. Submit with TEMPORARY_FAILURE (Expected: 503 Service Unavailable, returns to VALIDATED)..."
curl -s -i -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "TEMPORARY_FAILURE"}'

echo -e "\n\n==> 3. Retry Submit with SUCCESS (Expected: 200 OK ISSUED, same premium snapshot)..."
curl -s -i -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "SUCCESS"}'
echo ""
