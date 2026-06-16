#!/usr/bin/env bash
#
# STEP 100.4 — Responsive Image Audit acceptance suite.
#
# Read-only responsive-image diagnostics on the existing `media_enhance`
# operation (no new operation; operation_map stays 33):
#   - srcset_verify             (WordPress srcset/sizes metadata for one attachment)
#   - responsive_image_audit    (per-attachment readiness, or library-wide aggregate)
#   - missing_sizes_audit       (attachments missing applicable registered sizes)
#   - image_size_context_audit  (oversized / undersized original vs display sizes)
#
# Covers: complete sizes, a missing size, oversized original, undersized original,
# upscale not_applicable, structured recommendations, REST + MCP parity.
#
# Requires: curl, jq, wp, wpcc-env.sh, GD. Uses temp mu-plugins (cleaned up).
# Usage: bash tests/test-media-enhance-responsive-step100-4.sh

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

BIG=""; SMALL=""; PAGE=""; MUDIR="$WP_PATH/wp-content/mu-plugins"; MU1="$MUDIR/wpcc-104-threshold.php"; MU2="$MUDIR/wpcc-104-size.php"
cleanup() {
  [ -n "$BIG" ]   && wpe 'wp_delete_attachment('"$BIG"',true);'
  [ -n "$SMALL" ] && wpe 'wp_delete_attachment('"$SMALL"',true);'
  [ -n "$PAGE" ]  && wpe 'wp_delete_post('"$PAGE"',true);'
  [ -f "$MU1" ] && rm -f "$MU1"
  [ -f "$MU2" ] && rm -f "$MU2"
}
trap cleanup EXIT

echo "== 0. Disable big_image_size_threshold (mu-plugin) so a truly oversized original survives =="
mkdir -p "$MUDIR"
cat > "$MU1" <<'PHP'
<?php
add_filter( 'big_image_size_threshold', '__return_false' );
PHP
assert_eq "threshold mu-plugin written" "yes" "$([ -f "$MU1" ] && echo yes || echo no)"

echo "== 1. Seed a 6144x1200 (oversized) image and a 250x200 (undersized) image =="
BIG=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-104-big.jpg";
$im=imagecreatetruecolor(6144,1200); imagefill($im,0,0,imagecolorallocate($im,10,80,140)); imagejpeg($im,$s,82); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"104big","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s));
echo $a;')
SMALL=$(wpe '
$up=wp_upload_dir(); $s=$up["basedir"]."/wpcc-104-small.jpg";
$im=imagecreatetruecolor(250,200); imagefill($im,0,0,imagecolorallocate($im,150,40,40)); imagejpeg($im,$s,82); imagedestroy($im);
$a=wp_insert_attachment(["post_mime_type"=>"image/jpeg","post_title"=>"104small","post_status"=>"inherit"],$s);
require_once ABSPATH."wp-admin/includes/image.php"; wp_update_attachment_metadata($a, wp_generate_attachment_metadata($a,$s));
echo $a;')
assert_nonempty "big attachment seeded" "$BIG"
assert_nonempty "small attachment seeded" "$SMALL"
assert_eq "threshold disabled → original kept above 2560px" "true" "$(me "$(jq -n --argjson a "$BIG" '{action:"image_size_context_audit",media_id:$a}')" | jq -r '.image_size_context_audit.original.width > 2560')"

echo "== 2. srcset_verify — multi-size image has a responsive srcset (REST) =="
SV=$(me "$(jq -n --argjson a "$BIG" '{action:"srcset_verify",media_id:$a}')")
assert_eq "BIG candidate_count >= 2" "true" "$(echo "$SV" | jq -r '.srcset.candidate_count >= 2')"
assert_eq "BIG has_srcset true" "true" "$(echo "$SV" | jq -r '.srcset.has_srcset')"
assert_nonempty "BIG sizes attribute present" "$(echo "$SV" | jq -r '.srcset.sizes')"

echo "== 3. srcset_verify — tiny single-size image has no responsive srcset =="
SVS=$(me "$(jq -n --argjson a "$SMALL" '{action:"srcset_verify",media_id:$a}')")
assert_eq "SMALL candidate_count < 2" "true" "$(echo "$SVS" | jq -r '.srcset.candidate_count < 2')"
assert_eq "SMALL has_srcset false" "false" "$(echo "$SVS" | jq -r '.srcset.has_srcset')"

echo "== 4. srcset_verify (MCP parity) =="
SVM=$(memcp "$(jq -n --argjson a "$BIG" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_enhance",arguments:{action:"srcset_verify",media_id:$a}}}')")
assert_eq "MCP candidate_count matches REST" "$(echo "$SV" | jq -r '.srcset.candidate_count')" "$(echo "$SVM" | jq -r '.srcset.candidate_count')"

echo "== 5. responsive_image_audit (per-attachment) — complete sizes => responsive_ready =="
RA=$(me "$(jq -n --argjson a "$BIG" '{action:"responsive_image_audit",media_id:$a}')")
assert_eq "BIG has no missing sizes (complete)" "0" "$(echo "$RA" | jq -r '.responsive_image_audit.missing | length')"
assert_eq "BIG responsive_ready true" "true" "$(echo "$RA" | jq -r '.responsive_image_audit.responsive_ready')"

