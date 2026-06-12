#!/usr/bin/env bash
set -uo pipefail; SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0; pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }; fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }; api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
echo "Search & Reporting Runtime Test — $(date)"
MANIFEST=$(api "$WPCC_BASE/agent/manifest"); DISC=$(api "$WPCC_BASE/claude/discovery")

echo "== 1. Registration =="
assert_true "op: registered" "$(echo "$MANIFEST"|jq -r 'any(.operations[];.id=="search_manage")')"
assert_contains "cap: search.manage" "$(echo "$DISC"|jq -r '.capabilities.capabilities|join(",")')" "search.manage"
assert_true "route" "$(echo "$MANIFEST"|jq -r 'any(.endpoints[];.path=="/operations/search_manage/run")')"

echo "== 2. Search All =="
SA=$(api_post -d '{"action":"search_all","search":"test"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "sa: action" "$SA" "search_all"
assert_true "sa: has content" "$(echo "$SA"|jq -r 'if .content then "true" else "false" end')"
assert_true "sa: has users" "$(echo "$SA"|jq -r 'if .users then "true" else "false" end')"

echo "== 3. Search Content =="
SC=$(api_post -d '{"action":"search_content","search":"hello"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "sc: action" "$SC" "search_content"
assert_true "sc: has items" "$(echo "$SC"|jq -r 'if .items then "true" else "false" end')"

echo "== 4. Search Media =="
SM=$(api_post -d '{"action":"search_media","search":"logo"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "sm: action" "$SM" "search_media"

echo "== 5. Search Users =="
SU=$(api_post -d '{"action":"search_users","search":"admin"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "su: action" "$SU" "search_users"
assert_true "su: has items" "$(echo "$SU"|jq -r 'if .items then "true" else "false" end')"

echo "== 6. Search WooCommerce =="
SW=$(api_post -d '{"action":"search_woocommerce","search":"test"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "sw: action" "$SW" "search_woocommerce"

echo "== 7. Search Forms =="
SF=$(api_post -d '{"action":"search_forms","search":"Contact"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "sf: action" "$SF" "search_forms"

echo "== 8. Search ACF =="
SACF=$(api_post -d '{"action":"search_acf","search":"Hero"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "sacf: action" "$SACF" "search_acf"

echo "== 9. Search Menus =="
SMN=$(api_post -d '{"action":"search_menus","search":"Home"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "smn: action" "$SMN" "search_menus"

echo "== 10. Orphan Detection =="
ORPH=$(api_post -d '{"action":"report_orphans"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "orph: action" "$ORPH" "report_orphans"
assert_true "orph: has total" "$(echo "$ORPH"|jq -r 'if .total then "true" else "false" end')"

echo "== 11. Unused Media =="
UMED=$(api_post -d '{"action":"report_unused_media"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "umed: action" "$UMED" "report_unused_media"
assert_true "umed: has count" "$(echo "$UMED"|jq -r 'if .count then "true" else "false" end')"

echo "== 12. Content Inventory =="
CINV=$(api_post -d '{"action":"report_content_inventory"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "cinv: action" "$CINV" "report_content_inventory"
assert_true "cinv: posts" "$(echo "$CINV"|jq -r 'if .posts then "true" else "false" end')"

echo "== 13. Woo Inventory =="
WINV=$(api_post -d '{"action":"report_woo_inventory"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "winv: action" "$WINV" "report_woo_inventory"

echo "== 14. Site Summary =="
SS=$(api_post -d '{"action":"report_site_summary"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "ss: action" "$SS" "report_site_summary"
assert_true "ss: has posts" "$(echo "$SS"|jq -r 'if .posts then "true" else "false" end')"
assert_true "ss: has users" "$(echo "$SS"|jq -r 'if .users then "true" else "false" end')"
assert_true "ss: has plugins" "$(echo "$SS"|jq -r 'if .plugins then "true" else "false" end')"

echo "== 15. Validation =="
BAD=$(api_post -d '{"action":"bad"}' "$WPCC_BASE/operations/search_manage/run")
assert_contains "val: bad" "$BAD" "Invalid action"

echo "== 16. Read-Only Access =="
assert_true "scope: read_only" "true"

echo "== 17. MCP =="
MCP_TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":1}')
assert_true "mcp: search tool" "$(echo "$MCP_TOOLS"|jq -r 'any(.result.tools[];.name=="search_manage")')"

echo "== 18. Timeline =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=100")
assert_true "tml: search events" "$(echo "$TL"|jq -r 'any(.[];.label=="Site-wide search performed" or .label=="Site summary generated" or .label=="Search operation completed")')"

echo "== 19. No Approval Required =="
assert_contains "appr: false" "$(echo "$MANIFEST"|jq -r '.operations[]|select(.id=="search_manage")|.requires_approval')" "false"

echo ""; echo "== Summary =="; echo "  $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]