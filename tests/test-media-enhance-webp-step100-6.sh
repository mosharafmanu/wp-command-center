#!/usr/bin/env bash
#
# STEP 100.6 — WebP Audit & Generation acceptance suite.
#
# Additive WebP generation on media_enhance (creates <file>.webp sidecars beside
# originals — never modifies/replaces/deletes originals), capability-gated, and
# reversible (rollback deletes the generated .webp). Read audits: webp_audit,
# webp_verify. Writes: webp_generate, webp_generate_batch.
#
# Covers: capability available/unavailable, successful generation, existing-webp
# no-op, unsupported mime, missing source file, rollback restores state,
# verification, batch partial-success, structured errors, REST + MCP parity.
#
# Requires: curl, jq, wp, wpcc-env.sh, GD with WebP. Uses temp mu-plugins.
# Usage: bash tests/test-media-enhance-webp-step100-6.sh

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

JPG=""; GIF=""; PAGE=""; MUDIR="$WP_PATH/wp-content/mu-plugins"; MU_NOWEBP="$MUDIR/wpcc-106-nowebp.php"
cleanup() {
  [ -n "$JPG" ]  && wpe 'wp_delete_attachment('"$JPG"',true);'
  [ -n "$GIF" ]  && wpe 'wp_delete_attachment('"$GIF"',true);'
  [ -n "$PAGE" ] && wpe 'wp_delete_post('"$PAGE"',true);'
  [ -f "$MU_NOWEBP" ] && rm -f "$MU_NOWEBP"
  wpe 'update_option("wpcc_media_enhance_rollbacks",[]); $s=get_option("wpcc_media_file_snapshots",[]); foreach($s as $r){ (new \WPCommandCenter\Operations\MediaSnapshot())->delete($r["id"]); }'
}
trap cleanup EXIT

echo "== 0. Seed a JPEG (1000x800) and a GIF (unsupported source) =="
JPG=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-106.jpg";
$im=imagecreatetruecolor(1000,800); imagefill($im,0,0,imagecolorallocate($im,60,140,200)); imagejpeg($im,$s,85); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"106jpg","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s));
echo $a;')
GIF=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-106.gif";
$im=imagecreatetruecolor(300,200); imagefill($im,0,0,imagecolorallocate($im,200,100,50)); imagegif($im,$s); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/gif","post_title"=>"106gif","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s));
echo $a;')
assert_nonempty "jpeg seeded" "$JPG"
assert_nonempty "gif seeded" "$GIF"
ORIGH=$(wpe 'echo md5_file(get_attached_file('"$JPG"'));')

echo "== 1. Capability available (webp_audit) =="
AUD=$(me '{"action":"webp_audit","limit":50}')
assert_eq "webp_encode capability is boolean" "true" "$(echo "$AUD" | jq -r '.webp_audit.capability.webp_encode | type=="boolean"')"
assert_eq "webp_encode available on this dev (GD)" "true" "$(echo "$AUD" | jq -r '.webp_audit.capability.webp_encode')"
assert_eq "audit reports coverage_percent" "true" "$(echo "$AUD" | jq -r '.webp_audit.coverage_percent | type=="number"')"

echo "== 2. webp_verify pre-generation — no webp yet =="
V0=$(me "$(jq -n --argjson a "$JPG" '{action:"webp_verify",media_id:$a}')")
assert_eq "total image files > 0" "true" "$(echo "$V0" | jq -r '.webp_verify.total > 0')"
assert_eq "not fully covered yet" "false" "$(echo "$V0" | jq -r '.webp_verify.fully_covered')"

echo "== 3. Successful WebP generation =="
G=$(me "$(jq -n --argjson a "$JPG" '{action:"webp_generate",media_id:$a}')")
assert_eq "generated >= 1" "true" "$(echo "$G" | jq -r '.webp_generate.count_generated >= 1')"
assert_eq "no failures" "0" "$(echo "$G" | jq -r '.webp_generate.count_failed')"
assert_eq "result verified" "true" "$(echo "$G" | jq -r '.webp_generate.verified')"
RID=$(echo "$G" | jq -r '.webp_generate.rollback_id')
assert_nonempty "rollback_id returned" "$RID"
assert_eq "original .webp exists on disk" "yes" "$(wpe '$f=get_attached_file('"$JPG"'); echo is_file($f.".webp")?"yes":"no";')"

echo "== 4. Verification after generation + originals untouched =="
V1=$(me "$(jq -n --argjson a "$JPG" '{action:"webp_verify",media_id:$a}')")
assert_eq "now fully covered" "true" "$(echo "$V1" | jq -r '.webp_verify.fully_covered')"
assert_eq "webp smaller_or_equal for original" "true" "$(echo "$V1" | jq -r '[.webp_verify.files[] | select(.role=="original") | .smaller_or_equal][0]')"
assert_eq "original bytes unchanged (no replacement)" "$ORIGH" "$(wpe 'echo md5_file(get_attached_file('"$JPG"'));')"

echo "== 5. Existing WebP → no-op (no duplicate generation) =="
assert_eq "re-generate is no_action" "true" "$(me "$(jq -n --argjson a "$JPG" '{action:"webp_generate",media_id:$a}')" | jq -r '.webp_generate.no_action')"

