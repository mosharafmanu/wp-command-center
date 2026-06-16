#!/usr/bin/env bash
#
# STEP 100.5 — Thumbnail Regeneration acceptance suite.
#
# First reversible WRITE on media_enhance, snapshot-backed (STEP 100.1):
#   - thumbnail_regenerate / thumbnail_regenerate_attachment (mode missing|all)
#   - thumbnail_regenerate_batch (cursor)
#   - thumbnail_verify (read)
#   - /operations/media_enhance/rollback
#
# Covers: successful regeneration, missing-size regeneration, already-complete
# no-op, oversized/not-applicable handling, failed-regeneration rollback,
# metadata verification, byte-for-byte snapshot restore after rollback, REST+MCP.
#
# Requires: curl, jq, wp, wpcc-env.sh, GD. Uses temp mu-plugins (cleaned up).
# Usage: bash tests/test-media-enhance-regenerate-step100-5.sh

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
me()  { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/media_enhance/run"; }
mrb() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/media_enhance/rollback"; }
memcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

BIG=""; SMALL=""; PAGE=""; MUDIR="$WP_PATH/wp-content/mu-plugins"; MU_SIZE="$MUDIR/wpcc-105-sizes.php"; MU_NOEDIT="$MUDIR/wpcc-105-noeditor.php"
cleanup() {
  [ -n "$BIG" ]   && wpe 'wp_delete_attachment('"$BIG"',true);'
  [ -n "$SMALL" ] && wpe 'wp_delete_attachment('"$SMALL"',true);'
  [ -n "$PAGE" ]  && wpe 'wp_delete_post('"$PAGE"',true);'
  [ -f "$MU_SIZE" ]   && rm -f "$MU_SIZE"
  [ -f "$MU_NOEDIT" ] && rm -f "$MU_NOEDIT"
  wpe '$s=get_option("wpcc_media_file_snapshots",[]); foreach($s as $r){ (new \WPCommandCenter\Operations\MediaSnapshot())->delete($r["id"]); }'
}
trap cleanup EXIT

echo "== 0. Seed a 1200x900 image and a 250x200 image (editors on, no test sizes) =="
BIG=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-105-big.jpg";
$im=imagecreatetruecolor(1200,900); imagefill($im,0,0,imagecolorallocate($im,20,120,90)); imagejpeg($im,$s,85); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"105big","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s));
echo $a;')
SMALL=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-105-small.jpg";
$im=imagecreatetruecolor(250,200); imagefill($im,0,0,imagecolorallocate($im,150,40,40)); imagejpeg($im,$s,85); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"105small","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s));
echo $a;')
assert_nonempty "big attachment seeded" "$BIG"
assert_nonempty "small attachment seeded" "$SMALL"

echo "== 1. Already-complete image → no-op (no snapshot, no rollback) =="
N=$(me "$(jq -n --argjson a "$BIG" '{action:"thumbnail_regenerate",media_id:$a,mode:"missing"}')")
assert_eq "no_action true" "true" "$(echo "$N" | jq -r '.thumbnail_regenerate.no_action')"
assert_eq "nothing regenerated" "0" "$(echo "$N" | jq -r '.thumbnail_regenerate.regenerated | length')"

echo "== 2. Register sizes post-upload (mu-plugin): wpcc_acc_size applicable, wpcc_big_size not =="
mkdir -p "$MUDIR"
cat > "$MU_SIZE" <<'PHP'
<?php
add_action( 'init', function () {
	// 412x309: applicable to BIG (1200x900) and a UNIQUE dimension so it produces
	// a brand-new file (no filename collision with core sizes) → exercises the
	// created-file deletion path on rollback.
	add_image_size( 'wpcc_acc_size', 412, 309, false );
	add_image_size( 'wpcc_big_size', 1500, 1500, false ); // > both originals → never generated
} );
PHP
assert_eq "size mu-plugin written" "yes" "$([ -f "$MU_SIZE" ] && echo yes || echo no)"

echo "== 3. thumbnail_verify (pre-regen) — applicable size missing =="
V0=$(me "$(jq -n --argjson a "$BIG" '{action:"thumbnail_verify",media_id:$a}')")
assert_eq "wpcc_acc_size reported missing" "true" "$(echo "$V0" | jq -r '[.thumbnail_verify.applicable_missing[]] | index("wpcc_acc_size") != null')"
assert_eq "not complete yet" "false" "$(echo "$V0" | jq -r '.thumbnail_verify.complete')"

echo "== 4. Successful missing-size regeneration (snapshot-backed) =="
RG=$(me "$(jq -n --argjson a "$BIG" '{action:"thumbnail_regenerate",media_id:$a,mode:"missing"}')")
assert_eq "regenerated includes wpcc_acc_size" "true" "$(echo "$RG" | jq -r '[.thumbnail_regenerate.regenerated[]] | index("wpcc_acc_size") != null')"
assert_eq "result verified" "true" "$(echo "$RG" | jq -r '.thumbnail_regenerate.verified')"
assert_nonempty "snapshot_id captured before write" "$(echo "$RG" | jq -r '.thumbnail_regenerate.snapshot_id')"
RID=$(echo "$RG" | jq -r '.thumbnail_regenerate.rollback_id')
assert_nonempty "rollback_id returned" "$RID"
assert_eq "generated file present on disk" "present" "$(wpe '$m=wp_get_attachment_metadata('"$BIG"'); $b=dirname(get_attached_file('"$BIG"')); echo (isset($m["sizes"]["wpcc_acc_size"]) && is_file($b."/".$m["sizes"]["wpcc_acc_size"]["file"]))?"present":"absent";')"

echo "== 5. thumbnail_verify (post-regen) — complete =="
assert_eq "now complete" "true" "$(me "$(jq -n --argjson a "$BIG" '{action:"thumbnail_verify",media_id:$a}')" | jq -r '.thumbnail_verify.complete')"

echo "== 6. Rollback the missing-size regeneration (created file removed) =="
# Capture the newly-created (unique-dimension) file path BEFORE rolling back.
ACC_FILE=$(wpe '$m=wp_get_attachment_metadata('"$BIG"'); $b=dirname(get_attached_file('"$BIG"')); echo $b."/".$m["sizes"]["wpcc_acc_size"]["file"];')
assert_eq "created file is on disk pre-rollback" "yes" "$(wpe 'echo is_file("'"$ACC_FILE"'")?"yes":"no";')"
RB=$(mrb "$(jq -n --arg r "$RID" '{rollback_id:$r}')")
assert_eq "rollback verified" "true" "$(echo "$RB" | jq -r '.verified')"
assert_eq "created size removed from metadata" "removed" "$(wpe '$m=wp_get_attachment_metadata('"$BIG"'); echo isset($m["sizes"]["wpcc_acc_size"])?"present":"removed";')"
assert_eq "newly-created size file removed from disk" "gone" "$(wpe 'echo is_file("'"$ACC_FILE"'")?"exists":"gone";')"

echo "== 7. mode=all regeneration → byte-for-byte snapshot restore on rollback =="
H_ORIG=$(wpe 'echo md5_file(get_attached_file('"$BIG"'));')
H_THUMB=$(wpe '$m=wp_get_attachment_metadata('"$BIG"'); $b=dirname(get_attached_file('"$BIG"')); echo md5_file($b."/".$m["sizes"]["thumbnail"]["file"]);')
RGA=$(me "$(jq -n --argjson a "$BIG" '{action:"thumbnail_regenerate_attachment",media_id:$a,mode:"all"}')")
assert_eq "mode=all verified" "true" "$(echo "$RGA" | jq -r '.thumbnail_regenerate.verified')"
assert_eq "mode=all rebuilt multiple sizes" "true" "$(echo "$RGA" | jq -r '.thumbnail_regenerate.regenerated | length > 1')"
RIDA=$(echo "$RGA" | jq -r '.thumbnail_regenerate.rollback_id')
RBA=$(mrb "$(jq -n --arg r "$RIDA" '{rollback_id:$r}')")
assert_eq "rollback verified" "true" "$(echo "$RBA" | jq -r '.verified')"
assert_eq "original restored byte-for-byte" "$H_ORIG" "$(wpe 'echo md5_file(get_attached_file('"$BIG"'));')"
assert_eq "thumbnail restored byte-for-byte" "$H_THUMB" "$(wpe '$m=wp_get_attachment_metadata('"$BIG"'); $b=dirname(get_attached_file('"$BIG"')); echo md5_file($b."/".$m["sizes"]["thumbnail"]["file"]);')"

echo "== 8. MCP parity — regenerate via MCP, then rollback via REST =="
RGM=$(memcp "$(jq -n --argjson a "$BIG" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_enhance",arguments:{action:"thumbnail_regenerate",media_id:$a,mode:"missing"}}}')")
assert_eq "MCP regen verified" "true" "$(echo "$RGM" | jq -r '.thumbnail_regenerate.verified')"
assert_eq "MCP regen included wpcc_acc_size" "true" "$(echo "$RGM" | jq -r '[.thumbnail_regenerate.regenerated[]] | index("wpcc_acc_size") != null')"
mrb "$(jq -n --arg r "$(echo "$RGM" | jq -r '.thumbnail_regenerate.rollback_id')" '{rollback_id:$r}')" >/dev/null

