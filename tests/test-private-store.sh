#!/usr/bin/env bash
#
# Sensitive artifacts under uploads must not be retrievable over HTTP on ANY server.
#
# Found during the pre-submission security audit: every WPCC store under
# wp-content/uploads was protected by a `.htaccess` carrying `Require all denied`
# plus an `index.php`. Both are Apache-only. Served through nginx — verified with a
# real nginx instance, not reasoned about — `wpcc-tokens/manifest.json`,
# `wpcc-patches/manifest.json`, `wpcc-snapshots/manifest.json` and
# `wpcc-audit/audit.log` all returned HTTP 200, and the manifests hand over every
# snapshot and patch UUID, so the rest of the store followed. `wpcc-media-snapshots`
# had no `.htaccess` at all and was fetchable even on Apache.
#
# The fix is Security\PrivateStore: each store directory carries a per-install
# random suffix, so no path in it can be named by anyone who has not already read
# the database or the filesystem. That does not depend on the web server honouring
# anything. This suite locks the properties that make it true.
#
# Requires: WP_ROOT (or WP_PATH). HTTP assertions run only when WPCC_BASE is set.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

WP="${WP_ROOT:-${WP_PATH:-}}"
if [ -z "$WP" ] || [ ! -f "$WP/wp-load.php" ]; then
  echo "private store: SKIP (WP_ROOT/WP_PATH not set to a WordPress install)"
  exit 0
fi

wpe() { wp eval "$1" --path="$WP" --skip-themes 2>/dev/null; }

echo "private store (uploads must not be web-retrievable on any server)"

UPLOADS=$(wpe '$u = wp_upload_dir(); echo $u["basedir"];')
STORES="wpcc-tokens wpcc-audit wpcc-snapshots wpcc-media-snapshots wpcc-patches wpcc-plugin-backups"

# ── 1. The suffix is a real secret, stable within an install ────────────────────
SUFFIX=$(wpe 'echo \WPCommandCenter\Security\PrivateStore::suffix();')
if echo "$SUFFIX" | grep -qE '^[a-f0-9]{32}$'; then
  pass "suffix is 32 hex characters (128 bits)"
else
  fail "suffix is not 32 hex characters (got ${#SUFFIX} chars)"
fi

SUFFIX2=$(wpe 'echo \WPCommandCenter\Security\PrivateStore::suffix();')
[ "$SUFFIX" = "$SUFFIX2" ] \
  && pass "suffix is stable across requests" \
  || fail "suffix changed between requests — every store would be orphaned"

# ── 2. Every store resolves to a suffixed path, never the guessable one ─────────
for store in $STORES; do
  P=$(wpe "echo \\WPCommandCenter\\Security\\PrivateStore::path( '$store' );")
  case "$P" in
    */$store-$SUFFIX) pass "$store resolves to a suffixed directory" ;;
    *)                fail "$store resolved to '$P' (expected $store-$SUFFIX)" ;;
  esac
done

# ── 3. No unsuffixed store directory is left behind ─────────────────────────────
LEFTOVER=""
for store in $STORES; do
  [ -d "$UPLOADS/$store" ] && LEFTOVER="$LEFTOVER $store"
done
[ -z "$LEFTOVER" ] \
  && pass "no legacy unsuffixed store directory remains" \
  || fail "legacy directories still present:$LEFTOVER"

# ── 4. Hardening markers exist (defence in depth, not the mechanism) ────────────
for store in $STORES; do
  D="$UPLOADS/$store-$SUFFIX"
  [ -d "$D" ] || continue
  missing=""
  for marker in .htaccess index.php index.html web.config; do
    [ -f "$D/$marker" ] || missing="$missing $marker"
  done
  [ -z "$missing" ] \
    && pass "$store carries all four server markers" \
    || fail "$store is missing:$missing"
done

# ── 5. A legacy directory is migrated, not abandoned ────────────────────────────
LEGACY="$UPLOADS/wpcc-patches"
CANARY="migration-canary-$$.json"
mkdir -p "$LEGACY" && echo '{"canary":true}' > "$LEGACY/$CANARY"
wpe "\\WPCommandCenter\\Security\\PrivateStore::path( 'wpcc-patches' );" >/dev/null
if [ -f "$UPLOADS/wpcc-patches-$SUFFIX/$CANARY" ] && [ ! -d "$LEGACY" ]; then
  pass "legacy directory contents migrate to the suffixed store"
  rm -f "$UPLOADS/wpcc-patches-$SUFFIX/$CANARY"
else
  fail "legacy directory was not migrated (canary not found in suffixed store)"
  rm -rf "$LEGACY"
fi

# ── 5b. A stale duplicate is discarded, not treated as un-migratable ────────────
DUP="$UPLOADS/wpcc-patches-$SUFFIX/dup-canary-$$.json"
echo '{"live":true}' > "$DUP"
mkdir -p "$LEGACY" && echo '{"stale":true}' > "$LEGACY/$(basename "$DUP")"
wpe "\\WPCommandCenter\\Security\\PrivateStore::path( 'wpcc-patches' );" >/dev/null
if [ ! -d "$LEGACY" ] && grep -q '"live"' "$DUP" 2>/dev/null; then
  pass "stale duplicate discarded; the live copy wins"
