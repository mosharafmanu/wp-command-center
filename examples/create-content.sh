#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# WP Command Center — Create Content Workflow Example
# ──────────────────────────────────────────────────────────────────────────────
# Demonstrates the operation request → approve → execute → check results
# lifecycle using the REST API.
#
# Prerequisites:
#   - curl installed
#   - wpcc-env.sh sourced (exports $WPCC_BASE and $WPCC_TOKEN)
#   - A full-access token in Settings → API Tokens
# ──────────────────────────────────────────────────────────────────────────────
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"

# ── 1. Source credentials ──
if [ -f "$PLUGIN_DIR/wpcc-env.sh" ]; then
  # shellcheck source=/dev/null
  source "$PLUGIN_DIR/wpcc-env.sh"
else
  echo "ERROR: wpcc-env.sh not found at $PLUGIN_DIR/wpcc-env.sh"
  echo "Create it with:"
  echo '  export WPCC_BASE="http://localhost/wp-json/wp-command-center/v1"'
  echo '  export WPCC_TOKEN="wpcc_..."'
  exit 1
fi

echo "=== WP Command Center — Create Content Workflow ==="
echo ""

# ── Helper ──
wpcc() {
  curl -s -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"
}

# ── 2. Create an operation request for content seeding ──
echo ">>> Step 1: Creating content_seed operation request..."
REQUEST=$(wpcc -X POST \
  -d '{"operation_id":"content_seed","payload":{"type":"post","count":5,"status":"draft","title_pattern":"Demo Post {n}"}}' \
  "$WPCC_BASE/operations/requests")

REQUEST_ID=$(echo "$REQUEST" | jq -r '.request_id')

if [ -z "$REQUEST_ID" ] || [ "$REQUEST_ID" = "null" ]; then
  echo "ERROR: Failed to create request."
  echo "$REQUEST" | jq .
  exit 1
fi

echo "   Request created: $REQUEST_ID"
echo "   Status: $(echo "$REQUEST" | jq -r '.status')"
echo "   Risk level: $(echo "$REQUEST" | jq -r '.risk_level')"
echo ""

# ── 3. Approve the request ──
echo ">>> Step 2: Approving request $REQUEST_ID..."
APPROVAL=$(wpcc -X POST "$WPCC_BASE/operations/requests/$REQUEST_ID/approve")
APPROVAL_STATUS=$(echo "$APPROVAL" | jq -r '.status')

if [ "$APPROVAL_STATUS" != "approved" ]; then
  echo "ERROR: Approval failed."
  echo "$APPROVAL" | jq .
  exit 1
fi

echo "   Request approved."
echo ""

# ── 4. Execute the request ──
echo ">>> Step 3: Executing request $REQUEST_ID..."
EXEC_RESULT=$(wpcc -X POST "$WPCC_BASE/operations/requests/$REQUEST_ID/execute")
EXEC_STATUS=$(echo "$EXEC_RESULT" | jq -r '.status')

if [ "$EXEC_STATUS" != "executed" ]; then
  echo "ERROR: Execution failed."
  echo "$EXEC_RESULT" | jq .
  exit 1
fi

echo "   Execution complete."
echo ""

# ── 5. Check results ──
echo ">>> Step 4: Checking operation results..."
RESULTS=$(wpcc "$WPCC_BASE/operations/results?request_id=$REQUEST_ID")
RESULT_COUNT=$(echo "$RESULTS" | jq 'length')

echo "   Results found: $RESULT_COUNT"
echo ""

if [ "$RESULT_COUNT" -gt 0 ]; then
  echo ">>> Latest result:"
  echo "$RESULTS" | jq '.[0] | {result_id, status, operation_id, created_at}'
fi

echo ""
echo "=== Workflow complete ==="
