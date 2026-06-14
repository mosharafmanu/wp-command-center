#!/usr/bin/env bash
#
# STEP 100.3 — Media Enhancement Runtime (foundation) acceptance suite.
#
# Read-only diagnostics that stand up the new `media_enhance` operation:
#   - media_enhance_capabilities  (GD/Imagick + WebP/AVIF encode probe)
#   - image_sizes_list            (all registered sizes incl. theme add_image_size)
#   - image_size_usage_audit      (per-size on-disk coverage across the library)
#   - image_size_recommendations  (unused / oversized registered sizes)
#   - image_size_verify           (which registered sizes exist for one attachment)
# Proves REST + MCP parity, capability wiring (operation_map = 33), and that the
# verify audit distinguishes present / missing / not_applicable correctly.
#
# Requires: curl, jq, wp, wpcc-env.sh, GD.
# Usage: bash tests/test-media-enhance-step100-3.sh

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
memcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

ATT=""; PAGE=""; MU=""
cleanup() {
  [ -n "$ATT" ]  && wpe 'wp_delete_attachment('"$ATT"',true);'
  [ -n "$PAGE" ] && wpe 'wp_delete_post('"$PAGE"',true);'
  [ -n "$MU" ] && [ -f "$MU" ] && rm -f "$MU"
}
trap cleanup EXIT

echo "== 0. Seed a 400x400 image attachment (before any test size is registered) =="
ATT=$(wpe '
$up=wp_upload_dir(); $src=$up["basedir"]."/wpcc-s1003-test.jpg";
$im=imagecreatetruecolor(400,400); imagefill($im,0,0,imagecolorallocate($im,30,90,160)); imagejpeg($im,$src,90); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"s1003","post_status"=>"inherit"],$src);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$src));
echo $a;')
assert_nonempty "attachment seeded" "$ATT"

echo "== 1. media_enhance_capabilities (REST) =="
CAP=$(me '{"action":"media_enhance_capabilities"}')
assert_nonempty "image_library reported" "$(echo "$CAP" | jq -r '.capabilities.image_library')"
assert_eq "gd availability is boolean" "true" "$(echo "$CAP" | jq -r '.capabilities.gd.available | type=="boolean"')"
assert_eq "imagick availability is boolean" "true" "$(echo "$CAP" | jq -r '.capabilities.imagick.available | type=="boolean"')"
assert_eq "webp_encode is boolean" "true" "$(echo "$CAP" | jq -r '.capabilities.webp_encode | type=="boolean"')"
assert_eq "resize is boolean" "true" "$(echo "$CAP" | jq -r '.capabilities.resize | type=="boolean"')"

echo "== 2. media_enhance_capabilities (MCP parity) =="
CAPM=$(memcp '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"media_enhance","arguments":{"action":"media_enhance_capabilities"}}}')
assert_eq "MCP image_library matches REST" "$(echo "$CAP" | jq -r '.capabilities.image_library')" "$(echo "$CAPM" | jq -r '.capabilities.image_library')"

echo "== 3. image_sizes_list (REST) =="
SL=$(me '{"action":"image_sizes_list"}')
assert_eq "total sizes > 0" "true" "$(echo "$SL" | jq -r '.total > 0')"
assert_eq "thumbnail present and tagged core" "core" "$(echo "$SL" | jq -r '.image_sizes[] | select(.name=="thumbnail") | .source')"
assert_eq "at least one additional (theme/plugin) size" "true" "$(echo "$SL" | jq -r '[.image_sizes[] | select(.source=="additional")] | length > 0')"
assert_eq "thumbnail has positive dimensions" "true" "$(echo "$SL" | jq -r '.image_sizes[] | select(.name=="thumbnail") | (.width>0 and .height>0)')"

echo "== 4. image_sizes_list (MCP parity) =="
SLM=$(memcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"media_enhance","arguments":{"action":"image_sizes_list"}}}')
assert_eq "MCP total matches REST" "$(echo "$SL" | jq -r '.total')" "$(echo "$SLM" | jq -r '.total')"

echo "== 5. image_size_usage_audit (REST) =="
UA=$(me '{"action":"image_size_usage_audit"}')
assert_eq "scanned >= 1 (seeded image counts)" "true" "$(echo "$UA" | jq -r '.image_size_usage.scanned >= 1')"
assert_eq "registered count > 0" "true" "$(echo "$UA" | jq -r '.image_size_usage.registered > 0')"
assert_eq "truncated flag is boolean" "true" "$(echo "$UA" | jq -r '.image_size_usage.truncated | type=="boolean"')"
assert_eq "at least one size has on_disk coverage" "true" "$(echo "$UA" | jq -r '[.image_size_usage.usage[] | select(.on_disk > 0)] | length > 0')"

