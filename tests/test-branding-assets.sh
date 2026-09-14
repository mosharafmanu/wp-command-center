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

EXPECTED="wpcc-admin-16.svg wpcc-admin-20.svg wpcc-logo-dark.svg wpcc-logo.svg wpcc-mark-dark.svg wpcc-mark-mono.svg wpcc-mark.svg"
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
	define( "WPCC_PLUGIN_URL", "https://example.test/wp-content/plugins/siteradian/" );
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
#
# This asserted the MECHANISM (`exactly one Brand::picture(` call) rather than the
# property it cared about, and so it defended a bug. picture() chooses its variant with
# `(prefers-color-scheme: dark)`, which reports the viewer's OS theme and says nothing
# about the surface. The shell header is permanently light — the WP admin content area
# stays light in every core colour scheme — so every admin on an OS dark theme was served
# the near-white dark-surface mark onto a light card: 1.09:1 contrast, invisible.
#
# What must actually hold is asserted instead: the header loads a real brand SVG, and it
# does NOT scheme-switch on this light surface. Stated as two positive facts, so neither
# a glyph placeholder nor a reintroduced picture() can pass.
BRANDMARK_IMG="$( grep -c 'wpcc-shell__brand-mark' includes/Admin/AppShell.php 2>/dev/null | tr -d ' ' )"
[ "$BRANDMARK_IMG" -ge 1 ] && pass "shell header keeps the brand-mark hook" || fail "shell header lost the brand-mark hook"
assert_eq "shell header loads the light-surface mark artwork" "1" \
  "$( grep -c 'Brand::mark()' includes/Admin/AppShell.php | tr -d ' ' )"
# Comments may DISCUSS picture(); only an actual call is a defect here, so match a call
# site (`Brand::picture(` followed by a newline or argument) rather than any mention.
assert_eq "shell header does not scheme-switch on a light surface" "0" \
  "$( grep -cE '^[^*]*Brand::picture\(' includes/Admin/AppShell.php | tr -d ' ' )"
assert_eq "onboarding hero does not scheme-switch on a light surface" "0" \
  "$( grep -cE '^[^*]*Brand::picture\(' includes/Admin/views/command-home.php | tr -d ' ' )"

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
  WORD="$( grep -c 'SiteRadian' "$BRAND_DIR/$f" 2>/dev/null | tr -d ' ' )"
  [ "$WORD" -ge 1 ] && pass "$f still carries the wordmark" || fail "$f lost the wordmark"
done

# The approved symbol is an engineered R: a bounded radial bowl, one command
# origin, a measured radius, and an execution leg. Its open silhouette stays
# legible at menu size without reading as refresh, speedometer, or generic radar.
RADIUS_PATH='M13.8 14.1l7.9-5.3'
BOUNDARY_PATH='M6 28V4h10.2'
for f in wpcc-mark.svg wpcc-logo.svg wpcc-mark-dark.svg wpcc-logo-dark.svg; do
  HAS="$( grep -ciF "$RADIUS_PATH" "$BRAND_DIR/$f" 2>/dev/null | tr -d ' ' )"
  [ "$HAS" -ge 1 ] && pass "$f uses the approved measured radius" \
    || fail "$f no longer uses the approved measured radius"
  BOUND="$( grep -ciF "$BOUNDARY_PATH" "$BRAND_DIR/$f" 2>/dev/null | tr -d ' ' )"
  [ "$BOUND" -ge 1 ] && pass "$f uses the approved bounded-R silhouette" \
    || fail "$f no longer uses the approved bounded-R silhouette"
done

assert_eq "mark contains one command-origin diamond" "1" \
  "$( grep -co 'm13.8 12.5 1.6 1.6-1.6 1.6-1.6-1.6z' "$BRAND_DIR/wpcc-mark.svg" | tr -d ' ' )"
assert_eq "mark has no circular-arrow arc command" "0" \
  "$( rg -c '<circle|A12\.5|a12\.5' "$BRAND_DIR/wpcc-mark.svg" || echo 0 )"
