#!/usr/bin/env bash
#
# STEP 100.9 — Guarded, reversible media cleanup acceptance suite.
#
# unused_media_cleanup on media_enhance: re-verifies usage at execution time,
# hard-excludes protected categories, then Snapshot -> Trash -> Verify. NEVER
# permanently deletes. DestructiveGuard (CLEANUP_MEDIA) enforced in every mode.
# Fully reversible via /media_enhance/rollback (bytes + metadata + post_status +
# post_parent + trash state).
#
# Covers: confirmation gate, exclusions (active / draft-only / revision-only /
# WooCommerce / theme asset / protected-filter), successful trash + verify,
# no-permanent-delete, rollback restore (byte-for-byte + status/parent + untrash),
# rollback idempotency, structured errors, REST + MCP parity.
#
# Requires: curl, jq, wp, wpcc-env.sh; WooCommerce active on dev.
# Usage: bash tests/test-media-enhance-cleanup-step100-9.sh

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
# cleanup with full confirmation
clean() { me "$(jq -n --argjson a "$1" '{action:"unused_media_cleanup",media_id:$a,confirm:true,confirmation_phrase:"CLEANUP_MEDIA",reason:"acceptance test"}')"; }
rawstatus() { wpe '$p=get_post('"$1"'); echo $p? $p->post_status : "GONE";'; }

IDS=""; SETUP_PHP="/tmp/wpcc-u9-setup.php"; MUDIR="$WP_PATH/wp-content/mu-plugins"; MU_PROT="$MUDIR/wpcc-u9-protect.php"
cleanup() {
  if [ -n "$IDS" ]; then
    cat > /tmp/wpcc-u9-teardown.php <<PHP
<?php
\$d = json_decode('$IDS', true);
foreach ( ['feat','draft','rev','woo','si','protected','unused'] as \$k ) { if ( ! empty( \$d[\$k] ) ) { wp_delete_attachment( (int) \$d[\$k], true ); } }
foreach ( ['fp','dp','rp','prod'] as \$k ) { if ( ! empty( \$d[\$k] ) ) { wp_delete_post( (int) \$d[\$k], true ); } }
if ( isset(\$d['prev_si']) ) { if ( \$d['prev_si'] ) { update_option('site_icon', \$d['prev_si']); } else { delete_option('site_icon'); } }
update_option('wpcc_media_enhance_rollbacks', []);
\$s = get_option('wpcc_media_file_snapshots', []); foreach ( \$s as \$r ) { ( new \WPCommandCenter\Operations\MediaSnapshot() )->delete( \$r['id'] ); }
echo 'ok';
PHP
    wp eval-file /tmp/wpcc-u9-teardown.php --path="$WP_PATH" >/dev/null 2>&1; rm -f /tmp/wpcc-u9-teardown.php
  fi
  rm -f "$SETUP_PHP" "$MU_PROT"
}
trap cleanup EXIT

