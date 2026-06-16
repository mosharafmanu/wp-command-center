#!/usr/bin/env bash
#
# AuditLog rotation test suite for WP Command Center (STEP 104.0).
#
# Verifies the size-based rotation hardening of the append-only audit log
# (includes/Security/AuditLog.php):
#
#   - record() still appends; tail() returns newest-first
#   - tail() is rotation-aware: reads across rotated segments newest->oldest
#     until the requested limit is satisfied (no read regression after a
#     rotation), while a small limit still reads only the active log
#   - the active log rotates to audit-<ts>.log once it reaches the 50 MB cap;
#     rotation is idempotent (a second record() does not rotate again)
#   - pruning keeps only the newest 5 rotated segments
#
# Rotation is triggered with sparse files (dd seek) so the suite stays fast
# and does not write 50 MB of real data.
#
# Requires: wp-cli and wpcc-env.sh (sourced from this plugin's root). It does
# NOT require network/token access — AuditLog is exercised in-process via
# `wp eval-file`. The live audit directory is backed up and restored.
#
# Usage: bash tests/test-audit-log.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_CONTENT_DIR="$(cd "$PLUGIN_DIR/../.." && pwd)"
WP_ROOT="$(cd "$WP_CONTENT_DIR/.." && pwd)"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0
FAIL=0

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

assert_eq() {
	local desc="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		pass "$desc"
	else
		fail "$desc (expected '$expected', got '$actual')"
	fi
}

assert_true() {
	local desc="$1" actual="$2"
	if [ "$actual" = "true" ]; then
		pass "$desc"
	else
		fail "$desc (expected 'true', got '$actual')"
	fi
}

ROTATE_BYTES=52428800 # Must mirror AuditLog::ROTATE_BYTES (50 MB).
MAX_SEGMENTS=5        # Must mirror AuditLog::MAX_SEGMENTS.

AUDIT_DIR="$WP_CONTENT_DIR/uploads/wpcc-audit"
ACTIVE_LOG="$AUDIT_DIR/audit.log"
BACKUP_DIR=""

# Run a PHP snippet (read from stdin) against the live WordPress install.
run_php() {
	local php_file
	php_file="$(mktemp /tmp/wpcc-audit-test-XXXXXX.php)"
	cat > "$php_file"
	wp --path="$WP_ROOT" eval-file "$php_file" 2>/dev/null
	local rc=$?
	rm -f "$php_file"
	return $rc
}

# Remove every test log so each scenario starts clean.
reset_logs() {
	rm -f "$AUDIT_DIR"/audit.log "$AUDIT_DIR"/audit-*.log 2>/dev/null
}

segment_count() {
	local n
	n=$(find "$AUDIT_DIR" -maxdepth 1 -name 'audit-*.log' 2>/dev/null | wc -l)
	echo "$((n))"
}

# Create a sparse file of exactly $1 bytes (instant, no real data written).
make_sparse() {
	local path="$1" bytes="$2"
	rm -f "$path"
	dd if=/dev/zero of="$path" bs=1 count=0 seek="$bytes" 2>/dev/null
}

# Write one JSONL audit entry to a file (appended).
write_entry() {
	local path="$1" action="$2" ts="$3"
	printf '{"timestamp":%s,"action":"%s","context":{}}\n' "$ts" "$action" >> "$path"
}

cleanup() {
	reset_logs

	if [ -n "$BACKUP_DIR" ] && [ -d "$BACKUP_DIR" ]; then
		# Restore the operator's original audit logs.
		find "$BACKUP_DIR" -maxdepth 1 -name 'audit*.log' -exec cp -p {} "$AUDIT_DIR"/ \; 2>/dev/null
		rm -rf "$BACKUP_DIR"
	fi
}
trap cleanup EXIT

echo "== Setup =="

# Ensure the audit dir exists (record() creates it on first use, but tests
# manipulate files directly so create it up front).
mkdir -p "$AUDIT_DIR"
assert_true "setup: audit dir present" "$( [ -d "$AUDIT_DIR" ] && echo true || echo false )"

# Back up any existing audit logs so the suite is non-destructive.
BACKUP_DIR="$(mktemp -d /tmp/wpcc-audit-backup-XXXXXX)"
find "$AUDIT_DIR" -maxdepth 1 -name 'audit*.log' -exec cp -p {} "$BACKUP_DIR"/ \; 2>/dev/null
pass "setup: existing audit logs backed up"

echo
echo "== 1. record() appends, tail() newest-first =="

reset_logs

TAIL_BASIC=$(run_php <<'PHP'
<?php
$log = new \WPCommandCenter\Security\AuditLog();
$log->record( 'wpcc.test.a', [ 'i' => 1 ] );
$log->record( 'wpcc.test.b', [ 'i' => 2 ] );
$log->record( 'wpcc.test.c', [ 'i' => 3 ] );
$entries = $log->tail( 10 );
echo wp_json_encode( [
	'count' => count( $entries ),
	'first' => $entries[0]['action'] ?? '',
	'last'  => $entries[ count( $entries ) - 1 ]['action'] ?? '',
] );
PHP
)

