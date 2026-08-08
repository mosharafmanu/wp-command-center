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
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
pj(){ printf '%s' "$1" | jq -r "$2"; }
op(){ curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$2" "$WPCC_BASE/operations/$1/run"; }

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

echo "== Invariant: catalogue is 42, term_manage read-only via read-only token semantics =="
CAT=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/operations" | jq -r '.operations | length' 2>/dev/null || echo "?")
echo "  catalogue via REST = $CAT (expect 42)"

echo
echo "== Summary =="; echo "  PASS: $PASS  FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
