#!/usr/bin/env bash
#
# Build the WordPress.org release package.
#
# ALLOWLIST, not blocklist. A blocklist ships whatever someone forgot to add to
# it — which is how development docs, test suites and local environment notes end
# up in a public plugin. This copies only the files the plugin needs to run, so a
# new directory in the repo is excluded by default rather than included by
# default.
#
# Usage:  ./scripts/build-release.sh [output-dir]
# Output: <output-dir>/wp-command-center-<version>.zip  (default: ./build)

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT_DIR="${1:-$ROOT/build}"
SLUG="wp-command-center"

# Single source of truth for the version: the plugin header.
VERSION="$( grep -m1 '^ \* Version:' "$ROOT/$SLUG.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]' )"
if [[ -z "$VERSION" ]]; then
  echo "ERROR: could not read Version from $SLUG.php" >&2
  exit 1
fi

# The readme's Stable tag must match the plugin header, or WordPress.org serves
# the wrong version to users. Catch it here rather than after submission.
STABLE="$( grep -m1 '^Stable tag:' "$ROOT/readme.txt" | sed -E 's/.*Stable tag:[[:space:]]*//' | tr -d '[:space:]' )"
if [[ "$VERSION" != "$STABLE" ]]; then
  echo "ERROR: version mismatch — plugin header '$VERSION' vs readme Stable tag '$STABLE'" >&2
  exit 1
fi

STAGE="$( mktemp -d )"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

# ── The allowlist: everything the plugin needs at runtime, and nothing else ──
copy() {
  local item="$1"
  if [[ -e "$ROOT/$item" ]]; then
    cp -R "$ROOT/$item" "$DEST/"
  fi
}

copy "$SLUG.php"
copy "uninstall.php"
copy "readme.txt"
copy "LICENSE"
copy "includes"
copy "assets"
copy "languages"

# The MCP relay is not optional. The configuration this plugin generates for
# Claude Desktop, Cursor, Codex and the rest tells the user's machine to fetch
# this exact file from their own site and run it — see
# Integration\BaseClientIntegration::relay_url(). If it is missing from the
# package the fetch 404s, the connector never starts, and every connection
# fails. The unreferenced sdk/ samples stay out; only the runtime file ships.
# Copied leniently so a missing source file is reported by the explicit
# verification below, with a message that says what to do about it, rather than
# aborting here on a bare "cp: No such file or directory".
if [[ -f "$ROOT/sdk/javascript/wpcc-mcp-relay.mjs" ]]; then
  mkdir -p "$DEST/sdk/javascript"
  cp "$ROOT/sdk/javascript/wpcc-mcp-relay.mjs" "$DEST/sdk/javascript/"
fi

# ── Scrub anything that rode along inside a copied directory ────────────────
find "$DEST" \( -name '.DS_Store' -o -name 'Thumbs.db' -o -name '*.map' \
  -o -name '*.local.php' -o -name '*.local.json' \) -delete
find "$DEST" -type d \( -name '.git' -o -name 'node_modules' -o -name 'tests' \) \
  -prune -exec rm -rf {} +

# ── Verify the package before declaring success ─────────────────────────────
fail=0

# No development material.
while IFS= read -r stray; do
  echo "ERROR: development file in package: ${stray#$DEST/}" >&2
  fail=1
done < <( find "$DEST" -type f \( -name '*.md' -o -name 'composer.json' \
  -o -name 'openapi.json' -o -name '*.sh' -o -name 'phpcs.xml*' \) )

# No developer machine paths left in shipped code. These are always a mistake.
if grep -rIlE '/Users/|/home/[a-z]+/|AMPPS|MAMP|XAMPP|wp-content/plugins/wp-command-center/' "$DEST" >/dev/null 2>&1; then
  echo "ERROR: developer filesystem paths found in package:" >&2
  grep -rInE '/Users/|/home/[a-z]+/|AMPPS|MAMP|XAMPP|wp-content/plugins/wp-command-center/' "$DEST" | sed "s|$DEST/|  |" >&2
  fail=1
fi

# Loopback addresses are NOT automatically wrong: Ollama and LM Studio are local
# model runners whose default endpoints are localhost by design. List them for a
# human to confirm rather than blocking the build on a legitimate feature.
if grep -rIlE 'localhost|127\.0\.0\.1' "$DEST" >/dev/null 2>&1; then
  echo "NOTE: loopback references (expected for local AI providers) in:" >&2
  grep -rIlE 'localhost|127\.0\.0\.1' "$DEST" | sed "s|$DEST/|  |" >&2
fi

# Every runtime file the generated client configuration fetches must be in the
# package. Shipping a config that points at a file we did not ship is a silent,
# total connection failure for every user, so it fails the build.
for required in "sdk/javascript/wpcc-mcp-relay.mjs"; do
  if [[ ! -f "$DEST/$required" ]]; then
    echo "ERROR: required runtime file missing from package: $required" >&2
    fail=1
  fi
done

# Every PHP file must parse.
while IFS= read -r php_file; do
  if ! php -l "$php_file" >/dev/null 2>&1; then
    echo "ERROR: syntax error in ${php_file#$DEST/}" >&2
    fail=1
  fi
done < <( find "$DEST" -name '*.php' )

[[ $fail -eq 0 ]] || { echo "Build FAILED." >&2; exit 1; }

# ── Package ─────────────────────────────────────────────────────────────────
mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" "$SLUG" )

echo "Built $ZIP"
echo "  version : $VERSION"
echo "  files   : $( find "$DEST" -type f | wc -l | tr -d ' ' )"
echo "  size    : $( du -h "$ZIP" | cut -f1 | tr -d ' ' )"