else
  fail "duplicate handling wrong (legacy dir left behind, or live copy overwritten)"
  rm -rf "$LEGACY"
fi
rm -f "$DUP"

# ── 5c. A migration the host refuses must not empty the store ───────────────────
# The failure that would turn hardening into an outage: rename() denied, a new
# empty store created, every access token invalidated. path() must keep serving
# the legacy directory instead.
STUCK_DIR="$UPLOADS/wpcc-plugin-backups"
STUCK_NEW="$UPLOADS/wpcc-plugin-backups-$SUFFIX"
STUCK_FILE="stuck-canary-$$.bin"
# The suffixed store must already exist, so the whole-directory rename is not
# taken; then make the legacy directory itself unwritable, which is what denies
# moving a file OUT of it.
mkdir -p "$STUCK_NEW"
mkdir -p "$STUCK_DIR" && echo 'payload' > "$STUCK_DIR/$STUCK_FILE"
chmod 500 "$STUCK_DIR"
STUCK_PATH=$(wpe "echo \\WPCommandCenter\\Security\\PrivateStore::path( 'wpcc-plugin-backups' );")
chmod 700 "$STUCK_DIR" 2>/dev/null
if [ "$STUCK_PATH" = "$STUCK_DIR" ] && [ -f "$STUCK_DIR/$STUCK_FILE" ]; then
  pass "refused migration falls back to the legacy store, data intact"
else
  fail "refused migration returned '$STUCK_PATH' — data would have been stranded"
fi
rm -rf "$STUCK_DIR"
# Leave the store exactly as a normal install would have it.
chmod 700 "$STUCK_NEW" 2>/dev/null
wpe "\\WPCommandCenter\\Security\\PrivateStore::harden( '$STUCK_NEW' );" >/dev/null

# ── 6. Losing the option must not orphan the data ───────────────────────────────
RECOVERED=$(wpe '
  delete_option( "wpcc_storage_suffix" );
  $r = new ReflectionClass( \WPCommandCenter\Security\PrivateStore::class );
  $p = $r->getProperty( "suffix" ); $p->setAccessible( true ); $p->setValue( null, "" );
  echo \WPCommandCenter\Security\PrivateStore::suffix();
')
[ "$RECOVERED" = "$SUFFIX" ] \
  && pass "suffix recovers from disk when the option is lost" \
  || fail "suffix did not recover (got '$RECOVERED', expected the original)"

# ── 6b. The upgrade path moves every store, not just the one being used ─────────
# Lazy resolution alone is not enough: a site that upgrades and never creates
# another patch would leave its existing patches at the guessable path forever.
RELOCATED=$(wpe 'echo \WPCommandCenter\Security\PrivateStore::relocate_all();')
[ "$RELOCATED" = "6" ] \
  && pass "relocate_all() resolves all six stores" \
  || fail "relocate_all() resolved $RELOCATED of 6 stores"

grep -q "maybe_relocate_private_stores" "$PLUGIN_DIR/includes/Core/Schema.php" \
  && pass "upgrade path runs the relocation (Schema::maybe_upgrade)" \
  || fail "nothing relocates the stores on upgrade — 1.0.0 sites would stay exposed"

grep -q "PrivateStore::relocate_all" "$PLUGIN_DIR/includes/Core/Activator.php" \
  && pass "activation runs the relocation" \
  || fail "activation does not relocate the stores"

# The flag must only be set once the move really happened.
if grep -A12 "function maybe_relocate_private_stores" "$PLUGIN_DIR/includes/Core/Schema.php" \
   | grep -q "relocate_all() === count"; then
  pass "relocation flag is set only when every store landed"
else
  fail "relocation flag could be set after a partial migration"
fi

# ── 7. uninstall.php must remove suffixed stores, not just legacy names ─────────
grep -q "glob( \$base . \$dir_name . '-\*', GLOB_ONLYDIR )" "$PLUGIN_DIR/uninstall.php" \
  && pass "uninstall.php globs suffixed store directories" \
  || fail "uninstall.php would leave the real (suffixed) stores on disk"

# ── 8. Nothing builds a public URL into a store ─────────────────────────────────
if grep -rn "baseurl" --include="*.php" "$PLUGIN_DIR/includes" | grep -qE "wpcc-(tokens|audit|snapshots|patches|plugin-backups|media-snapshots)"; then
  fail "a store path is being turned into a public URL"
else
  pass "no code builds a public URL into a store"
fi

# ── 9. The old predictable URLs must not resolve ────────────────────────────────
if [ -n "${WPCC_BASE:-}" ]; then
  SITE="${WPCC_BASE%/wp-json/*}"
  for path in \
    "wp-content/uploads/wpcc-tokens/manifest.json" \
    "wp-content/uploads/wpcc-patches/manifest.json" \
    "wp-content/uploads/wpcc-snapshots/manifest.json" \
    "wp-content/uploads/wpcc-audit/audit.log"
  do
    CODE=$(curl -s -o /dev/null -w '%{http_code}' -m 15 "$SITE/$path")
    [ "$CODE" = "200" ] \
      && fail "still fetchable over HTTP: /$path (HTTP $CODE)" \
      || pass "not fetchable over HTTP: /$path (HTTP $CODE)"
  done
else
  echo "  note: WPCC_BASE unset — HTTP assertions skipped"
fi

echo "private store: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
