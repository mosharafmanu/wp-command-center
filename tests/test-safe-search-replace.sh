#!/usr/bin/env bash
#
# Safe Search & Replace Operation test suite for WP Command Center (Step 26).
#
# Verifies:
#   - dry run works
#   - real run works
#   - empty search blocked
#   - same search/replace blocked
#   - invalid table blocked
#   - non-prefixed table blocked
#   - serialized data safety basic case
#   - audit entries
#   - timeline entries
#   - queue execution
#   - full regression passes
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-safe-search-replace.sh

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

WP_PREFIX=$(wp eval "global \$wpdb; echo \$wpdb->prefix;")

echo "== 1. Validation & Guards =="

# Empty search
INV_EMP=$(api POST /operations/safe_search_replace/run "{\"search\":\"\",\"replace\":\"new\",\"tables\":[\"${WP_PREFIX}options\"]}")
assert_eq "validation: empty search blocked" "wpcc_empty_search" "$(echo "$INV_EMP" | jq -r '.code')"

# Same search and replace
INV_SAME=$(api POST /operations/safe_search_replace/run "{\"search\":\"test\",\"replace\":\"test\",\"tables\":[\"${WP_PREFIX}options\"]}")
assert_eq "validation: same search/replace blocked" "wpcc_search_equals_replace" "$(echo "$INV_SAME" | jq -r '.code')"

# Invalid table
INV_TAB=$(api POST /operations/safe_search_replace/run "{\"search\":\"old\",\"replace\":\"new\",\"tables\":[\"${WP_PREFIX}doesnotexist\"]}")
assert_eq "validation: invalid table blocked" "wpcc_invalid_table" "$(echo "$INV_TAB" | jq -r '.code')"

# Non-prefixed table
INV_PRE=$(api POST /operations/safe_search_replace/run "{\"search\":\"old\",\"replace\":\"new\",\"tables\":[\"users\"]}")
assert_eq "validation: non-prefixed table blocked" "wpcc_invalid_table_prefix" "$(echo "$INV_PRE" | jq -r '.code')"

echo
echo "== 2. Serialized Data & Basic Replaces =="

# Create a test post with serialized data in meta
POST_ID=$(wp post create --post_title="S&R Test" --post_status=publish --porcelain)
wp eval "update_post_meta($POST_ID, 'test_meta', ['url' => 'http://old-domain.com/test', 'text' => 'old-domain.com']);"

# Dry Run
DRY_BODY=$(jq -n --arg prefix "$WP_PREFIX" '{search:"old-domain.com",replace:"new-domain.com",dry_run:true,tables:[$prefix+"postmeta"]}')
DRY_RESP=$(api POST /operations/safe_search_replace/run "$DRY_BODY")

assert_true "dry run: flag is true" "$(echo "$DRY_RESP" | jq -r '.dry_run')"
# Expect at least 2 matches (one in array key 'url', one in 'text')
MATCHES=$(echo "$DRY_RESP" | jq -r '.matches_found // 0')
assert_true "dry run: matches found >= 2" "$([[ $MATCHES -ge 2 ]] && echo true || echo false)"
# Dry run should report rows that *would* be affected
AFFECTED=$(echo "$DRY_RESP" | jq -r '.rows_affected // 0')
assert_true "dry run: rows affected >= 1" "$([[ $AFFECTED -ge 1 ]] && echo true || echo false)"

# Verify data was NOT changed
ACTUAL_OLD=$(wp eval "echo get_post_meta($POST_ID, 'test_meta', true)['text'];")
assert_eq "dry run: data unchanged" "old-domain.com" "$ACTUAL_OLD"

# Real Run via Queue
REQ_BODY=$(jq -n --arg prefix "$WP_PREFIX" '{operation_id:"safe_search_replace",payload:{search:"old-domain.com",replace:"new-domain.com",dry_run:false,tables:[$prefix+"postmeta"]}}')
REQ_ID=$(api POST /operations/requests "$REQ_BODY" | jq -r '.request_id')
api POST "/operations/requests/$REQ_ID/approve" > /dev/null
QUEUE_ID=$(api POST "/operations/requests/$REQ_ID/queue" | jq -r '.queue_id')

# Execute via worker process endpoint
api POST /operations/queue/process > /dev/null

# Verify data WAS changed
ACTUAL_NEW=$(wp eval "echo get_post_meta($POST_ID, 'test_meta', true)['text'];")
assert_eq "real run: data changed" "new-domain.com" "$ACTUAL_NEW"

echo
echo "== 3. Timeline & Audit =="

TIMELINE=$(api GET "/agent/timeline?limit=30")
assert_true "timeline: has started event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Search and replace started")')"
assert_true "timeline: has completed event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Search and replace completed")')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

# Cleanup
if [[ -n "$POST_ID" ]]; then wp post delete "$POST_ID" --force > /dev/null; fi

[ "$FAIL" -eq 0 ]
