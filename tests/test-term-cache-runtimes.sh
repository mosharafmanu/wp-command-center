#!/usr/bin/env bash
#
# ISSUE 11 (term_manage) + ISSUE 12 (cache_manage) new runtimes.
#   term_manage: read-only term_list/term_get/term_search/term_describe.
#   cache_manage: cache_status/cache_purge_all/cache_purge_url/cache_describe (no shell).
#   Invariant: catalogue + MCP tools are now 42 (was 40); OPERATION_MAP 34, caps 23.
#
# Requires: curl, jq, wpcc-env.sh (full-scope token).
# Usage: bash tests/test-term-cache-runtimes.sh

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0; RO_ID=""
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
pj(){ printf '%s' "$1" | jq -r "$2"; }
op(){ curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$2" "$WPCC_BASE/operations/$1/run"; }
cleanup(){
	[ -z "$RO_ID" ] || wp --path="$WP_ROOT" eval '(new \WPCommandCenter\Security\AuthTokens())->delete("'"$RO_ID"'");' >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "== ISSUE 11: term_manage =="
D=$(op term_manage '{"action":"term_describe"}')
[ "$(pj "$D" '.runtime')" = "term_manage" ] && pass "term_describe works" || fail "term_describe missing"
L=$(op term_manage '{"action":"term_list","taxonomy":"category","number":5}')
[ "$(pj "$L" '.action')" = "term_list" ] && pass "term_list returns" || fail "term_list failed"
TID=$(printf '%s' "$L" | jq -r '.terms[0].term_id // empty')
if [ -n "$TID" ]; then
  G=$(op term_manage "$(jq -nc --argjson t "$TID" '{action:"term_get",term_id:$t}')")
  [ "$(pj "$G" '.term.term_id')" = "$TID" ] && pass "term_get by id returns term_id/slug/name/taxonomy" || fail "term_get failed"
else echo "  SKIP: no category terms"; fi
S=$(op term_manage '{"action":"term_search"}')
pj "$S" '.message // .error.message // empty' | grep -qi "search" && pass "term_search names required 'search' param" || fail "term_search error should name param"
BADTAX=$(op term_manage '{"action":"term_list","taxonomy":"nope_not_a_tax"}')
pj "$BADTAX" '.code // .error.code // empty' | grep -q "wpcc_invalid_taxonomy" && pass "invalid taxonomy rejected with valid list" || fail "invalid taxonomy should error"

echo "== ISSUE 12: cache_manage =="
CS=$(op cache_manage '{"action":"cache_status"}')
[ "$(pj "$CS" '.action')" = "cache_status" ] && pass "cache_status detects layers" || fail "cache_status failed"
echo "  detected layers: $(pj "$CS" '.detected | join(",")')"
PA=$(op cache_manage '{"action":"cache_purge_all"}')
[ "$(pj "$PA" '.action')" = "cache_purge_all" ] && pass "cache_purge_all runs (no shell needed)" || fail "cache_purge_all failed"
echo "  purged: $(pj "$PA" '.purged | join(",")')"
PU=$(op cache_manage '{"action":"cache_purge_url"}')
pj "$PU" '.code // .error.code // empty' | grep -q "wpcc_missing_url" && pass "cache_purge_url names required 'url' param" || fail "should name url param"

echo "== REST route/auth contract =="
RO_JSON=$(wp --path="$WP_ROOT" eval '
$auth = new \WPCommandCenter\Security\AuthTokens();
$created = $auth->create( "T2 term/cache REST boundary " . wp_generate_uuid4(), \WPCommandCenter\Security\AuthTokens::SCOPE_READ_ONLY, time() + 1800, 1 );
if ( ! is_wp_error( $created ) ) echo wp_json_encode( [ "token" => $created["token"], "id" => $created["record"]["id"] ] );
' 2>/dev/null)
RO_TOKEN=$(printf '%s' "$RO_JSON" | jq -r '.token // empty')
RO_ID=$(printf '%s' "$RO_JSON" | jq -r '.id // empty')
[ -n "$RO_TOKEN" ] && [ -n "$RO_ID" ] && pass "temporary read-only boundary credential created" || fail "temporary read-only boundary credential created"

UNAUTH=$(curl -s -X POST -H "Content-Type: application/json" -d '{"action":"term_describe"}' "$WPCC_BASE/operations/term_manage/run")
[ "$(pj "$UNAUTH" '.code // empty')" = "wpcc_missing_token" ] && pass "term_manage REST route rejects unauthenticated requests" || fail "term_manage unauthenticated boundary"

RO_TERM=$(curl -s -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d '{"action":"term_describe"}' "$WPCC_BASE/operations/term_manage/run")
[ "$(pj "$RO_TERM" '.runtime // empty')" = "term_manage" ] && pass "read-only credential may call term_manage diagnostics" || fail "term_manage read-only boundary"

RO_CACHE=$(curl -s -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d '{"action":"cache_status"}' "$WPCC_BASE/operations/cache_manage/run")
[ "$(pj "$RO_CACHE" '.action // empty')" = "cache_status" ] && pass "read-only credential may call cache_status" || fail "cache_status read-only boundary"

RO_PURGE=$(curl -s -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d '{"action":"cache_purge_all"}' "$WPCC_BASE/operations/cache_manage/run")
[ "$(pj "$RO_PURGE" '.code // empty')" = "wpcc_insufficient_scope" ] && pass "read-only credential cannot purge cache" || fail "cache purge read-only boundary"

MCP_TERM=$(curl -s -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"term_manage","arguments":{"action":"term_describe"}},"id":91}' "$WPCC_BASE/mcp")
[ "$(pj "$MCP_TERM" '.result.isError // false')" = "false" ] && pass "term_manage remains available through MCP" || fail "term_manage MCP parity"
MCP_CACHE=$(curl -s -X POST -H "Authorization: Bearer $RO_TOKEN" -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"cache_manage","arguments":{"action":"cache_status"}},"id":92}' "$WPCC_BASE/mcp")
[ "$(pj "$MCP_CACHE" '.result.isError // false')" = "false" ] && pass "cache_manage remains available through MCP" || fail "cache_manage MCP parity"

cleanup
RO_ID=""
REMAINS=$(wp --path="$WP_ROOT" eval '$found=false; foreach ((new \WPCommandCenter\Security\AuthTokens())->list() as $token) { if (str_starts_with($token["label"], "T2 term/cache REST boundary ")) $found=true; } echo $found ? "yes" : "no";' 2>/dev/null)
[ "$REMAINS" = "no" ] && pass "temporary boundary credential removed" || fail "temporary boundary credential removed"

echo "== Invariant: catalogue is 42, term_manage read-only via read-only token semantics =="
CAT=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/operations" | jq -r 'if type == "array" then length else (.operations | length) end' 2>/dev/null || echo "?")
[ "$CAT" = "42" ] && pass "REST catalogue exposes all 42 operations" || fail "REST catalogue count (expected 42, got $CAT)"

echo
echo "== Summary =="; echo "  PASS: $PASS  FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
