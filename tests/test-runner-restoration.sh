#!/usr/bin/env bash
# Focused regression for tests/run.sh state transport and verified restoration.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
# shellcheck source=lib/runner-state.sh
source "$SCRIPT_DIR/lib/runner-state.sh"
source "$SCRIPT_DIR/lib/source-integrity.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_true() { [ "$2" = "1" ] && pass "$1" || fail "$1"; }

ORIGINAL="$(RUNNER_STATE_SNAPSHOT)"
ORIGINAL_RC=$?
cleanup() {
	[ -n "${ORIGINAL:-}" ] && RUNNER_STATE_RESTORE "$ORIGINAL" >/dev/null 2>&1
	rm -f "${SOURCE_BEFORE:-}" "${SOURCE_AFTER:-}" "${SOURCE_CHANGED:-}"
}
trap cleanup EXIT

echo "== 1. Snapshot creation is valid and does not expose its payload =="
assert_true "snapshot command succeeds" "$([ "$ORIGINAL_RC" -eq 0 ] && echo 1 || echo 0)"
assert_true "snapshot has the versioned option map" "$(printf '%s' "$ORIGINAL" | jq -r '(.version == 1 and (.options|type == "object")) | if . then 1 else 0 end' 2>/dev/null)"
assert_true "snapshot includes token-manifest integrity metadata" "$(printf '%s' "$ORIGINAL" | jq -r '(.token_manifest|has("exists") and has("sha256")) | if . then 1 else 0 end' 2>/dev/null)"

echo "== 2. The old positional-argument transport is rejected by WP-CLI =="
if wp --path="$WP_ROOT" eval 'echo "not reached";' '{"probe":true}' >/dev/null 2>&1; then
	fail "wp eval rejects an extra positional JSON argument"
else
	pass "wp eval rejects an extra positional JSON argument"
fi

echo "== 3. Environment transport restores exact options and credentials =="
FIXTURE="wpcc-runner-$(date +%s)-$$"
env WPCC_RUNNER_FIXTURE="$FIXTURE" wp --path="$WP_ROOT" eval '
	use WPCommandCenter\Security\AuthTokens;
	update_option( "wpcc_security_mode", "enterprise" );
	update_option( "wpcc_enforce_capabilities", false );
	update_option( "wpcc_builtin_ai_tools", [ "content" => true, "seo" => false, "alt_text" => true ] );
	update_option( "wpcc_capability_assignments", [ "token:runner-fixture" => [ "system.admin" ] ], false );
	update_option( "wpcc_ai_connections", [ "runner-fixture" => [ "provider" => "openai" ] ], false );
	update_option( "wpcc_ai_credentials", [ "runner-fixture" => "never-print-this-fixture" ], false );
	$result = ( new AuthTokens() )->create( getenv( "WPCC_RUNNER_FIXTURE" ), AuthTokens::SCOPE_READ_ONLY, time() + 300, 1 );
	if ( is_wp_error( $result ) ) { WP_CLI::error( "Unable to create runner credential fixture." ); }
' >/dev/null 2>&1
MUTATE_RC=$?
assert_true "fixture mutation succeeds" "$([ "$MUTATE_RC" -eq 0 ] && echo 1 || echo 0)"

if RUNNER_STATE_RESTORE "$ORIGINAL"; then
	pass "verified restore succeeds"
else
	fail "verified restore succeeds"
fi
AFTER="$(RUNNER_STATE_SNAPSHOT)"
assert_true "restored snapshot is byte-for-byte identical" "$([ "$ORIGINAL" = "$AFTER" ] && echo 1 || echo 0)"

echo "== 4. Invalid snapshots fail closed =="
if RUNNER_STATE_RESTORE '{"version":1}' >/dev/null 2>&1; then
	fail "malformed restore is rejected"
else
	pass "malformed restore is rejected"
fi

echo "== 5. Candidate-source comparison fails closed =="
SOURCE_BEFORE="$(mktemp "${TMPDIR:-/tmp}/wpcc-runner-source-before.XXXXXX")"
SOURCE_AFTER="$(mktemp "${TMPDIR:-/tmp}/wpcc-runner-source-after.XXXXXX")"
SOURCE_CHANGED="$(mktemp "${TMPDIR:-/tmp}/wpcc-runner-source-changed.XXXXXX")"
WPCC_SOURCE_MANIFEST "$SOURCE_BEFORE" "$PLUGIN_DIR"
WPCC_SOURCE_MANIFEST "$SOURCE_AFTER" "$PLUGIN_DIR"
assert_true "unchanged candidate manifests compare equal" "$([ "$(WPCC_SOURCE_FINGERPRINT "$SOURCE_BEFORE")" = "$(WPCC_SOURCE_FINGERPRINT "$SOURCE_AFTER")" ] && echo 1 || echo 0)"
cp "$SOURCE_AFTER" "$SOURCE_CHANGED"
printf '%s\t%s\n' "$(printf mutation | shasum -a 256 | awk '{print $1}')" "synthetic-source-drift" >> "$SOURCE_CHANGED"
if WPCC_SOURCE_ASSERT_IDENTICAL "$SOURCE_BEFORE" "$SOURCE_CHANGED" >/dev/null 2>&1; then
	fail "manifest comparator rejects source drift"
else
	pass "manifest comparator rejects source drift"
fi
assert_true "T2 runner makes source drift a harness failure" "$(grep -q 'SOURCE_DRIFT_RC=1' "$SCRIPT_DIR/run.sh" && grep -q 'HARNESS_FAIL=1' "$SCRIPT_DIR/run.sh" && echo 1 || echo 0)"

echo "== 6. T2 credential liveness and monotonic timing are fail-closed =="
assert_true "runner invokes authenticated preflight before governance baseline" "$(awk '/WPCC_GATE_AUTH_PREFLIGHT/{p=NR}/governance baseline/{g=NR}END{print(p&&g&&p<g?1:0)}' "$SCRIPT_DIR/run.sh")"
assert_true "runner checks liveness between suites" "$(grep -q 'WPCC_GATE_CHECKPOINT' "$SCRIPT_DIR/run.sh" && echo 1 || echo 0)"
assert_true "runner duration is monotonic" "$(grep -q 'WPCC_GATE_MONOTONIC_DURATION_MS' "$SCRIPT_DIR/run.sh" && ! grep -q '^START=$(date' "$SCRIPT_DIR/run.sh" && echo 1 || echo 0)"

echo
echo "Runner restoration: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
