#!/usr/bin/env bash
# 03_submit_success.sh - Submit a validated policy request for issuance
# Returns 200 OK with policy_number (e.g. POL-...) and status ISSUED

BASE_URL="${BASE_URL:-http://localhost:8000}"
REQ_ID="req-submit-$(date +%s)-$RANDOM"
AGENT_ID="agent-007"

echo "==> 1. Creating Policy Request..."
INTAKE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "Charlie Brown",
    "date_of_birth": "1990-05-12",
    "destination": "INTERNATIONAL",
    "trip_start": "2024-09-01",
    "trip_end": "2024-09-10",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }')

POLICY_ID=$(echo "$INTAKE_RESP" | grep -o '"id":"[^"]*' | head -1 | cut -d'"' -f4)
echo "Created Policy ID: ${POLICY_ID}"

echo -e "\n==> 2. Submitting Policy for Issuance (SUCCESS scenario)..."
curl -s -i -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "SUCCESS"}'
echo ""
