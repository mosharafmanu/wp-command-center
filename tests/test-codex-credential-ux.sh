#!/usr/bin/env bash
# Render the actual post-create journey; delete only tokens created by this suite.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
RENDER_DIR="$(mktemp -d "${TMPDIR:-/tmp}/wpcc-ux-render.XXXXXX")"
trap 'rm -rf "$RENDER_DIR"' EXIT
export WPCC_UX_RENDER_DIR="$RENDER_DIR"
wp --path="$ROOT/../../.." eval-file "$ROOT/tests/lib/codex-credential-ux.php" "$ROOT"
node "$ROOT/tests/lib/codex-credential-ux.mjs" "$RENDER_DIR"
