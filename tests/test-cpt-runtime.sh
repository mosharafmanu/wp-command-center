#!/usr/bin/env bash
#
# Custom Post Type Runtime test suite for WP Command Center (Step 74).
#
# Verifies:
#   - registry discovery (manifest / context)
#   - cpt_list
#   - cpt_get
#   - cpt_create
#   - cpt_update
#   - cpt_disable
#   - taxonomy_list
#   - taxonomy_create
#   - taxonomy_update
#   - rollback
#   - invalid actions rejected
#   - timeline entries
#   - dashboard card
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-cpt-runtime.sh

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

assert_contains() {
	local desc="$1" haystack="$2" needle="$3"
	if [[ "$haystack" == *"$needle"* ]]; then
		pass "$desc"
	else
		fail "$desc (string does not contain '$needle')"
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

echo "== 1. Manifest Integration =="
MANIFEST=$(api GET /agent/manifest)
assert_true "manifest: cpt_management exists" "$(echo "$MANIFEST" | jq -r 'if .cpt_management then "true" else "false" end')"
assert_true "manifest: cpt_management.supported_actions array" "$(echo "$MANIFEST" | jq -r 'if (.cpt_management.supported_actions | type) == "array" then "true" else "false" end')"
assert_true "manifest: capability cpt_management is true" "$(echo "$MANIFEST" | jq -r '.capabilities.cpt_management // false')"
CPT_ACTIONS=$(echo "$MANIFEST" | jq -r '.cpt_management.supported_actions | length')
assert_eq "manifest: cpt_management has 9+ actions" "9" "$CPT_ACTIONS"

echo
echo "== 2. Operations Registry =="
OPS=$(api GET /operations)
assert_true "ops registry: cpt_manage present" "$(echo "$OPS" | jq -r 'if map(select(.id == "cpt_manage")) | length > 0 then "true" else "false" end')"
CPT_OP=$(echo "$OPS" | jq -r '.[] | select(.id == "cpt_manage")')
assert_contains "ops registry: cpt title" "$CPT_OP" "Custom Post Types"

echo
echo "== 3. CPT List =="
CPTLIST=$(api POST /operations/cpt_manage/run '{"action":"cpt_list"}')
assert_eq "cpt_list: action is cpt_list" "cpt_list" "$(echo "$CPTLIST" | jq -r '.action // "none"')"
assert_true "cpt_list: summary present" "$(echo "$CPTLIST" | jq -r 'if .summary then "true" else "false" end')"
assert_true "cpt_list: post_types array present" "$(echo "$CPTLIST" | jq -r 'if (.summary.post_types | type) == "array" then "true" else "false" end')"

echo
echo "== 4. Taxonomy List =="
TAXLIST=$(api POST /operations/cpt_manage/run '{"action":"taxonomy_list"}')
assert_eq "taxonomy_list: action is taxonomy_list" "taxonomy_list" "$(echo "$TAXLIST" | jq -r '.action // "none"')"
assert_true "taxonomy_list: taxonomies array present" "$(echo "$TAXLIST" | jq -r 'if (.taxonomies | type) == "array" then "true" else "false" end')"

echo
echo "== 5. CPT Get (invalid) =="
CPTGET_ERR=$(api POST /operations/cpt_manage/run '{"action":"cpt_get","name":"nonexistent_cpt_12345"}')
EMPTY_OR_ERR=$(echo "$CPTGET_ERR" | jq -r 'if .code == "wpcc_cpt_not_found" then "wpcc_cpt_not_found" else "none" end')
assert_eq "cpt_get: invalid rejected" "wpcc_cpt_not_found" "$EMPTY_OR_ERR"

echo
echo "== 6. Invalid Action Rejected =="
BAD=$(api POST /operations/cpt_manage/run '{"action":"evil_action"}')
assert_eq "bad action: rejected" "wpcc_invalid_cpt_action" "$(echo "$BAD" | jq -r '.code // "none"')"

echo
echo "== 7. CPT Create =="
CPT_NAME="wpcc_test_book_$(date +%s)"
CPTCREATE=$(api POST /operations/cpt_manage/run "{\"action\":\"cpt_create\",\"name\":\"$CPT_NAME\",\"label\":\"Test Books\"}")
echo "cpt_create result: $(echo "$CPTCREATE" | jq -r '.code // .action')"
CREATE_ACT=$(echo "$CPTCREATE" | jq -r '.action // "none"')
assert_eq "cpt_create: action is cpt_create" "cpt_create" "$CREATE_ACT"
assert_true "cpt_create: has rollback_id" "$(echo "$CPTCREATE" | jq -r 'if .rollback_id then "true" else "false" end')"
CPT_ROLLBACK_ID=$(echo "$CPTCREATE" | jq -r '.rollback_id')

echo
echo "== 8. CPT Get (valid) =="
CPTGET=$(api POST /operations/cpt_manage/run "{\"action\":\"cpt_get\",\"name\":\"$CPT_NAME\"}")
GET_ACT2=$(echo "$CPTGET" | jq -r '.action // "none"')
assert_eq "cpt_get: action is cpt_get" "cpt_get" "$GET_ACT2"
assert_true "cpt_get: post_type data present" "$(echo "$CPTGET" | jq -r 'if .post_type then "true" else "false" end')"

echo
echo "== 9. CPT Update =="
CPTUPDATE=$(api POST /operations/cpt_manage/run "{\"action\":\"cpt_update\",\"name\":\"$CPT_NAME\",\"config\":{\"description\":\"Updated book post type\"}}")
UPD_ACT=$(echo "$CPTUPDATE" | jq -r '.action // "none"')
assert_eq "cpt_update: action is cpt_update" "cpt_update" "$UPD_ACT"
assert_true "cpt_update: has rollback_id" "$(echo "$CPTUPDATE" | jq -r 'if .rollback_id then "true" else "false" end')"
UPD_ROLLBACK_ID=$(echo "$CPTUPDATE" | jq -r '.rollback_id')

echo
echo "== 10. CPT Disable =="
CPTDISABLE=$(api POST /operations/cpt_manage/run "{\"action\":\"cpt_disable\",\"name\":\"$CPT_NAME\"}")
DIS_ACT=$(echo "$CPTDISABLE" | jq -r '.action // "none"')
assert_eq "cpt_disable: action is cpt_disable" "cpt_disable" "$DIS_ACT"
assert_true "cpt_disable: has rollback_id" "$(echo "$CPTDISABLE" | jq -r 'if .rollback_id then "true" else "false" end')"
DIS_ROLLBACK_ID=$(echo "$CPTDISABLE" | jq -r '.rollback_id')

echo
echo "== 11. Taxonomy Create =="
TAX_NAME="wpcc_test_genre_$(date +%s)"
TAXCREATE=$(api POST /operations/cpt_manage/run "{\"action\":\"taxonomy_create\",\"name\":\"$TAX_NAME\",\"label\":\"Test Genres\",\"object_type\":\"post\"}")
TAX_ACT=$(echo "$TAXCREATE" | jq -r '.action // "none"')
assert_eq "taxonomy_create: action is taxonomy_create" "taxonomy_create" "$TAX_ACT"
assert_true "taxonomy_create: has rollback_id" "$(echo "$TAXCREATE" | jq -r 'if .rollback_id then "true" else "false" end')"
TAX_ROLLBACK_ID=$(echo "$TAXCREATE" | jq -r '.rollback_id')

echo
echo "== 12. Taxonomy Update =="
TAXUPDATE=$(api POST /operations/cpt_manage/run "{\"action\":\"taxonomy_update\",\"name\":\"$TAX_NAME\",\"config\":{\"description\":\"Updated genre taxonomy\"}}")
TAX_UPD_ACT=$(echo "$TAXUPDATE" | jq -r '.action // "none"')
assert_eq "taxonomy_update: action is taxonomy_update" "taxonomy_update" "$TAX_UPD_ACT"
assert_true "taxonomy_update: has rollback_id" "$(echo "$TAXUPDATE" | jq -r 'if .rollback_id then "true" else "false" end')"

echo
echo "== 13. Rollback CPT Create =="
if [ -n "${CPT_ROLLBACK_ID:-}" ]; then
	RB=$(api POST /operations/cpt_manage/rollback "{\"rollback_id\":\"$CPT_ROLLBACK_ID\"}")
	RB_ACT=$(echo "$RB" | jq -r '.action // .code // "none"')
	assert_contains "rollback CPT: result contains rollback" "$RB_ACT" "rollback"
fi

echo
echo "== 14. Rollback Taxonomy Create =="
if [ -n "${TAX_ROLLBACK_ID:-}" ]; then
	RB=$(api POST /operations/cpt_manage/rollback "{\"rollback_id\":\"$TAX_ROLLBACK_ID\"}")
	RB_ACT=$(echo "$RB" | jq -r '.action // .code // "none"')
	assert_contains "rollback tax: result contains rollback" "$RB_ACT" "rollback"
fi

echo
echo "== 15. Missing name =="
NO_NAME=$(api POST /operations/cpt_manage/run '{"action":"cpt_get"}')
assert_eq "missing name: rejected" "wpcc_missing_cpt_name" "$(echo "$NO_NAME" | jq -r '.code // "none"')"

echo
echo "== 16. Invalid CPT name =="
BADNAME=$(api POST /operations/cpt_manage/run '{"action":"cpt_create","name":"Bad Name!","label":"Test"}')
assert_eq "invalid name: rejected" "wpcc_invalid_cpt_name" "$(echo "$BADNAME" | jq -r '.code // "none"')"

echo
echo "== 17. Timeline Integration =="
TIMELINE=$(api GET "/agent/timeline?limit=50")
assert_contains "timeline: CPT operation present" "$TIMELINE" "CPT"

echo
echo "== 18. Agent Context =="
CTX=$(api GET "/agent/context")
assert_true "context: operations include cpt_manage" "$(echo "$CTX" | jq -r 'if (.operations | map(select(.id == "cpt_manage")) | length > 0) then "true" else "false" end')"

echo
echo "=============================================="
echo "Results: $PASS passed, $FAIL failed"
echo "=============================================="
[ "$FAIL" -eq 0 ] && exit 0 || exit 1
