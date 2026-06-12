#!/usr/bin/env bash
set -uo pipefail; SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0; pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }; fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }; api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
echo "ACF Runtime Test — $(date)"
MANIFEST=$(api "$WPCC_BASE/agent/manifest"); DISC=$(api "$WPCC_BASE/claude/discovery")

echo "== 1. Registration =="
assert_true "op: registered" "$(echo "$MANIFEST" | jq -r 'any(.operations[]; .id == "acf_manage")')"
assert_eq "op: approval" "true" "$(echo "$MANIFEST" | jq -r '.operations[]|select(.id=="acf_manage")|.requires_approval')"

echo "== 2. Capability =="
assert_contains "cap: acf.manage" "$(echo "$DISC"|jq -r '.capabilities.capabilities|join(",")')" "acf.manage"

echo "== 3. Routes =="
assert_true "route: run" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[];.path=="/operations/acf_manage/run")')"
assert_true "route: rollback" "$(echo "$MANIFEST" | jq -r 'any(.endpoints[];.path=="/operations/acf_manage/rollback")')"

echo "== 4. ACF Inventory =="
INV=$(api_post -d '{"action":"acf_inventory"}' "$WPCC_BASE/operations/acf_manage/run")
assert_contains "inv: action" "$INV" "acf_inventory"
assert_true "inv: has groups" "$(echo "$INV"|jq -r 'if .groups then "true" else "false" end')"
assert_true "inv: has total_fields" "$(echo "$INV"|jq -r 'if .total_fields then "true" else "false" end')"
assert_true "inv: has synced" "$(echo "$INV"|jq -r 'if .synced then "true" else "false" end')"

echo "== 5. Group List =="
GLIST=$(api_post -d '{"action":"acf_group_list"}' "$WPCC_BASE/operations/acf_manage/run")
assert_contains "grp: list" "$GLIST" "acf_group_list"
FIRST_GID=$(echo "$GLIST"|jq -r '.groups[0].key // ""')

