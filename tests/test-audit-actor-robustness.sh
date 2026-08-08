#!/usr/bin/env bash
#
# V1 finalization — AuditLog::resolve_actor() must never be the reason a governed
# change is lost.
#
# Found during clean-install lifecycle certification: approving a request whose
# context carried a SCALAR actor threw a fatal TypeError inside
# OperationQueue::enqueue() -> AuditLog::resolve_actor( array $actor ). Because
# OperationManager::approve_request() marks the request APPROVED *before* it
# enqueues, the throw left the request approved, never queued, and with no audit
# entry — a silently stuck approval plus a missing audit record.
#
# No shipped caller passes a scalar (admin/token actors are arrays), so this is a
# robustness + audit-integrity fix at a public boundary, not a change of contract:
# well-formed array actors are still returned byte-identically.
#
# Requires wp-cli.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>&1; }

SRC="$PLUGIN_DIR/includes/Security/AuditLog.php"

echo "V1 finalization — audit actor robustness"

# --- static: the boundary is no longer type-fatal -------------------------------
has "resolve_actor accepts an untyped actor" 'public static function resolve_actor( $actor ): array {' "$SRC"
has "array actors still short-circuit"       'if ( is_array( $actor ) && ! empty( $actor ) ) {' "$SRC"

# --- behaviour: well-formed actors are untouched (no contract change) -----------
assert_eq "array actor returned unchanged" \
  'admin|7' \
  "$(wpe '$a = \WPCommandCenter\Security\AuditLog::resolve_actor( [ "type" => "admin", "user_id" => 7 ] ); echo $a["type"]."|".$a["user_id"];')"

assert_eq "empty array still falls back to unknown (logged out)" \
  'unknown' \
  "$(wpe '$a = \WPCommandCenter\Security\AuditLog::resolve_actor( [] ); echo $a["type"];')"

# --- behaviour: a malformed actor normalises instead of throwing ----------------
assert_eq "scalar actor does not throw" \
  'unknown|cert' \
  "$(wpe '$a = \WPCommandCenter\Security\AuditLog::resolve_actor( "cert" ); echo $a["type"]."|".$a["label"];')"

assert_eq "scalar actor is NOT promoted to a trusted type" \
  'unknown' \
  "$(wpe '$a = \WPCommandCenter\Security\AuditLog::resolve_actor( "admin" ); echo $a["type"];')"

assert_eq "null actor falls back, no label invented" \
  'unknown|nolabel' \
  "$(wpe '$a = \WPCommandCenter\Security\AuditLog::resolve_actor( null ); echo $a["type"]."|".( $a["label"] ?? "nolabel" );')"

# --- the real regression: approval with a scalar actor completes -----------------
# Proves the stuck-approval path is closed end to end: approve -> queued.
RES="$(wpe '
$m = new \WPCommandCenter\Operations\OperationManager();
$r = $m->create_request( "system_info", [ "action" => "system_overview" ], [] );
$rid = is_array( $r ) ? ( $r["request_id"] ?? "" ) : "";
if ( "" === $rid ) { echo "NOREQUEST"; return; }
try {
    $m->approve_request( $rid, [ "actor" => "scalar-actor" ] );
} catch ( \Throwable $e ) {
    echo "THREW:" . get_class( $e ); return;
}
global $wpdb;
$q = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_operation_queue WHERE request_id = %s", $rid ) );
$s = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wpcc_operation_requests WHERE request_id = %s", $rid ) );
echo $s . "|queued=" . (int) $q;
' | tail -1)"
assert_eq "approval with a scalar actor enqueues (no stuck approval)" 'approved|queued=1' "$RES"

echo
echo "audit actor robustness: $PASS passed / $FAIL failed"
[ "$FAIL" -eq 0 ]
