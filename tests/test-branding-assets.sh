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
echo "== 6. UI lockups are symbol + wordmark only =="

# The lockup used to carry "AI-POWERED WORDPRESS OPERATIONS PLATFORM" set at 10.5px
# inside a 456x72 viewBox. The tagline was what made the artwork 456 wide, so every
# surface that rendered the lockup at a sane size scaled the whole thing down and the
# tagline arrived at ~5.7px — illegible at any real viewing size — while dragging the
# product name down to 12px, smaller than the body text beneath it. It is not coming
# back into a UI lockup: a tagline that only works at 456px does not work.
for f in wpcc-logo.svg wpcc-logo-dark.svg; do
  TAG="$( grep -c 'AI-POWERED\|AI-powered' "$BRAND_DIR/$f" 2>/dev/null | tr -d ' ' )"
  assert_eq "$f carries no tagline" "0" "$TAG"
  # The wordmark itself must still be there — this is a lockup, not a bare mark.
  WORD="$( grep -c 'WP Command Center' "$BRAND_DIR/$f" 2>/dev/null | tr -d ' ' )"
  [ "$WORD" -ge 1 ] && pass "$f still carries the wordmark" || fail "$f lost the wordmark"
done

# The approved symbol is untouched by that edit: the lockups must still use the exact
# command-block path and the Execute Blue core from the master mark.
BLOCK_PATH='M4 2h5.25c.41 0 .75.34.75.75v1.5c0 .41-.34.75-.75.75H5v4.25c0 .41-.34.75-.75.75h-1.5A.75.75 0 0 1 2 9.25V4a2 2 0 0 1 2-2Z'
for f in wpcc-mark.svg wpcc-logo.svg wpcc-mark-dark.svg wpcc-logo-dark.svg; do
  HAS="$( grep -cF "$BLOCK_PATH" "$BRAND_DIR/$f" 2>/dev/null | tr -d ' ' )"
  [ "$HAS" -ge 1 ] && pass "$f uses the approved command-block geometry" \
    || fail "$f no longer uses the approved command-block geometry"
done

echo
echo "== 7. Brand asset URLs are cache-keyed to the artwork, not to the release =="

# Assets::ver() does this for CSS/JS; the SVGs were emitted as bare URLs, so a browser
# holding the old artwork kept it forever and an artwork revision reached nobody who
# had already loaded the admin. Brand::url() now appends the file mtime.
VER_URL="$( "$PHP_BIN" -r '
	define( "ABSPATH", "/" );
	define( "WPCC_PLUGIN_URL", "https://example.test/wp-content/plugins/ai-command-center/" );
	define( "WPCC_PLUGIN_DIR", getcwd() . "/" );
	define( "WPCC_VERSION", "9.9.9" );
	require "includes/Admin/Brand.php";
	echo WPCommandCenter\Admin\Brand::logo();
' 2>/dev/null )"
case "$VER_URL" in
  *"assets/brand/wpcc-logo.svg?ver=9.9.9."*) pass "Brand::url() cache-keys the asset to its own mtime" ;;
  *) fail "Brand::url() emitted an unversioned URL ($VER_URL)" ;;
esac

# Loadable standalone with only ABSPATH + WPCC_PLUGIN_URL (section 3 depends on this):
# a missing WPCC_PLUGIN_DIR/WPCC_VERSION must degrade to the bare URL, never fatal.
BARE_URL="$( "$PHP_BIN" -r '
	define( "ABSPATH", "/" );
	define( "WPCC_PLUGIN_URL", "https://example.test/wp-content/plugins/ai-command-center/" );
	require "includes/Admin/Brand.php";
	echo WPCommandCenter\Admin\Brand::logo();
' 2>/dev/null )"
assert_eq "falls back to a bare URL when the plugin dir is unknown" \
  "https://example.test/wp-content/plugins/ai-command-center/assets/brand/wpcc-logo.svg" "$BARE_URL"

echo
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