assert_eq "16px menu asset uses the bounded-R silhouette" "yes" \
  "$( rg -q -F 'M3.3 13.8V2.2h5' "$BRAND_DIR/wpcc-admin-16.svg" && echo yes || echo no )"
assert_eq "20px menu asset uses the bounded-R silhouette" "yes" \
  "$( rg -q -F 'M4 17V3h6.2' "$BRAND_DIR/wpcc-admin-20.svg" && echo yes || echo no )"

assert_eq "light mark uses the SiteRadian indigo" "1" "$( grep -ci '#4055D5' "$BRAND_DIR/wpcc-mark.svg" | tr -d ' ' )"
assert_eq "dark mark uses the accessible signal indigo" "1" "$( grep -ci '#8B9BFF' "$BRAND_DIR/wpcc-mark-dark.svg" | tr -d ' ' )"
assert_eq "monochrome mark uses currentColor" "1" "$( grep -ci 'currentColor' "$BRAND_DIR/wpcc-mark-mono.svg" | tr -d ' ' )"
assert_eq "public lockups do not append AI to the brand" "0" "$( rg -l -F 'SiteRadian AI' "$BRAND_DIR" | wc -l | tr -d ' ' )"

echo
echo "== 7. Brand asset URLs are cache-keyed to the artwork, not to the release =="

# Assets::ver() does this for CSS/JS; the SVGs were emitted as bare URLs, so a browser
# holding the old artwork kept it forever and an artwork revision reached nobody who
# had already loaded the admin. Brand::url() now appends the file mtime.
VER_URL="$( "$PHP_BIN" -r '
	define( "ABSPATH", "/" );
	define( "WPCC_PLUGIN_URL", "https://example.test/wp-content/plugins/siteradian/" );
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
	define( "WPCC_PLUGIN_URL", "https://example.test/wp-content/plugins/siteradian/" );
	require "includes/Admin/Brand.php";
	echo WPCommandCenter\Admin\Brand::logo();
' 2>/dev/null )"
assert_eq "falls back to a bare URL when the plugin dir is unknown" \
  "https://example.test/wp-content/plugins/siteradian/assets/brand/wpcc-logo.svg" "$BARE_URL"

echo
echo "== 8. WordPress.org directory assets are complete and correctly sized =="

ORG_DIR="wordpress-org-assets"
ORG_EXPECTED="banner-1544x500.png banner-772x250.png icon-128x128.png icon-256x256.png icon.svg screenshot-1.png screenshot-2.png screenshot-3.png screenshot-4.png screenshot-5.png screenshot-6.png"
ORG_ACTUAL="$( ls "$ORG_DIR" 2>/dev/null | sort | tr '\n' ' ' | sed 's/ $//' )"
assert_eq "exact directory asset set" "$ORG_EXPECTED" "$ORG_ACTUAL"

check_png_size() {
	local file="$1" expected="$2"
	local actual
	actual="$( "$PHP_BIN" -r '$s=getimagesize($argv[1]); echo $s[0]."x".$s[1];' "$ORG_DIR/$file" 2>/dev/null )"
	assert_eq "$file dimensions" "$expected" "$actual"
}
check_png_size icon-128x128.png 128x128
check_png_size icon-256x256.png 256x256
check_png_size banner-772x250.png 772x250
check_png_size banner-1544x500.png 1544x500
for n in 1 2 3 4 5 6; do check_png_size "screenshot-$n.png" 1440x1000; done

assert_eq "directory SVG uses the bounded-R mark" "yes" \
  "$( rg -q -F 'M6 28V4h10.2' "$ORG_DIR/icon.svg" && echo yes || echo no )"
assert_eq "readme has six screenshot captions" "6" \
  "$( sed -n '/^== Screenshots ==/,/^== /p' readme.txt | rg -c '^[1-6]\.' )"
assert_eq "WordPress.org assets stay outside the runtime build allowlist" "0" \
  "$( rg -c 'copy "wordpress-org-assets"|copy "design"' scripts/build-release.sh || echo 0 )"

echo
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
