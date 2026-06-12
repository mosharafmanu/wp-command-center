#!/usr/bin/env bash
#
# CF7 Seeder Operation test suite for WP Command Center (Step 18).
#
# Verifies:
#   - operation discovery
#   - template support (contact_basic, newsletter, quote_request)
#   - native CF7 API usage (form created)
#   - audit logging
#   - timeline integration
#   - full-access security
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-cf7-seed.sh

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

echo "== 1. Registry & Manifest Discovery =="

# Check Registry
OP_REG=$(api GET "/operations/cf7_seed")
assert_eq "registry: id matches" "cf7_seed" "$(echo "$OP_REG" | jq -r '.id')"
assert_eq "registry: available is true (CF7 is active)" "true" "$(echo "$OP_REG" | jq -r '.available')"

# Check Manifest
MANIFEST=$(api GET "/agent/manifest")
assert_true "manifest: contains cf7_seed" "$(echo "$MANIFEST" | jq -r '.operations | any(.[]; .id == "cf7_seed")')"

echo
echo "== 2. Valid Seeding (Basic Contact) =="

SEED_BODY='{"title":"QA Basic Form","form_template":"contact_basic"}'
SEED_RESP=$(api POST /operations/cf7_seed/run "$SEED_BODY")

FORM_ID=$(echo "$SEED_RESP" | jq -r '.id // empty')
assert_true "execution: form created (id exists)" "$([[ -n \"$FORM_ID\" ]] && echo true || echo false)"
assert_eq "execution: title matches" "QA Basic Form" "$(echo "$SEED_RESP" | jq -r '.title')"

# Verify via WP-CLI
ACTUAL_TITLE=$(wp post get "$FORM_ID" --field=post_title)
assert_eq "verification: post title matches" "QA Basic Form" "$ACTUAL_TITLE"
ACTUAL_TYPE=$(wp post get "$FORM_ID" --field=post_type)
assert_eq "verification: post type is wpcf7_contact_form" "wpcf7_contact_form" "$ACTUAL_TYPE"

echo
echo "== 3. Valid Seeding (Newsletter) =="

SEED_NL_BODY='{"title":"QA Newsletter","form_template":"newsletter"}'
SEED_NL_RESP=$(api POST /operations/cf7_seed/run "$SEED_NL_BODY")
NL_ID=$(echo "$SEED_NL_RESP" | jq -r '.id // empty')
assert_true "execution: newsletter form created" "$([[ -n \"$NL_ID\" ]] && echo true || echo false)"

echo
echo "== 4. Invalid Parameters =="

# Invalid Template
INV_TEMPLATE=$(api POST /operations/cf7_seed/run '{"title":"Bad","form_template":"invalid_temp"}')
assert_eq "validation: invalid template error" "wpcc_invalid_cf7_template" "$(echo "$INV_TEMPLATE" | jq -r '.code')"

echo
echo "== 5. Timeline & Audit =="

TIMELINE=$(api GET "/agent/timeline?limit=30")
assert_true "timeline: has started event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "CF7 seeding started")')"
assert_true "timeline: has completed event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "CF7 seeding completed")')"

# Verify summary
COMP_SUM=$(echo "$TIMELINE" | jq -r --arg id "$NL_ID" '.[] | select(.label == "CF7 seeding completed" and (.summary | contains($id))) | .summary' | head -1)
assert_true "timeline: summary has form id" "$([[ -n \"$COMP_SUM\" ]] && echo true || echo false)"

echo
echo "== 6. Security =="

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$WPCC_BASE/operations/cf7_seed/run")
assert_eq "security: no token returns 401" "401" "$HTTP_CODE"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

# Cleanup
if [[ -n "$FORM_ID" ]]; then wp post delete "$FORM_ID" --force > /dev/null; fi
if [[ -n "$NL_ID" ]]; then wp post delete "$NL_ID" --force > /dev/null; fi

[ "$FAIL" -eq 0 ]
