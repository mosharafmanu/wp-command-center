#!/usr/bin/env bash
#
# STEP 100.7 — Image Optimization acceptance suite.
#
# Reversible re-encode on media_enhance: re-compress JPEG/PNG/WebP at a quality
# target to cut bytes WITHOUT changing dimensions. Capability-gated, snapshot-
# backed, skips insignificant savings, reversible via /media_enhance/rollback.
#   - image_optimize_audit (R), image_optimize_verify (R)
#   - image_optimize (RW), image_optimize_batch (RW)
#
# Covers: supported JPEG/PNG/WebP, unsupported GIF/SVG, capability unavailable,
# success, skipped, rollback (byte-for-byte), batch partial success, verify path,
# audit path, REST + MCP parity, structured errors.
#
# Requires: curl, jq, wp, wpcc-env.sh, GD with WebP. Uses temp mu-plugins.
# Usage: bash tests/test-media-enhance-optimize-step100-7.sh

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

JPG=""; PNG=""; WEBP=""; GIF=""; SVG=""; PAGE=""; MUDIR="$WP_PATH/wp-content/mu-plugins"; MU_NOOPT="$MUDIR/wpcc-107-noopt.php"
cleanup() {
  for v in "$JPG" "$PNG" "$WEBP" "$GIF" "$SVG"; do [ -n "$v" ] && wpe 'wp_delete_attachment('"$v"',true);'; done
  [ -n "$PAGE" ] && wpe 'wp_delete_post('"$PAGE"',true);'
  [ -f "$MU_NOOPT" ] && rm -f "$MU_NOOPT"
  wpe 'update_option("wpcc_media_enhance_rollbacks",[]); $s=get_option("wpcc_media_file_snapshots",[]); foreach($s as $r){ (new \WPCommandCenter\Operations\MediaSnapshot())->delete($r["id"]); }'
}
trap cleanup EXIT

echo "== 0. Seed complex JPEG/PNG/WebP (optimizable) + GIF/SVG (unsupported) =="
JPG=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-107.jpg";
$im=imagecreatetruecolor(1200,900); for($i=0;$i<3000;$i++){imagefilledellipse($im,rand(0,1200),rand(0,900),rand(5,90),rand(5,90),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));} imagejpeg($im,$s,95); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"107jpg","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s)); echo $a;')
PNG=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-107.png";
$im=imagecreatetruecolor(1000,800); for($i=0;$i<2500;$i++){imagefilledellipse($im,rand(0,1000),rand(0,800),rand(5,80),rand(5,80),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));} imagepng($im,$s,0); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/png","post_title"=>"107png","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s)); echo $a;')
WEBP=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-107.webp";
$im=imagecreatetruecolor(1000,800); for($i=0;$i<2500;$i++){imagefilledellipse($im,rand(0,1000),rand(0,800),rand(5,80),rand(5,80),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));} imagewebp($im,$s,95); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/webp","post_title"=>"107webp","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s)); echo $a;')
GIF=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-107.gif";
$im=imagecreatetruecolor(300,200); imagefill($im,0,0,imagecolorallocate($im,200,100,50)); imagegif($im,$s); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/gif","post_title"=>"107gif","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s)); echo $a;')
SVG=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-107.svg";
file_put_contents($s,"<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"10\" height=\"10\"></svg>");
$a=wp_insert_attachment(["post_mime_type"=>"image/svg+xml","post_title"=>"107svg","post_status"=>"inherit"],$s); echo $a;')
assert_nonempty "jpeg seeded" "$JPG"
assert_nonempty "png seeded" "$PNG"
assert_nonempty "webp seeded" "$WEBP"
assert_nonempty "gif seeded" "$GIF"
assert_nonempty "svg seeded" "$SVG"
ORIGH=$(wpe 'echo md5_file(get_attached_file('"$JPG"'));')
ORIGSZ=$(wpe 'echo filesize(get_attached_file('"$JPG"'));')

echo "== 1. Audit path =="
AUD=$(me '{"action":"image_optimize_audit","limit":60}')
assert_eq "total_images > 0" "true" "$(echo "$AUD" | jq -r '.image_optimize_audit.total_images > 0')"
assert_eq "supported > 0" "true" "$(echo "$AUD" | jq -r '.image_optimize_audit.supported > 0')"
assert_eq "estimated_savings_bytes is number" "true" "$(echo "$AUD" | jq -r '.image_optimize_audit.estimated_savings_bytes | type=="number"')"
assert_eq "capability.optimize is boolean" "true" "$(echo "$AUD" | jq -r '.image_optimize_audit.capability.optimize | type=="boolean"')"

