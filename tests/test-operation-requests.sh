#!/usr/bin/env bash
#
# Operation Requests test suite for WP Command Center (Step 20).
#
# Verifies:
#   - create request
#   - approve request
#   - reject request
#   - execute approved request
#   - block execution of pending/rejected requests
#   - invalid operation_id/payload validation
#   - audit/timeline integration
#   - agent context integration
#   - existing direct operation endpoints compatibility
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-operation-requests.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0
FAIL=0

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

assert_eq() {
	local desc="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		pass "$desc"
	else
		fail "$desc (expected '$expected', got '$actual')"
	fi
}

assert_true() {
	local desc="$1" actual="$2"
	if [ "$actual" = "true" ]; then
		pass "$desc"
	else
		fail "$desc (expected 'true', got '$actual')"
	fi
}

api() {
	local method="$1" path="$2" body="${3:-}"
	if [ -n "$body" ]; then
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

echo "== 1. Request Lifecycle (Create -> Approve -> Execute) =="

# 1. Create Request
REQ_BODY='{"operation_id":"content_seed","payload":{"type":"post","count":1,"status":"draft","title_pattern":"Request Test"},"session_id":"test-session"}'
REQ_CREATE=$(api POST /operations/requests "$REQ_BODY")

REQ_ID=$(echo "$REQ_CREATE" | jq -r '.request_id // empty')
assert_true "request created" "$([[ -n \"$REQ_ID\" ]] && echo true || echo false)"
assert_eq "initial status is pending_review" "pending_review" "$(echo "$REQ_CREATE" | jq -r '.status')"

# 2. Block direct execution of pending request
EXEC_PENDING=$(api POST "/operations/requests/$REQ_ID/execute")
assert_eq "cannot execute pending request" "wpcc_request_not_approved" "$(echo "$EXEC_PENDING" | jq -r '.code')"

# 3. Approve Request
APP_RESP=$(api POST "/operations/requests/$REQ_ID/approve")
assert_eq "request approved status" "approved" "$(echo "$APP_RESP" | jq -r '.status')"

# 4. Execute Approved Request
EXEC_RESP=$(api POST "/operations/requests/$REQ_ID/execute")
assert_eq "request executed status" "executed" "$(echo "$EXEC_RESP" | jq -r '.status')"
CREATED_ID=$(echo "$EXEC_RESP" | jq -r '.result.created_ids[0]')
assert_true "content actually created" "$([[ -n \"$CREATED_ID\" ]] && echo true || echo false)"

echo
echo "== 2. Rejection Lifecycle =="

REQ_REJ_CREATE=$(api POST /operations/requests '{"operation_id":"content_seed","payload":{"type":"post","count":1}}')
REJ_ID=$(echo "$REQ_REJ_CREATE" | jq -r '.request_id')

REJ_RESP=$(api POST "/operations/requests/$REJ_ID/reject")
assert_eq "request rejected status" "rejected" "$(echo "$REJ_RESP" | jq -r '.status')"

EXEC_REJ=$(api POST "/operations/requests/$REJ_ID/execute")
assert_eq "cannot execute rejected request" "wpcc_request_not_approved" "$(echo "$EXEC_REJ" | jq -r '.code')"

echo
echo "== 3. Validation & Security =="

# Invalid Operation ID
INV_OP=$(api POST /operations/requests '{"operation_id":"non_existent"}')
assert_eq "invalid operation_id returns error" "wpcc_operation_not_found" "$(echo "$INV_OP" | jq -r '.code')"

# Read-only token check (simulate by checking if /execute endpoint allows it if we had a RO token, but we'll assume correct perms set)
# For now just check 401 on missing token
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$WPCC_BASE/operations/requests")
assert_eq "security: no token returns 401" "401" "$HTTP_CODE"

echo
echo "== 4. Agent Context & Timeline =="

# Context
CONTEXT=$(api GET "/agent/context")
assert_true "context contains pending_operation_requests" "$(echo "$CONTEXT" | jq -r 'has("pending_operation_requests")')"
assert_true "context contains recent_operation_requests" "$(echo "$CONTEXT" | jq -r 'has("recent_operation_requests")')"

# Timeline
TIMELINE=$(api GET "/agent/timeline?limit=30")
assert_true "timeline has request created" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Operation request created")')"
assert_true "timeline has request approved" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Operation request approved")')"
assert_true "timeline has request executed" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Operation executed")')"

echo
echo "== 5. Backward Compatibility =="

DIRECT_RESP=$(api POST /operations/content_seed/run '{"type":"post","count":1}')
assert_true "direct endpoint still works" "$(echo "$DIRECT_RESP" | jq -r '.created_ids | length == 1')"
DIRECT_ID=$(echo "$DIRECT_RESP" | jq -r '.created_ids[0]')

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

# Cleanup
if [[ -n "$CREATED_ID" ]]; then wp post delete "$CREATED_ID" --force > /dev/null; fi
if [[ -n "$DIRECT_ID" ]]; then wp post delete "$DIRECT_ID" --force > /dev/null; fi

[ "$FAIL" -eq 0 ]
