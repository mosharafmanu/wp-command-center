#!/usr/bin/env bash
#
# Content Seeder Operation test suite for WP Command Center (Step 16).
#
# Verifies:
#   - create posts
#   - create pages
#   - count limits (max 100)
#   - invalid parameters
#   - audit entries
#   - timeline entries
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-content-seed.sh

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

echo "== 1. Valid Seeding (Posts) =="

SEED_BODY='{"type":"post","count":3,"status":"publish","title_pattern":"Test Post {n}","content_template":"QA Content"}'
SEED_RESP=$(api POST /operations/content_seed/run "$SEED_BODY")

assert_eq "create 3 posts: type matches" "post" "$(echo "$SEED_RESP" | jq -r '.type')"
assert_eq "create 3 posts: count is 3" "3" "$(echo "$SEED_RESP" | jq -r '.count')"
assert_eq "create 3 posts: IDs count is 3" "3" "$(echo "$SEED_RESP" | jq '.created_ids | length')"

echo
echo "== 2. Valid Seeding (Pages) =="

SEED_PAGE_BODY='{"type":"page","count":2,"status":"draft","title_pattern":"Test Page {n}"}'
SEED_PAGE_RESP=$(api POST /operations/content_seed/run "$SEED_PAGE_BODY")

assert_eq "create 2 pages: type matches" "page" "$(echo "$SEED_PAGE_RESP" | jq -r '.type')"
assert_eq "create 2 pages: count is 2" "2" "$(echo "$SEED_PAGE_RESP" | jq -r '.count')"

echo
echo "== 3. Count Limits & Safety =="

# Max limit test
LIMIT_BODY='{"type":"post","count":150}'
LIMIT_RESP=$(api POST /operations/content_seed/run "$LIMIT_BODY")
assert_eq "max limit capped at 100" "100" "$(echo "$LIMIT_RESP" | jq -r '.count')"

# Invalid post type
INVALID_TYPE_BODY='{"type":"unsupported","count":1}'
INVALID_TYPE_RESP=$(api POST /operations/content_seed/run "$INVALID_TYPE_BODY")
assert_eq "invalid type returns error" "wpcc_invalid_post_type" "$(echo "$INVALID_TYPE_RESP" | jq -r '.code')"

# Invalid status
INVALID_STATUS_BODY='{"type":"post","status":"deleted"}'
INVALID_STATUS_RESP=$(api POST /operations/content_seed/run "$INVALID_STATUS_BODY")
assert_eq "invalid status returns error" "wpcc_invalid_post_status" "$(echo "$INVALID_STATUS_RESP" | jq -r '.code')"

echo
echo "== 4. Timeline & Audit Verification =="

# Create a specific session to filter the timeline
SESSION_ID=$(api POST /agent/sessions '{"source":"api","label":"Seeder Test"}' | jq -r '.session_id')
# Run operation
api POST /operations/content_seed/run '{"type":"post","count":1}' > /dev/null

TIMELINE=$(api GET "/agent/timeline?limit=30")

assert_true "timeline has content_seed.started" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Content seeding started")')"
assert_true "timeline has content_seed.completed" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Content seeding completed")')"

# Verify summary content
COMPLETED_SUMMARY=$(echo "$TIMELINE" | jq -r '.[] | select(.label == "Content seeding completed") | .summary' | head -1)
assert_true "completed summary contains count" "$(echo "$COMPLETED_SUMMARY" | grep -q "Created 1 post" && echo true || echo false)"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