echo "== 9. Oversized / not-applicable handling — never generate impossible sizes =="
NS=$(me "$(jq -n --argjson a "$SMALL" '{action:"thumbnail_regenerate",media_id:$a,mode:"missing"}')")
assert_eq "small image: no applicable missing → no_action" "true" "$(echo "$NS" | jq -r '.thumbnail_regenerate.no_action')"
assert_eq "wpcc_big_size NOT generated on small image" "absent" "$(wpe '$m=wp_get_attachment_metadata('"$SMALL"'); echo isset($m["sizes"]["wpcc_big_size"])?"present":"absent";')"
assert_eq "verify reports wpcc_big_size not_applicable" "true" "$(me "$(jq -n --argjson a "$SMALL" '{action:"thumbnail_verify",media_id:$a}')" | jq -r '[.thumbnail_verify.not_applicable[]] | index("wpcc_big_size") != null')"

echo "== 10. Failed regeneration → automatic rollback, no partial state =="
cat > "$MU_NOEDIT" <<'PHP'
<?php
add_filter( 'wp_image_editors', function () { return []; } ); // no usable image editor
PHP
H_BEFORE=$(wpe 'echo md5_file(get_attached_file('"$BIG"'));')
FR=$(me "$(jq -n --argjson a "$BIG" '{action:"thumbnail_regenerate",media_id:$a,mode:"missing"}')")
assert_eq "failure returns structured error" "wpcc_thumbnail_regenerate_failed" "$(echo "$FR" | jq -r '.code // "none"')"
assert_eq "original intact after failed regen (no partial write)" "$H_BEFORE" "$(wpe 'echo md5_file(get_attached_file('"$BIG"'));')"
assert_eq "applicable size still missing (not partially created)" "true" "$(wpe '$m=wp_get_attachment_metadata('"$BIG"'); echo isset($m["sizes"]["wpcc_acc_size"])?"false":"true";')"
rm -f "$MU_NOEDIT"