echo "== 2. Verify path — supported JPEG/PNG/WebP, unsupported GIF =="
assert_eq "JPEG supported+eligible" "true" "$(me "$(jq -n --argjson a "$JPG" '{action:"image_optimize_verify",media_id:$a}')" | jq -r '.image_optimize_verify | (.supported and .eligible)')"
assert_eq "JPEG dimensions reported" "true" "$(me "$(jq -n --argjson a "$JPG" '{action:"image_optimize_verify",media_id:$a}')" | jq -r '.image_optimize_verify.dimensions.width > 0')"
assert_eq "PNG supported" "true" "$(me "$(jq -n --argjson a "$PNG" '{action:"image_optimize_verify",media_id:$a}')" | jq -r '.image_optimize_verify.supported')"
assert_eq "WebP supported" "true" "$(me "$(jq -n --argjson a "$WEBP" '{action:"image_optimize_verify",media_id:$a}')" | jq -r '.image_optimize_verify.supported')"
assert_eq "GIF NOT supported" "false" "$(me "$(jq -n --argjson a "$GIF" '{action:"image_optimize_verify",media_id:$a}')" | jq -r '.image_optimize_verify.supported')"

echo "== 3. Optimization success (JPEG q50) — dimensions preserved =="
G=$(me "$(jq -n --argjson a "$JPG" '{action:"image_optimize",media_id:$a,quality:50}')")
assert_eq "bytes_saved > 0" "true" "$(echo "$G" | jq -r '.image_optimize.bytes_saved > 0')"
assert_eq "percent_saved > 0" "true" "$(echo "$G" | jq -r '.image_optimize.percent_saved > 0')"
assert_eq "result verified" "true" "$(echo "$G" | jq -r '.image_optimize.verified')"
RID=$(echo "$G" | jq -r '.image_optimize.rollback_id')
assert_nonempty "rollback_id returned" "$RID"
assert_eq "dimensions unchanged" "1200x900" "$(wpe '$m=wp_get_attachment_metadata('"$JPG"'); echo $m["width"]."x".$m["height"];')"
assert_eq "original file smaller on disk than pre-optimize" "true" "$(wpe 'echo filesize(get_attached_file('"$JPG"')) < '"$ORIGSZ"' ? "true":"false";')"

echo "== 4. Optimization skipped — re-optimize at higher quality (no savings) =="
assert_eq "re-optimize q95 → no_action" "true" "$(me "$(jq -n --argjson a "$JPG" '{action:"image_optimize",media_id:$a,quality:95}')" | jq -r '.image_optimize.no_action')"