echo "== 6. image_size_verify before test sizes — present/not_applicable on a 400px original =="
V0=$(me "$(jq -n --argjson a "$ATT" '{action:"image_size_verify",media_id:$a}')")
assert_eq "thumbnail present for seeded image" "true" "$(echo "$V0" | jq -r '[.image_size_verify.present[]] | index("thumbnail") != null')"
assert_eq "every registered size accounted for" "true" "$(echo "$V0" | jq -r '(.image_size_verify.present|length)+(.image_size_verify.missing|length)+(.image_size_verify.not_applicable|length) == .image_size_verify.registered')"

echo "== 7. Register test image sizes via mu-plugin (small=missing, big/huge=not_applicable+oversized) =="
MUDIR="$WP_PATH/wp-content/mu-plugins"; mkdir -p "$MUDIR"; MU="$MUDIR/wpcc-s1003-sizes.php"
cat > "$MU" <<'PHP'
<?php
add_action( 'init', function () {
	add_image_size( 'wpcc_small_size', 200, 200, false ); // <= 400 → applicable but not generated → missing
	add_image_size( 'wpcc_big_size', 900, 900, false );   // > 400 → never generated → not_applicable
	add_image_size( 'wpcc_huge_size', 3000, 3000, false ); // > 2048 → oversized recommendation
} );
PHP
assert_eq "mu-plugin written" "yes" "$([ -f "$MU" ] && echo yes || echo no)"

echo "== 8. image_sizes_list now exposes the theme-registered test size =="
SL2=$(me '{"action":"image_sizes_list"}')
assert_eq "wpcc_small_size now listed" "additional" "$(echo "$SL2" | jq -r '.image_sizes[] | select(.name=="wpcc_small_size") | .source')"
assert_eq "wpcc_small_size dimensions correct" "200x200" "$(echo "$SL2" | jq -r '.image_sizes[] | select(.name=="wpcc_small_size") | "\(.width)x\(.height)"')"

echo "== 9. image_size_verify flags missing vs not_applicable correctly =="
V1=$(me "$(jq -n --argjson a "$ATT" '{action:"image_size_verify",media_id:$a}')")
assert_eq "wpcc_small_size reported MISSING (applicable, not generated)" "missing" "$(echo "$V1" | jq -r '.image_size_verify.detail.wpcc_small_size.state')"
assert_eq "wpcc_big_size reported NOT_APPLICABLE (larger than original)" "not_applicable" "$(echo "$V1" | jq -r '.image_size_verify.detail.wpcc_big_size.state')"
assert_eq "small size in missing[]" "true" "$(echo "$V1" | jq -r '[.image_size_verify.missing[]] | index("wpcc_small_size") != null')"

echo "== 10. image_size_verify (MCP parity) =="
V1M=$(memcp "$(jq -n --argjson a "$ATT" '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:"media_enhance",arguments:{action:"image_size_verify",media_id:$a}}}')")
assert_eq "MCP small size state matches REST" "missing" "$(echo "$V1M" | jq -r '.image_size_verify.detail.wpcc_small_size.state')"

echo "== 11. image_size_recommendations flags unused + oversized =="
REC=$(me '{"action":"image_size_recommendations"}')
assert_eq "wpcc_small_size flagged unused" "true" "$(echo "$REC" | jq -r '[.image_size_recommendations.recommendations[] | select(.name=="wpcc_small_size" and .issue=="unused")] | length > 0')"
assert_eq "wpcc_huge_size flagged oversized" "true" "$(echo "$REC" | jq -r '[.image_size_recommendations.recommendations[] | select(.name=="wpcc_huge_size" and .issue=="oversized")] | length > 0')"

echo "== 12. Structured errors =="
assert_eq "invalid action" "wpcc_invalid_media_enhance_action" "$(me '{"action":"bogus_action"}' | jq -r '.code // "none"')"
assert_eq "verify without media_id → media not found" "wpcc_media_not_found" "$(me '{"action":"image_size_verify"}' | jq -r '.code // "none"')"
PAGE=$(wpe '$p=wp_insert_post(["post_title"=>"not media","post_type"=>"page","post_status"=>"draft"]); echo $p;')
assert_eq "verify on non-attachment → media not found" "wpcc_media_not_found" "$(me "$(jq -n --argjson a "$PAGE" '{action:"image_size_verify",media_id:$a}')" | jq -r '.code // "none"')"

echo "== 13. Wiring: capability map + operation discovery =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map has media_enhance → media.manage" "media.manage" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map.media_enhance // "none"')"
assert_eq "operation_map count = 33" "33" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length')"
assert_eq "media_enhance in MCP tools/list" "true" "$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":9,"method":"tools/list"}' "$WPCC_BASE/mcp" | jq -r '[.result.tools[].name] | index("media_enhance") != null')"

echo
echo "================================================"
echo "  STEP 100.3 — Media Enhancement Runtime (foundation)"
echo "  PASS: $PASS   FAIL: $FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ]
