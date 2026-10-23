#!/usr/bin/env bash
# 02_intake_idempotent_replay.sh - Replay identical agent_id + client_request_id
# Returns 200 OK on duplicate with the original snapshot preserved without duplicate DB rows

BASE_URL="${BASE_URL:-http://localhost:8000}"
REQ_ID="req-fixed-$(date +%s)"
AGENT_ID="agent-alpha"

echo "==> 1. Initial Intake Call (Expected: 201 Created)"
curl -s -i -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "Bob Jones",
    "date_of_birth": "1994-01-15",
    "destination": "DOMESTIC",
    "trip_start": "2024-07-01",
    "trip_end": "2024-07-07",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }'

echo -e "\n\n==> 2. Replay Intake Call with SAME key (Expected: 200 OK idempotent replay)"
curl -s -i -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "Bob Jones",
    "date_of_birth": "1994-01-15",
    "destination": "DOMESTIC",
    "trip_start": "2024-07-01",
    "trip_end": "2024-07-07",
    "plan_code": "BASIC",
    "sum_insured": 100000
  }'
echo ""
