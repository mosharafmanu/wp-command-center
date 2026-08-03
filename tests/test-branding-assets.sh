#!/usr/bin/env bash
#
# Branding assets — identity integrity contract.
#
# The official identity standard is explicit that the mark must never be reconstructed
# from CSS borders or font glyphs, and that every shipped brand asset is real SVG
# artwork. This suite holds that line, and holds the one place where the artwork is
# necessarily duplicated: the admin menu icon is inlined in Brand::menu_icon() as a
# base64 data URI (WordPress requires a data URI there, and reading the file on every
# admin page load to rebuild a fixed 364-byte string would be waste). The inlined copy
# and assets/brand/wpcc-admin-20.svg must therefore never drift apart.
#
# Requires: nothing but the checkout.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PLUGIN_DIR"

PHP_BIN="$( command -v php || echo /Applications/AMPPS/apps/php82/bin/php )"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

echo "Branding — identity integrity"
echo
echo "== 1. Every shipped brand asset exists and is real SVG =="

BRAND_DIR="assets/brand"
assert_eq "brand directory ships" "yes" "$( [ -d "$BRAND_DIR" ] && echo yes || echo no )"

EXPECTED="wpcc-admin-16.svg wpcc-admin-20.svg wpcc-logo-dark.svg wpcc-logo.svg wpcc-mark-dark.svg wpcc-mark.svg"
ACTUAL="$( ls "$BRAND_DIR" 2>/dev/null | sort | tr '\n' ' ' | sed 's/ $//' )"
assert_eq "exactly the expected assets ship (no strays, none missing)" "$EXPECTED" "$ACTUAL"

for f in $EXPECTED; do
  head1="$( head -c 4 "$BRAND_DIR/$f" 2>/dev/null )"
  assert_eq "$f is SVG" "<svg" "$head1"
done

echo
echo "== 2. No brand asset ships unreferenced, and no reference is missing its asset =="

for f in $EXPECTED; do
  refs="$( grep -rlF "$f" includes/ assets/js/ assets/css/ 2>/dev/null | wc -l | tr -d ' ' )"
  [ "$refs" -gt 0 ] && pass "$f is referenced by shipped code" \
    || fail "$f ships but nothing references it"
done

echo
echo "== 3. The inlined admin menu icon has not drifted from its source asset =="

# Compare what Brand::menu_icon() ACTUALLY emits against the source asset, rather than
# trying to re-parse the PHP string literal with sed. The class is loaded standalone with
# only the two constants it touches, so this asserts the shipped output, not our reading
# of the source.
DECODED="$( "$PHP_BIN" -r '
	define( "ABSPATH", "/" );
	define( "WPCC_PLUGIN_URL", "https://example.test/wp-content/plugins/ai-command-center/" );
	require "includes/Admin/Brand.php";
	$uri = WPCommandCenter\Admin\Brand::menu_icon();
	$prefix = "data:image/svg+xml;base64,";
	if ( strpos( $uri, $prefix ) !== 0 ) { echo "NOT_A_DATA_URI"; exit; }
	echo base64_decode( substr( $uri, strlen( $prefix ) ) );
' 2>/dev/null )"

SRC="$( cat "$BRAND_DIR/wpcc-admin-20.svg" )"
assert_eq "inlined menu icon matches assets/brand/wpcc-admin-20.svg byte for byte" "$SRC" "$DECODED"

echo
echo "== 4. The mark is never reconstructed from glyphs or CSS borders =="

# The trigram/box-drawing placeholders the mark used to be built from.
GLYPHS="$( grep -rn '&#9783;\|&#9781;\|&#9776;' includes/ assets/ 2>/dev/null | grep -v '^\S*:.*\*' | wc -l | tr -d ' ' )"
assert_eq "no Unicode glyph stands in for the mark" "0" "$GLYPHS"

# The shell header must load real artwork, not a filled container with a character in it.
BRANDMARK_IMG="$( grep -c 'wpcc-shell__brand-mark' includes/Admin/AppShell.php 2>/dev/null | tr -d ' ' )"
assert_eq "shell header renders the mark through Brand::picture()" "1" \
  "$( grep -c 'Brand::picture(' includes/Admin/AppShell.php | tr -d ' ' )"
[ "$BRANDMARK_IMG" -ge 1 ] && pass "shell header keeps the brand-mark hook" || fail "shell header lost the brand-mark hook"

echo
echo "== 5. The admin menu icon is a data URI (WordPress's supported SVG path) =="
assert_eq "menu icon is returned as a base64 SVG data URI" "1" \
  "$( grep -c "return 'data:image/svg+xml;base64,' . base64_encode" includes/Admin/Brand.php | tr -d ' ' )"
assert_eq "menu page registers the brand icon, not a dashicon" "1" \
  "$( grep -c "Brand::menu_icon()" includes/Admin/AdminMenu.php | tr -d ' ' )"
assert_eq "no dashicons-shield-alt left on the menu" "0" \
  "$( grep -c "dashicons-shield-alt" includes/Admin/AdminMenu.php | tr -d ' ' )"

echo
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
