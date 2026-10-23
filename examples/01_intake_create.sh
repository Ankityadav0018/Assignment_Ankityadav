#!/usr/bin/env bash
# 01_intake_create.sh - Create a valid policy intake request
# Returns 201 Created with premium_amount = 225.00 and status VALIDATED

BASE_URL="${BASE_URL:-http://localhost:8000}"
REQ_ID="req-$(date +%s)-$RANDOM"
AGENT_ID="agent-007"

echo "==> Creating Policy Request: client_request_id=${REQ_ID}, agent_id=${AGENT_ID}"

curl -s -i -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "Alice Smith",
    "date_of_birth": "1996-06-01",
    "destination": "INTERNATIONAL",
    "trip_start": "2024-06-01",
    "trip_end": "2024-06-05",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
echo ""
