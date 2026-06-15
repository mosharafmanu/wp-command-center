#!/usr/bin/env bash
#
# Acceptance — MediaUsageResolver legacy Gutenberg gallery `ids` fix.
#
# Reproduces the STEP-100 validation false positive: a `core/gallery` block
# serialized in the legacy flat format ( <!-- wp:gallery {"ids":[A,B]} --> with no
# inner wp:image / wp-image-N / src markup ) was classified `unused` because the
# channel-4 SQL pre-gate matched only `wp-image-N` / `"id":N` / basename — so the
# block parser (which handles attrs.ids) never ran. Each gallery image was therefore
# a false cleanup candidate.
#
# Verifies after the fix:
#   1. legacy ids-only gallery image    → active  (THE BUG — was unused)
#   2. legacy ids array, middle element → active  (position coverage)
#   3. modern nested gallery image      → active  (no regression)
#   4. single core/image block          → active  (no regression)
#   5. classic wp-image-N content       → active  (no regression)
#   6. unrelated numeric array, no block→ unused  (gate: loose match ≠ reference)
#   7. legacy gallery only in a revision→ indirect (protected, not unused)
#
# Requires: curl, jq, wp, wpcc-env.sh. Usage: bash tests/test-media-usage-gallery-ids-fix.sh

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

