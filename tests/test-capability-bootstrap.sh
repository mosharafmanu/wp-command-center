#!/usr/bin/env bash
# Step 79 — Token Capability Auto-Bootstrap & Self-Healing test suite.
#
# Verifies that API tokens are fully usable over MCP with zero manual
# capability_assign step, on a fresh install or after token rotation:
#
#   Scenario A — a freshly created full-access token immediately gets
#                 system.admin (CapabilityRegistry::PROFILE_FULL_ACCESS).
#   Scenario B — revoking a token removes its assignment, and a newly
#                 created replacement token works immediately.
#   Scenario C — an assignment accidentally cleared to [] self-heals on
#                 the very next MCP request, with an audit trail.
#   Scenario D — a read-only token gets exactly the read_only profile
#                 (database.inspect, search.manage); writes remain
#                 blocked by the existing scope gate.
#
# Assertions for plugin_manage use "code != -32001" rather than asserting
# success, so this suite is independent of wpcc_enforce_approval's value.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
mcp() { local token="$1" body="$2"; curl -s -X POST -H "Authorization: Bearer $token" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE/mcp"; }

create_token() {
	# $1 = label, $2 = AuthTokens::SCOPE_* constant name. Echoes "<raw_token> <id>".
	wp eval "
\$auth = new \WPCommandCenter\Security\AuthTokens();
\$r = \$auth->create( '$1', \WPCommandCenter\Security\AuthTokens::$2, null, 1 );
echo is_wp_error( \$r ) ? '' : \$r['token'] . ' ' . \$r['record']['id'];
" --path="$WP_PATH" 2>/dev/null
}

caps_for_token() {
	# $1 = token id. Echoes a JSON array of assigned capability strings.
	wp eval "
\$reg = new \WPCommandCenter\Operations\CapabilityRegistry();
echo wp_json_encode( \$reg->get_for_subject( 'token', '$1' ) );
" --path="$WP_PATH" 2>/dev/null
}

echo "Token Capability Auto-Bootstrap & Self-Healing (Step 79) — $(date)"
echo ""

# ===================================================================
echo "== 1. Scenario A — fresh full-access token bootstraps system.admin =="
read -r TOKEN_A ID_A <<< "$(create_token 'S-79 Full A' 'SCOPE_FULL')"
assert_true "A: token created" "$( [ -n "$TOKEN_A" ] && [ -n "$ID_A" ] && echo true || echo false )"

CAPS_A=$(caps_for_token "$ID_A")
assert_true "A: bootstrap is non-empty" "$(echo "$CAPS_A" | jq -r 'length > 0')"
assert_true "A: bootstrap includes system.admin" "$(echo "$CAPS_A" | jq -r 'index("system.admin") != null')"

RESP=$(mcp "$TOKEN_A" '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_list"}},"id":1}')
assert_true "A: plugin_manage not capability-denied (-32001)" "$(echo "$RESP" | jq -r 'if (.error.code // 0) == -32001 then "false" else "true" end')"

# ===================================================================
echo "== 2. Scenario B — revoke + rotate: replacement token works immediately =="
wp eval "
\$auth = new \WPCommandCenter\Security\AuthTokens();
\$auth->revoke( '$ID_A' );
" --path="$WP_PATH" >/dev/null 2>&1

CAPS_A_AFTER=$(caps_for_token "$ID_A")
assert_eq "B: revoked token's assignment removed" "0" "$(echo "$CAPS_A_AFTER" | jq -r 'length')"

read -r TOKEN_B ID_B <<< "$(create_token 'S-79 Full B' 'SCOPE_FULL')"
assert_true "B: replacement token created" "$( [ -n "$TOKEN_B" ] && [ -n "$ID_B" ] && echo true || echo false )"

CAPS_B=$(caps_for_token "$ID_B")
assert_true "B: replacement bootstrap includes system.admin" "$(echo "$CAPS_B" | jq -r 'index("system.admin") != null')"

RESP=$(mcp "$TOKEN_B" '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_list"}},"id":2}')
assert_true "B: plugin_manage not capability-denied for new token (-32001)" "$(echo "$RESP" | jq -r 'if (.error.code // 0) == -32001 then "false" else "true" end')"

# ===================================================================
echo "== 3. Scenario C — assignment accidentally cleared, self-heals on next MCP request =="
wp eval "
\$reg = new \WPCommandCenter\Operations\CapabilityRegistry();
\$all = \$reg->get_assignments();
\$all['token:$ID_B'] = [];
\$reg->save_assignments( \$all );
" --path="$WP_PATH" >/dev/null 2>&1

CAPS_B_CLEARED=$(caps_for_token "$ID_B")
assert_eq "C: assignment manually cleared to empty" "0" "$(echo "$CAPS_B_CLEARED" | jq -r 'length')"

mcp "$TOKEN_B" '{"jsonrpc":"2.0","method":"tools/list","id":3}' > /dev/null

CAPS_B_HEALED=$(caps_for_token "$ID_B")
assert_true "C: assignment self-healed (non-empty)" "$(echo "$CAPS_B_HEALED" | jq -r 'length > 0')"
assert_true "C: self-heal restored system.admin" "$(echo "$CAPS_B_HEALED" | jq -r 'index("system.admin") != null')"

AUDIT_OK=$(wp eval "
\$audit = new \WPCommandCenter\Security\AuditLog();
\$found = false;
foreach ( \$audit->tail( 50 ) as \$entry ) {
	if ( 'capability.bootstrap' === ( \$entry['action'] ?? '' )
		&& 'self_healed' === ( \$entry['context']['reason'] ?? '' )
		&& '$ID_B' === ( \$entry['context']['token_id'] ?? '' ) ) {
		\$found = true;
		break;
	}
}
echo \$found ? 'true' : 'false';
" --path="$WP_PATH" 2>/dev/null)
assert_eq "C: audit log records self-heal" "true" "$AUDIT_OK"

# ===================================================================
echo "== 4. Scenario D — read-only token gets the read_only profile =="
read -r TOKEN_D ID_D <<< "$(create_token 'S-79 Read Only D' 'SCOPE_READ_ONLY')"
assert_true "D: read-only token created" "$( [ -n "$TOKEN_D" ] && [ -n "$ID_D" ] && echo true || echo false )"

CAPS_D=$(caps_for_token "$ID_D")
assert_eq "D: read_only profile has exactly 3 capabilities" "3" "$(echo "$CAPS_D" | jq -r 'length')"
assert_true "D: read_only profile includes database.inspect" "$(echo "$CAPS_D" | jq -r 'index("database.inspect") != null')"
assert_true "D: read_only profile includes search.manage" "$(echo "$CAPS_D" | jq -r 'index("search.manage") != null')"
assert_true "D: read_only profile includes history.read (104.2)" "$(echo "$CAPS_D" | jq -r 'index("history.read") != null')"

RESP=$(mcp "$TOKEN_D" '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}},"id":4}')
assert_true "D: database_inspect succeeds for read-only token" "$(echo "$RESP" | jq -r 'if .result then "true" else "false" end')"

RESP=$(mcp "$TOKEN_D" '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_list"}},"id":5}')
# STEP 89: denials are isError tool results with a structured code.
assert_eq "D: plugin_manage blocked for read-only token (wpcc_token_read_only)" "wpcc_token_read_only" "$(echo "$RESP" | jq -r '.result.content[0].text | fromjson | .code // empty')"
assert_true "D: denial message mentions read-only" "$(echo "$RESP" | jq -r '(.result.content[0].text | fromjson | .message // "") | test("read-only"; "i")')"

# ===================================================================
echo "== 5. Cleanup =="
wp eval "
\$auth = new \WPCommandCenter\Security\AuthTokens();
foreach ( \$auth->list() as \$t ) {
	if ( str_starts_with( \$t['label'], 'S-79 ' ) ) { \$auth->delete( \$t['id'] ); }
}
echo 'ok';
" --path="$WP_PATH" >/dev/null 2>&1

REMAINING=$(wp eval "
\$reg = new \WPCommandCenter\Operations\CapabilityRegistry();
\$all = \$reg->get_assignments();
\$leftover = 0;
foreach ( ['$ID_A', '$ID_B', '$ID_D'] as \$id ) {
	if ( isset( \$all['token:' . \$id] ) ) { \$leftover++; }
}
echo \$leftover;
" --path="$WP_PATH" 2>/dev/null)
assert_eq "cleanup: no stale capability assignments for test tokens" "0" "$REMAINING"

LABELS_GONE=$(wp eval "
\$auth = new \WPCommandCenter\Security\AuthTokens();
\$still = false;
foreach ( \$auth->list() as \$t ) {
	if ( str_starts_with( \$t['label'], 'S-79 ' ) ) { \$still = true; }
}
echo \$still ? 'false' : 'true';
" --path="$WP_PATH" 2>/dev/null)
assert_eq "cleanup: all S-79 test tokens removed" "true" "$LABELS_GONE"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
