#!/usr/bin/env bash
# 08_late_callback_ignored.sh - Late callback arriving after policy is already ISSUED
# When a policy is already terminal (ISSUED or FAILED), an incoming callback is recorded
# in provider_events but ignored (returns 200 "ignored") without overwriting status or policy_number.

BASE_URL="${BASE_URL:-http://localhost:8000}"
REQ_ID="req-late-cb-$(date +%s)-$RANDOM"
AGENT_ID="agent-007"

echo "==> 1. Creating Policy and Issuing directly via SUCCESS..."
INTAKE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "Hannah Abbott",
    "date_of_birth": "1993-02-17",
    "destination": "DOMESTIC",
    "trip_start": "2024-05-01",
    "trip_end": "2024-05-04",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }')

POLICY_ID=$(echo "$INTAKE_RESP" | grep -o '"id":"[^"]*' | head -1 | cut -d'"' -f4)

SUBMIT_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit")
ORIGINAL_POL=$(echo "$SUBMIT_RESP" | grep -o '"policy_number":"[^"]*' | cut -d'"' -f4)
PROVIDER_REF=$(echo "$SUBMIT_RESP" | grep -o '"provider_reference":"[^"]*' | cut -d'"' -f4)

echo "Policy Issued with Policy Number: ${ORIGINAL_POL}"
echo "Provider Reference: ${PROVIDER_REF}"

echo -e "\n==> 2. Late callback arriving with conflicting rogue policy number (Expected: 200 OK 'ignored')..."
curl -s -i -X POST "${BASE_URL}/api/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_event_id": "EVT-LATE-'"$(date +%s)"'",
    "provider_reference": "'"${PROVIDER_REF}"'",
    "policy_number": "POL-ROGUE-OVERWRITE-9999",
    "event_type": "POLICY_ISSUED"
  }'

echo -e "\n\n==> 3. Verifying Policy in DB (Original policy_number MUST be preserved)..."
curl -s "${BASE_URL}/api/policy-requests/${POLICY_ID}" | grep -o '"policy_number":"[^"]*'
echo ""