echo "== 6. Rollback restores state (generated .webp removed, original intact) =="
RB=$(mrb "$(jq -n --arg r "$RID" '{rollback_id:$r}')")
assert_eq "rollback verified" "true" "$(echo "$RB" | jq -r '.verified')"
assert_eq "generated .webp removed from disk" "gone" "$(wpe '$f=get_attached_file('"$JPG"'); echo is_file($f.".webp")?"exists":"gone";')"
assert_eq "original still intact after rollback" "$ORIGH" "$(wpe 'echo md5_file(get_attached_file('"$JPG"'));')"

echo "== 7. Unsupported mime type (GIF source) =="
assert_eq "webp_generate on gif → unsupported" "wpcc_webp_unsupported_mime" "$(me "$(jq -n --argjson a "$GIF" '{action:"webp_generate",media_id:$a}')" | jq -r '.code // "none"')"
assert_eq "webp_verify reports gif unsupported" "false" "$(me "$(jq -n --argjson a "$GIF" '{action:"webp_verify",media_id:$a}')" | jq -r '.webp_verify.supported')"

echo "== 8. Missing source file =="
SAVE=$(wpe '$f=get_attached_file('"$JPG"'); copy($f,$f.".bak"); @unlink($f); echo $f;')
assert_eq "generate with missing original → no files" "wpcc_media_no_files" "$(me "$(jq -n --argjson a "$JPG" '{action:"webp_generate",media_id:$a}')" | jq -r '.code // "none"')"
wpe 'copy(get_attached_file('"$JPG"').".bak", get_attached_file('"$JPG"')); @unlink(get_attached_file('"$JPG"').".bak");'
assert_eq "original restored for remaining tests" "$ORIGH" "$(wpe 'echo md5_file(get_attached_file('"$JPG"'));')"

echo "== 9. MCP parity — generate via MCP, rollback via REST =="
GM=$(memcp "$(jq -n --argjson a "$JPG" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_enhance",arguments:{action:"webp_generate",media_id:$a}}}')")
assert_eq "MCP generated >= 1" "true" "$(echo "$GM" | jq -r '.webp_generate.count_generated >= 1')"
assert_eq "MCP result verified" "true" "$(echo "$GM" | jq -r '.webp_generate.verified')"
mrb "$(jq -n --arg r "$(echo "$GM" | jq -r '.webp_generate.rollback_id')" '{rollback_id:$r}')" >/dev/null

echo "== 10. Batch generation — partial success (jpeg generated, gif unsupported) =="
B=$(me "$(jq -n --argjson j "$JPG" --argjson g "$GIF" '{action:"webp_generate_batch",media_ids:[$j,$g],limit:10}')")
assert_eq "batch_id returned" "true" "$(echo "$B" | jq -r '.webp_generate_batch.batch_id | type=="string"')"
assert_eq "batch generated >= 1" "true" "$(echo "$B" | jq -r '.webp_generate_batch.generated >= 1')"
assert_eq "batch unsupported >= 1 (gif)" "true" "$(echo "$B" | jq -r '.webp_generate_batch.unsupported >= 1')"
for r in $(echo "$B" | jq -r '.webp_generate_batch.results[].rollback_id // empty'); do mrb "$(jq -n --arg r "$r" '{rollback_id:$r}')" >/dev/null; done

echo "== 11. Capability unavailable (filter) → fail closed, never fatal =="
mkdir -p "$MUDIR"
cat > "$MU_NOWEBP" <<'PHP'
<?php
add_filter( 'wpcc_media_webp_encode_available', '__return_false' );
PHP
assert_eq "audit reports capability now false" "false" "$(me '{"action":"webp_audit","limit":5}' | jq -r '.webp_audit.capability.webp_encode')"
assert_eq "webp_generate aborts with lib unavailable" "wpcc_image_lib_unavailable" "$(me "$(jq -n --argjson a "$JPG" '{action:"webp_generate",media_id:$a}')" | jq -r '.code // "none"')"
assert_eq "webp_generate_batch aborts with lib unavailable" "wpcc_image_lib_unavailable" "$(me '{"action":"webp_generate_batch","limit":5}' | jq -r '.code // "none"')"
rm -f "$MU_NOWEBP"

echo "== 12. Structured errors =="
assert_eq "generate without media_id" "wpcc_media_not_found" "$(me '{"action":"webp_generate"}' | jq -r '.code // "none"')"
assert_eq "verify without media_id" "wpcc_media_not_found" "$(me '{"action":"webp_verify"}' | jq -r '.code // "none"')"
assert_eq "rollback without id" "wpcc_missing_rollback_id" "$(mrb '{}' | jq -r '.code // "none"')"
PAGE=$(wpe '$p=wp_insert_post(["post_title"=>"not media 106","post_type"=>"page","post_status"=>"draft"]); echo $p;')
assert_eq "generate on non-attachment" "wpcc_media_not_found" "$(me "$(jq -n --argjson a "$PAGE" '{action:"webp_generate",media_id:$a}')" | jq -r '.code // "none"')"

echo "== 13. Wiring: operation_map unchanged, MCP tool present =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map count 34 (this step adds no new op; 104.2 change_history)" "34" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length')"
assert_eq "media_enhance still in MCP tools/list" "true" "$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":9,"method":"tools/list"}' "$WPCC_BASE/mcp" | jq -r '[.result.tools[].name] | index("media_enhance") != null')"

echo
echo "================================================"
echo "  STEP 100.6 — WebP Audit & Generation"
echo "  PASS: $PASS   FAIL: $FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ]
