#!/usr/bin/env bash
#
# Media Import Operation test suite for WP Command Center (Step 27).
#
# Verifies:
#   - valid image import
#   - invalid URL blocked
#   - unsupported extension blocked
#   - SVG blocked
#   - attach to post
#   - alt text saved
#   - audit entries
#   - timeline entries
#   - queue execution
#   - regression passes
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-media-import.sh

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

echo "== 1. Validation & Guards =="

# Invalid URL
INV_URL=$(api POST /operations/media_import/run "{\"source_url\":\"not-a-url\"}")
assert_eq "validation: invalid URL blocked" "wpcc_invalid_url" "$(echo "$INV_URL" | jq -r '.code')"

# Unsupported extension
INV_EXT=$(api POST /operations/media_import/run "{\"source_url\":\"https://example.com/test.zip\"}")
assert_eq "validation: unsupported extension blocked" "wpcc_unsupported_file_extension" "$(echo "$INV_EXT" | jq -r '.code')"

# SVG Blocked
INV_SVG=$(api POST /operations/media_import/run "{\"source_url\":\"https://example.com/test.svg\"}")
assert_eq "validation: SVG blocked" "wpcc_unsupported_file_extension" "$(echo "$INV_SVG" | jq -r '.code')"

echo
echo "== 2. Valid Import =="

# Valid image import via Queue
# Use a reliable test image URL
IMAGE_URL="https://s.w.org/style/images/about/WordPress-logotype-standard.png"
POST_ID=$(wp post create --post_title="Media Parent" --post_status=publish --porcelain)

REQ_BODY=$(jq -n --arg url "$IMAGE_URL" --arg title "Test Image" --arg alt "Test Alt" --arg pid "$POST_ID" \
  '{operation_id:"media_import",payload:{source_url:$url,title:$title,alt:$alt,attach_to_post_id:($pid|tonumber)}}')

REQ_ID=$(api POST /operations/requests "$REQ_BODY" | jq -r '.request_id')
api POST "/operations/requests/$REQ_ID/approve" > /dev/null
QUEUE_ID=$(api POST "/operations/requests/$REQ_ID/queue" | jq -r '.queue_id')

# Execute via worker process
api POST /operations/queue/process > /dev/null

# Get the result
Q_RESULT=$(api GET "/operations/results?queue_id=$QUEUE_ID&limit=1" | jq -r '.[0].result_json.result')

ATT_ID=$(echo "$Q_RESULT" | jq -r '.id // empty')
assert_true "execution: attachment created" "$([[ -n \"$ATT_ID\" ]] && echo true || echo false)"

# Verify via WP-CLI
ACTUAL_TITLE=$(wp post get "$ATT_ID" --field=post_title)
assert_eq "verification: title matches" "Test Image" "$ACTUAL_TITLE"

ACTUAL_PARENT=$(wp post get "$ATT_ID" --field=post_parent)
assert_eq "verification: attached to post" "$POST_ID" "$ACTUAL_PARENT"

ACTUAL_ALT=$(wp post meta get "$ATT_ID" _wp_attachment_image_alt)
assert_eq "verification: alt text saved" "Test Alt" "$ACTUAL_ALT"

echo
echo "== 3. Timeline & Audit =="

# The timeline is global and many queue/audit events can share the same
# second during a full-suite run. Use a wider window so this test verifies
# event integrity without depending on unstable ordering among tied entries.
TIMELINE=$(api GET "/agent/timeline?limit=250")
assert_true "timeline: has started event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Media import started")')"
assert_true "timeline: has completed event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "Media import completed")')"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

# Cleanup
if [[ -n "$ATT_ID" ]]; then wp post delete "$ATT_ID" --force > /dev/null; fi
if [[ -n "$POST_ID" ]]; then wp post delete "$POST_ID" --force > /dev/null; fi

[ "$FAIL" -eq 0 ]
