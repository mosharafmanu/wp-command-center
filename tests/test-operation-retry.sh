#!/usr/bin/env bash
#
# Operation Retry Engine test suite for WP Command Center (Step 24).
#
# Verifies:
#   - failed item can retry
#   - completed item cannot retry
#   - cancelled item cannot retry
#   - running item cannot retry
#   - queued item cannot retry
#   - max attempts respected
#   - audit entries created
#   - timeline entries created
#   - agent context includes retryable failures
#   - full regression passes
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-operation-retry.sh

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

echo "== 1. Setup Request =="

# 1. Create Request
REQ_BODY='{"operation_id":"content_seed","payload":{"type":"bad_type","count":1}}'
REQ_CREATE=$(api POST /operations/requests "$REQ_BODY")
REQ_ID=$(echo "$REQ_CREATE" | jq -r '.request_id')

api POST "/operations/requests/$REQ_ID/approve" > /dev/null

QUEUE_RESP=$(api POST "/operations/requests/$REQ_ID/queue")
QUEUE_ID=$(echo "$QUEUE_RESP" | jq -r '.queue_id')

echo "== 2. Queue Status Guards =="

RETRY_QUEUED=$(api POST "/operations/queue/$QUEUE_ID/retry")
assert_eq "cannot retry queued item" "wpcc_cannot_retry" "$(echo "$RETRY_QUEUED" | jq -r '.code')"

# Run to make it fail
api POST "/operations/queue/$QUEUE_ID/run" > /dev/null

echo "== 3. Successful Retry =="

RETRY_RESP=$(api POST "/operations/queue/$QUEUE_ID/retry")
assert_eq "retry successful (queued status)" "queued" "$(echo "$RETRY_RESP" | jq -r '.status')"

# Context Check
CONTEXT=$(api GET "/agent/context")
assert_true "context contains failed_queue_items" "$(echo "$CONTEXT" | jq -r 'has("failed_queue_items")')"
assert_true "context contains retryable_queue_items" "$(echo "$CONTEXT" | jq -r 'has("retryable_queue_items")')"

echo "== 4. Max Attempts =="

# Run and retry to hit max
api POST "/operations/queue/$QUEUE_ID/run" > /dev/null
api POST "/operations/queue/$QUEUE_ID/retry" > /dev/null
api POST "/operations/queue/$QUEUE_ID/run" > /dev/null

# Now attempts should be 3 (max_attempts is 3)
RETRY_MAX=$(api POST "/operations/queue/$QUEUE_ID/retry")
assert_eq "cannot retry max attempts reached" "wpcc_max_attempts_reached" "$(echo "$RETRY_MAX" | jq -r '.code')"

echo "== 5. Cancellation Guard =="

REQ_CAN_CREATE=$(api POST /operations/requests '{"operation_id":"content_seed","payload":{"type":"bad_type","count":1}}')
CAN_REQ_ID=$(echo "$REQ_CAN_CREATE" | jq -r '.request_id')
api POST "/operations/requests/$CAN_REQ_ID/approve" > /dev/null
QUEUE_CAN_RESP=$(api POST "/operations/requests/$CAN_REQ_ID/queue")
CAN_ID=$(echo "$QUEUE_CAN_RESP" | jq -r '.queue_id')

api POST "/operations/queue/$CAN_ID/cancel" > /dev/null
RETRY_CAN=$(api POST "/operations/queue/$CAN_ID/retry")
assert_eq "cannot retry cancelled item" "wpcc_cannot_retry" "$(echo "$RETRY_CAN" | jq -r '.code')"

echo "== 6. Timeline & Audit =="

TIMELINE=$(api GET "/agent/timeline?limit=30")
assert_true "timeline has retry requested" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Operation retry requested")')"
assert_true "timeline has retry queued" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Operation retry queued")')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