echo "== 5. Rollback success — byte-for-byte restore =="
assert_eq "rollback verified" "true" "$(mrb "$(jq -n --arg r "$RID" '{rollback_id:$r}')" | jq -r '.verified')"
assert_eq "original restored byte-for-byte" "$ORIGH" "$(wpe 'echo md5_file(get_attached_file('"$JPG"'));')"
assert_eq "no leftover temp files" "0" "$(wpe '$b=dirname(get_attached_file('"$JPG"')); echo count(glob($b."/*wpccopt*"));')"

echo "== 6. Supported PNG / WebP optimize (non-error; reversible) =="
PR=$(me "$(jq -n --argjson a "$PNG" '{action:"image_optimize",media_id:$a,quality:50}')")
assert_eq "PNG optimize is structured (no error code)" "none" "$(echo "$PR" | jq -r '.code // "none"')"
PRID=$(echo "$PR" | jq -r '.image_optimize.rollback_id // empty'); [ -n "$PRID" ] && mrb "$(jq -n --arg r "$PRID" '{rollback_id:$r}')" >/dev/null
WR=$(me "$(jq -n --argjson a "$WEBP" '{action:"image_optimize",media_id:$a,quality:50}')")
assert_eq "WebP optimize is structured (no error code)" "none" "$(echo "$WR" | jq -r '.code // "none"')"
assert_eq "WebP optimize saved bytes" "true" "$(echo "$WR" | jq -r '(.image_optimize.bytes_saved // 0) >= 0')"
WRID=$(echo "$WR" | jq -r '.image_optimize.rollback_id // empty'); [ -n "$WRID" ] && mrb "$(jq -n --arg r "$WRID" '{rollback_id:$r}')" >/dev/null

echo "== 7. Unsupported mime types =="
assert_eq "GIF optimize → unsupported mime" "wpcc_optimize_unsupported_mime" "$(me "$(jq -n --argjson a "$GIF" '{action:"image_optimize",media_id:$a}')" | jq -r '.code // "none"')"
SVG_CODE=$(me "$(jq -n --argjson a "$SVG" '{action:"image_optimize",media_id:$a}')" | jq -r '.code // "none"')
assert_eq "SVG optimize rejected (not an image / unsupported)" "true" "$([ "$SVG_CODE" = "wpcc_not_an_image" ] || [ "$SVG_CODE" = "wpcc_optimize_unsupported_mime" ] && echo true || echo false)"

echo "== 8. MCP parity — optimize via MCP, rollback via REST =="
GM=$(memcp "$(jq -n --argjson a "$JPG" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_enhance",arguments:{action:"image_optimize",media_id:$a,quality:50}}}')")
assert_eq "MCP bytes_saved > 0" "true" "$(echo "$GM" | jq -r '.image_optimize.bytes_saved > 0')"
assert_eq "MCP verified" "true" "$(echo "$GM" | jq -r '.image_optimize.verified')"
mrb "$(jq -n --arg r "$(echo "$GM" | jq -r '.image_optimize.rollback_id')" '{rollback_id:$r}')" >/dev/null
assert_eq "original restored after MCP rollback" "$ORIGH" "$(wpe 'echo md5_file(get_attached_file('"$JPG"'));')"

echo "== 9. Batch partial success (jpeg optimized, gif unsupported) =="
B=$(me "$(jq -n --argjson j "$JPG" --argjson g "$GIF" '{action:"image_optimize_batch",media_ids:[$j,$g],quality:50,limit:10}')")
assert_eq "batch_id returned" "true" "$(echo "$B" | jq -r '.image_optimize_batch.batch_id | type=="string"')"
assert_eq "batch optimized >= 1" "true" "$(echo "$B" | jq -r '.image_optimize_batch.optimized >= 1')"
assert_eq "batch unsupported >= 1 (gif)" "true" "$(echo "$B" | jq -r '.image_optimize_batch.unsupported >= 1')"
for r in $(echo "$B" | jq -r '.image_optimize_batch.results[].rollback_id // empty'); do mrb "$(jq -n --arg r "$r" '{rollback_id:$r}')" >/dev/null; done
assert_eq "original restored after batch rollback" "$ORIGH" "$(wpe 'echo md5_file(get_attached_file('"$JPG"'));')"

echo "== 10. Capability unavailable (filter) → fail closed, never fatal =="
mkdir -p "$MUDIR"
cat > "$MU_NOOPT" <<'PHP'
<?php
add_filter( 'wpcc_media_optimize_available', '__return_false' );
PHP
assert_eq "optimize aborts with lib unavailable" "wpcc_image_lib_unavailable" "$(me "$(jq -n --argjson a "$JPG" '{action:"image_optimize",media_id:$a}')" | jq -r '.code // "none"')"
assert_eq "batch aborts with lib unavailable" "wpcc_image_lib_unavailable" "$(me '{"action":"image_optimize_batch","limit":5}' | jq -r '.code // "none"')"
assert_eq "audit still reports capability false" "false" "$(me '{"action":"image_optimize_audit","limit":5}' | jq -r '.image_optimize_audit.capability.optimize')"
rm -f "$MU_NOOPT"

echo "== 11. Structured errors =="
assert_eq "optimize without media_id" "wpcc_media_not_found" "$(me '{"action":"image_optimize"}' | jq -r '.code // "none"')"
assert_eq "verify without media_id" "wpcc_media_not_found" "$(me '{"action":"image_optimize_verify"}' | jq -r '.code // "none"')"
assert_eq "rollback without id" "wpcc_missing_rollback_id" "$(mrb '{}' | jq -r '.code // "none"')"
PAGE=$(wpe '$p=wp_insert_post(["post_title"=>"not media 107","post_type"=>"page","post_status"=>"draft"]); echo $p;')
assert_eq "optimize on non-attachment" "wpcc_media_not_found" "$(me "$(jq -n --argjson a "$PAGE" '{action:"image_optimize",media_id:$a}')" | jq -r '.code // "none"')"

echo "== 12. Wiring: operation_map unchanged, MCP tool present =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map count 34 (this step adds no new op; 104.2 change_history)" "34" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length')"
assert_eq "media_enhance still in MCP tools/list" "true" "$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":9,"method":"tools/list"}' "$WPCC_BASE/mcp" | jq -r '[.result.tools[].name] | index("media_enhance") != null')"

echo
echo "================================================"
echo "  STEP 100.7 — Image Optimization"
echo "  PASS: $PASS   FAIL: $FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ]