me()   { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/media_enhance/run"; }
status() { me "$(jq -n --argjson a "$1" '{action:"media_usage_scan",media_id:$a}')" | jq -r '.media_usage_scan.status'; }
source_of() { me "$(jq -n --argjson a "$1" '{action:"media_usage_scan",media_id:$a}')" | jq -rc '.media_usage_scan.by_source'; }

SETUP_PHP="/tmp/wpcc-galids-setup.php"; TEARDOWN_PHP="/tmp/wpcc-galids-teardown.php"; IDS=""
cleanup() {
  if [ -n "$IDS" ]; then
    cat > "$TEARDOWN_PHP" <<PHP
<?php
\$d = json_decode('$IDS', true);
foreach ( ['legacy1','legacy2','mid','modern1','modern2','single','classic','arr','rev'] as \$k ) { if ( ! empty( \$d[\$k] ) ) { wp_delete_attachment( (int) \$d[\$k], true ); } }
foreach ( ['p_legacy','p_modern','p_single','p_classic','p_arr','p_rev','rev_id'] as \$k ) { if ( ! empty( \$d[\$k] ) ) { wp_delete_post( (int) \$d[\$k], true ); } }
echo 'ok';
PHP
    wp eval-file "$TEARDOWN_PHP" --path="$WP_PATH" >/dev/null 2>&1
    rm -f "$TEARDOWN_PHP"
  fi
  rm -f "$SETUP_PHP"
}
trap cleanup EXIT

echo "== 0. Seed gallery scenarios =="
cat > "$SETUP_PHP" <<'PHP'
<?php
require_once ABSPATH . 'wp-admin/includes/image.php';
function galids_mk( $n ) {
	$up = wp_upload_dir(); $s = $up['basedir'] . "/$n.jpg";
	$im = imagecreatetruecolor( 400, 300 ); imagejpeg( $im, $s, 80 ); imagedestroy( $im );
	$a = wp_insert_attachment( [ 'post_mime_type' => 'image/jpeg', 'post_title' => $n, 'post_status' => 'inherit' ], $s );
	wp_update_attachment_metadata( $a, wp_generate_attachment_metadata( $a, $s ) );
	return $a;
}
$d = [];
foreach ( [ 'legacy1','legacy2','mid','modern1','modern2','single','classic','arr','rev' ] as $k ) { $d[ $k ] = galids_mk( 'wpcc-galids-' . $k ); }

// 1+2. Legacy flat gallery: ids array only, NO inner image markup. legacy1 first,
// legacy2 last, mid in the middle of a 3-id array.
$legacy = '<!-- wp:gallery {"ids":[' . $d['legacy1'] . ',' . $d['mid'] . ',' . $d['legacy2'] . '],"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-default is-cropped"></figure><!-- /wp:gallery -->';
$d['p_legacy'] = wp_insert_post( [ 'post_title' => 'galids legacy', 'post_status' => 'publish', 'post_content' => $legacy ] );

// 3. Modern nested gallery: each image as an inner image block (wp-image-N present).
$u1 = wp_get_attachment_url( $d['modern1'] ); $u2 = wp_get_attachment_url( $d['modern2'] );
$modern = '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery">'
	. '<!-- wp:image {"id":' . $d['modern1'] . '} --><figure class="wp-block-image"><img src="' . $u1 . '" class="wp-image-' . $d['modern1'] . '"/></figure><!-- /wp:image -->'
	. '<!-- wp:image {"id":' . $d['modern2'] . '} --><figure class="wp-block-image"><img src="' . $u2 . '" class="wp-image-' . $d['modern2'] . '"/></figure><!-- /wp:image -->'
	. '</figure><!-- /wp:gallery -->';
$d['p_modern'] = wp_insert_post( [ 'post_title' => 'galids modern', 'post_status' => 'publish', 'post_content' => $modern ] );

// 4. Single core/image block.
$d['p_single'] = wp_insert_post( [ 'post_title' => 'galids single', 'post_status' => 'publish', 'post_content' => '<!-- wp:image {"id":' . $d['single'] . '} --><figure class="wp-block-image"><img class="wp-image-' . $d['single'] . '"/></figure><!-- /wp:image -->' ] );

// 5. Classic wp-image content.
$d['p_classic'] = wp_insert_post( [ 'post_title' => 'galids classic', 'post_status' => 'publish', 'post_content' => '<p>x</p><img class="wp-image-' . $d['classic'] . '"/>' ] );

// 6. Unrelated numeric array containing arr's id, NOT inside a block and no basename
//    — must NOT count as a reference (the genuine-reference gate).
$d['p_arr'] = wp_insert_post( [ 'post_title' => 'galids arr', 'post_status' => 'publish', 'post_content' => '<p>random tuple [' . $d['arr'] . ',7,9] not a gallery</p>' ] );

// 7. Legacy gallery referencing rev ONLY inside a revision row (parent has clean content).
$d['p_rev'] = wp_insert_post( [ 'post_title' => 'galids rev parent', 'post_status' => 'publish', 'post_content' => '<p>clean</p>' ] );
$d['rev_id'] = wp_insert_post( [ 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $d['p_rev'], 'post_name' => $d['p_rev'] . '-revision-v1', 'post_title' => 'galids rev', 'post_content' => '<!-- wp:gallery {"ids":[' . $d['rev'] . ']} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->' ] );

echo wp_json_encode( $d );
PHP
IDS=$(wp eval-file "$SETUP_PHP" --path="$WP_PATH" 2>/dev/null | tail -1)
[ -n "$IDS" ] && [ "$IDS" != "null" ] && pass "setup produced ids" || { fail "setup produced ids"; echo "RESULT: $PASS passed, $FAIL failed"; exit 1; }
gid() { echo "$IDS" | jq -r ".$1"; }

echo "== 1. Legacy ids-only gallery — THE BUG (was unused) =="
assert_eq "legacy first element → active"  "active" "$(status "$(gid legacy1)")"
assert_eq "legacy last element → active"   "active" "$(status "$(gid legacy2)")"
assert_eq "legacy first labeled block"     "true"   "$(source_of "$(gid legacy1)" | jq -r '.block != null')"

echo "== 2. Legacy ids array — middle element =="
assert_eq "legacy middle element → active" "active" "$(status "$(gid mid)")"

echo "== 3. Modern nested gallery — no regression =="
assert_eq "modern image 1 → active" "active" "$(status "$(gid modern1)")"
assert_eq "modern image 2 → active" "active" "$(status "$(gid modern2)")"
assert_eq "modern labeled block"    "true"   "$(source_of "$(gid modern1)" | jq -r '.block != null')"

echo "== 4. Single image block — no regression =="
assert_eq "single image block → active" "active" "$(status "$(gid single)")"

echo "== 5. Classic wp-image content — no regression =="
assert_eq "classic content → active" "active" "$(status "$(gid classic)")"
assert_eq "classic labeled content"  "true"   "$(source_of "$(gid classic)" | jq -r '.content != null')"

echo "== 6. Gate — unrelated numeric array is NOT a reference =="
assert_eq "loose array match stays unused" "unused" "$(status "$(gid arr)")"

echo "== 7. Legacy gallery only in a revision → indirect (protected) =="
assert_eq "revision-only legacy gallery → indirect" "indirect" "$(status "$(gid rev)")"
assert_eq "revision source present" "true" "$(source_of "$(gid rev)" | jq -r '.revision != null')"

echo ""
echo "================================================"
echo "  Media Usage — legacy gallery ids fix"
echo "  PASS: $PASS   FAIL: $FAIL"
echo "================================================"
[ "$FAIL" -eq 0 ]
