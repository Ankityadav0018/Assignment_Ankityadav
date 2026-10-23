#!/usr/bin/env bash
# 06_delayed_callback_flow.sh - Submit with delayed callback and asynchronous webhook
# 1. Submit returns 202 Accepted with status PENDING_CONFIRMATION and provider_reference.
# 2. Insurer asynchronously fires webhook POST /api/mock-insurer/callback.
# 3. Policy transitions to ISSUED.

BASE_URL="${BASE_URL:-http://localhost:8000}"
REQ_ID="req-delayed-$(date +%s)-$RANDOM"
AGENT_ID="agent-007"

echo "==> 1. Creating Policy Request..."
INTAKE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "Fiona Gallagher",
    "date_of_birth": "1995-07-14",
    "destination": "DOMESTIC",
    "trip_start": "2024-11-01",
    "trip_end": "2024-11-05",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }')

POLICY_ID=$(echo "$INTAKE_RESP" | grep -o '"id":"[^"]*' | head -1 | cut -d'"' -f4)
echo "Created Policy ID: ${POLICY_ID}"

echo -e "\n==> 2. Submit with DELAYED_CALLBACK (Expected: 202 Accepted, PENDING_CONFIRMATION)..."
SUBMIT_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "DELAYED_CALLBACK"}')
echo "$SUBMIT_RESP"

PROVIDER_REF=$(echo "$SUBMIT_RESP" | grep -o '"provider_reference":"[^"]*' | cut -d'"' -f4)
echo "Provider Reference: ${PROVIDER_REF}"

EVENT_ID="EVT-$(date +%s)-$RANDOM"
POL_NO="POL-ASYNC-$(date +%s)"

echo -e "\n==> 3. Emitting Webhook Callback (POST /api/mock-insurer/callback)..."
curl -s -i -X POST "${BASE_URL}/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "'"${EVENT_ID}"'",
    "provider_reference": "'"${PROVIDER_REF}"'",
    "policy_number": "'"${POL_NO}"'",
    "event_type": "POLICY_ISSUED"
  }'

echo -e "\n\n==> 4. Checking Policy State (Expected: ISSUED)..."
curl -s "${BASE_URL}/api/policy-requests/${POLICY_ID}" | grep -o '"status":"[^"]*'
echo ""
