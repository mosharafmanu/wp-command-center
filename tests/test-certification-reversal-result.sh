#!/usr/bin/env bash
# Certification Finding C: real MCP approval boundaries and persisted undo results.
# Run on the documented isolated regression site, with its wpcc-env.sh.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd -P)"
source "$PLUGIN_DIR/wpcc-env.sh"
wp --path="$WP_ROOT" eval-file "$SCRIPT_DIR/lib/certification-reversal-result.php" "$WPCC_BASE"