echo "== 6. Group Get =="
if [ -n "$FIRST_GID" ]; then
	GGET=$(api_post -d "{\"action\":\"acf_group_get\",\"group_id\":\"$FIRST_GID\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "grp: get" "$GGET" "acf_group_get"
	assert_true "grp: has fields" "$(echo "$GGET"|jq -r 'if .fields then "true" else "false" end')"
else pass "grp: skip"; pass "grp: skip"
fi

echo "== 7. Field List =="
FLIST=$(api_post -d "{\"action\":\"acf_field_list\",\"group_id\":\"$FIRST_GID\"}" "$WPCC_BASE/operations/acf_manage/run")
assert_contains "fld: list" "$FLIST" "acf_field_list"
if [ -n "$FIRST_GID" ]; then
	assert_true "fld: has fields" "$(echo "$FLIST"|jq -r 'if .fields then "true" else "false" end')"
fi

echo "== 8. JSON Status =="
JSONS=$(api_post -d '{"action":"acf_json_status"}' "$WPCC_BASE/operations/acf_manage/run")
assert_contains "json: status" "$JSONS" "acf_json_status"
assert_true "json: has json_path" "$(echo "$JSONS"|jq -r 'if .json_path then "true" else "false" end')"

echo "== 9. Value Get =="
VAL_GET=$(api_post -d '{"action":"acf_value_get","post_id":1,"field_key":"nonexistent"}' "$WPCC_BASE/operations/acf_manage/run")
assert_contains "val: get" "$VAL_GET" "acf_value_get"

echo "== 10. Group Create =="
GRP_NAME="wpcc_test_acf_$(date +%s)"
GCREATE=$(api_post -d "{\"action\":\"acf_group_create\",\"title\":\"$GRP_NAME\"}" "$WPCC_BASE/operations/acf_manage/run")
assert_contains "grp: create" "$GCREATE" "acf_group_create"
NEW_GID=$(echo "$GCREATE"|jq -r '.group_id // ""')

echo "== 11. Group Update =="
if [ -n "$NEW_GID" ]; then
	GUPD=$(api_post -d "{\"action\":\"acf_group_update\",\"group_id\":\"$NEW_GID\",\"title\":\"Updated $GRP_NAME\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "grp: update" "$GUPD" "acf_group_update"
else pass "grp: update skip"
fi

echo "== 12. Group Deactivate =="
if [ -n "$NEW_GID" ]; then
	DEACT=$(api_post -d "{\"action\":\"acf_group_deactivate\",\"group_id\":\"$NEW_GID\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "grp: deact" "$DEACT" "acf_group_deactivate"
else pass "grp: deact skip"
fi

echo "== 13. Group Activate =="
if [ -n "$NEW_GID" ]; then
	ACT=$(api_post -d "{\"action\":\"acf_group_activate\",\"group_id\":\"$NEW_GID\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "grp: act" "$ACT" "acf_group_activate"
else pass "grp: act skip"
fi

echo "== 14. Field Create =="
if [ -n "$NEW_GID" ]; then
	FCREATE=$(api_post -d "{\"action\":\"acf_field_create\",\"group_id\":\"$NEW_GID\",\"label\":\"Test Text\",\"name\":\"test_text\",\"type\":\"text\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "fld: create" "$FCREATE" "acf_field_create"
	NEW_FKEY=$(echo "$FCREATE"|jq -r '.field_key // ""')
else pass "fld: create skip"
fi

echo "== 15. Field Delete =="
if [ -n "${NEW_FKEY:-}" ]; then
	FDEL=$(api_post -d "{\"action\":\"acf_field_delete\",\"field_key\":\"$NEW_FKEY\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "fld: delete" "$FDEL" "acf_field_delete"
else pass "fld: delete skip"
fi

echo "== 16. Group Delete =="
if [ -n "$NEW_GID" ]; then
	GDEL=$(api_post -d "{\"action\":\"acf_group_delete\",\"group_id\":\"$NEW_GID\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "grp: delete" "$GDEL" "acf_group_delete"
else pass "grp: delete skip"
fi

echo "== 17. Validation — Invalid Action =="
BAD=$(api_post -d '{"action":"bad"}' "$WPCC_BASE/operations/acf_manage/run")
assert_contains "val: bad" "$BAD" "Invalid ACF action"

echo "== 18. Validation — Not Found =="
NF=$(api_post -d '{"action":"acf_group_get","group_id":"nonexistent_key"}' "$WPCC_BASE/operations/acf_manage/run")
assert_contains "val: nf" "$NF" "error"

echo "== 19. MCP Discovery =="
MCP_TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":1}')
assert_true "mcp: acf tool" "$(echo "$MCP_TOOLS"|jq -r 'any(.result.tools[];.name=="acf_manage")')"
assert_true "mcp: 19+ tools" "$(if [ "$(echo "$MCP_TOOLS"|jq -r '.result.tools|length')" -ge 19 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 20. Timeline =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=100")
assert_true "tml: acf events" "$(echo "$TL"|jq -r 'any(.[];.label=="ACF operation completed" or .label=="ACF groups listed" or .label=="ACF group created")')"

echo "== 21. Rollback Endpoint =="
RB=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"rollback_id":"nonexistent"}' "$WPCC_BASE/operations/acf_manage/rollback")
assert_true "rb: works" "$( [ "$RB" = "400" -o "$RB" = "404" ] && echo true || echo false )"

echo "== 22. No Token =="
NOAUTH=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"action":"acf_group_list"}' "$WPCC_BASE/operations/acf_manage/run")
assert_contains "auth: 401" "$NOAUTH" "401"

echo "== 23. JSON Export =="
if [ -n "$FIRST_GID" ]; then
	JEXP=$(api_post -d "{\"action\":\"acf_json_export\",\"group_id\":\"$FIRST_GID\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "jexp: ok" "$JEXP" "acf_json_export"
	assert_true "jexp: has json" "$(echo "$JEXP"|jq -r 'if .json then "true" else "false" end')"
else pass "jexp: skip"; pass "jexp: skip"
fi

echo "== 24. JSON Diff =="
if [ -n "$FIRST_GID" ]; then
	JDIFF=$(api_post -d "{\"action\":\"acf_json_diff\",\"group_id\":\"$FIRST_GID\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "jdiff: ok" "$JDIFF" "acf_json_diff"
else pass "jdiff: skip"
fi

echo "== 25. Location List =="
if [ -n "$FIRST_GID" ]; then
	LL=$(api_post -d "{\"action\":\"acf_location_list\",\"group_id\":\"$FIRST_GID\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "loc: list" "$LL" "acf_location_list"
else pass "loc: skip"
fi

echo "== 26. Context =="
CONTEXT=$(api "$WPCC_BASE/agent/context")
assert_true "ctx: ops" "$(echo "$CONTEXT"|jq -r 'if .operations then "true" else "false" end')"
assert_true "ctx: health" "$(echo "$CONTEXT"|jq -r 'if .health then "true" else "false" end')"

echo "== 27. Capability Count =="
CC=$(echo "$DISC"|jq -r '.capabilities.capabilities|length')
assert_true "cap: 13+" "$(if [ "$CC" -ge 13 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 28. Manifest — Op Count =="
OC=$(echo "$MANIFEST"|jq -r '.operations|length')
assert_true "ops: 20+" "$(if [ "$OC" -ge 20 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 29. Performance =="
PSTART=$(date +%s%N); api_post -d '{"action":"acf_inventory"}' "$WPCC_BASE/operations/acf_manage/run" >/dev/null
PEND=$(date +%s%N); PMS=$(( (PEND-PSTART) / 1000000 ))
assert_true "perf: <3s" "$( [ "$PMS" -lt 3000 ] && echo true || echo false )"
echo "  INFO: ${PMS}ms"

echo "== 30. MCP Initialize =="
assert_contains "mcp: init" "$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')" "WP Command Center"

echo "== 31. Field Get =="
FIRST_FKEY=$(echo "$FLIST"|jq -r '.fields[0].key // ""')
if [ -n "$FIRST_FKEY" ]; then
	FGET=$(api_post -d "{\"action\":\"acf_field_get\",\"field_key\":\"$FIRST_FKEY\"}" "$WPCC_BASE/operations/acf_manage/run")
	assert_contains "fld: get" "$FGET" "acf_field_get"
else pass "fld: get skip"
fi

echo "== 32. Bulk Value Update =="
BULK=$(api_post -d '{"action":"acf_bulk_value_update","post_id":1,"fields":{}}' "$WPCC_BASE/operations/acf_manage/run")
assert_contains "bulk: ok" "$BULK" "acf_bulk_value_update"

echo "== 33. Inventory Fields =="
assert_true "inv: groups >0" "$(if [ "$(echo "$INV"|jq -r '.groups')" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]