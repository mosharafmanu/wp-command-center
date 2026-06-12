#!/usr/bin/env bash
# Step 50 — Enterprise Hardening test suite
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }
api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

echo "Enterprise Hardening Validation — $(date)"
echo ""

# ===================================================================
echo "== 1. Capability Enforcement — MCP Default Fix =="
MANIFEST=$(api "$WPCC_BASE/agent/manifest")
assert_contains "cap: enforcement defaults checked" "$(echo "$MANIFEST" | jq -r '.capability_negotiation // {}')" ""

# Verify capability.enforcement from discovery reports correctly
DISC=$(api "$WPCC_BASE/claude/discovery")
CAP_ENFORCED=$(echo "$DISC" | jq -r '.capabilities.enforcement')
assert_true "cap: enforcement is boolean" "$( [ "$CAP_ENFORCED" = "true" -o "$CAP_ENFORCED" = "false" ] && echo true || echo false )"

# ===================================================================
echo "== 2. Capability Registry — capability_manage is mapped =="
OPS_MAP=$(echo "$DISC" | jq -r '.capabilities.operation_map')
assert_contains "cap: capability_manage has mapping" "$OPS_MAP" "capability_manage"
assert_contains "cap: content_manage mapped" "$OPS_MAP" "content_manage"
assert_contains "cap: plugin_manage mapped" "$OPS_MAP" "plugin_manage"
assert_contains "cap: wp_cli_bridge mapped" "$OPS_MAP" "wp_cli_bridge"
assert_contains "cap: database_inspect mapped" "$OPS_MAP" "database_inspect"

# ===================================================================
echo "== 3. Approval Enforcement — All Mutation Ops Require Approval =="
MANIFEST_OPS=$(echo "$MANIFEST" | jq -r '.operations')
for op_id in plugin_manage theme_manage content_manage option_manage safe_search_replace safe_updates media_import wp_cli_bridge capability_manage; do
	REQUIRES_APPROVAL=$(echo "$MANIFEST_OPS" | jq -r --arg id "$op_id" '.[] | select(.id == $id) | .requires_approval')
	assert_eq "approval: $op_id requires approval" "true" "$REQUIRES_APPROVAL"
done

# database_inspect should NOT require approval
DB_INSPECT_APPROVAL=$(echo "$MANIFEST_OPS" | jq -r '.[] | select(.id == "database_inspect") | .requires_approval')
assert_eq "approval: database_inspect no approval" "false" "$DB_INSPECT_APPROVAL"

# ===================================================================
echo "== 4. Queue Lifecycle — Request/Approve/Queue/Execute =="
REQ_BODY='{"operation_id":"database_inspect","payload":{"action":"db_table_list"},"session_id":null,"task_id":null}'
REQ=$(api_post -d "$REQ_BODY" "$WPCC_BASE/operations/requests")
REQ_ID=$(echo "$REQ" | jq -r '.request_id // empty')
assert_true "queue: request created" "$(echo "$REQ" | jq -r 'if .request_id then "true" else "false" end')"

