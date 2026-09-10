#!/usr/bin/env bash
#
# DX regression suite (production-session issues 7–10):
#   ISSUE 9  — invalid-action errors list the valid actions; describe actions exist.
#   ISSUE 10 — acf_value_get routes by object_type (term/user/option), never silent post_id 0.
#   ISSUE 8  — failures return a structured {code,message} envelope, not a bare string.
#   ISSUE 7  — the params handlers require are declared in the tool schema
#              (media_search.search, acf_value_set.object_type/object_id/fields).
#
# Requires: curl, jq, wpcc-env.sh (full-scope token). ACF active for acf checks.
# Usage: bash tests/test-dx-drift-fixes.sh

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

echo "== ISSUE 9: invalid-action error lists valid actions =="
R=$(op acf_manage '{"action":"totally_bogus"}')
pj "$R" '.message // .error.message // empty' | grep -qi "valid actions" && pass "acf invalid-action names valid actions" || fail "should list valid actions"
D=$(op acf_manage '{"action":"acf_describe"}')
M=$(pj "$R" '.message // .error.message // empty')
MISSING=$(printf '%s' "$D" | jq -r '.actions[]?.action' | while IFS= read -r action; do
	printf '%s' "$M" | grep -qF "$action" || printf '%s\n' "$action"
done)
[ -z "$MISSING" ] && pass "acf error lists every registry-described action" || fail "acf error omits described actions: $MISSING"
W=$(op woocommerce_manage '{"action":"totally_bogus"}')
pj "$W" '.message // .error.message // empty' | grep -qi "valid actions" && pass "woo invalid-action names valid actions" || fail "woo should list valid actions"

echo "== ISSUE 9: describe actions =="
[ "$(pj "$D" '.runtime // empty')" = "acf_manage" ] && pass "acf_describe returns action catalogue" || fail "acf_describe missing"
printf '%s' "$D" | jq -e '.actions[] | select(.action=="acf_value_set")' >/dev/null 2>&1 && pass "acf_describe lists acf_value_set" || fail "describe should list acf_value_set"

echo "== ISSUE 10: acf_value_get routes by object_type =="
G=$(op acf_manage '{"action":"acf_value_get","object_type":"term","object_id":999999999,"field_key":"x"}')
pj "$G" '.code // .error.code // empty' | grep -qE "wpcc_invalid_object" && pass "bad term object errors (no silent post_id 0)" || fail "should error, not silently read post 0"

echo "== ISSUE 8: structured envelope on bad input =="
E=$(op acf_manage '{"action":"acf_value_get"}')
[ -n "$(pj "$E" '.code // .error.code // empty')" ] && pass "missing field_key returns a wpcc_ code" || fail "should return structured code"

echo "== ISSUE 7: schema declares handler-required params =="
CAT=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/operations/media_manage")
printf '%s' "$CAT" | grep -q '"search"' && pass "media_manage schema declares 'search'" || fail "media schema missing 'search'"
ACAT=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/operations/acf_manage")
printf '%s' "$ACAT" | grep -q '"object_type"' && printf '%s' "$ACAT" | grep -q '"fields"' && pass "acf_manage schema declares object_type + fields" || fail "acf schema missing object params"

echo
echo "== Summary =="; echo "  PASS: $PASS  FAIL: $FAIL"
[ "$FAIL" -eq 0 ]
