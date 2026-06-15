#!/usr/bin/env bash
#
# STEP 100.8 — Media Usage Analysis acceptance suite.
#
# Read-only "where is this media used?" cleanup intelligence on media_enhance:
#   - media_usage_scan (R)   — one item: cross-source references + classification
#   - media_usage_report (R) — library aggregate
#   - unused_media_find (R)  — cleanup candidates (no references)
#   - orphaned_media_find (R)— DB rows whose file is missing on disk
#
# Verifies detection across: featured image, classic content, Gutenberg blocks,
# WooCommerce gallery, ACF fields, ACF options, Elementor, theme_mods, site_icon;
# and classification active / indirect / unused / orphaned / cleanup_candidate.
# Plus structured errors and REST + MCP parity. Audit-first, no mutations.
#
# Requires: curl, jq, wp, wpcc-env.sh; WooCommerce/ACF/Elementor active on dev.
# Usage: bash tests/test-media-enhance-usage-step100-8.sh

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
scan() { me "$(jq -n --argjson a "$1" '{action:"media_usage_scan",media_id:$a}')"; }

SETUP_PHP="/tmp/wpcc-u8-setup.php"; TEARDOWN_PHP="/tmp/wpcc-u8-teardown.php"; IDS=""
cleanup() {
  if [ -n "$IDS" ]; then
    cat > "$TEARDOWN_PHP" <<PHP
<?php
\$d = json_decode('$IDS', true);
foreach ( ['feat','block','content','woo','ele','acf','acfo','tm','si','draft','unused','orphan'] as \$k ) { if ( ! empty( \$d[\$k] ) ) { wp_delete_attachment( (int) \$d[\$k], true ); } }
foreach ( ['pp','bp','cp','prod','ep','ap','dp'] as \$k ) { if ( ! empty( \$d[\$k] ) ) { wp_delete_post( (int) \$d[\$k], true ); } }
delete_option('options_wpcc_u8_logo'); delete_option('_options_wpcc_u8_logo');
if ( isset(\$d['prev_si']) ) { if ( \$d['prev_si'] ) { update_option('site_icon', \$d['prev_si']); } else { delete_option('site_icon'); } }
if ( isset(\$d['prev_logo']) ) { if ( \$d['prev_logo'] ) { set_theme_mod('custom_logo', \$d['prev_logo']); } else { remove_theme_mod('custom_logo'); } }
echo "ok";
PHP
    wp eval-file "$TEARDOWN_PHP" --path="$WP_PATH" >/dev/null 2>&1
    rm -f "$TEARDOWN_PHP"
  fi
  rm -f "$SETUP_PHP"
}
trap cleanup EXIT

echo "== 0. Seed references across every source =="
cat > "$SETUP_PHP" <<'PHP'
<?php
require_once ABSPATH . 'wp-admin/includes/image.php';
function wpcc_u8_mk( $n ) {
	$up = wp_upload_dir(); $s = $up['basedir'] . "/$n.jpg";
	$im = imagecreatetruecolor( 400, 300 ); imagejpeg( $im, $s, 80 ); imagedestroy( $im );
	$a = wp_insert_attachment( [ 'post_mime_type' => 'image/jpeg', 'post_title' => $n, 'post_status' => 'inherit' ], $s );
	wp_update_attachment_metadata( $a, wp_generate_attachment_metadata( $a, $s ) );
	return $a;
}
$d = [];
$d['prev_si']   = (int) get_option( 'site_icon' );
$d['prev_logo'] = (int) get_theme_mod( 'custom_logo' );
foreach ( [ 'feat','block','content','woo','ele','acf','acfo','tm','si','draft','unused','orphan' ] as $k ) { $d[ $k ] = wpcc_u8_mk( 'wpcc-u8-' . $k ); }

