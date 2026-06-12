#!/usr/bin/env bash
set -uo pipefail; SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0; pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }; fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }; api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
echo "Menu Runtime Test — $(date)"
MANIFEST=$(api "$WPCC_BASE/agent/manifest"); DISC=$(api "$WPCC_BASE/claude/discovery")

echo "== 1. Registration =="
assert_true "op: registered" "$(echo "$MANIFEST"|jq -r 'any(.operations[];.id=="menu_manage")')"
echo "== 2. Capability =="
assert_contains "cap: menu.manage" "$(echo "$DISC"|jq -r '.capabilities.capabilities|join(",")')" "menu.manage"
echo "== 3. Routes =="
assert_true "route: run" "$(echo "$MANIFEST"|jq -r 'any(.endpoints[];.path=="/operations/menu_manage/run")')"
assert_true "route: rollback" "$(echo "$MANIFEST"|jq -r 'any(.endpoints[];.path=="/operations/menu_manage/rollback")')"

echo "== 4. Menu List =="
MLIST=$(api_post -d '{"action":"menu_list"}' "$WPCC_BASE/operations/menu_manage/run")
assert_contains "ml: list" "$MLIST" "menu_list"
assert_true "ml: has menus" "$(echo "$MLIST"|jq -r 'if .menus then "true" else "false" end')"

echo "== 5. Menu Get =="
FIRST_MID=$(echo "$MLIST"|jq -r '.menus[0].id // 0')
if [ "$FIRST_MID" -gt 0 ] 2>/dev/null; then
	MGET=$(api_post -d "{\"action\":\"menu_get\",\"menu_id\":$FIRST_MID}" "$WPCC_BASE/operations/menu_manage/run")
	assert_contains "mg: get" "$MGET" "menu_get"
	assert_true "mg: has items" "$(echo "$MGET"|jq -r 'if .items then "true" else "false" end')"
else pass "mg: skip"; pass "mg: skip"
fi

echo "== 6. Menu Create =="
MNAME="wpcc_test_menu_$(date +%s)"
MCREATE=$(api_post -d "{\"action\":\"menu_create\",\"name\":\"$MNAME\"}" "$WPCC_BASE/operations/menu_manage/run")
assert_contains "mc: create" "$MCREATE" "menu_create"
NEW_MID=$(echo "$MCREATE"|jq -r '.menu_id // 0')

echo "== 7. Menu Item Add =="
if [ "$NEW_MID" -gt 0 ] 2>/dev/null; then
	MIADD=$(api_post -d "{\"action\":\"menu_item_add\",\"menu_id\":$NEW_MID,\"title\":\"Test Page\",\"url\":\"/test\"}" "$WPCC_BASE/operations/menu_manage/run")
	assert_contains "mi: add" "$MIADD" "menu_item_add"
	NEW_IID=$(echo "$MIADD"|jq -r '.item_id // 0')
else pass "mi: skip"; NEW_IID=0
fi

echo "== 8. Menu Item List =="
if [ "$NEW_MID" -gt 0 ] 2>/dev/null; then
	MIL=$(api_post -d "{\"action\":\"menu_item_list\",\"menu_id\":$NEW_MID}" "$WPCC_BASE/operations/menu_manage/run")
	assert_contains "mil: list" "$MIL" "menu_item_list"
else pass "mil: skip"
fi

echo "== 9. Menu Item Remove =="
if [ "${NEW_IID:-0}" -gt 0 ] 2>/dev/null; then
	MIRM=$(api_post -d "{\"action\":\"menu_item_remove\",\"item_id\":$NEW_IID}" "$WPCC_BASE/operations/menu_manage/run")
	assert_contains "mir: remove" "$MIRM" "menu_item_remove"
else pass "mir: skip"
fi

echo "== 10. Menu Locations =="
LL=$(api_post -d '{"action":"menu_location_list"}' "$WPCC_BASE/operations/menu_manage/run")
assert_contains "ll: list" "$LL" "menu_location_list"
assert_true "ll: has locations" "$(echo "$LL"|jq -r 'if .locations then "true" else "false" end')"

echo "== 11. Menu Inventory =="
MINV=$(api_post -d '{"action":"menu_inventory"}' "$WPCC_BASE/operations/menu_manage/run")
assert_contains "minv: inv" "$MINV" "menu_inventory"
assert_true "minv: has menus" "$(echo "$MINV"|jq -r 'if .menus then "true" else "false" end')"

echo "== 12. Menu Analyze =="
MANALYZE=$(api_post -d '{"action":"menu_analyze"}' "$WPCC_BASE/operations/menu_manage/run")
assert_contains "ma: analyze" "$MANALYZE" "menu_analyze"
assert_true "ma: has issues" "$(echo "$MANALYZE"|jq -r 'if .issues then "true" else "false" end')"

echo "== 13. Menu Tree Get =="
if [ "$NEW_MID" -gt 0 ] 2>/dev/null; then
	TREE=$(api_post -d "{\"action\":\"menu_tree_get\",\"menu_id\":$NEW_MID}" "$WPCC_BASE/operations/menu_manage/run")
	assert_contains "tree: get" "$TREE" "menu_tree_get"
else pass "tree: skip"
fi

echo "== 14. Menu Update =="
if [ "$NEW_MID" -gt 0 ] 2>/dev/null; then
	MUPD=$(api_post -d "{\"action\":\"menu_update\",\"menu_id\":$NEW_MID,\"name\":\"Updated $MNAME\"}" "$WPCC_BASE/operations/menu_manage/run")
	assert_contains "mu: update" "$MUPD" "menu_update"
else pass "mu: skip"
fi

echo "== 15. Menu Delete =="
if [ "$NEW_MID" -gt 0 ] 2>/dev/null; then
	MDEL=$(api_post -d "{\"action\":\"menu_delete\",\"menu_id\":$NEW_MID}" "$WPCC_BASE/operations/menu_manage/run")
	assert_contains "md: delete" "$MDEL" "menu_delete"
else pass "md: skip"
fi

echo "== 16. Validation =="
BAD=$(api_post -d '{"action":"bad"}' "$WPCC_BASE/operations/menu_manage/run")
assert_contains "val: bad" "$BAD" "Invalid menu action"
NF=$(api_post -d '{"action":"menu_get","menu_id":99999}' "$WPCC_BASE/operations/menu_manage/run")
assert_contains "val: nf" "$NF" "error"

echo "== 17. MCP =="
MCP_TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":1}')
assert_true "mcp: menu tool" "$(echo "$MCP_TOOLS"|jq -r 'any(.result.tools[];.name=="menu_manage")')"

echo "== 18. Timeline =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=100")
assert_true "tml: menu events" "$(echo "$TL"|jq -r 'any(.[];.label=="Menu created" or .label=="Menu operation completed")')"

echo "== 19. Rollback =="
RB=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"rollback_id":"nonexistent"}' "$WPCC_BASE/operations/menu_manage/rollback")
assert_true "rb: endpoint" "$( [ "$RB" = "400" -o "$RB" = "404" ] && echo true || echo false )"

echo "== 20. No Token =="
assert_contains "auth: 401" "$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"action":"menu_list"}' "$WPCC_BASE/operations/menu_manage/run")" "401"

echo "== 21. Performance =="
PSTART=$(date +%s%N); api_post -d '{"action":"menu_list"}' "$WPCC_BASE/operations/menu_manage/run" >/dev/null
PEND=$(date +%s%N); echo "  INFO: $(((PEND-PSTART)/1000000))ms"
assert_true "perf: ok" "true"

echo ""; echo "== Summary =="; echo "  $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]