echo "== 11. Structured errors =="
assert_eq "regenerate without media_id" "wpcc_media_not_found" "$(me '{"action":"thumbnail_regenerate"}' | jq -r '.code // "none"')"
assert_eq "rollback without rollback_id" "wpcc_missing_rollback_id" "$(mrb '{}' | jq -r '.code // "none"')"
assert_eq "rollback unknown id" "wpcc_rollback_not_found" "$(mrb '{"rollback_id":"nope-123"}' | jq -r '.code // "none"')"
PAGE=$(wpe '$p=wp_insert_post(["post_title"=>"not media 105","post_type"=>"page","post_status"=>"draft"]); echo $p;')
assert_eq "regenerate on non-attachment" "wpcc_media_not_found" "$(me "$(jq -n --argjson a "$PAGE" '{action:"thumbnail_regenerate",media_id:$a}')" | jq -r '.code // "none"')"

echo "== 12. Batch regeneration (cursor) =="
B=$(me "$(jq -n --argjson a "$BIG" --argjson s "$SMALL" '{action:"thumbnail_regenerate_batch",media_ids:[$a,$s],mode:"missing",limit:1}')")
assert_eq "batch_id returned" "true" "$(echo "$B" | jq -r '.thumbnail_regenerate_batch.batch_id | type=="string"')"
assert_eq "processed 1 (limit honored)" "1" "$(echo "$B" | jq -r '.thumbnail_regenerate_batch.processed')"
assert_eq "next_cursor present (more remain)" "1" "$(echo "$B" | jq -r '.thumbnail_regenerate_batch.next_cursor')"
# roll back any item the batch regenerated, to keep state clean
for r in $(echo "$B" | jq -r '.thumbnail_regenerate_batch.results[].rollback_id // empty'); do mrb "$(jq -n --arg r "$r" '{rollback_id:$r}')" >/dev/null; done

echo "== 13. Wiring: operation_map unchanged, MCP tool present, rollback route reachable =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map count 34 (this step adds no new op; 104.2 change_history)" "34" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length')"
assert_eq "media_enhance still in MCP tools/list" "true" "$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":9,"method":"tools/list"}' "$WPCC_BASE/mcp" | jq -r '[.result.tools[].name] | index("media_enhance") != null')"

echo
echo "================================================"
echo "  STEP 100.5 — Thumbnail Regeneration"
echo "  PASS: $PASS   FAIL: $FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ]
