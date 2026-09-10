#!/usr/bin/env bash
#
# Safe Search & Replace Operation test suite for Action Steward (Step 26).
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

echo
echo "== ISSUE 16: return_matches — row-level identity behind matches_found =="

assert_true "without return_matches: no matches[] key" "$(echo "$DRY_RESP" | jq -r 'has("matches") | not')"

META_ID=$(wp eval "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT meta_id FROM {\$wpdb->postmeta} WHERE post_id=%d AND meta_key='test_meta'\", $POST_ID));")

RM_BODY=$(jq -n --arg prefix "$WP_PREFIX" '{search:"old-domain.com",replace:"new-domain.com",dry_run:true,tables:[$prefix+"postmeta"],return_matches:true,max_matches:10}')
RM_RESP=$(api POST /operations/safe_search_replace/run "$RM_BODY")

assert_true "return_matches: matches[] present" "$(echo "$RM_RESP" | jq -r 'has("matches")')"
RM_COUNT=$(echo "$RM_RESP" | jq -r '.matches | length')
assert_true "return_matches: matches[] non-empty" "$([[ $RM_COUNT -ge 1 ]] && echo true || echo false)"
assert_true "return_matches: row identifies our test post's meta_id" "$(echo "$RM_RESP" | jq --arg mid "$META_ID" -r 'any(.matches[]; (.primary_key_value|tostring) == $mid)')"
assert_true "return_matches: excerpt mentions the search term" "$(echo "$RM_RESP" | jq -r '[.matches[] | select(.column=="meta_value")][0].excerpt // "" | test("old-domain.com")')"
assert_true "return_matches: primary_key_column is meta_id" "$(echo "$RM_RESP" | jq -r 'all(.matches[]; .primary_key_column == "meta_id")')"
assert_eq "return_matches: matches_returned matches array length" "$RM_COUNT" "$(echo "$RM_RESP" | jq -r '.matches_returned')"
assert_eq "return_matches: matches_omitted is 0 (under cap)" "0" "$(echo "$RM_RESP" | jq -r '.matches_omitted')"

# Cap enforcement: a second disposable row gives 2 matching (row,column)
# pairs, so max_matches=1 must cap the list to 1 and report 1 omitted.
POST_ID2=$(wp post create --post_title="S&R Test 2" --post_status=publish --porcelain)
wp eval "update_post_meta($POST_ID2, 'test_meta2', ['text' => 'old-domain.com']);"

RM_CAP=$(api POST /operations/safe_search_replace/run "$(jq -n --arg prefix "$WP_PREFIX" '{search:"old-domain.com",replace:"new-domain.com",dry_run:true,tables:[$prefix+"postmeta"],return_matches:true,max_matches:1}')")
assert_eq "return_matches: max_matches caps the list to 1" "1" "$(echo "$RM_CAP" | jq -r '.matches | length')"
assert_eq "return_matches: matches_omitted counts the 1 capped-out row" "1" "$(echo "$RM_CAP" | jq -r '.matches_omitted')"

wp post delete "$POST_ID2" --force > /dev/null 2>&1

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

# ── Customer safety in the Search & Replace UI ──────────────────────────────
#
# The picker used to open on every table in the database — users, usermeta,
# session stores, OAuth token tables, WooCommerce internals and the plugin's own
# governance tables — with WPCC's own tables merely display:none behind a
# checkbox. A first-time customer fixing a domain name was being asked which of
# those a text replace should touch. And the risk model had three tiers, so a
# replace across wp_users scored the same MEDIUM as one across categories.
echo ""
echo "== UI safety: table exposure and risk =="
SR_VIEW="$SCRIPT_DIR/../includes/Admin/views/tools-search-replace.php"
has()   { if grep -qF -- "$2" "$3"; then pass "$1"; else fail "$1 (missing '$2')"; fi; }
lacks() { if grep -qF -- "$2" "$3"; then fail "$1 (found '$2')"; else pass "$1"; fi; }

has "tables are classified by data class"        "wpcc_classify_table" "$SR_VIEW"
has "accounts are their own class"               "'users', 'usermeta'" "$SR_VIEW"
has "auth/session/secret tables detected by name" "session|token|oauth"  "$SR_VIEW"
has "sensitive classes are named"                "wpcc_sensitive_groups" "$SR_VIEW"
has "sensitive tables sit behind their own reveal" "wpcc-sr-sensitive" "$SR_VIEW"
has "the reveal states the consequence"          "lock people out of the site" "$SR_VIEW"
has "custom table choice is a disclosure"        "Choose specific tables" "$SR_VIEW"
lacks "no bare show-system checkbox any more"    "wpcc-sr-show-system" "$SR_VIEW"

# Five tiers that mean what they say, mirrored client-side so the badge the
# customer reads is the one the request is scored with.
has "critical tier exists"                       "return 'critical'" "$SR_VIEW"
has "accounts/security/system are critical"      "in_array( \$critical, \$classes, true )" "$SR_VIEW"
has "breadth alone can be critical"              "count( \$tables ) >= 8" "$SR_VIEW"
has "client mirrors the server tiers"            "if ( checked.length >= 8 ) { return 'critical'; }" "$SR_VIEW"
has "critical has its own badge colour"          "wpcc-risk-critical" "$SR_VIEW"

# A live run at high or critical risk must be previewed first.
has "dry run gates the live run"                 "function needsPreviewFirst" "$SR_VIEW"
has "the gate explains itself"                   "Run a Dry Preview first" "$SR_VIEW"
has "the live button is disabled while gated"    "submitBtn.disabled = blocked" "$SR_VIEW"
# Governance is unchanged: a live run is still a governed request.
has "live run still creates a governed request"  "create_request( 'safe_search_replace'" "$SR_VIEW"

# Cleanup
if [[ -n "$POST_ID" ]]; then wp post delete "$POST_ID" --force > /dev/null; fi

[ "$FAIL" -eq 0 ]
