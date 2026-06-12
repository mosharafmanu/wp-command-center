#!/usr/bin/env bash
set -uo pipefail; SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0; pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }; fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }; api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
echo "Forms Runtime Test — $(date)"
MANIFEST=$(api "$WPCC_BASE/agent/manifest"); DISC=$(api "$WPCC_BASE/claude/discovery")

echo "== 1. Registration =="
assert_true "op: registered" "$(echo "$MANIFEST"|jq -r 'any(.operations[];.id=="forms_manage")')"
assert_eq "op: approval" "true" "$(echo "$MANIFEST"|jq -r '.operations[]|select(.id=="forms_manage")|.requires_approval')"

echo "== 2. Capability =="
assert_contains "cap: forms.manage" "$(echo "$DISC"|jq -r '.capabilities.capabilities|join(",")')" "forms.manage"

echo "== 3. Routes =="
assert_true "route: run" "$(echo "$MANIFEST"|jq -r 'any(.endpoints[];.path=="/operations/forms_manage/run")')"
assert_true "route: rollback" "$(echo "$MANIFEST"|jq -r 'any(.endpoints[];.path=="/operations/forms_manage/rollback")')"

echo "== 4. Form List =="
FLIST=$(api_post -d '{"action":"form_list"}' "$WPCC_BASE/operations/forms_manage/run")
assert_contains "fl: action" "$FLIST" "form_list"
assert_true "fl: has items" "$(echo "$FLIST"|jq -r 'if .items then "true" else "false" end')"

echo "== 5. Form Get =="
FIRST_FID=$(echo "$FLIST"|jq -r '.items[0].id // ""')
if [ -n "$FIRST_FID" ]; then
	FGET=$(api_post -d "{\"action\":\"form_get\",\"form_id\":\"$FIRST_FID\"}" "$WPCC_BASE/operations/forms_manage/run")
	assert_contains "fg: get" "$FGET" "form_get"
	assert_true "fg: has markup" "$(echo "$FGET"|jq -r 'if .form_markup then "true" else "false" end')"
else pass "fg: skip"; pass "fg: skip"
fi

echo "== 6. Form Search =="
FSEARCH=$(api_post -d '{"action":"form_search","search":"Contact"}' "$WPCC_BASE/operations/forms_manage/run")
assert_contains "fs: search" "$FSEARCH" "form_search"

echo "== 7. Form Create =="
FNAME="wpcc_test_form_$(date +%s)"
FCREATE=$(api_post -d "{\"action\":\"form_create\",\"title\":\"$FNAME\",\"template\":\"contact_basic\"}" "$WPCC_BASE/operations/forms_manage/run")
assert_contains "fc: create" "$FCREATE" "form_create"
NEW_FID=$(echo "$FCREATE"|jq -r '.id // ""')

echo "== 8. Form Update =="
if [ -n "$NEW_FID" ]; then
	FUPD=$(api_post -d "{\"action\":\"form_update\",\"form_id\":\"$NEW_FID\",\"title\":\"Updated $FNAME\"}" "$WPCC_BASE/operations/forms_manage/run")
	assert_contains "fu: update" "$FUPD" "form_update"
else pass "fu: skip"
fi

echo "== 9. Form Activate =="
if [ -n "$NEW_FID" ]; then
	FACT=$(api_post -d "{\"action\":\"form_activate\",\"form_id\":\"$NEW_FID\"}" "$WPCC_BASE/operations/forms_manage/run")
	assert_contains "fa: act" "$FACT" "form_activate"
else pass "fa: skip"
fi

echo "== 10. Form Deactivate =="
if [ -n "$NEW_FID" ]; then
	FDACT=$(api_post -d "{\"action\":\"form_deactivate\",\"form_id\":\"$NEW_FID\"}" "$WPCC_BASE/operations/forms_manage/run")
	assert_contains "fd: deact" "$FDACT" "form_deactivate"
else pass "fd: skip"
fi

echo "== 11. Notification Get =="
if [ -n "$FIRST_FID" ]; then
	NGET=$(api_post -d "{\"action\":\"notification_get\",\"form_id\":\"$FIRST_FID\"}" "$WPCC_BASE/operations/forms_manage/run")
	assert_contains "ng: get" "$NGET" "notification_get"
	assert_true "ng: has admin" "$(echo "$NGET"|jq -r 'if .admin then "true" else "false" end')"