echo "== 0. Protect filter mu-plugin + seed scenarios =="
mkdir -p "$MUDIR"
cat > "$MU_PROT" <<'PHP'
<?php
add_filter( 'wpcc_media_cleanup_protected', function ( $protected, $id ) {
	return $protected || false !== strpos( (string) get_the_title( $id ), 'u9-protected' );
}, 10, 2 );
PHP
cat > "$SETUP_PHP" <<'PHP'
<?php
require_once ABSPATH . 'wp-admin/includes/image.php';
function wpcc_u9_mk( $n ) {
	$up = wp_upload_dir(); $s = $up['basedir'] . "/$n.jpg";
	$im = imagecreatetruecolor( 300, 200 ); imagejpeg( $im, $s, 80 ); imagedestroy( $im );
	$a = wp_insert_attachment( [ 'post_mime_type' => 'image/jpeg', 'post_title' => $n, 'post_status' => 'inherit' ], $s );
	wp_update_attachment_metadata( $a, wp_generate_attachment_metadata( $a, $s ) );
	return $a;
}
$d = [];
$d['prev_si'] = (int) get_option( 'site_icon' );
foreach ( [ 'feat','draft','rev','woo','si','protected','unused' ] as $k ) { $d[ $k ] = wpcc_u9_mk( 'u9-' . $k ); }
// active: featured on a published post
$d['fp'] = wp_insert_post( [ 'post_title' => 'u9 feat', 'post_status' => 'publish', 'post_content' => 'x' ] ); set_post_thumbnail( $d['fp'], $d['feat'] );
// indirect: referenced only by a draft
$d['dp'] = wp_insert_post( [ 'post_title' => 'u9 draft', 'post_status' => 'draft', 'post_content' => '<img class="wp-image-' . $d['draft'] . '"/>' ] );
// indirect: referenced only in an old revision
$d['rp'] = wp_insert_post( [ 'post_title' => 'u9 rev', 'post_status' => 'publish', 'post_content' => '<img class="wp-image-' . $d['rev'] . '"/>' ] );
wp_save_post_revision( $d['rp'] );
wp_update_post( [ 'ID' => $d['rp'], 'post_content' => 'no image now' ] );
// WooCommerce product image (parent = product), otherwise unreferenced
$d['prod'] = wp_insert_post( [ 'post_title' => 'u9 prod', 'post_type' => 'product', 'post_status' => 'publish' ] ); wp_update_post( [ 'ID' => $d['woo'], 'post_parent' => $d['prod'] ] );
// theme asset
update_option( 'site_icon', $d['si'] );
echo wp_json_encode( $d );
PHP
IDS=$(wp eval-file "$SETUP_PHP" --path="$WP_PATH" 2>/dev/null | tail -1)
assert_nonempty "setup produced ids" "$IDS"
gid() { echo "$IDS" | jq -r ".$1"; }

echo "== 1. DestructiveGuard confirmation gate =="
NC=$(me "$(jq -n --argjson a "$(gid unused)" '{action:"unused_media_cleanup",media_id:$a}')")
assert_eq "no confirmation → confirmation_required" "confirmation_required" "$(echo "$NC" | jq -r '.status')"
assert_eq "still inherit (not trashed) after gate" "inherit" "$(rawstatus "$(gid unused)")"
WP=$(me "$(jq -n --argjson a "$(gid unused)" '{action:"unused_media_cleanup",media_id:$a,confirm:true,confirmation_phrase:"WRONG",reason:"x"}')")
assert_eq "wrong phrase → confirmation_required" "confirmation_required" "$(echo "$WP" | jq -r '.status')"

echo "== 2. Hard-exclusions (each refused; nothing trashed) =="
assert_eq "active (featured) refused" "wpcc_media_cleanup_refused" "$(clean "$(gid feat)" | jq -r '.code // "none"')"
assert_eq "draft-only (indirect) refused" "wpcc_media_cleanup_refused" "$(clean "$(gid draft)" | jq -r '.code // "none"')"
assert_eq "revision-only (indirect) refused" "wpcc_media_cleanup_refused" "$(clean "$(gid rev)" | jq -r '.code // "none"')"
assert_eq "WooCommerce product image refused" "wpcc_media_cleanup_refused" "$(clean "$(gid woo)" | jq -r '.code // "none"')"
assert_eq "theme asset (site_icon) refused" "wpcc_media_cleanup_refused" "$(clean "$(gid si)" | jq -r '.code // "none"')"
assert_eq "protected-filter item refused" "wpcc_media_cleanup_refused" "$(clean "$(gid protected)" | jq -r '.code // "none"')"
assert_eq "excluded items remain untouched (rev still inherit)" "inherit" "$(rawstatus "$(gid rev)")"

