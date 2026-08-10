#!/usr/bin/env bash
#
# Resolve a WPCC private store directory.
#
# The stores under wp-content/uploads carry a per-install random suffix
# (includes/Security/PrivateStore.php) so that nothing in them can be fetched by
# guessing a path — the protection that `.htaccess` could not provide on nginx.
# A suite therefore has to ask the plugin where a store is; hardcoding
# `wp-content/uploads/wpcc-audit` finds an empty path the plugin never reads.
#
#   source "$SCRIPT_DIR/lib/private-store.sh"
#   AUDIT_DIR="$(wpcc_store_dir wpcc-audit)"

wpcc_store_dir() {
  local store="$1"
  local wp="${WPCC_STORE_WP_PATH:-${WP_ROOT:-${WP_PATH:-}}}"

  if [ -z "$wp" ]; then
    wp="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../../.." && pwd)"
  fi

  wp eval "echo \\WPCommandCenter\\Security\\PrivateStore::path( '$store' );" \
    --path="$wp" --skip-themes 2>/dev/null
}
