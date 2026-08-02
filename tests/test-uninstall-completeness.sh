#!/usr/bin/env bash
#
# "Delete all data on uninstall" must actually delete all of it.
#
# Found on a clean-install lifecycle run: after the owner opted into full deletion,
# `wpcc_telemetry` survived. TelemetryStore creates that table lazily on first write
# (CREATE TABLE IF NOT EXISTS) and is deliberately decoupled from Schema::DB_VERSION,
# so it appears in neither the schema installer nor — until now — the uninstaller. Any
# site that had ever recorded telemetry kept an orphaned table forever.
#
# This asserts the uninstall list is a SUPERSET of every table the plugin can create:
# the ones Schema installs, plus the lazily-created ones. A new table added to either
# side without the other fails here rather than on a customer's database.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

UNINSTALL="$PLUGIN_DIR/uninstall.php"
SCHEMA="$PLUGIN_DIR/includes/Core/Schema.php"

echo "uninstall completeness"

# Tables the uninstaller drops.
DROPPED=$(sed -n "/const WPCC_UNINSTALL_TABLES/,/];/p" "$UNINSTALL" \
  | grep -oE "'wpcc_[a-z_]+'" | tr -d "'" | sort -u)

# Tables Schema installs (prefix . 'wpcc_...' assignments).
CREATED=$(grep -oE "\\\$wpdb->prefix \. 'wpcc_[a-z_]+'" "$SCHEMA" \
  | grep -oE "wpcc_[a-z_]+" | sort -u)

# Lazily-created tables live in their own stores, outside Schema.
LAZY=$(grep -rhoE "private const TABLE = 'wpcc_[a-z_]+'" "$PLUGIN_DIR/includes" \
  | grep -oE "wpcc_[a-z_]+" | sort -u)

ALL=$(printf '%s\n%s\n' "$CREATED" "$LAZY" | grep -v '^$' | sort -u)

echo "  schema tables: $(echo "$CREATED" | grep -c .)  lazy: $(echo "$LAZY" | grep -c .)  dropped: $(echo "$DROPPED" | grep -c .)"

missing=$(comm -23 <(echo "$ALL") <(echo "$DROPPED"))
if [ -z "$missing" ]; then
  pass "every table the plugin creates is dropped on opt-in uninstall"
else
  fail "tables created but never dropped: $(echo "$missing" | tr '\n' ' ')"
fi

# The specific regression.
case "$DROPPED" in
  *wpcc_telemetry*) pass "wpcc_telemetry (lazily created) is dropped";;
  *) fail "wpcc_telemetry (lazily created) is dropped";;
esac

# Guard the other direction loosely: dropping a table nothing creates is harmless
# (DROP ... IF EXISTS) but usually means a rename was half-applied.
orphan=$(comm -13 <(echo "$ALL") <(echo "$DROPPED"))
if [ -z "$orphan" ]; then
  pass "uninstall drops nothing the plugin never creates"
else
  echo "    note: dropped but not created here: $(echo "$orphan" | tr '\n' ' ')"
  pass "uninstall drops nothing the plugin never creates (informational)"
fi

echo
echo "uninstall completeness: $PASS passed / $FAIL failed"
[ "$FAIL" -eq 0 ]