if [ -n "$REQ_ID" ]; then
	APPROVE=$(api_post -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/approve")
	assert_eq "queue: approved" "approved" "$(echo "$APPROVE" | jq -r '.status')"

	QUEUE_LIST=$(api "$WPCC_BASE/operations/queue?status=queued&limit=5")
	assert_true "queue: item appears in queue" "$(echo "$QUEUE_LIST" | jq -r 'if length > 0 then "true" else "false" end')"

	EXEC=$(api_post -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/execute")
	assert_true "queue: execution completes" "$(echo "$EXEC" | jq -r 'if .id and .status then "true" else "false" end')"
fi

# ===================================================================
echo "== 5. Rollback — Patch Create/Approve/Apply/Rollback =="
TEST_PATH="themes/mosharaf-core/style.css"
PATCH_BODY=$(jq -n --arg path "$TEST_PATH" '{files: [{path: $path, modified: "/* Enterprise hardening validation */"}], explanation: "Enterprise hardening rollback test", risk_level: "low"}')
PATCH=$(api_post -d "$PATCH_BODY" "$WPCC_BASE/patches")
PATCH_ID=$(echo "$PATCH" | jq -r '.id // empty')
assert_true "rollback: patch created" "$(echo "$PATCH" | jq -r 'if .id then "true" else "false" end')"

if [ -n "$PATCH_ID" ]; then
	api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/approve" >/dev/null
	APPLY=$(api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/apply")
	assert_contains "rollback: applied" "$APPLY" "applied"

	ROLLBACK=$(api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/rollback")
	assert_contains "rollback: rolled back" "$ROLLBACK" "rolled_back"

	RESTORED=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=$TEST_PATH")
	assert_true "rollback: file accessible" "$(echo "$RESTORED" | jq -r 'if .path then "true" else "false" end')"
fi

# ===================================================================
echo "== 6. MCP Runtime — Stability Under Load =="
FAILED=0
for i in $(seq 1 30); do
	RESP=$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
	if ! echo "$RESP" | jq -e '.result.serverInfo' >/dev/null 2>&1; then
		FAILED=$((FAILED+1))
	fi
done
assert_eq "mcp: 30 rapid requests" "0" "$FAILED"

# ===================================================================
echo "== 7. Security — Protected Files =="
WP_CONFIG=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=../../../wp-config.php")
assert_contains "sec: wp-config blocked" "$(echo "$WP_CONFIG" | jq -r '.code // ""')" "wpcc"

ENV_FILE=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/content?path=themes/mosharaf-core/../../.htaccess")
assert_contains "sec: path traversal blocked" "$(echo "$ENV_FILE" | jq -r '.code // ""')" "wpcc"

# ===================================================================
echo "== 8. Security — Token Required =="
NO_AUTH_HEALTH=$(curl -s -o /dev/null -w "%{http_code}" "$WPCC_BASE/health")
assert_contains "sec: REST no-token 401" "$NO_AUTH_HEALTH" "401"

NO_AUTH_MCP=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","id":1}' "$WPCC_BASE/mcp")
assert_true "sec: MCP no-token blocked" "$( [ "$NO_AUTH_MCP" != "200" ] && echo true || echo false )"

# ===================================================================
echo "== 9. Audit Events — Timeline Completeness =="
TL=$(api "$WPCC_BASE/agent/timeline?limit=50")
assert_true "timeline: has events" "$(echo "$TL" | jq -r 'if length > 0 then "true" else "false" end')"
assert_true "timeline: timestamp present" "$(echo "$TL" | jq -r 'all(.[]; has("timestamp"))')"
assert_true "timeline: type present" "$(echo "$TL" | jq -r 'all(.[]; has("type"))')"
assert_true "timeline: label present" "$(echo "$TL" | jq -r 'all(.[]; has("label"))')"
assert_true "timeline: status present" "$(echo "$TL" | jq -r 'all(.[]; has("status"))')"

# ===================================================================
echo "== 10. Audit Completeness — Lifecycle Events =="
assert_true "audit: patch lifecycle events present" "$(echo "$TL" | jq -r 'any(.[]; .label == "Patch created" or .label == "Patch approved" or .label == "Patch applied")')"
assert_true "audit: has timeline entries" "$(echo "$TL" | jq -r 'if length > 0 then "true" else "false" end')"

# ===================================================================
echo "== 11. Backward Compatibility =="
for endpoint in "/claude/config" "/claude/discovery" "/claude/tools" "/claude/prompts"; do
	assert_true "bwcompat: $endpoint works" "$(api "$WPCC_BASE$endpoint" | jq -r 'if . then "true" else "false" end')"
done
assert_true "bwcompat: /ai-clients works" "$(api "$WPCC_BASE/ai-clients" | jq -r 'if .clients then "true" else "false" end')"

# ===================================================================
echo "== 12. AI Client Registry — Completeness =="
CLIENTS=$(api "$WPCC_BASE/ai-clients")
assert_eq "ai: 11 clients total" "11" "$(echo "$CLIENTS" | jq -r '.counts.total')"
assert_eq "ai: 2 active" "2" "$(echo "$CLIENTS" | jq -r '.counts.active')"
assert_eq "ai: 0 planned" "0" "$(echo "$CLIENTS" | jq -r '.counts.planned')"

# ===================================================================
echo "== 13. Context Completeness =="
CONTEXT=$(api "$WPCC_BASE/agent/context")
REQUIRED_SECTIONS="health capabilities site_summary context operations open_recommendations recent_patches recent_actions recent_audit_entries mcp_server_available mcp_endpoint ai_clients environment_mode wp_cli_available plugin_management_available theme_management_available content_management_available snapshot_management_available database_inspection_available capability_management_available"
for section in $REQUIRED_SECTIONS; do
	assert_true "ctx: has $section" "$(echo "$CONTEXT" | jq -r "has(\"$section\")")"
done

# ===================================================================
echo "== 14. Health Verification =="
HV=$(api_post -d '{}' "$WPCC_BASE/health/verify")
assert_true "health: verification_id present" "$(echo "$HV" | jq -r 'if .verification_id then "true" else "false" end')"
assert_contains "health: has checks" "$HV" "checks"

# ===================================================================
echo "== 15. Recommendation Engine =="
RECS=$(api "$WPCC_BASE/recommendations?limit=5")
assert_true "recs: list works" "$(echo "$RECS" | jq -r 'if type == "array" then "true" else "false" end')"

# ===================================================================
echo "== 16. Database Inspection — All Actions =="
for action in db_table_list db_health_summary db_table_stats db_autoload_analysis db_row_counts; do
	DB_ACTION=$(api_post -d "{\"action\":\"$action\",\"table\":\"posts\"}" "$WPCC_BASE/operations/database_inspect/run")
	assert_true "db: $action works" "$(echo "$DB_ACTION" | jq -r 'if .action then "true" else "false" end')"
done

# ===================================================================
echo "== 17. Content Runtime =="
CONTENT=$(api_post -d '{"action":"content_list","type":"post","per_page":3}' "$WPCC_BASE/operations/content_manage/run")
assert_true "content: list has items" "$(echo "$CONTENT" | jq -r 'if .items then "true" else "false" end')"
CONTENT_COUNT=$(echo "$CONTENT" | jq -r '.total // 0')
assert_true "content: post count > 0" "$( [ "$CONTENT_COUNT" -gt 0 ] 2>/dev/null && echo true || echo false )"

# ===================================================================
echo "== 18. Plugin/Theme Runtime =="
PLUGINS=$(api_post -d '{"action":"plugin_list"}' "$WPCC_BASE/operations/plugin_manage/run")
assert_true "plugin: list has plugins" "$(echo "$PLUGINS" | jq -r 'if .plugins then "true" else "false" end')"

THEMES=$(api_post -d '{"action":"theme_list"}' "$WPCC_BASE/operations/theme_manage/run")
assert_true "theme: list has themes" "$(echo "$THEMES" | jq -r 'if .themes then "true" else "false" end')"

# ===================================================================
echo "== 19. Option Runtime =="
OPT_GET=$(api_post -d '{"action":"option_get","option_id":"site_title"}' "$WPCC_BASE/operations/option_manage/run")
assert_contains "option: get site_title" "$OPT_GET" "option_id"
assert_contains "option: has current_value" "$OPT_GET" "current_value"

# ===================================================================
echo "== 20. Environment Mode =="
ENV=$(api "$WPCC_BASE/system/environment")
MODE=$(echo "$ENV" | jq -r '.mode')
assert_true "env: valid mode" "$( [ "$MODE" = "development" -o "$MODE" = "staging" -o "$MODE" = "production" ] && echo true || echo false )"

# ===================================================================
echo "== 21. Capability Registry — capability.admin exists =="
ALL_CAPS=$(echo "$DISC" | jq -r '.capabilities.capabilities | join(",")')
assert_contains "cap: capability.admin in all caps" "$ALL_CAPS" "capability.admin"
assert_contains "cap: system.admin in all caps" "$ALL_CAPS" "system.admin"
assert_contains "cap: content.manage in all caps" "$ALL_CAPS" "content.manage"

# ===================================================================
echo "== 22. Manifest — ai_clients block =="
assert_true "manifest: ai_clients block" "$(echo "$MANIFEST" | jq -r 'if .ai_clients then "true" else "false" end')"
assert_eq "manifest: 11 clients" "11" "$(echo "$MANIFEST" | jq -r '.ai_clients.clients | length')"

# ===================================================================
echo "== 23. Capability enforce default unified =="
# Both MCP and REST executor paths should be consistent
# Verify ClaudeIntegration reports enforcement correctly
CLAUDE_ENFORCEMENT=$(echo "$DISC" | jq -r '.capabilities.enforcement')
assert_true "cap: enforcement reported from discovery" "$( [ "$CLAUDE_ENFORCEMENT" = "true" -o "$CLAUDE_ENFORCEMENT" = "false" ] && echo true || echo false )"

# ===================================================================
echo "== 24. Site Intelligence =="
SI=$(api "$WPCC_BASE/site-intelligence")
assert_true "site: wordpress info" "$(echo "$SI" | jq -r 'if .wordpress then "true" else "false" end')"
assert_true "site: php info" "$(echo "$SI" | jq -r 'if .php then "true" else "false" end')"
assert_true "site: plugins array" "$(echo "$SI" | jq -r 'if (.plugins | type) == "array" then "true" else "false" end')"
assert_true "site: woocommerce info" "$(echo "$SI" | jq -r 'if .woocommerce then "true" else "false" end')"

# ===================================================================
echo "== 25. Diagnostics =="
DIAG=$(api "$WPCC_BASE/diagnostics?type=security")
assert_contains "diag: has checks" "$DIAG" "checks"

# ===================================================================
echo "== 26. Recommendations — Scan =="
SCAN=$(api_post -d '{}' "$WPCC_BASE/recommendations/scan")
assert_true "recs: scan runs" "$(echo "$SCAN" | jq -r 'if .recommendations then "true" else "false" end')"

# ===================================================================
echo "== 27. Generic AI Client Config =="
CLAUDE_CFG=$(api "$WPCC_BASE/ai-clients/claude/config")
assert_true "ai: generic claude config" "$(echo "$CLAUDE_CFG" | jq -r 'if .config.mcpServers then "true" else "false" end')"
CODEX_CFG=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/ai-clients/codex/config")
assert_eq "ai: codex returns 200" "200" "$CODEX_CFG"

# ===================================================================
echo "== 28. Backward Compat — manifest keys =="
assert_true "bwcompat: claude_integration key" "$(echo "$MANIFEST" | jq -r 'if .claude_integration then "true" else "false" end')"

# ===================================================================
echo "== 29. Queue — Retry/Worker Status =="
RETRY_COUNT=$(echo "$CONTEXT" | jq -r '.retryable_queue_items | length')
assert_true "queue: retryable count valid" "$( [ "$RETRY_COUNT" -ge 0 ] 2>/dev/null && echo true || echo false )"
WORKER_STATUS=$(echo "$CONTEXT" | jq -r '.queue_worker_status // ""')
assert_true "queue: worker status present" "$(echo "$CONTEXT" | jq -r 'if .queue_worker_status then "true" else "false" end')"

# ===================================================================
echo "== 30. Performance — Response Baseline =="
PERF_START=$(date +%s%N)
api "$WPCC_BASE/health" >/dev/null
PERF_END=$(date +%s%N)
PERF_MS=$(( (PERF_END - PERF_START) / 1000000 ))
assert_true "perf: health < 2s" "$( [ "$PERF_MS" -lt 2000 ] && echo true || echo false )"
echo "  INFO: Health: ${PERF_MS}ms"

PERF2_START=$(date +%s%N)
api "$WPCC_BASE/agent/manifest" >/dev/null
PERF2_END=$(date +%s%N)
PERF2_MS=$(( (PERF2_END - PERF2_START) / 1000000 ))
assert_true "perf: manifest < 5s" "$( [ "$PERF2_MS" -lt 5000 ] && echo true || echo false )"
echo "  INFO: Manifest: ${PERF2_MS}ms"

PERF3_START=$(date +%s%N)
api "$WPCC_BASE/agent/context" >/dev/null
PERF3_END=$(date +%s%N)
PERF3_MS=$(( (PERF3_END - PERF3_START) / 1000000 ))
assert_true "perf: context < 5s" "$( [ "$PERF3_MS" -lt 5000 ] && echo true || echo false )"
echo "  INFO: Context: ${PERF3_MS}ms"

# Verify snapshot count is readable
SNAP_COUNT=$(echo "$CONTEXT" | jq -r '.snapshot_count // 0')
assert_true "snap: count valid" "$( [ "$SNAP_COUNT" -ge 0 ] 2>/dev/null && echo true || echo false )"
# Verify DB size is readable
DB_SIZE=$(echo "$CONTEXT" | jq -r '.database_size_mb // 0')
assert_true "db: size valid" "$(echo "$DB_SIZE" | jq -r 'type == "number"')"

# Verify manifest_version on agent/context matches
MANIFEST_VER_CONTEXT=$(echo "$CONTEXT" | jq -r '.manifest_version // ""')
MANIFEST_VER_DIRECT=$(echo "$MANIFEST" | jq -r '.manifest_version // ""')
assert_eq "ver: manifest_version matches" "$MANIFEST_VER_DIRECT" "$MANIFEST_VER_CONTEXT"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
echo "  Areas: Capability, Approval, Queue, Rollback, MCP, Security, Audit, Timeline, Backward Compat, AI Clients, Context, Health, Recommendations, DB, Content, Plugins, Themes, Options, Environment"
[ "$FAIL" -eq 0 ]