echo "== 3. Successful cleanup: Snapshot → Trash → Verify (never permanent) =="
MD5_BEFORE=$(wpe 'echo md5_file(get_attached_file('"$(gid unused)"'));')
CL=$(clean "$(gid unused)")
assert_eq "action trashed" "trashed" "$(echo "$CL" | jq -r '.unused_media_cleanup.action')"
assert_eq "reversible true" "true" "$(echo "$CL" | jq -r '.unused_media_cleanup.reversible')"
assert_eq "permanently_deleted false" "false" "$(echo "$CL" | jq -r '.unused_media_cleanup.permanently_deleted')"
assert_eq "verified true" "true" "$(echo "$CL" | jq -r '.unused_media_cleanup.verified')"
RID=$(echo "$CL" | jq -r '.unused_media_cleanup.rollback_id')
assert_nonempty "rollback_id returned" "$RID"
assert_eq "DB post_status now trash" "trash" "$(rawstatus "$(gid unused)")"
assert_eq "file still on disk (NOT permanently deleted)" "yes" "$(wpe 'echo is_file(get_attached_file('"$(gid unused)"'))?"yes":"no";')"
assert_eq "attachment row still exists" "attachment" "$(wpe '$p=get_post('"$(gid unused)"'); echo $p?$p->post_type:"GONE";')"

echo "== 4. Rollback restores bytes + status + parent + trash state =="
RB=$(mrb "$(jq -n --arg r "$RID" '{rollback_id:$r}')")
assert_eq "rollback verified" "true" "$(echo "$RB" | jq -r '.verified')"
assert_eq "rollback mode cleanup" "unused_media_cleanup" "$(echo "$RB" | jq -r '.mode')"
assert_eq "post_status restored to inherit" "inherit" "$(rawstatus "$(gid unused)")"
assert_eq "trash meta cleared" "yes" "$(wpe 'echo get_post_meta('"$(gid unused)"',"_wp_trash_meta_status",true)===""?"yes":"no";')"
assert_eq "file bytes byte-for-byte" "$MD5_BEFORE" "$(wpe 'echo md5_file(get_attached_file('"$(gid unused)"'));')"

echo "== 5. Rollback idempotency =="
assert_eq "second rollback → already applied" "wpcc_rollback_already_applied" "$(mrb "$(jq -n --arg r "$RID" '{rollback_id:$r}')" | jq -r '.code // "none"')"

echo "== 6. MCP parity (cleanup + rollback) =="
CLM=$(memcp "$(jq -n --argjson a "$(gid unused)" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"media_enhance",arguments:{action:"unused_media_cleanup",media_id:$a,confirm:true,confirmation_phrase:"CLEANUP_MEDIA",reason:"mcp test"}}}')")
assert_eq "MCP cleanup trashed" "trashed" "$(echo "$CLM" | jq -r '.unused_media_cleanup.action')"
assert_eq "MCP DB status trash" "trash" "$(rawstatus "$(gid unused)")"
mrb "$(jq -n --arg r "$(echo "$CLM" | jq -r '.unused_media_cleanup.rollback_id')" '{rollback_id:$r}')" >/dev/null
assert_eq "restored after MCP rollback" "inherit" "$(rawstatus "$(gid unused)")"

echo "== 7. Structured errors =="
PAGE=$(wpe 'echo wp_insert_post(["post_title"=>"u9 not media","post_type"=>"page","post_status"=>"draft"]);')
assert_eq "cleanup (confirmed) on non-attachment → media not found" "wpcc_media_not_found" "$(me "$(jq -n --argjson a "$PAGE" '{action:"unused_media_cleanup",media_id:$a,confirm:true,confirmation_phrase:"CLEANUP_MEDIA",reason:"x"}')" | jq -r '.code // "none"')"
wpe 'wp_delete_post('"$PAGE"',true);'

echo "== 8. Wiring: operation_map unchanged, MCP tool present =="
MANIFEST=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/agent/manifest")
assert_eq "operation_map count 34 (this step adds no new op; 104.2 change_history)" "34" "$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length')"
assert_eq "media_enhance in MCP tools/list" "true" "$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","id":9,"method":"tools/list"}' "$WPCC_BASE/mcp" | jq -r '[.result.tools[].name] | index("media_enhance") != null')"

echo
echo "================================================"
echo "  STEP 100.9 — Guarded Reversible Media Cleanup"
echo "  PASS: $PASS   FAIL: $FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ]
