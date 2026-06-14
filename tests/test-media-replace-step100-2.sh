#!/usr/bin/env bash
#
# STEP 100.2 — media_replace rollback fix + media_replace_verify acceptance suite.
#
# Fixes two bugs found in the original media_replace: (1) it created a new orphan
# attachment instead of replacing in place; (2) its rollback was a silent no-op.
# Now media_replace swaps bytes in place (same attachment ID + URL) after taking a
# MediaSnapshot, and rollback restores the original bytes + sizes + metadata
# byte-for-byte. Verified over REST and MCP.
#
# Requires: curl, jq, wp, wpcc-env.sh, GD.
# Usage: bash tests/test-media-replace-step100-2.sh

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
ms() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/media_manage/run"; }
msmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
msrb() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/media_manage/rollback"; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

A=""; B=""
cleanup() {
  wpe 'foreach(get_option("wpcc_media_file_snapshots",[]) as $r){ (new \WPCommandCenter\Operations\MediaSnapshot())->delete($r["id"]); }'
  [ -n "$A" ] && wpe 'wp_delete_attachment('"$A"',true);'
  [ -n "$B" ] && wpe 'wp_delete_attachment('"$B"',true);'
  wpe '$u=wp_upload_dir(); @unlink($u["basedir"]."/s1002-bad.txt");'
}
trap cleanup EXIT

echo "== 0. Seed original (A) + replacement source (B) =="
A=$(wpe '
require_once ABSPATH."wp-admin/includes/image.php"; $u=wp_upload_dir(); $f=$u["basedir"]."/s1002-A.jpg";
$im=imagecreatetruecolor(640,480); imagefill($im,0,0,imagecolorallocate($im,20,80,200)); imagejpeg($im,$f,92); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"s1002 A","post_status"=>"inherit"],$f); wp_update_attachment_metadata($a,wp_generate_attachment_metadata($a,$f)); echo $a;')
B=$(wpe '
require_once ABSPATH."wp-admin/includes/image.php"; $u=wp_upload_dir(); $f=$u["basedir"]."/s1002-B.jpg";
$im=imagecreatetruecolor(300,300); imagefill($im,0,0,imagecolorallocate($im,220,40,40)); imagejpeg($im,$f,92); imagedestroy($im);
$b=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"s1002 B","post_status"=>"inherit"],$f); wp_update_attachment_metadata($b,wp_generate_attachment_metadata($b,$f)); echo $b;')
assert_nonempty "seeded A" "$A"; assert_nonempty "seeded B" "$B"
A_HASH=$(wpe 'echo md5_file(get_attached_file('"$A"'));')
A_URL=$(wpe 'echo wp_get_attachment_url('"$A"');')
B_URL=$(wpe 'echo wp_get_attachment_url('"$B"');')
B_HASH=$(wpe 'echo md5_file(get_attached_file('"$B"'));')
ATT_BEFORE=$(wpe '$q=new WP_Query(["post_type"=>"attachment","post_status"=>"inherit","posts_per_page"=>-1,"fields"=>"ids"]); echo $q->found_posts;')

echo "== 1. media_replace (REST) — in place, snapshot-backed =="
R=$(ms "$(jq -n --argjson a "$A" --arg u "$B_URL" '{action:"media_replace",media_id:$a,source_url:$u}')")
RID=$(echo "$R" | jq -r '.rollback_id')
assert_nonempty "replace returned rollback_id" "$RID"
assert_eq "URL preserved (same attachment)" "$A_URL" "$(echo "$R" | jq -r '.url')"

echo "== 2. No orphan attachment created (the original-bug guard) =="
ATT_AFTER=$(wpe '$q=new WP_Query(["post_type"=>"attachment","post_status"=>"inherit","posts_per_page"=>-1,"fields"=>"ids"]); echo $q->found_posts;')
assert_eq "attachment count unchanged" "$ATT_BEFORE" "$ATT_AFTER"

echo "== 3. media_replace_verify (REST) — file is now B =="
V=$(ms "$(jq -n --argjson a "$A" '{action:"media_replace_verify",media_id:$a}')")
assert_eq "live file now matches B" "$B_HASH" "$(echo "$V" | jq -r '.hash')"
assert_eq "live file differs from original A" "true" "$(echo "$V" | jq -r '.hash != "'"$A_HASH"'"')"
assert_eq "file exists" "true" "$(echo "$V" | jq -r '.file_exists')"

echo "== 4. rollback (REST) — byte-for-byte restore =="
RB=$(msrb "$(jq -n --arg r "$RID" '{rollback_id:$r}')")
assert_eq "rollback media_id" "$A" "$(echo "$RB" | jq -r '.media_id')"
V2=$(ms "$(jq -n --argjson a "$A" '{action:"media_replace_verify",media_id:$a}')")
assert_eq "ORIGINAL restored byte-for-byte" "$A_HASH" "$(echo "$V2" | jq -r '.hash')"
assert_eq "URL still preserved after rollback" "$A_URL" "$(echo "$V2" | jq -r '.url')"
assert_eq "double rollback rejected" "wpcc_rollback_already_applied" "$(msrb "$(jq -n --arg r "$RID" '{rollback_id:$r}')" | jq -r '.code // .data.code // "none"')"

echo "== 5. MCP parity — replace + verify + rollback over MCP =="
RM=$(msmcp "$(jq -n --argjson a "$A" --arg u "$B_URL" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_manage",arguments:{action:"media_replace",media_id:$a,source_url:$u}}}')")
RID2=$(echo "$RM" | jq -r '.rollback_id')
assert_nonempty "MCP replace rollback_id" "$RID2"
assert_eq "MCP verify shows B" "$B_HASH" "$(msmcp "$(jq -n --argjson a "$A" '{jsonrpc:"2.0",id:2,method:"tools/call",params:{name:"media_manage",arguments:{action:"media_replace_verify",media_id:$a}}}')" | jq -r '.hash')"
msrb "$(jq -n --arg r "$RID2" '{rollback_id:$r}')" >/dev/null
assert_eq "MCP cycle rolled back to A byte-for-byte" "$A_HASH" "$(wpe 'echo md5_file(get_attached_file('"$A"'));')"

echo "== 6. Structured errors =="
assert_eq "missing source_url" "wpcc_missing_url" "$(ms "$(jq -n --argjson a "$A" '{action:"media_replace",media_id:$a}')" | jq -r '.code // .data.code // "none"')"
assert_eq "replace non-attachment" "wpcc_media_not_found" "$(ms "$(jq -n --arg u "$B_URL" '{action:"media_replace",media_id:99999999,source_url:$u}')" | jq -r '.code // .data.code // "none"')"
BAD_URL=$(wpe '$u=wp_upload_dir(); file_put_contents($u["basedir"]."/s1002-bad.txt","not an image"); echo $u["baseurl"]."/s1002-bad.txt";')
assert_eq "non-image source rejected" "wpcc_replace_not_image" "$(ms "$(jq -n --argjson a "$A" --arg u "$BAD_URL" '{action:"media_replace",media_id:$a,source_url:$u}')" | jq -r '.code // .data.code // "none"')"
assert_eq "verify non-attachment" "wpcc_media_not_found" "$(ms '{"action":"media_replace_verify","media_id":99999999}' | jq -r '.code // .data.code // "none"')"

echo "== 7. A failed (non-image) replace leaves the original intact =="
assert_eq "original still A after rejected replace" "$A_HASH" "$(wpe 'echo md5_file(get_attached_file('"$A"'));')"

echo
echo "================================================"
echo "  Media Replace (STEP 100.2): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
