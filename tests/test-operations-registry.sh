#!/usr/bin/env bash
#
# Operations Registry test suite for WP Command Center (Step 15).
#
# Verifies:
#   - registry loads
#   - operation discovery works
#   - availability detection works
#   - operations appear in manifest
#   - operations appear in agent context
#   - read-only access enforced
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-operations-registry.sh

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

echo "== 1. Operation Discovery =="

OPS=$(api GET /operations)
assert_true "operations list is an array" "$(echo "$OPS" | jq -r 'type == "array"')"

# Check for specific operations
for op_id in "content_seed" "acf_seed" "cf7_seed" "woo_product_seed" "safe_search_replace" "safe_updates" "wp_cli_bridge"; do
	assert_true "operation found: $op_id" "$(echo "$OPS" | jq -r --arg id "$op_id" 'any(.[]; .id == $id)')"
done

# Check operation detail
OP_DETAIL=$(api GET /operations/content_seed)
assert_eq "operation detail: correct id" "content_seed" "$(echo "$OP_DETAIL" | jq -r '.id')"
assert_true "operation detail: has risk_level" "$(echo "$OP_DETAIL" | jq -r 'has("risk_level")')"
assert_true "operation detail: has available status" "$(echo "$OP_DETAIL" | jq -r 'has("available")')"

echo
echo "== 2. Availability Detection =="

# Check WooCommerce availability
WOO_INFO=$(api GET /site-intelligence | jq -r '.woocommerce.active')
WOO_OP_AVAL=$(echo "$OPS" | jq -r '(.[] | select(.id == "woo_product_seed")).available')
assert_eq "woo_product_seed availability matches site-intelligence" "$WOO_INFO" "$WOO_OP_AVAL"

# Check WP-CLI availability
CLI_INFO=$(api GET /capabilities | jq -r '.wp_cli')
CLI_OP_AVAL=$(echo "$OPS" | jq -r '(.[] | select(.id == "wp_cli_bridge")).available')
assert_eq "wp_cli_bridge availability matches capabilities" "$CLI_INFO" "$CLI_OP_AVAL"

echo
echo "== 3. Manifest & Context Integration =="

MANIFEST=$(api GET /agent/manifest)
assert_true "manifest contains operations" "$(echo "$MANIFEST" | jq -r 'has("operations")')"
assert_true "manifest operations list is not empty" "$(echo "$MANIFEST" | jq -r '.operations | length > 0')"

CONTEXT=$(api GET /agent/context)
assert_true "context contains operations" "$(echo "$CONTEXT" | jq -r 'has("operations")')"
assert_true "context operations list is not empty" "$(echo "$CONTEXT" | jq -r '.operations | length > 0')"

echo
echo "== 4. Security =="

# Verify read-only access (using a fake token or no token)
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$WPCC_BASE/operations")
assert_eq "no token: returns 401" "401" "$HTTP_CODE"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
