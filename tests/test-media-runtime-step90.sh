#!/usr/bin/env bash
#
# STEP 90 — Media Runtime acceptance suite.
#
# Verifies the complete media workflow over REST + MCP, including the new
# media_update (title/alt/caption/description) with rollback, the spec-named
# media_set_featured / media_remove_featured aliases (backward-compatible with
# featured_image_assign/remove), description metadata, structured error codes,
# and rollback_id exposure on every write.
#
# Workflow: create draft → upload image → set featured → verify → replace →
# remove featured → delete → verify deletion. Plus update + rollback.
#
# Requires: curl, jq, wp, wpcc-env.sh. Image upload is network-tolerant.
# Usage: bash tests/test-media-runtime-step90.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }

rest() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/media_manage/run"; }
mcp_text() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

# Fixtures: an attachment + a draft post (no network needed).
MID=$(wpe '$id=wp_insert_attachment(["post_title"=>"S90 Orig","post_mime_type"=>"image/png","post_status"=>"inherit","post_excerpt"=>"orig cap","post_content"=>"orig desc"],false,0);update_post_meta($id,"_wp_attachment_image_alt","orig alt");echo $id;')
POST_ID=$(wpe 'echo wp_insert_post(["post_title"=>"S90 Draft","post_status"=>"draft","post_type"=>"post"]);')
cleanup() { wpe 'wp_delete_attachment('"$MID"',true); wp_delete_post('"$POST_ID"',true);' >/dev/null 2>&1; }
trap cleanup EXIT

assert_nonempty "fixture: attachment created" "$MID"
assert_nonempty "fixture: draft post created" "$POST_ID"

echo "== 1. media_get returns metadata incl. description (REST) =="
R=$(rest "$(jq -n --argjson id "$MID" '{action:"media_get",media_id:$id}')")
assert_eq "media_get: title" "S90 Orig" "$(echo "$R" | jq -r '.media.title')"
assert_eq "media_get: description present" "orig desc" "$(echo "$R" | jq -r '.media.description')"

echo "== 2. media_get works over MCP too (parity) =="
R=$(mcp_text "$(jq -n --argjson id "$MID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_manage",arguments:{action:"media_get",media_id:$id}}}')")
assert_eq "MCP media_get: title" "S90 Orig" "$(echo "$R" | jq -r '.media.title')"

echo "== 3. media_update sets all four metadata fields + returns rollback_id =="
R=$(rest "$(jq -n --argjson id "$MID" '{action:"media_update",media_id:$id,title:"S90 New",alt:"new alt",caption:"new cap",description:"new desc"}')")
assert_eq "update: title"       "S90 New"  "$(echo "$R" | jq -r '.media.title')"
assert_eq "update: alt"         "new alt"  "$(echo "$R" | jq -r '.media.alt')"
assert_eq "update: caption"     "new cap"  "$(echo "$R" | jq -r '.media.caption')"
assert_eq "update: description" "new desc" "$(echo "$R" | jq -r '.media.description')"
RID=$(echo "$R" | jq -r '.rollback_id')
assert_nonempty "update: rollback_id returned" "$RID"

echo "== 4. media_restore rolls back all fields =="
rest "$(jq -n --arg rid "$RID" '{action:"media_restore",rollback_id:$rid}')" >/dev/null
R=$(rest "$(jq -n --argjson id "$MID" '{action:"media_get",media_id:$id}')")
assert_eq "rollback: title restored"       "S90 Orig"  "$(echo "$R" | jq -r '.media.title')"
assert_eq "rollback: alt restored"         "orig alt"  "$(echo "$R" | jq -r '.media.alt')"
assert_eq "rollback: description restored" "orig desc" "$(echo "$R" | jq -r '.media.description')"

echo "== 5. media_update structured errors =="
assert_eq "no fields → wpcc_media_no_fields" "wpcc_media_no_fields" "$(rest "$(jq -n --argjson id "$MID" '{action:"media_update",media_id:$id}')" | jq -r '.code // "none"')"
assert_eq "missing media → wpcc_media_not_found" "wpcc_media_not_found" "$(rest '{"action":"media_update","media_id":99999999,"title":"x"}' | jq -r '.code // "none"')"

