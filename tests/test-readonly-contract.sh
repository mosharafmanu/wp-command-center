#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_ROOT="${WPCC_TEST_WP_PATH:-$(cd "$SCRIPT_DIR/../../../.." && pwd)}"
wp --path="$WP_ROOT" eval-file "$SCRIPT_DIR/lib/readonly-contract.php"
