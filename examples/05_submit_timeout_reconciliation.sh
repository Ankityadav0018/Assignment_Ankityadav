#!/usr/bin/env bash
# 05_submit_timeout_reconciliation.sh - Timeout after provider creates policy
# Scenario: Provider created policy, network timed out before ACK reached caller.
# IssuanceService catches timeout, transitions to NEEDS_RECONCILIATION, queries status
# lookup from provider, resolves policy to ISSUED, preserving single mock policy row.

BASE_URL="${BASE_URL:-http://localhost:8000}"
REQ_ID="req-timeout-$(date +%s)-$RANDOM"
AGENT_ID="agent-007"

echo "==> 1. Creating Policy Request..."
INTAKE_RESP=$(curl -s -X POST "${BASE_URL}/api/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "'"${REQ_ID}"'",
    "agent_id": "'"${AGENT_ID}"'",
    "customer_name": "Evan Wright",
    "date_of_birth": "1988-11-05",
    "destination": "INTERNATIONAL",
    "trip_start": "2024-10-01",
    "trip_end": "2024-10-10",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }')

POLICY_ID=$(echo "$INTAKE_RESP" | grep -o '"id":"[^"]*' | head -1 | cut -d'"' -f4)
echo "Created Policy ID: ${POLICY_ID}"

echo -e "\n==> 2. Submit with TIMEOUT_AFTER_ISSUE (Expected: 200 OK reconciled to ISSUED via provider status lookup)..."
curl -s -i -X POST "${BASE_URL}/api/policy-requests/${POLICY_ID}/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"scenario": "TIMEOUT_AFTER_ISSUE"}'
echo ""