echo "== 6. Full image workflow: upload → set featured → verify → replace → remove → delete → verify deletion =="
# set_post_thumbnail only applies when the attachment is a REAL image, and WP
# does not trash media without MEDIA_TRASH, so the featured/replace/delete
# workflow runs against an actually-uploaded image (network-tolerant).
UP=$(rest '{"action":"media_upload","source_url":"https://s.w.org/style/images/about/WordPress-logotype-standard.png","title":"S90 Upload","alt":"wp logo","description":"uploaded desc"}')
IMG=$(echo "$UP" | jq -r '.media_id // empty')
if [ -n "$IMG" ] && [ "$IMG" != "null" ]; then
  pass "upload: media_id returned"
  assert_nonempty "upload: rollback_id" "$(echo "$UP" | jq -r '.rollback_id')"
  assert_eq "upload: description stored" "uploaded desc" "$(rest "$(jq -n --argjson id "$IMG" '{action:"media_get",media_id:$id}')" | jq -r '.media.description')"

  # set featured (spec alias) + verify
  R=$(rest "$(jq -n --argjson m "$IMG" --argjson p "$POST_ID" '{action:"media_set_featured",media_id:$m,post_id:$p}')")
  assert_nonempty "set_featured: rollback_id" "$(echo "$R" | jq -r '.rollback_id')"
  assert_eq "set_featured: thumbnail applied" "$IMG" "$(wpe 'echo get_post_thumbnail_id('"$POST_ID"');')"

  # replace
  REP=$(rest "$(jq -n --argjson id "$IMG" '{action:"media_replace",media_id:$id,source_url:"https://s.w.org/style/images/about/WordPress-logotype-standard.png"}')")
  assert_eq "replace: action" "media_replace" "$(echo "$REP" | jq -r '.action // .code')"

  # remove featured (spec alias) + verify cleared
  rest "$(jq -n --argjson p "$POST_ID" '{action:"media_remove_featured",post_id:$p}')" >/dev/null
  assert_eq "remove_featured: thumbnail cleared" "0" "$(wpe 'echo (int) get_post_thumbnail_id('"$POST_ID"');')"

  # backward compat: legacy featured_image_assign still works
  rest "$(jq -n --argjson m "$IMG" --argjson p "$POST_ID" '{action:"featured_image_assign",media_id:$m,post_id:$p}')" >/dev/null
  assert_eq "legacy featured_image_assign" "$IMG" "$(wpe 'echo get_post_thumbnail_id('"$POST_ID"');')"
  rest "$(jq -n --argjson p "$POST_ID" '{action:"featured_image_remove",post_id:$p}')" >/dev/null

  # delete (WP media is permanent without MEDIA_TRASH) → verify deletion
  rest "$(jq -n --argjson id "$IMG" '{action:"media_delete",media_id:$id,force:true,confirm:true,confirmation_phrase:"DELETE_MEDIA",reason:"step90 acceptance"}')" >/dev/null
  assert_eq "delete → media_get not found" "wpcc_media_not_found" "$(rest "$(jq -n --argjson id "$IMG" '{action:"media_get",media_id:$id}')" | jq -r '.code // "none"')"
else
  echo "  SKIP: image workflow (media_upload network unavailable: $(echo "$UP" | jq -r '.code // .message // "?"'))"
fi

echo "== 7. media_delete (force) on the fixture → verify deletion =="
rest "$(jq -n --argjson id "$MID" '{action:"media_delete",media_id:$id,force:true,confirm:true,confirmation_phrase:"DELETE_MEDIA",reason:"step90 acceptance"}')" >/dev/null
assert_eq "force delete: media_get → not found" "wpcc_media_not_found" "$(rest "$(jq -n --argjson id "$MID" '{action:"media_get",media_id:$id}')" | jq -r '.code // "none"')"

echo
echo "================================================"
echo "  Media Runtime (STEP 90): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
