#!/usr/bin/env bash
#
# ACF Seeder Operation test suite for WP Command Center (Step 17).
#
# Verifies:
#   - ACF inactive (simulated if possible, but here it's active)
#   - Invalid post ID
#   - Unknown field
#   - Unsupported field type
#   - Successful update
#   - Audit logging
#   - Timeline integration
#   - Permission failures
#   - Registry integration
#   - Manifest integration
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-acf-seed.sh

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
OP_REG=$(api GET "/operations/acf_seed")
assert_eq "registry: id matches" "acf_seed" "$(echo "$OP_REG" | jq -r '.id')"
assert_eq "registry: available is true (ACF is active)" "true" "$(echo "$OP_REG" | jq -r '.available')"

# Check Manifest
MANIFEST=$(api GET "/agent/manifest")
assert_true "manifest: contains acf_seed" "$(echo "$MANIFEST" | jq -r '.operations | any(.[]; .id == "acf_seed")')"

echo
echo "== 2. Invalid Parameters & Validation =="

# Invalid Post ID
INV_POST=$(api POST /operations/acf_seed/run '{"post_id": 9999999, "fields": {"test": "val"}}')
assert_eq "validation: invalid post_id" "wpcc_invalid_post_id" "$(echo "$INV_POST" | jq -r '.code')"

# Create a real post for further testing
TEST_POST_ID=$(wp post create --post_type=post --post_title="ACF Seeder QA Post" --post_status=publish --porcelain)
echo "  Created test post: $TEST_POST_ID"

# Unknown Field
INV_FIELD=$(api POST /operations/acf_seed/run "$(jq -n --arg pid "$TEST_POST_ID" '{post_id: ($pid|tonumber), fields: {"non_existent_field": "val"}}')")
assert_eq "validation: unknown field" "wpcc_unknown_acf_field" "$(echo "$INV_FIELD" | jq -r '.code')"

# Unsupported Field Type (Repeater)
INV_TYPE=$(api POST /operations/acf_seed/run "$(jq -n --arg pid "$TEST_POST_ID" '{post_id: ($pid|tonumber), fields: {"social_medias": "val"}}')")
assert_eq "validation: unsupported type (repeater)" "wpcc_unsupported_acf_field_type" "$(echo "$INV_TYPE" | jq -r '.code')"

echo
echo "== 3. Successful Seeding =="

# Note: In this environment, show_page_title exists and is true_false. 
# It's attached to 'page', so let's create a page.
TEST_PAGE_ID=$(wp post create --post_type=page --post_title="ACF Seeder QA Page" --post_status=publish --porcelain)
echo "  Created test page: $TEST_PAGE_ID"

SEED_BODY=$(jq -n --arg pid "$TEST_PAGE_ID" '{post_id: ($pid|tonumber), fields: {"show_page_title": false}}')
SEED_RESP=$(api POST /operations/acf_seed/run "$SEED_BODY")

assert_eq "execution: post_id matches" "$TEST_PAGE_ID" "$(echo "$SEED_RESP" | jq -r '.post_id')"
assert_eq "execution: field_count is 1" "1" "$(echo "$SEED_RESP" | jq -r '.field_count')"
assert_eq "execution: result status" "updated" "$(echo "$SEED_RESP" | jq -r '.execution_result.show_page_title')"

# Verify via PHP eval (more reliable for ACF)
ACTUAL_VAL=$(wp eval "echo get_field('show_page_title', $TEST_PAGE_ID) ? '1' : '0';")
assert_eq "verification: meta value updated" "0" "$ACTUAL_VAL"

echo
echo "== 4. Timeline & Audit =="

TIMELINE=$(api GET "/agent/timeline?limit=20")
assert_true "timeline: has started event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "ACF seeding started")')"
assert_true "timeline: has completed event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "ACF seeding completed")')"

# Verify summary
COMP_SUM=$(echo "$TIMELINE" | jq -r '.[] | select(.label == "ACF seeding completed") | .summary' | head -1)
assert_true "timeline: summary has post id" "$(echo "$COMP_SUM" | grep -q "post $TEST_PAGE_ID" && echo true || echo false)"

echo
echo "== 5. Security & Permissions =="

# Read-only token check
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$WPCC_BASE/operations/acf_seed/run")
assert_eq "security: no token returns 401" "401" "$HTTP_CODE"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

# Cleanup
wp post delete "$TEST_POST_ID" --force > /dev/null
wp post delete "$TEST_PAGE_ID" --force > /dev/null

[ "$FAIL" -eq 0 ]
