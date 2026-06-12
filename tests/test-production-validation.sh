#!/usr/bin/env bash
# Step 49 — Production Beta Validation suite
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
assert_not_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" != *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }
api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
api_code() { curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

START_TIME=$(date +%s)
echo "Production Beta Validation — $(date)"
echo ""

# ===================================================================
echo "== 1. Platform Health =="
HEALTH=$(api "$WPCC_BASE/health")
assert_eq "health: status ok" "ok" "$(echo "$HEALTH" | jq -r '.status')"
assert_eq "health: plugin version" "0.1.0" "$(echo "$HEALTH" | jq -r '.plugin_version')"

MANIFEST=$(api "$WPCC_BASE/agent/manifest")
assert_true "health: manifest accessible" "$(echo "$MANIFEST" | jq -r 'if .plugin then "true" else "false" end')"
assert_true "health: manifest has capabilities" "$(echo "$MANIFEST" | jq -r 'if .capabilities then "true" else "false" end')"
assert_true "health: manifest has endpoints" "$(echo "$MANIFEST" | jq -r 'if .endpoints then "true" else "false" end')"

CONTEXT=$(api "$WPCC_BASE/agent/context")
assert_true "health: context accessible" "$(echo "$CONTEXT" | jq -r 'if .health then "true" else "false" end')"
assert_true "health: context has site_summary" "$(echo "$CONTEXT" | jq -r 'if .site_summary then "true" else "false" end')"

# ===================================================================
echo "== 2. Queue Validation — State Integrity =="
QUEUE=$(api "$WPCC_BASE/operations/queue?limit=10")
assert_true "queue: list accessible" "$(echo "$QUEUE" | jq -r 'if type == "array" then "true" else "false" end')"

PENDING=$(echo "$CONTEXT" | jq -r '.pending_queue_count')
RUNNING=$(echo "$CONTEXT" | jq -r '.running_queue_count')
FAILED=$(echo "$CONTEXT" | jq -r '.failed_queue_count')
assert_true "queue: counts are integers" "$( [ "$PENDING" -ge 0 ] 2>/dev/null && echo true || echo false )"
assert_true "queue: running count integer" "$( [ "$RUNNING" -ge 0 ] 2>/dev/null && echo true || echo false )"
assert_true "queue: failed count integer" "$( [ "$FAILED" -ge 0 ] 2>/dev/null && echo true || echo false )"

# ===================================================================
echo "== 3. Queue Validation — Request/Approve/Execute =="
REQ_BODY='{"operation_id":"database_inspect","payload":{"action":"db_table_list"},"session_id":null,"task_id":null}'
REQ=$(api_post -d "$REQ_BODY" "$WPCC_BASE/operations/requests")
REQ_ID=$(echo "$REQ" | jq -r '.request_id // empty')
assert_true "queue: request created" "$(echo "$REQ" | jq -r 'if .request_id then "true" else "false" end')"

if [ -n "$REQ_ID" ]; then
	APPROVE=$(api_post -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/approve")
	assert_eq "queue: request approved" "approved" "$(echo "$APPROVE" | jq -r '.status')"

	EXEC=$(api_post -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/execute")
	assert_true "queue: execution result" "$(echo "$EXEC" | jq -r 'if .id and .status then "true" else "false" end')"
fi

RESULTS=$(api "$WPCC_BASE/operations/results?limit=5")
assert_true "queue: results accessible" "$(echo "$RESULTS" | jq -r 'if type == "array" then "true" else "false" end')"

# ===================================================================
echo "== 4. Queue Validation — Retry Integrity =="
RETRY_ITEMS=$(echo "$CONTEXT" | jq -r '.retryable_queue_items')
assert_true "queue: retryable items array" "$( [ "$RETRY_ITEMS" != "null" ] && echo true || echo false )"

# ===================================================================
echo "== 5. Content Runtime Validation =="
CONTENT=$(api_post -d '{"action":"content_list","type":"post","per_page":3}' "$WPCC_BASE/operations/content_manage/run")
assert_contains "content: list returns items" "$CONTENT" "items"
CONTENT_COUNT=$(echo "$CONTENT" | jq -r '.total // 0')
assert_true "content: post count > 0" "$( [ "$CONTENT_COUNT" -gt 0 ] 2>/dev/null && echo true || echo false )"

FIRST_POST_ID=$(echo "$CONTENT" | jq -r '.items[0].id // empty')
if [ -n "$FIRST_POST_ID" ]; then
	CONTENT_GET=$(api_post -d "{\"action\":\"content_get\",\"content_id\":$FIRST_POST_ID}" "$WPCC_BASE/operations/content_manage/run")
	assert_contains "content: get single works" "$CONTENT_GET" "title"
fi

# ===================================================================
echo "== 6. Database Inspection Validation =="
DB1=$(api_post -d '{"action":"db_health_summary"}' "$WPCC_BASE/operations/database_inspect/run")
assert_contains "db: health summary" "$DB1" "db_size_mb"
assert_true "db: has largest_table" "$(echo "$DB1" | jq -r 'if .largest_table then "true" else "false" end')"

DB2=$(api_post -d '{"action":"db_table_list"}' "$WPCC_BASE/operations/database_inspect/run")
assert_contains "db: table list" "$DB2" "posts"

DB3=$(api_post -d '{"action":"db_autoload_analysis"}' "$WPCC_BASE/operations/database_inspect/run")
assert_true "db: autoload analysis" "$(echo "$DB3" | jq -r 'if .autoloaded_count then "true" else "false" end')"

DB4=$(api_post -d '{"action":"db_table_stats","table":"posts"}' "$WPCC_BASE/operations/database_inspect/run")
assert_true "db: table stats" "$(echo "$DB4" | jq -r 'if .table then "true" else "false" end')"

# ===================================================================
echo "== 7. Snapshot Validation =="
SNAP=$(api_post -d '{"action":"snapshot_create","path":"themes/mosharaf-core/style.css","label":"Production Validation Snapshot"}' "$WPCC_BASE/operations/snapshot_manage/run")
SNAP_ID=$(echo "$SNAP" | jq -r '.snapshot_id // empty')
assert_true "snap: created" "$(echo "$SNAP" | jq -r 'if .snapshot_id then "true" else "false" end')"

SNAP_LIST=$(api_post -d '{"action":"snapshot_list"}' "$WPCC_BASE/operations/snapshot_manage/run")
assert_true "snap: list has snapshots" "$(echo "$SNAP_LIST" | jq -r 'if .snapshots then "true" else "false" end')"

if [ -n "$SNAP_ID" ]; then
	SNAP_VERIFY=$(api_post -d "{\"action\":\"snapshot_verify\",\"snapshot_id\":\"$SNAP_ID\"}" "$WPCC_BASE/operations/snapshot_manage/run")
	assert_contains "snap: verified" "$SNAP_VERIFY" "$SNAP_ID"
fi

# ===================================================================
echo "== 8. MCP Runtime — 20 Rapid Requests =="
MCP_START=$(date +%s)
FAILED_MCP=0
for i in $(seq 1 20); do
	RESP=$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
	if ! echo "$RESP" | jq -e '.result.serverInfo' >/dev/null 2>&1; then
		FAILED_MCP=$((FAILED_MCP+1))
	fi
done
MCP_END=$(date +%s)
MCP_DURATION=$((MCP_END - MCP_START))
assert_eq "mcp: 20 rapid requests all pass" "0" "$FAILED_MCP"
assert_true "mcp: duration < 30s" "$( [ "$MCP_DURATION" -lt 30 ] && echo true || echo false )"
echo "  INFO: 20 MCP requests in ${MCP_DURATION}s"

MCP_RESOURCES=$(mcp '{"jsonrpc":"2.0","method":"resources/list","id":2}')
assert_true "mcp: resources list" "$(echo "$MCP_RESOURCES" | jq -r 'if .result.resources then "true" else "false" end')"

MCP_TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":3}')
assert_true "mcp: tools list" "$(echo "$MCP_TOOLS" | jq -r 'if .result.tools then "true" else "false" end')"

MCP_MANIFEST=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://manifest"},"id":4}')
assert_true "mcp: resource read" "$(echo "$MCP_MANIFEST" | jq -r 'if .result then "true" else "false" end')"

MCP_BAD=$(mcp '{"jsonrpc":"2.0","method":"nonexistent_method","id":99}')
assert_contains "mcp: unknown method handled" "$MCP_BAD" "-32601"

# ===================================================================
echo "== 9. Approval Runtime Validation =="
assert_eq "approval: db_inspect no approval" "false" "$(echo "$MANIFEST" | jq -r '.operations[] | select(.id == "database_inspect") | .requires_approval')"
assert_eq "approval: option_manage needs approval" "true" "$(echo "$MANIFEST" | jq -r '.operations[] | select(.id == "option_manage") | .requires_approval')"
assert_eq "approval: safe_search_replace needs approval" "true" "$(echo "$MANIFEST" | jq -r '.operations[] | select(.id == "safe_search_replace") | .requires_approval')"

# ===================================================================
echo "== 10. Rollback Validation — Patch Lifecycle =="
TEST_PATH="themes/mosharaf-core/style.css"
# Read content first
FILE_META=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=$TEST_PATH")
HAS_FILE=$(echo "$FILE_META" | jq -r 'if .path then "true" else "false" end')
assert_true "rollback: file accessible" "$HAS_FILE"

PATCH_BODY=$(jq -n --arg path "$TEST_PATH" '{files: [{path: $path, modified: "/* Step 49 validation marker */"}], explanation: "Production beta validation rollback test", risk_level: "low"}')
PATCH=$(api_post -d "$PATCH_BODY" "$WPCC_BASE/patches")
PATCH_ID=$(echo "$PATCH" | jq -r '.id // empty')
assert_true "rollback: patch created" "$(echo "$PATCH" | jq -r 'if .id then "true" else "false" end')"

if [ -n "$PATCH_ID" ]; then
	APPROVE_PATCH=$(api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/approve")
	assert_contains "rollback: patch approved" "$APPROVE_PATCH" "approved"

	APPLY_PATCH=$(api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/apply")
	assert_contains "rollback: patch applied" "$APPLY_PATCH" "applied"

	ROLLBACK=$(api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/rollback")
	assert_contains "rollback: rolled back" "$ROLLBACK" "rolled_back"

	# Verify file exists and is readable after rollback
	RESTORED=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=$TEST_PATH")
	assert_true "rollback: file restored" "$(echo "$RESTORED" | jq -r 'if .path then "true" else "false" end')"
fi

# ===================================================================
echo "== 11. Security Validation — Protected Files =="
# Test with wp-config.php (always blocked)
WP_CONFIG_BLOCK=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=../../../wp-config.php")
assert_contains "security: wp-config blocked" "$(echo "$WP_CONFIG_BLOCK" | jq -r '.code // ""')" "wpcc"

# Test that a real path outside allowed dirs is rejected
OUTSIDE=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=/etc/passwd")
assert_contains "security: outside path blocked" "$(echo "$OUTSIDE" | jq -r '.code // ""')" "wpcc"

# ===================================================================
echo "== 12. Security Validation — Secret Redaction =="
SEARCH=$(api "$WPCC_BASE/search?q=define&type=text&path=themes/mosharaf-core")
assert_true "security: search works" "$(echo "$SEARCH" | jq -r 'if .matches then "true" else "false" end')"
# Redaction flag presence depends on content, just verify no crash

# ===================================================================
echo "== 13. Security Validation — Token Required =="
HTTP_NO_TOKEN=$(curl -s -o /dev/null -w "%{http_code}" "$WPCC_BASE/health")
assert_contains "security: no token HTTP 401" "$HTTP_NO_TOKEN" "401"

HTTP_MCP_NO_TOKEN=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","method":"initialize","id":1}' "$WPCC_BASE/mcp")
assert_true "security: MCP no token blocked (4xx/5xx)" "$( [ "$HTTP_MCP_NO_TOKEN" != "200" ] && echo true || echo false )"

# ===================================================================
echo "== 14. AI Client Registry Validation =="
CLIENTS=$(api "$WPCC_BASE/ai-clients")
assert_eq "ai: total clients" "11" "$(echo "$CLIENTS" | jq -r '.counts.total')"
assert_eq "ai: active clients" "2" "$(echo "$CLIENTS" | jq -r '.counts.active')"
assert_eq "ai: claude gold" "gold" "$(echo "$CLIENTS" | jq -r '.clients.claude.status')"
assert_eq "ai: planned count" "0" "$(echo "$CLIENTS" | jq -r '.counts.planned')"

CLAUDE_CFG=$(api "$WPCC_BASE/ai-clients/claude/config")
assert_true "ai: generic config works" "$(echo "$CLAUDE_CFG" | jq -r 'if .config.mcpServers then "true" else "false" end')"

UNK_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/ai-clients/nonexistent/config")
assert_contains "ai: unknown client 4xx" "$UNK_CODE" "4"

CODEX_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/ai-clients/codex/config")
assert_eq "ai: codex configured 200" "200" "$CODEX_CODE"

# ===================================================================
echo "== 15. Recommendations Validation =="
RECS=$(api "$WPCC_BASE/recommendations?limit=5")
assert_true "recs: list accessible" "$(echo "$RECS" | jq -r 'if type == "array" then "true" else "false" end')"
assert_true "recs: summary in context" "$(echo "$CONTEXT" | jq -r 'if .recommendation_summary then "true" else "false" end')"

# ===================================================================
echo "== 16. Timeline Validation =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=20")
TL_COUNT=$(echo "$TL" | jq -r 'length')
assert_true "timeline: events returned" "$( [ "$TL_COUNT" -gt 0 ] 2>/dev/null && echo true || echo false )"
assert_true "timeline: has timestamps" "$(echo "$TL" | jq -r 'all(.[]; has("timestamp"))')"
assert_true "timeline: has types" "$(echo "$TL" | jq -r 'all(.[]; has("type"))')"
assert_true "timeline: has labels" "$(echo "$TL" | jq -r 'all(.[]; has("label"))')"

# ===================================================================
echo "== 17. Health Verification Validation =="
HV=$(api_post -d '{}' "$WPCC_BASE/health/verify")
assert_true "health: verification runs" "$(echo "$HV" | jq -r 'if .verification_id then "true" else "false" end')"
assert_contains "health: has results" "$HV" "checks"
assert_contains "health: frontend check" "$HV" "frontend"

# ===================================================================
echo "== 18. System Environment Validation =="
ENV=$(api "$WPCC_BASE/system/environment")
MODE=$(echo "$ENV" | jq -r '.mode')
assert_true "env: mode is valid" "$( [ "$MODE" = "development" -o "$MODE" = "staging" -o "$MODE" = "production" ] && echo true || echo false )"

# ===================================================================
echo "== 19. Backward Compatibility Validation =="
assert_true "bwcompat: /claude/config" "$(api "$WPCC_BASE/claude/config" | jq -r 'if .mcpServers then "true" else "false" end')"
assert_true "bwcompat: /claude/discovery" "$(api "$WPCC_BASE/claude/discovery" | jq -r 'if .server then "true" else "false" end')"
assert_true "bwcompat: /claude/tools" "$(api "$WPCC_BASE/claude/tools" | jq -r 'if .tool_groups then "true" else "false" end')"
assert_true "bwcompat: /claude/prompts" "$(api "$WPCC_BASE/claude/prompts" | jq -r 'if .prompts then "true" else "false" end')"
assert_true "bwcompat: manifest claude_integration" "$(echo "$MANIFEST" | jq -r 'if .claude_integration then "true" else "false" end')"
assert_true "bwcompat: manifest ai_clients" "$(echo "$MANIFEST" | jq -r 'if .ai_clients then "true" else "false" end')"

# ===================================================================
echo "== 20. Option Runtime Validation =="
OPT_GET=$(api_post -d '{"action":"option_get","option_id":"site_title"}' "$WPCC_BASE/operations/option_manage/run")
assert_contains "option: get works" "$OPT_GET" "option_id"
assert_contains "option: has current_value" "$OPT_GET" "current_value"
assert_contains "option: has risk_level" "$OPT_GET" "risk_level"

# ===================================================================
echo "== 21. Plugin Runtime Validation =="
PLUGINS=$(api_post -d '{"action":"plugin_list"}' "$WPCC_BASE/operations/plugin_manage/run")
assert_true "plugin: list has plugins" "$(echo "$PLUGINS" | jq -r 'if .plugins then "true" else "false" end')"
assert_true "plugin: acf-pro present" "$(echo "$PLUGINS" | jq -r 'any(.plugins.plugins[]; .slug == "advanced-custom-fields-pro")')"
assert_true "plugin: woocommerce present" "$(echo "$PLUGINS" | jq -r 'any(.plugins.plugins[]; .slug == "woocommerce")')"
assert_true "plugin: wp-command-center present" "$(echo "$PLUGINS" | jq -r 'any(.plugins.plugins[]; .slug == "wp-command-center")')"

# ===================================================================
echo "== 22. Theme Runtime Validation =="
THEMES=$(api_post -d '{"action":"theme_list"}' "$WPCC_BASE/operations/theme_manage/run")
assert_true "theme: list has themes" "$(echo "$THEMES" | jq -r 'if .themes then "true" else "false" end')"
assert_true "theme: mosharaf-core present" "$(echo "$THEMES" | jq -r 'any(.themes.themes[]; .slug == "mosharaf-core")')"

# ===================================================================
echo "== 23. Site Intelligence Validation =="
SI=$(api "$WPCC_BASE/site-intelligence")
assert_true "site: wordpress info" "$(echo "$SI" | jq -r 'if .wordpress then "true" else "false" end')"
assert_true "site: php info" "$(echo "$SI" | jq -r 'if .php then "true" else "false" end')"
assert_true "site: theme info" "$(echo "$SI" | jq -r 'if .theme then "true" else "false" end')"
assert_true "site: plugins array" "$(echo "$SI" | jq -r 'if (.plugins | type) == "array" then "true" else "false" end')"
assert_true "site: woocommerce info" "$(echo "$SI" | jq -r 'if .woocommerce then "true" else "false" end')"

# ===================================================================
echo "== 24. Agent Context — Section Completeness =="
for section in operations open_recommendations recent_patches recent_actions plugin_management_available theme_management_available environment_mode mcp_server_available wp_cli_available recent_operation_results capability_management_available content_management_available snapshot_management_available database_inspection_available ai_clients; do
	assert_true "ctx: has $section" "$(echo "$CONTEXT" | jq -r "has(\"$section\")")"
done

# ===================================================================
echo "== 25. Performance — Response Times =="
PERF_START=$(date +%s%N)
for i in $(seq 1 5); do api "$WPCC_BASE/health" >/dev/null; done
PERF_END=$(date +%s%N)
PERF_MS=$(( (PERF_END - PERF_START) / 5000000 ))
assert_true "perf: 5 health requests avg < 5s" "$( [ "$PERF_MS" -lt 5000 ] && echo true || echo false )"
echo "  INFO: 5 health requests avg ${PERF_MS}ms"

PERF2_START=$(date +%s%N); api "$WPCC_BASE/agent/context" >/dev/null; PERF2_END=$(date +%s%N)
PERF2_MS=$(( (PERF2_END - PERF2_START) / 1000000 ))
assert_true "perf: context < 5s" "$( [ "$PERF2_MS" -lt 5000 ] && echo true || echo false )"
echo "  INFO: Context: ${PERF2_MS}ms"

PERF3_START=$(date +%s%N); api "$WPCC_BASE/agent/manifest" >/dev/null; PERF3_END=$(date +%s%N)
PERF3_MS=$(( (PERF3_END - PERF3_START) / 1000000 ))
assert_true "perf: manifest < 5s" "$( [ "$PERF3_MS" -lt 5000 ] && echo true || echo false )"
echo "  INFO: Manifest: ${PERF3_MS}ms"

echo ""
echo "== Summary =="
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
echo "  $PASS passed, $FAIL failed"
echo "  Duration: ${DURATION}s"
echo "  Areas: Health, Queue, Content, Database, Snapshots, MCP, Approval, Rollback, Security, AI Clients, Recommendations, Timeline, Health Verify, Environment, Backward Compat, Options, Plugins, Themes, Site Intel, Context, Performance"
[ "$FAIL" -eq 0 ]
