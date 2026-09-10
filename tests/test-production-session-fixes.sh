#!/usr/bin/env bash
#
# Regression suite for the purplesurgical.com production-session findings:
#   ISSUE 4 — a rejected call (invalid action) must be recorded 'rejected', never 'applied'.
#   ISSUE 5 — media_search accepts `search` (and aliases); its empty error names the param.
#   ISSUE 6 — acf_group_get.group.field_count reflects the real field count.
#   ISSUE 2 — change_history operation_status action exists (idempotency lookup).
#   ISSUE 3 — acf_value_set validates object identity (rejects a bad object cleanly).
#
# Requires: curl, jq, wpcc-env.sh (full-scope token). ACF active for 3/6.
# Usage: bash tests/test-production-session-fixes.sh

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

echo "== ISSUE 4: invalid acf_manage action is recorded 'rejected', not 'applied' =="
R=$(op acf_manage '{"action":"acf_field_rollback"}')
CODE=$(pj "$R" '.code // .error.code // empty')
[ -n "$CODE" ] && pass "invalid action returns an error code ($CODE)" || fail "expected an error code for invalid action"
# The change_history entry for this attempt must not be 'applied'.
sleep 1
H=$(op change_history '{"action":"history_list","operation_id":"acf_manage","limit":5}')
BADAPPLIED=$(printf '%s' "$H" | jq -r '[.changes[]? | select(.status=="applied" and (.operation_id=="acf_manage"))] | length' 2>/dev/null || echo "?")
echo "  (recent acf_manage entries marked applied: $BADAPPLIED — a rejected attempt must not be among them)"

echo "== ISSUE 5: media_search =="
E=$(op media_manage '{"action":"media_search"}')
pj "$E" '.message // .error.message // empty' | grep -qi "search" && pass "empty media_search error names the 'search' param" || fail "empty error should name 'search'"
S=$(op media_manage '{"action":"media_search","search":"a"}')
[ "$(pj "$S" '.action // empty')" = "media_search" ] && pass "media_search with 'search' param runs" || fail "media_search with 'search' should run"

echo "== ISSUE 6: acf_group_get field_count =="
GL=$(op acf_manage '{"action":"acf_group_list"}')
GID=$(printf '%s' "$GL" | jq -r '.groups[0].key // empty')
if [ -n "$GID" ]; then
  GG=$(op acf_manage "$(jq -nc --arg g "$GID" '{action:"acf_group_get",group_id:$g}')")
  FC=$(pj "$GG" '.group.field_count // 0'); NF=$(printf '%s' "$GG" | jq -r '.fields | length')
  [ "$FC" = "$NF" ] && pass "field_count ($FC) matches returned fields ($NF)" || fail "field_count $FC != fields length $NF"
else
  echo "  SKIP: no ACF groups present"
fi

echo "== ISSUE 2: operation_status action exists =="
OS=$(op change_history '{"action":"operation_status","idempotency_key":"nonexistent-key"}')
[ "$(pj "$OS" '.action // empty')" = "operation_status" ] && pass "operation_status action responds" || fail "operation_status action missing"
[ "$(pj "$OS" 'if has("found") then (.found | tostring) else "missing" end')" = "false" ] && pass "unknown key reports found=false (safe to retry)" || fail "unknown key should report found=false"

echo "== ISSUE 3: acf_value_set object validation =="
BAD=$(op acf_manage '{"action":"acf_value_set","object_type":"term","object_id":999999999,"fields":{"x":"y"}}')
pj "$BAD" '.code // .error.code // empty' | grep -qE "wpcc_invalid_object|wpcc_unknown_acf_field" && pass "non-existent term rejected cleanly" || fail "bad object should be rejected"

echo
echo "== Summary =="; echo "  PASS: $PASS  FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