echo "== 6. image_size_context_audit — oversized original (larger than needed) =="
CB=$(me "$(jq -n --argjson a "$BIG" '{action:"image_size_context_audit",media_id:$a}')")
assert_eq "BIG status oversized" "oversized" "$(echo "$CB" | jq -r '.image_size_context_audit.status')"
assert_eq "BIG oversized recommendation present" "true" "$(echo "$CB" | jq -r '[.image_size_context_audit.recommendations[] | select(.issue=="oversized_original")] | length > 0')"

echo "== 7. image_size_context_audit — undersized original + upscale not_applicable =="
CS=$(me "$(jq -n --argjson a "$SMALL" '{action:"image_size_context_audit",media_id:$a}')")
assert_eq "SMALL status undersized" "undersized" "$(echo "$CS" | jq -r '.image_size_context_audit.status')"
assert_eq "SMALL has unfillable (not-upscaled) sizes" "true" "$(echo "$CS" | jq -r '.image_size_context_audit.unfillable_sizes | length > 0')"
assert_eq "SMALL undersized recommendation present" "true" "$(echo "$CS" | jq -r '[.image_size_context_audit.recommendations[] | select(.issue=="undersized_original")] | length > 0')"

echo "== 8. Register a new image size AFTER upload (mu-plugin) → it is now missing on BIG =="
cat > "$MU2" <<'PHP'
<?php
add_action( 'init', function () {
	add_image_size( 'wpcc_resp_missing', 160, 160, false ); // <= original, applicable, never generated → missing
} );
PHP
assert_eq "size mu-plugin written" "yes" "$([ -f "$MU2" ] && echo yes || echo no)"
RA2=$(me "$(jq -n --argjson a "$BIG" '{action:"responsive_image_audit",media_id:$a}')")
assert_eq "BIG now reports wpcc_resp_missing missing" "true" "$(echo "$RA2" | jq -r '[.responsive_image_audit.missing[]] | index("wpcc_resp_missing") != null')"
assert_eq "BIG no longer responsive_ready" "false" "$(echo "$RA2" | jq -r '.responsive_image_audit.responsive_ready')"
assert_eq "BIG has missing_sizes recommendation (structured)" "true" "$(echo "$RA2" | jq -r '[.responsive_image_audit.recommendations[] | select(.issue=="missing_sizes")] | length > 0')"

echo "== 9. missing_sizes_audit (library-wide) lists BIG with the missing size =="
MSA=$(me '{"action":"missing_sizes_audit","limit":200}')
assert_eq "scanned >= 2" "true" "$(echo "$MSA" | jq -r '.missing_sizes_audit.scanned >= 2')"
assert_eq "with_missing >= 1" "true" "$(echo "$MSA" | jq -r '.missing_sizes_audit.with_missing >= 1')"
assert_eq "BIG present in missing list with wpcc_resp_missing" "true" "$(echo "$MSA" | jq -r --argjson a "$BIG" '[.missing_sizes_audit.attachments[] | select(.media_id==$a) | .missing[] | select(.=="wpcc_resp_missing")] | length > 0')"

echo "== 10. responsive_image_audit (library-wide aggregate) =="
RAL=$(me '{"action":"responsive_image_audit","limit":200}')
assert_eq "aggregate scanned >= 2" "true" "$(echo "$RAL" | jq -r '.responsive_image_audit.scanned >= 2')"
assert_eq "aggregate not_ready >= 1" "true" "$(echo "$RAL" | jq -r '.responsive_image_audit.not_ready >= 1')"
assert_eq "aggregate exposes without_srcset count" "true" "$(echo "$RAL" | jq -r '.responsive_image_audit.without_srcset | type=="number"')"
assert_eq "aggregate exposes with_missing_sizes count" "true" "$(echo "$RAL" | jq -r '.responsive_image_audit.with_missing_sizes | type=="number"')"

echo "== 11. responsive_image_audit library-wide (MCP parity) =="
RALM=$(memcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"media_enhance","arguments":{"action":"responsive_image_audit","limit":200}}}')
assert_eq "MCP aggregate scanned matches REST" "$(echo "$RAL" | jq -r '.responsive_image_audit.scanned')" "$(echo "$RALM" | jq -r '.responsive_image_audit.scanned')"

echo "== 12. Structured errors =="
assert_eq "srcset_verify without media_id" "wpcc_media_not_found" "$(me '{"action":"srcset_verify"}' | jq -r '.code // "none"')"
PAGE=$(wpe '$p=wp_insert_post(["post_title"=>"not media 104","post_type"=>"page","post_status"=>"draft"]); echo $p;')
assert_eq "context audit on non-attachment" "wpcc_media_not_found" "$(me "$(jq -n --argjson a "$PAGE" '{action:"image_size_context_audit",media_id:$a}')" | jq -r '.code // "none"')"

echo "== 13. Wiring: still one operation, MCP tool present, op_map unchanged =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map count 34 (this step adds no new op; 104.2 change_history)" "34" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length')"
assert_eq "media_enhance still in MCP tools/list" "true" "$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":9,"method":"tools/list"}' "$WPCC_BASE/mcp" | jq -r '[.result.tools[].name] | index("media_enhance") != null')"

echo
echo "================================================"
echo "  STEP 100.4 — Responsive Image Audit"
echo "  PASS: $PASS   FAIL: $FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ]
