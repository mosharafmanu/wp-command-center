#!/usr/bin/env bash
#
# STEP 100.1 — File-level media snapshot service acceptance suite.
#
# Byte-for-byte capture + restoration of an attachment's original file, every
# generated size file, and its metadata — over REST and MCP. Proves the safety
# primitive that later file-mutating media ops (replace/regenerate/optimize/
# cleanup) will depend on.
#
# Requires: curl, jq, wp, wpcc-env.sh, GD.
# Usage: bash tests/test-media-snapshot-step100-1.sh

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
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

ATT=""; PAGE=""
cleanup() {
  [ -n "$ATT" ] && wpe '$s=get_option("wpcc_media_file_snapshots",[]); foreach($s as $r){ (new \WPCommandCenter\Operations\MediaSnapshot())->delete($r["id"]); } wp_delete_attachment('"$ATT"',true);'
  [ -n "$PAGE" ] && wpe 'wp_delete_post('"$PAGE"',true);'
}
trap cleanup EXIT

echo "== 0. Seed an attachment with generated sizes =="
ATT=$(wpe '
$up=wp_upload_dir(); $src=$up["basedir"]."/wpcc-s100-test.jpg";
$im=imagecreatetruecolor(800,600); imagefill($im,0,0,imagecolorallocate($im,40,120,200)); imagejpeg($im,$src,90); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"s100 snap","post_status"=>"inherit"],$src);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$src));
echo $a;')
assert_nonempty "attachment seeded" "$ATT"
FP=$(wpe 'echo get_attached_file('"$ATT"');')
H0=$(wpe 'echo md5_file(get_attached_file('"$ATT"'));')
assert_nonempty "original file hash" "$H0"
# a generated size file (first size) to test multi-file + deletion restore
SFILE=$(wpe '$m=wp_get_attachment_metadata('"$ATT"'); $k=array_key_first($m["sizes"]); echo dirname(get_attached_file('"$ATT"'))."/".$m["sizes"][$k]["file"];')
assert_eq "a size file exists on disk" "yes" "$(wpe 'echo is_file("'"$SFILE"'")?"yes":"no";')"

echo "== 1. media_snapshot_create (REST) =="
C=$(ms "$(jq -n --argjson a "$ATT" '{action:"media_snapshot_create",media_id:$a,label:"acceptance"}')")
SID=$(echo "$C" | jq -r '.snapshot_id')
assert_nonempty "snapshot_id returned" "$SID"
assert_eq "captured >1 file (original + sizes)" "true" "$(echo "$C" | jq -r '.files > 1')"

echo "== 2. media_snapshot_verify (REST) — fresh snapshot matches disk =="
V=$(ms "$(jq -n --arg s "$SID" '{action:"media_snapshot_verify",snapshot_id:$s}')")
assert_eq "snapshot valid" "true" "$(echo "$V" | jq -r '.valid')"
assert_eq "all files match current disk" "true" "$(echo "$V" | jq -r '[.files[].matches_current] | all')"

echo "== 3. Corrupt original + delete a size file on disk =="
wpe 'file_put_contents("'"$FP"'","CORRUPTED-BYTES"); @unlink("'"$SFILE"'");'
assert_eq "original now differs from snapshot" "no" "$(wpe 'echo md5_file("'"$FP"'")==="'"$H0"'"?"yes":"no";')"
assert_eq "size file removed" "no" "$(wpe 'echo is_file("'"$SFILE"'")?"yes":"no";')"

echo "== 4. media_snapshot_verify reflects drift (snapshot still intact) =="
V2=$(ms "$(jq -n --arg s "$SID" '{action:"media_snapshot_verify",snapshot_id:$s}')")
assert_eq "snapshot bytes still intact" "true" "$(echo "$V2" | jq -r '[.files[].snapshot_intact] | all')"
assert_eq "live files no longer all match" "false" "$(echo "$V2" | jq -r '[.files[].matches_current] | all')"

echo "== 5. media_snapshot_restore (MCP) — byte-for-byte =="
R=$(msmcp "$(jq -n --arg s "$SID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_manage",arguments:{action:"media_snapshot_restore",snapshot_id:$s}}}')")
assert_eq "restore verified" "true" "$(echo "$R" | jq -r '.verified')"
assert_eq "restored >1 file" "true" "$(echo "$R" | jq -r '.files_restored > 1')"
assert_eq "ORIGINAL restored byte-for-byte" "yes" "$(wpe 'echo md5_file("'"$FP"'")==="'"$H0"'"?"yes":"no";')"
assert_eq "deleted size file recreated" "yes" "$(wpe 'echo is_file("'"$SFILE"'")?"yes":"no";')"

echo "== 6. media_snapshot_verify after restore — all match again =="
V3=$(ms "$(jq -n --arg s "$SID" '{action:"media_snapshot_verify",snapshot_id:$s}')")
assert_eq "all files match after restore" "true" "$(echo "$V3" | jq -r '[.files[].matches_current] | all')"

echo "== 7. media_snapshot_list (REST + MCP parity) =="
assert_eq "list includes snapshot (REST)" "true" "$(ms "$(jq -n --argjson a "$ATT" '{action:"media_snapshot_list",media_id:$a}')" | jq -r '[.snapshots[].id] | index("'"$SID"'") != null')"
assert_eq "list includes snapshot (MCP)" "true" "$(msmcp "$(jq -n --argjson a "$ATT" '{jsonrpc:"2.0",id:2,method:"tools/call",params:{name:"media_manage",arguments:{action:"media_snapshot_list",media_id:$a}}}')" | jq -r '[.snapshots[].id] | index("'"$SID"'") != null')"

echo "== 8. MCP create parity =="
CM=$(msmcp "$(jq -n --argjson a "$ATT" '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:"media_manage",arguments:{action:"media_snapshot_create"}}}')")
assert_eq "MCP create without media_id errors cleanly" "wpcc_media_not_found" "$(echo "$CM" | jq -r '.code // "none"')"

echo "== 9. Structured errors =="
assert_eq "restore missing snapshot_id" "wpcc_missing_snapshot_id" "$(ms '{"action":"media_snapshot_restore"}' | jq -r '.code // "none"')"
assert_eq "restore unknown snapshot" "wpcc_media_snapshot_not_found" "$(ms '{"action":"media_snapshot_restore","snapshot_id":"nope-123"}' | jq -r '.code // "none"')"
PAGE=$(wpe '$p=wp_insert_post(["post_title"=>"not media","post_type"=>"page","post_status"=>"draft"]); echo $p;')
assert_eq "snapshot of non-attachment" "wpcc_media_not_found" "$(ms "$(jq -n --argjson p "$PAGE" '{action:"media_snapshot_create",media_id:$p}')" | jq -r '.code // "none"')"
assert_eq "verify missing snapshot_id" "wpcc_missing_snapshot_id" "$(ms '{"action":"media_snapshot_verify"}' | jq -r '.code // "none"')"

echo
echo "================================================"
echo "  Media Snapshot (STEP 100.1): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