assert_eq "record/tail: 3 entries returned" "3" "$(echo "$TAIL_BASIC" | jq -r '.count // empty')"
assert_eq "record/tail: newest entry first" "wpcc.test.c" "$(echo "$TAIL_BASIC" | jq -r '.first // empty')"
assert_eq "record/tail: oldest entry last" "wpcc.test.a" "$(echo "$TAIL_BASIC" | jq -r '.last // empty')"

echo
echo "== 2. tail() is rotation-aware (spans segments) =="

reset_logs

# Older segment (s1..s3, s3 newest within the file) + active (a1,a2).
SEG="$AUDIT_DIR/audit-20200101-000000-aaaaaa.log"
write_entry "$SEG" "wpcc.seg.s1" 1577836801
write_entry "$SEG" "wpcc.seg.s2" 1577836802
write_entry "$SEG" "wpcc.seg.s3" 1577836803
write_entry "$ACTIVE_LOG" "wpcc.act.a1" 1700000001
write_entry "$ACTIVE_LOG" "wpcc.act.a2" 1700000002

SPAN=$(run_php <<'PHP'
<?php
$log = new \WPCommandCenter\Security\AuditLog();
$small = $log->tail( 2 );   // Active only.
$wide  = $log->tail( 5 );   // Spans into the rotated segment.
$names = array_map( static fn( $e ) => $e['action'] ?? '', $wide );
echo wp_json_encode( [
	'small_count'  => count( $small ),
	'small_first'  => $small[0]['action'] ?? '',
	'small_has_seg'=> (bool) array_filter( $small, static fn( $e ) => str_starts_with( $e['action'] ?? '', 'wpcc.seg.' ) ),
	'wide_count'   => count( $wide ),
	'wide_order'   => $names,
] );
PHP
)

assert_eq "rotation-aware: small limit reads active only (2)" "2" "$(echo "$SPAN" | jq -r '.small_count // empty')"
assert_eq "rotation-aware: small limit newest first" "wpcc.act.a2" "$(echo "$SPAN" | jq -r '.small_first // empty')"
assert_true "rotation-aware: small limit does not reach segment" "$(echo "$SPAN" | jq -r '(.small_has_seg == false)')"
assert_eq "rotation-aware: wide limit returns 5 across files" "5" "$(echo "$SPAN" | jq -r '.wide_count // empty')"
assert_eq "rotation-aware: order newest-first across boundary" '["wpcc.act.a2","wpcc.act.a1","wpcc.seg.s3","wpcc.seg.s2","wpcc.seg.s1"]' "$(echo "$SPAN" | jq -c '.wide_order')"

echo
echo "== 3. Rotation at the 50 MB cap + idempotency =="

reset_logs

# Fill the active log to exactly the cap, then a record() must rotate it.
make_sparse "$ACTIVE_LOG" "$ROTATE_BYTES"
assert_eq "rotation: active log pre-sized to cap" "$ROTATE_BYTES" "$(wc -c < "$ACTIVE_LOG" | tr -d ' ')"

run_php <<'PHP' >/dev/null
<?php
( new \WPCommandCenter\Security\AuditLog() )->record( 'wpcc.rotate.trigger', [] );
PHP

assert_eq "rotation: exactly one rotated segment created" "1" "$(segment_count)"
ACTIVE_SIZE=$(wc -c < "$ACTIVE_LOG" | tr -d ' ')
assert_true "rotation: active log reset to a small file" "$( [ "$ACTIVE_SIZE" -lt 1000 ] && echo true || echo false )"
assert_true "rotation: segment matches audit-<ts>.log naming" "$( ls "$AUDIT_DIR"/audit-*.log >/dev/null 2>&1 && echo true || echo false )"

# Second record() must NOT rotate again (active is now small) -> idempotent.
run_php <<'PHP' >/dev/null
<?php
( new \WPCommandCenter\Security\AuditLog() )->record( 'wpcc.rotate.again', [] );
PHP

assert_eq "rotation: idempotent — no second rotation" "1" "$(segment_count)"

echo
echo "== 4. Pruning keeps only the newest 5 segments =="

reset_logs

# Seven pre-existing rotated segments (oldest -> newest by name).
for d in 20200101 20200102 20200103 20200104 20200105 20200106 20200107; do
	write_entry "$AUDIT_DIR/audit-${d}-000000-aaaaaa.log" "wpcc.old.$d" 1577836800
done
assert_eq "prune: 7 segments staged" "7" "$(segment_count)"

# Trigger a rotation -> 8 segments momentarily -> prune back to MAX_SEGMENTS.
make_sparse "$ACTIVE_LOG" "$ROTATE_BYTES"
run_php <<'PHP' >/dev/null
<?php
( new \WPCommandCenter\Security\AuditLog() )->record( 'wpcc.prune.trigger', [] );
PHP

assert_eq "prune: segment count capped at MAX_SEGMENTS" "$MAX_SEGMENTS" "$(segment_count)"
assert_true "prune: oldest segment (20200101) deleted" "$( [ ! -f "$AUDIT_DIR/audit-20200101-000000-aaaaaa.log" ] && echo true || echo false )"
assert_true "prune: newest pre-existing segment (20200107) retained" "$( [ -f "$AUDIT_DIR/audit-20200107-000000-aaaaaa.log" ] && echo true || echo false )"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

[ "$FAIL" -eq 0 ]