// featured image (published)
$d['pp'] = wp_insert_post( [ 'post_title' => 'u8 pub', 'post_status' => 'publish', 'post_content' => 'x' ] ); set_post_thumbnail( $d['pp'], $d['feat'] );
// Gutenberg block (published)
$bc = '<!-- wp:image {"id":' . $d['block'] . ',"sizeSlug":"full"} --><figure class="wp-block-image"><img src="x" class="wp-image-' . $d['block'] . '"/></figure><!-- /wp:image -->';
$d['bp'] = wp_insert_post( [ 'post_title' => 'u8 blk', 'post_status' => 'publish', 'post_content' => $bc ] );
// classic content (published)
$d['cp'] = wp_insert_post( [ 'post_title' => 'u8 content', 'post_status' => 'publish', 'post_content' => '<img class="wp-image-' . $d['content'] . '"/>' ] );
// WooCommerce gallery (published product)
$d['prod'] = wp_insert_post( [ 'post_title' => 'u8 prod', 'post_type' => 'product', 'post_status' => 'publish' ] ); update_post_meta( $d['prod'], '_product_image_gallery', '987654,' . $d['woo'] . ',987655' );
// Elementor (published)
$d['ep'] = wp_insert_post( [ 'post_title' => 'u8 ele', 'post_status' => 'publish' ] ); update_post_meta( $d['ep'], '_elementor_data', '[{"id":"a1","elType":"widget","settings":{"image":{"id":' . $d['ele'] . ',"url":"x"}}}]' );
// ACF field (published)
$d['ap'] = wp_insert_post( [ 'post_title' => 'u8 acf', 'post_status' => 'publish', 'post_content' => 'y' ] ); update_post_meta( $d['ap'], 'wpcc_u8_hero', (string) $d['acf'] ); update_post_meta( $d['ap'], '_wpcc_u8_hero', 'field_u8hero' );
// ACF options page
update_option( 'options_wpcc_u8_logo', (string) $d['acfo'] ); update_option( '_options_wpcc_u8_logo', 'field_u8logo' );
// theme_mods custom_logo
set_theme_mod( 'custom_logo', $d['tm'] );
// site_icon
update_option( 'site_icon', $d['si'] );
// draft-only reference (indirect)
$d['dp'] = wp_insert_post( [ 'post_title' => 'u8 draft', 'post_status' => 'draft', 'post_content' => '<img class="wp-image-' . $d['draft'] . '"/>' ] );
// orphan: remove the file on disk
@unlink( get_attached_file( $d['orphan'] ) );
// 'unused' gets no references at all.
echo wp_json_encode( $d );
PHP
IDS=$(wp eval-file "$SETUP_PHP" --path="$WP_PATH" 2>/dev/null | tail -1)
assert_nonempty "setup produced ids" "$IDS"
gid() { echo "$IDS" | jq -r ".$1"; }

echo "== 1. media_usage_scan — source detection =="
assert_eq "featured_image → active" "active" "$(scan "$(gid feat)" | jq -r '.media_usage_scan.status')"
assert_eq "featured not a cleanup candidate" "false" "$(scan "$(gid feat)" | jq -r '.media_usage_scan.cleanup_candidate')"
assert_eq "featured source" "1" "$(scan "$(gid feat)" | jq -r '.media_usage_scan.by_source.featured_image')"
assert_eq "Gutenberg block → active" "active" "$(scan "$(gid block)" | jq -r '.media_usage_scan.status')"
assert_eq "block source present" "true" "$(scan "$(gid block)" | jq -r '.media_usage_scan.by_source.block != null')"
assert_eq "classic content → active" "active" "$(scan "$(gid content)" | jq -r '.media_usage_scan.status')"
assert_eq "WooCommerce gallery → active" "active" "$(scan "$(gid woo)" | jq -r '.media_usage_scan.status')"
assert_eq "woo gallery source present" "true" "$(scan "$(gid woo)" | jq -r '.media_usage_scan.by_source.woocommerce_gallery != null')"
assert_eq "Elementor → active" "active" "$(scan "$(gid ele)" | jq -r '.media_usage_scan.status')"
assert_eq "elementor source present" "true" "$(scan "$(gid ele)" | jq -r '.media_usage_scan.by_source.elementor != null')"
assert_eq "ACF field → active" "active" "$(scan "$(gid acf)" | jq -r '.media_usage_scan.status')"
assert_eq "acf_field source present" "true" "$(scan "$(gid acf)" | jq -r '.media_usage_scan.by_source.acf_field != null')"
assert_eq "ACF options → active" "active" "$(scan "$(gid acfo)" | jq -r '.media_usage_scan.status')"
assert_eq "acf_options source present" "true" "$(scan "$(gid acfo)" | jq -r '.media_usage_scan.by_source.acf_options != null')"
assert_eq "theme_mods → active" "active" "$(scan "$(gid tm)" | jq -r '.media_usage_scan.status')"
assert_eq "site_icon → active" "active" "$(scan "$(gid si)" | jq -r '.media_usage_scan.status')"