else pass "ng: skip"; pass "ng: skip"
fi

echo "== 12. Notification Test =="
if [ -n "$FIRST_FID" ]; then
	NTEST=$(api_post -d "{\"action\":\"notification_test\",\"form_id\":\"$FIRST_FID\"}" "$WPCC_BASE/operations/forms_manage/run")
	assert_contains "nt: test" "$NTEST" "notification_test"
else pass "nt: skip"
fi

echo "== 13. Submission Stats =="
STATS=$(api_post -d '{"action":"submission_stats"}' "$WPCC_BASE/operations/forms_manage/run")
assert_contains "ss: stats" "$STATS" "submission_stats"
assert_true "ss: has total" "$(echo "$STATS"|jq -r 'if .total then "true" else "false" end')"

echo "== 14. Form Analyze =="
if [ -n "$FIRST_FID" ]; then
	FANALYZE=$(api_post -d "{\"action\":\"form_analyze\",\"form_id\":\"$FIRST_FID\"}" "$WPCC_BASE/operations/forms_manage/run")
	assert_contains "fa: analyze" "$FANALYZE" "form_analyze"
	assert_true "fa: has issues" "$(echo "$FANALYZE"|jq -r 'if .issues then "true" else "false" end')"
else pass "fa: skip"; pass "fa: skip"
fi

echo "== 15. Form Delete =="
if [ -n "$NEW_FID" ]; then
	FDEL=$(api_post -d "{\"action\":\"form_delete\",\"form_id\":\"$NEW_FID\"}" "$WPCC_BASE/operations/forms_manage/run")
	assert_contains "fd: delete" "$FDEL" "form_delete"
else pass "fd: skip"
fi

echo "== 16. Validation — Invalid Action =="
BAD=$(api_post -d '{"action":"bad"}' "$WPCC_BASE/operations/forms_manage/run")
assert_contains "val: bad" "$BAD" "Invalid forms action"

echo "== 17. Validation — Bad Provider =="
BDPROV=$(api_post -d '{"action":"form_list","provider":"nonexistent"}' "$WPCC_BASE/operations/forms_manage/run")
assert_contains "val: provider" "$BDPROV" "error"

echo "== 18. MCP =="
MCP_TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":1}')
assert_true "mcp: forms tool" "$(echo "$MCP_TOOLS"|jq -r 'any(.result.tools[];.name=="forms_manage")')"

echo "== 19. Timeline =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=100")
assert_true "tml: forms" "$(echo "$TL"|jq -r 'any(.[];.label=="Forms operation completed" or .label=="Form created")')"

echo "== 20. Rollback Endpoint =="
RB=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"rollback_id":"nonexistent"}' "$WPCC_BASE/operations/forms_manage/rollback")
assert_true "rb: works" "$( [ "$RB" = "400" -o "$RB" = "404" ] && echo true || echo false )"

echo "== 21. No Token =="
assert_contains "auth: 401" "$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"action":"form_list"}' "$WPCC_BASE/operations/forms_manage/run")" "401"

echo "== 22. Context =="
CONTEXT=$(api "$WPCC_BASE/agent/context")
assert_true "ctx: health" "$(echo "$CONTEXT"|jq -r 'if .health then "true" else "false" end')"
assert_true "ctx: ops" "$(echo "$CONTEXT"|jq -r 'if .operations then "true" else "false" end')"

echo "== 23. Manifest Op Count =="
assert_true "ops: 21+" "$(if [ "$(echo "$MANIFEST"|jq -r '.operations|length')" -ge 21 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 24. Performance =="
PSTART=$(date +%s%N); api_post -d '{"action":"form_list"}' "$WPCC_BASE/operations/forms_manage/run" >/dev/null
PEND=$(date +%s%N); PMS=$(( (PEND-PSTART)/1000000 ))
assert_true "perf: <3s" "$( [ "$PMS" -lt 3000 ] && echo true || echo false )"
echo "  INFO: ${PMS}ms"

echo ""
echo "== Summary =="; echo "  $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]