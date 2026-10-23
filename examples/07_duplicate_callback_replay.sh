#!/usr/bin/env bash
# 07_duplicate_callback_replay.sh - Replay identical webhook callback
# 1st callback: returns 200 "processed" and transitions to ISSUED.
# 2nd callback with same provider_event_id: returns 200 "already processed" without reapplying.

BASE_URL="${BASE_URL:-http://localhost:8000}"
REQ_ID="req-dup-cb-$(date +%s)-$RANDOM"
AGENT_ID="agent-007"

echo "==> 1. Creating & Submitting Policy with DELAYED_CALLBACK..."
INTAKE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "George Clark",
    "date_of_birth": "1985-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2024-12-01",
    "trip_end": "2024-12-10",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }')

POLICY_ID=$(echo "$INTAKE_RESP" | grep -o '"id":"[^"]*' | head -1 | cut -d'"' -f4)

SUBMIT_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "DELAYED_CALLBACK"}')

PROVIDER_REF=$(echo "$SUBMIT_RESP" | grep -o '"provider_reference":"[^"]*' | cut -d'"' -f4)

EVENT_ID="EVT-IDEMPOTENT-$(date +%s)-$RANDOM"
POL_NO="POL-IDEMPOTENT-12345"

echo -e "\n==> 2. Webhook 1st Arrival (Expected: 200 OK 'processed')..."
curl -s -i -X POST "${BASE_URL}/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "'"${EVENT_ID}"'",
    "provider_reference": "'"${PROVIDER_REF}"'",
    "policy_number": "'"${POL_NO}"'",
    "event_type": "POLICY_ISSUED"
  }'

echo -e "\n\n==> 3. Webhook 2nd Arrival with DUPLICATE provider_event_id (Expected: 200 OK 'already processed')..."
curl -s -i -X POST "${BASE_URL}/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "'"${EVENT_ID}"'",
    "provider_reference": "'"${PROVIDER_REF}"'",
    "policy_number": "'"${POL_NO}"'",
    "event_type": "POLICY_ISSUED"
  }'
echo ""