echo "== 2. Classification — indirect / unused / orphaned =="
assert_eq "draft-only → indirect" "indirect" "$(scan "$(gid draft)" | jq -r '.media_usage_scan.status')"
assert_eq "draft not a cleanup candidate" "false" "$(scan "$(gid draft)" | jq -r '.media_usage_scan.cleanup_candidate')"
assert_eq "unused → unused" "unused" "$(scan "$(gid unused)" | jq -r '.media_usage_scan.status')"
assert_eq "unused is cleanup candidate" "true" "$(scan "$(gid unused)" | jq -r '.media_usage_scan.cleanup_candidate')"
assert_eq "unused not orphaned" "false" "$(scan "$(gid unused)" | jq -r '.media_usage_scan.orphaned')"
assert_eq "orphan → orphaned true" "true" "$(scan "$(gid orphan)" | jq -r '.media_usage_scan.orphaned')"
assert_eq "orphan is cleanup candidate" "true" "$(scan "$(gid orphan)" | jq -r '.media_usage_scan.cleanup_candidate')"
assert_eq "scan exposes file_exists" "false" "$(scan "$(gid orphan)" | jq -r '.media_usage_scan.file_exists')"

echo "== 3. media_usage_report — aggregate =="
REP=$(me '{"action":"media_usage_report","limit":1000}')
assert_eq "active >= 9" "true" "$(echo "$REP" | jq -r '.media_usage_report.active >= 9')"
assert_eq "indirect >= 1" "true" "$(echo "$REP" | jq -r '.media_usage_report.indirect >= 1')"
assert_eq "unused >= 2" "true" "$(echo "$REP" | jq -r '.media_usage_report.unused >= 2')"
assert_eq "orphaned >= 1" "true" "$(echo "$REP" | jq -r '.media_usage_report.orphaned >= 1')"
assert_eq "cleanup_candidates >= 2" "true" "$(echo "$REP" | jq -r '.media_usage_report.cleanup_candidates >= 2')"

echo "== 4. unused_media_find / orphaned_media_find =="
UF=$(me '{"action":"unused_media_find","limit":1000}')
assert_eq "unused_find contains the unused item" "true" "$(echo "$UF" | jq -r --argjson u "$(gid unused)" '[.unused_media_find.attachments[].media_id] | index($u) != null')"
assert_eq "unused_find does NOT contain the featured item" "true" "$(echo "$UF" | jq -r --argjson f "$(gid feat)" '[.unused_media_find.attachments[].media_id] | index($f) == null')"
OF=$(me '{"action":"orphaned_media_find","limit":1000}')
assert_eq "orphaned_find contains the orphan" "true" "$(echo "$OF" | jq -r --argjson o "$(gid orphan)" '[.orphaned_media_find.attachments[].media_id] | index($o) != null')"
assert_eq "orphaned_find does NOT contain the unused (file present) item" "true" "$(echo "$OF" | jq -r --argjson u "$(gid unused)" '[.orphaned_media_find.attachments[].media_id] | index($u) == null')"

echo "== 5. MCP parity =="
assert_eq "MCP scan status matches REST" "active" "$(memcp "$(jq -n --argjson a "$(gid feat)" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_enhance",arguments:{action:"media_usage_scan",media_id:$a}}}')" | jq -r '.media_usage_scan.status')"
assert_eq "MCP report parity" "true" "$(memcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"media_enhance","arguments":{"action":"media_usage_report","limit":1000}}}' | jq -r '.media_usage_report.active >= 9')"

echo "== 6. Structured errors =="
assert_eq "scan without media_id" "wpcc_media_not_found" "$(me '{"action":"media_usage_scan"}' | jq -r '.code // "none"')"
PAGE=$(wp eval 'echo wp_insert_post(["post_title"=>"u8 not media","post_type"=>"page","post_status"=>"draft"]);' --path="$WP_PATH" 2>/dev/null)
assert_eq "scan on non-attachment" "wpcc_media_not_found" "$(me "$(jq -n --argjson a "$PAGE" '{action:"media_usage_scan",media_id:$a}')" | jq -r '.code // "none"')"
wp eval 'wp_delete_post('"$PAGE"',true);' --path="$WP_PATH" >/dev/null 2>&1

echo "== 7. Wiring: operation_map unchanged, MCP tool + action enum =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map count still 33 (no new op)" "33" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length')"
assert_eq "media_enhance in MCP tools/list" "true" "$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":9,"method":"tools/list"}' "$WPCC_BASE/mcp" | jq -r '[.result.tools[].name] | index("media_enhance") != null')"

echo
echo "================================================"
echo "  STEP 100.8 — Media Usage Analysis"
echo "  PASS: $PASS   FAIL: $FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ]
