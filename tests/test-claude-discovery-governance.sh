#!/usr/bin/env bash
# Claude discovery must describe the exact approval policy enforced at execution.
# Uses process-local option filters only; no protection setting is changed.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"

wp --path="$WP_ROOT" eval-file "$SCRIPT_DIR/lib/claude-discovery-governance.php"
