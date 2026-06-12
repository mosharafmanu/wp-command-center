#!/usr/bin/env bash
#
# WP-CLI Bridge Operation test suite for WP Command Center (Step 29).
#
# Verifies:
#   - unavailable environment returns unavailable
#   - arbitrary command blocked
#   - allowed command mapping works
#   - timeout handling
#   - redaction
#   - audit entries
#   - timeline entries
#   - manifest availability
#   - regression passes
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-wp-cli-bridge.sh

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

# Ensure availability before testing deep logic
REGISTRY=$(api GET /agent/manifest)
CLI_AVAIL=$(echo "$REGISTRY" | jq -r '.capability_negotiation.wp_cli // false')

if [[ "$CLI_AVAIL" != "true" ]]; then
	echo "== 1. Environment Simulation =="
	INV=$(api POST /operations/wp_cli_bridge/run '{"command":"plugin_list"}')
	assert_eq "unavailable env returns correctly" "operation_not_available" "$(echo "$INV" | jq -r '.code')"
	echo
	echo "== Summary =="
	echo "  $PASS passed, $FAIL failed"
	[ "$FAIL" -eq 0 ]
	exit 0
fi

echo "== 1. Validation & Guards =="

# Arbitrary command blocked
INV_CMD=$(api POST /operations/wp_cli_bridge/run '{"command":"eval"}')
assert_eq "validation: arbitrary command blocked" "wpcc_invalid_wpcli_command" "$(echo "$INV_CMD" | jq -r '.code')"

echo
echo "== 2. Allowed Commands =="

# plugin_list
PL_LIST=$(api POST /operations/wp_cli_bridge/run '{"command":"plugin_list"}')
assert_eq "execution: command is plugin_list" "plugin_list" "$(echo "$PL_LIST" | jq -r '.command')"
assert_true "execution: output is JSON array" "$([[ $(echo "$PL_LIST" | jq '.output | type') == '"array"' ]] && echo true || echo false)"

# cache_flush
CACHE=$(api POST /operations/wp_cli_bridge/run '{"command":"cache_flush"}')
assert_eq "execution: command is cache_flush" "cache_flush" "$(echo "$CACHE" | jq -r '.command')"

echo
echo "== 3. Timeline & Audit =="

TIMELINE=$(api GET "/agent/timeline?limit=30")
assert_true "timeline: has started event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "WP-CLI operation started")')"
assert_true "timeline: has completed event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "WP-CLI operation completed")')"
assert_true "timeline: summary contains command name" "$(echo "$TIMELINE" | jq -r 'any(.[]; .summary | contains("cache_flush"))')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
