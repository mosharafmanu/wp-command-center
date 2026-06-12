#!/usr/bin/env bash
set -uo pipefail; SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0; pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }; fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }; api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
echo "Site Settings Runtime Test — $(date)"
MANIFEST=$(api "$WPCC_BASE/agent/manifest"); DISC=$(api "$WPCC_BASE/claude/discovery")

echo "== 1. Registration =="
assert_true "op: registered" "$(echo "$MANIFEST"|jq -r 'any(.operations[];.id=="settings_manage")')"
echo "== 2. Capability =="
assert_contains "cap: settings.manage" "$(echo "$DISC"|jq -r '.capabilities.capabilities|join(",")')" "settings.manage"
echo "== 3. Routes =="
assert_true "route: run" "$(echo "$MANIFEST"|jq -r 'any(.endpoints[];.path=="/operations/settings_manage/run")')"
assert_true "route: rollback" "$(echo "$MANIFEST"|jq -r 'any(.endpoints[];.path=="/operations/settings_manage/rollback")')"

echo "== 4. General Get =="
GGET=$(api_post -d '{"action":"settings_general_get"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "gg: get" "$GGET" "settings_general_get"
assert_true "gg: has title" "$(echo "$GGET"|jq -r 'if .settings.site_title then "true" else "false" end')"
assert_true "gg: has tz" "$(echo "$GGET"|jq -r 'if .settings.timezone then "true" else "false" end')"

echo "== 5. Reading Get =="
RGET=$(api_post -d '{"action":"settings_reading_get"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "rg: get" "$RGET" "settings_reading_get"
assert_true "rg: has front_page" "$(echo "$RGET"|jq -r 'if .settings.front_page then "true" else "false" end')"

echo "== 6. Discussion Get =="
DGET=$(api_post -d '{"action":"settings_discussion_get"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "dg: get" "$DGET" "settings_discussion_get"

echo "== 7. Media Get =="
MGET=$(api_post -d '{"action":"settings_media_get"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "mg: get" "$MGET" "settings_media_get"

echo "== 8. Permalink Get =="
PGET=$(api_post -d '{"action":"settings_permalink_get"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "pg: get" "$PGET" "settings_permalink_get"
assert_true "pg: has structure" "$(echo "$PGET"|jq -r 'if .settings.structure then "true" else "false" end')"

echo "== 9. Privacy Get =="
PRGET=$(api_post -d '{"action":"settings_privacy_get"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "prg: get" "$PRGET" "settings_privacy_get"

echo "== 10. Inventory =="
INV=$(api_post -d '{"action":"settings_inventory"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "inv: action" "$INV" "settings_inventory"
assert_true "inv: has title" "$(echo "$INV"|jq -r 'if .site_title then "true" else "false" end')"

echo "== 11. Analyze =="
ANZ=$(api_post -d '{"action":"settings_analyze"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "anz: action" "$ANZ" "settings_analyze"
assert_true "anz: has issues" "$(echo "$ANZ"|jq -r 'if .issues then "true" else "false" end')"

echo "== 12. General Update =="
OLD=$(echo "$GGET"|jq -r '.settings.site_title')
GUPD=$(api_post -d "{\"action\":\"settings_general_update\",\"site_title\":\"$OLD\"}" "$WPCC_BASE/operations/settings_manage/run")
assert_contains "gu: update" "$GUPD" "settings_general_update"

echo "== 13. Validation =="
BAD=$(api_post -d '{"action":"bad"}' "$WPCC_BASE/operations/settings_manage/run")
assert_contains "val: bad" "$BAD" "Invalid settings action"

echo "== 14. MCP =="
MCP_TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":1}')
assert_true "mcp: settings tool" "$(echo "$MCP_TOOLS"|jq -r 'any(.result.tools[];.name=="settings_manage")')"

echo "== 15. Timeline =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=100")
assert_true "tml: settings" "$(echo "$TL"|jq -r 'any(.[];.label=="Site settings updated" or .label=="Settings operation completed")')"

echo "== 16. Rollback =="
RB=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d '{"rollback_id":"nonexistent"}' "$WPCC_BASE/operations/settings_manage/rollback")
assert_true "rb: endpoint" "$( [ "$RB" = "400" -o "$RB" = "404" ] && echo true || echo false )"

echo "== 17. No Token =="
assert_contains "auth: 401" "$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"action":"settings_inventory"}' "$WPCC_BASE/operations/settings_manage/run")" "401"

echo ""; echo "== Summary =="; echo "  $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]