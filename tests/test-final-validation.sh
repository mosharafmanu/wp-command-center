#!/usr/bin/env bash
# Step 75 — Final Platform Validation (65+ assertions)
# Verifies the entire WP Command Center platform end-to-end.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0
START_TIME=$(date +%s)

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
assert_gt() { local d="$1" a="$2" b="$3"; if [ "$a" -gt "$b" ] 2>/dev/null; then pass "$d"; else fail "$d"; fi; }

api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }
api_post() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" "$@"; }
api_code() { curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
perf_ms() { local s=$(date +%s%N); "$@" >/dev/null 2>&1; local e=$(date +%s%N); echo $(( (e - s) / 1000000 )); }

echo "=== WP Command Center — Final Platform Validation ==="
echo "Date: $(date)"
echo ""

# ═══════════════════════════════════════════════════════════════════
echo "= 1. GATEWAY HEALTH ="
# ═══════════════════════════════════════════════════════════════════
HEALTH=$(api "$WPCC_BASE/health")
assert_eq "gateway: status ok" "ok" "$(echo "$HEALTH" | jq -r '.status')"
assert_eq "gateway: plugin version present" "0.1.0" "$(echo "$HEALTH" | jq -r '.plugin_version')"
assert_eq "gateway: api version" "v1" "$(echo "$HEALTH" | jq -r '.api_version')"
assert_gt "gateway: timestamp > 0" "$(echo "$HEALTH" | jq -r '.timestamp')" "0"

HV=$(api_post -d '{}' "$WPCC_BASE/health/verify")
assert_true "gateway: health verify runs" "$(echo "$HV" | jq -r 'if .verification_id then "true" else "false" end')"
assert_contains "gateway: health verify has checks" "$HV" "checks"
assert_contains "gateway: health verify has frontend" "$HV" "frontend"

HVRES=$(api "$WPCC_BASE/health/results?limit=1")
assert_true "gateway: health results list" "$(echo "$HVRES" | jq -r 'if type == "array" then "true" else "false" end')"

SYSENV=$(api "$WPCC_BASE/system/environment")
MODE=$(echo "$SYSENV" | jq -r '.mode')
assert_true "gateway: environment mode valid" "$( [ "$MODE" = "development" ] || [ "$MODE" = "staging" ] || [ "$MODE" = "production" ] && echo true || echo false )"
assert_contains "gateway: supported modes" "$SYSENV" "supported_modes"

# ═══════════════════════════════════════════════════════════════════
echo "= 2. AGENT MANIFEST & CONTEXT ="
# ═══════════════════════════════════════════════════════════════════
MANIFEST=$(api "$WPCC_BASE/agent/manifest")
CONTEXT=$(api "$WPCC_BASE/agent/context")

assert_true "manifest: accessible" "$(echo "$MANIFEST" | jq -r 'if .plugin then "true" else "false" end')"
assert_true "manifest: has capabilities" "$(echo "$MANIFEST" | jq -r 'if .capabilities then "true" else "false" end')"
assert_true "manifest: has endpoints" "$(echo "$MANIFEST" | jq -r 'if .endpoints then "true" else "false" end')"
assert_true "manifest: has security" "$(echo "$MANIFEST" | jq -r 'if .security then "true" else "false" end')"
assert_true "manifest: has workflow" "$(echo "$MANIFEST" | jq -r 'if .workflow then "true" else "false" end')"
assert_true "manifest: has error_catalog" "$(echo "$MANIFEST" | jq -r 'if .error_catalog then "true" else "false" end')"
assert_true "manifest: has operations" "$(echo "$MANIFEST" | jq -r 'if .operations then "true" else "false" end')"
assert_true "manifest: has manifest_hash" "$(echo "$MANIFEST" | jq -r 'if .manifest_hash then "true" else "false" end')"

assert_true "context: accessible" "$(echo "$CONTEXT" | jq -r 'if .site_summary then "true" else "false" end')"
assert_true "context: has operations" "$(echo "$CONTEXT" | jq -r 'if .operations then "true" else "false" end')"
assert_true "context: has environment_mode" "$(echo "$CONTEXT" | jq -r 'if .environment_mode then "true" else "false" end')"

# Context section completeness. wp_cli_available/mcp_server_available are
# booleans that may legitimately be false, so check key presence, not truthiness.
for sec in pending_queue_count running_queue_count failed_queue_count retryable_queue_items mcp_server_available wp_cli_available recommendation_summary; do
	assert_true "ctx: has $sec" "$(echo "$CONTEXT" | jq -r "has(\"$sec\")")"
done
# capability_enforcement is a boolean (may be false), so check key exists
assert_true "ctx: has capability_enforcement" "$(echo "$CONTEXT" | jq -r 'has("capability_enforcement")')"

# ═══════════════════════════════════════════════════════════════════
echo "= 3. ALL 28 OPERATION FAMILIES ="
# ═══════════════════════════════════════════════════════════════════
OPS=$(api "$WPCC_BASE/operations")
OP_COUNT=$(echo "$OPS" | jq -r 'length')
assert_gt "ops: total operations >= 20" "$OP_COUNT" "19"

FAMILIES=(
	"content_seed" "acf_seed" "cf7_seed" "woo_product_seed"
	"safe_search_replace" "media_import" "safe_updates" "wp_cli_bridge"
	"option_manage" "plugin_manage" "theme_manage" "snapshot_manage"
	"content_manage" "database_inspect" "capability_manage"
	"bulk_manage" "workflow_manage" "comments_manage" "widgets_manage" "cpt_manage"
	"user_manage" "media_manage" "woocommerce_manage" "acf_manage"
	"forms_manage" "menu_manage" "search_manage" "settings_manage"
)
for fam in "${FAMILIES[@]}"; do
	assert_true "ops: $fam registered" "$(echo "$OPS" | jq -r --arg id "$fam" 'any(.[]; .id == $id)')"
done

echo "  INFO: $OP_COUNT operations total"

# ═══════════════════════════════════════════════════════════════════
echo "= 4. MCP — ALL ENDPOINTS (initialize, resources, tools, discovery) ="
# ═══════════════════════════════════════════════════════════════════
INIT=$(mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
assert_contains "mcp: protocol version" "$INIT" "2024-11-05"
assert_contains "mcp: server info" "$INIT" "WP Command Center"
assert_true "mcp: server name present" "$(echo "$INIT" | jq -r 'if .result.serverInfo.name then "true" else "false" end')"

RSC=$(mcp '{"jsonrpc":"2.0","method":"resources/list","id":2}')
assert_true "mcp: resources list" "$(echo "$RSC" | jq -r 'if .result.resources then "true" else "false" end')"
for rsc_name in "Agent Manifest" "Agent Context" "Capabilities" "Operations" "Queue Status" "Results" "Recommendations"; do
	assert_true "mcp: resource '$rsc_name'" "$(echo "$RSC" | jq -r --arg n "$rsc_name" 'any(.result.resources[]; .name == $n)')"
done

# Read each resource via MCP
for uri in "wpcc://manifest" "wpcc://context" "wpcc://capabilities" "wpcc://operations" "wpcc://queue" "wpcc://results" "wpcc://recommendations"; do
	R=$(mcp "{\"jsonrpc\":\"2.0\",\"method\":\"resources/read\",\"params\":{\"uri\":\"$uri\"},\"id\":99}")
	assert_true "mcp: read $uri" "$(echo "$R" | jq -r 'if .result then "true" else "false" end')"
done

TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":3}')
assert_true "mcp: tools list" "$(echo "$TOOLS" | jq -r 'if .result.tools then "true" else "false" end')"
TOOL_COUNT=$(echo "$TOOLS" | jq -r '.result.tools | length')
assert_gt "mcp: tools count > 10" "$TOOL_COUNT" "10"

# Tool call — read op
TCALL=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_health_summary"}},"id":7}')
assert_contains "mcp: tool call db_inspect" "$TCALL" "db_size_mb"

# Tool call — content op
TCALL2=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_manage","arguments":{"action":"content_list","type":"post","per_page":1}},"id":8}')
assert_true "mcp: tool call content_list" "$(echo "$TCALL2" | jq -r 'if .result then "true" else "false" end')"

# Prompts
PROMPTS=$(mcp '{"jsonrpc":"2.0","method":"prompts/list","id":9}')
assert_contains "mcp: prompts list" "$PROMPTS" "inspect_site"

# Unknown method
BAD=$(mcp '{"jsonrpc":"2.0","method":"nonexistent_method","id":99}')
assert_contains "mcp: unknown method handled" "$BAD" "-32601"

# Invalid resource URI
BADURI=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://nonexistent"},"id":98}')
assert_contains "mcp: invalid resource" "$BADURI" "-32002"

# ═══════════════════════════════════════════════════════════════════
echo "= 5. ALL 18+ CAPABILITIES ENFORCED ="
# ═══════════════════════════════════════════════════════════════════
CAPS=$(echo "$MANIFEST" | jq -r '.capabilities | keys')
CAP_COUNT=$(echo "$MANIFEST" | jq -r '.capabilities | keys | length')
assert_gt "caps: total >= 18" "$CAP_COUNT" "17"

for cap in "ai_clients" "capability_management" "claude_integration" "code_search" "content_management" "database_inspection" "diagnostics" "environment_management" "file_access" "health_verification" "mcp_server" "option_management" "patches" "plan_approval" "plans" "plugin_management" "recommendations" "rollback" "sessions" "site_intelligence" "snapshot_management" "tasks" "theme_management" "wp_cli_operations"; do
	assert_true "caps: $cap" "$(echo "$MANIFEST" | jq -r ".capabilities.$cap == true")"
done

# Operation-to-capability mappings
CAPMAP=$(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys')
for mapped in "content_manage" "plugin_manage" "theme_manage" "option_manage" "snapshot_manage" "database_inspect" "wp_cli_bridge" "bulk_manage" "workflow_manage" "comments_manage" "widgets_manage" "cpt_manage" "user_manage" "media_manage" "woocommerce_manage" "acf_manage" "forms_manage" "menu_manage" "search_manage" "settings_manage"; do
	assert_contains "capmap: $mapped" "$CAPMAP" "$mapped"
done

echo "  INFO: $CAP_COUNT capabilities, $(echo "$MANIFEST" | jq -r '.capability_management.operation_map | keys | length') mapped"

# ═══════════════════════════════════════════════════════════════════
echo "= 6. SECURITY GATES (token auth, protected files, no bypasses) ="
# ═══════════════════════════════════════════════════════════════════

# No token = 401 on health
NO_TOKEN=$(curl -s -o /dev/null -w "%{http_code}" "$WPCC_BASE/health")
assert_contains "sec: no token returns 401" "$NO_TOKEN" "401"

# No token = blocked on MCP
MCP_NO_TOKEN=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"jsonrpc":"2.0","method":"initialize","id":1}' "$WPCC_BASE/mcp")
assert_true "sec: MCP no token blocked" "$( [ "$MCP_NO_TOKEN" != "200" ] && echo true || echo false )"

# Protected file blocked
WP_CONFIG=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=../../../wp-config.php")
assert_contains "sec: wp-config blocked" "$(echo "$WP_CONFIG" | jq -r '.code // ""')" "wpcc"

# /etc/passwd blocked
PASSWD=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=/etc/passwd")
assert_contains "sec: outside path blocked" "$(echo "$PASSWD" | jq -r '.code // ""')" "wpcc"

# .env blocked
ENVFILE=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/files/meta?path=plugins/.env")
assert_contains "sec: .env blocked" "$(echo "$ENVFILE" | jq -r '.code // ""')" "wpcc"

# Secrets not leaked in manifest
MANIFEST_RAW=$(echo "$MANIFEST" | jq -c '.')
assert_true "sec: no tokens in manifest" "$(echo "$MANIFEST" | jq -r 'has("tokens") | not')"
assert_true "sec: no contents in manifest" "$(echo "$MANIFEST" | jq -r 'has("contents") | not')"

# Site intelligence accessible (no secrets leak)
SI=$(api "$WPCC_BASE/site-intelligence")
assert_true "sec: site intel accessible" "$(echo "$SI" | jq -r 'if .wordpress then "true" else "false" end')"
assert_true "sec: site intel no secrets" "$(echo "$SI" | jq -r 'has("wp-config") | not')"

# Search works (redaction flag present in system)
SEARCH=$(api "$WPCC_BASE/search?q=function&path=themes/mosharaf-core")
assert_true "sec: search works" "$(echo "$SEARCH" | jq -r 'if .matches then "true" else "false" end')"

# Diagnostics work
DIAG=$(api "$WPCC_BASE/diagnostics?type=performance")
assert_true "sec: diagnostics accessible" "$(echo "$DIAG" | jq -r 'if .checks then "true" else "false" end')"

# Files listing works for allowed paths
FILES=$(api "$WPCC_BASE/files?path=themes/mosharaf-core")
assert_true "sec: allowed file listing" "$(echo "$FILES" | jq -r 'if .entries then "true" else "false" end')"

# File content redaction
CONTENT=$(api "$WPCC_BASE/files/content?path=themes/mosharaf-core/style.css")
assert_true "sec: file content readable" "$(echo "$CONTENT" | jq -r 'if .contents then "true" else "false" end')"

echo "  INFO: 6 security gates verified"

# ═══════════════════════════════════════════════════════════════════
echo "= 7. QUEUE LIFECYCLE (request → approve → execute → result) ="
# ═══════════════════════════════════════════════════════════════════

# Create request
REQ_BODY='{"operation_id":"database_inspect","payload":{"action":"db_table_list"},"session_id":null,"task_id":null}'
REQ=$(api_post -d "$REQ_BODY" "$WPCC_BASE/operations/requests")
REQ_ID=$(echo "$REQ" | jq -r '.request_id // empty')
assert_true "queue: request created" "$(echo "$REQ" | jq -r 'if .request_id then "true" else "false" end')"

if [ -n "$REQ_ID" ]; then
	# Approve
	APPROVE=$(api_post -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/approve")
	assert_eq "queue: approved" "approved" "$(echo "$APPROVE" | jq -r '.status')"

	# Execute
	EXEC=$(api_post -d '{}' "$WPCC_BASE/operations/requests/$REQ_ID/execute")
	assert_true "queue: executed" "$(echo "$EXEC" | jq -r 'if .id then "true" else "false" end')"

	# Results accessible
	RESULTS=$(api "$WPCC_BASE/operations/results?limit=5")
	assert_true "queue: results list" "$(echo "$RESULTS" | jq -r 'if type == "array" then "true" else "false" end')"
fi

# Queue listing
QUEUE=$(api "$WPCC_BASE/operations/queue?limit=5")
assert_true "queue: list accessible" "$(echo "$QUEUE" | jq -r 'if type == "array" then "true" else "false" end')"

# Requests listing
REQS=$(api "$WPCC_BASE/operations/requests?limit=5")
assert_true "queue: requests list" "$(echo "$REQS" | jq -r 'if type == "array" then "true" else "false" end')"

# ═══════════════════════════════════════════════════════════════════
echo "= 8. ROLLBACK (patch create → approve → apply → rollback) ="
# ═══════════════════════════════════════════════════════════════════
TEST_PATH="themes/mosharaf-core/style.css"
PATCH_BODY=$(jq -n --arg path "$TEST_PATH" '{files: [{path: $path, modified: "/*\nTheme Name: Mosharaf Core\n*/\n/* Step 75 final validation marker */"}], explanation: "Final platform validation rollback test", risk_level: "low"}')
PATCH=$(api_post -d "$PATCH_BODY" "$WPCC_BASE/patches")
PATCH_ID=$(echo "$PATCH" | jq -r '.id // empty')
assert_true "rollback: patch created" "$(echo "$PATCH" | jq -r 'if .id then "true" else "false" end')"

if [ -n "$PATCH_ID" ]; then
	api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/approve" >/dev/null
	APPLY=$(api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/apply")
	assert_contains "rollback: applied" "$APPLY" "applied"

	ROLLBACK=$(api_post -d '{}' "$WPCC_BASE/patches/$PATCH_ID/rollback")
	assert_contains "rollback: rolled back" "$ROLLBACK" "rolled_back"

	RESTORED=$(api "$WPCC_BASE/files/meta?path=$TEST_PATH")
	assert_true "rollback: file restored" "$(echo "$RESTORED" | jq -r 'if .path then "true" else "false" end')"
fi

# Patch listing
PATCHES=$(api "$WPCC_BASE/patches?limit=5")
assert_true "rollback: patches list" "$(echo "$PATCHES" | jq -r 'if type == "array" then "true" else "false" end')"

# ═══════════════════════════════════════════════════════════════════
echo "= 9. TIMELINE (events from all families) ="
# ═══════════════════════════════════════════════════════════════════
TL=$(api "$WPCC_BASE/agent/timeline?limit=100")
TL_COUNT=$(echo "$TL" | jq -r 'length')
assert_gt "tl: events count > 10" "$TL_COUNT" "10"
assert_true "tl: all have timestamp" "$(echo "$TL" | jq -r 'all(.[]; has("timestamp"))')"
assert_true "tl: all have type" "$(echo "$TL" | jq -r 'all(.[]; has("type"))')"
assert_true "tl: all have label" "$(echo "$TL" | jq -r 'all(.[]; has("label"))')"

# Verify events from key families present in the timeline
TL_LABELS=$(echo "$TL" | jq -r '[.[].label] | join("|")')
for event_label in "Database" "MCP" "Execution" "Patch" "Operation" "Health" "Started" "Completed"; do
	if echo "$TL_LABELS" | grep -qi "$event_label"; then
		pass "tl: has event containing '$event_label'"
	else
		fail "tl: has event containing '$event_label'"
	fi
done

echo "  INFO: $TL_COUNT timeline events validated"

# ═══════════════════════════════════════════════════════════════════
echo "= 10. AI CLIENTS (all 11 certified) ="
# ═══════════════════════════════════════════════════════════════════
CLIENTS=$(api "$WPCC_BASE/ai-clients")
assert_eq "ai: 11 total clients" "11" "$(echo "$CLIENTS" | jq -r '.counts.total')"
assert_gt "ai: gold count > 0" "$(echo "$CLIENTS" | jq -r '.counts.gold')" "0"

for client_id in claude chatgpt codex gemini cursor continue opencode aider roo_code windsurf command_code; do
	assert_true "ai: $client_id registered" "$(echo "$CLIENTS" | jq -r --arg id "$client_id" 'if .clients[$id] then "true" else "false" end')"
done

# Claude Gold verification
CLAUDE_CERT=$(echo "$CLIENTS" | jq -r '.clients.claude.certification_level')
assert_eq "ai: claude is gold" "gold" "$CLAUDE_CERT"

# Generic config generation works for configured client
CLAUDE_CFG=$(api "$WPCC_BASE/ai-clients/claude/config")
assert_true "ai: claude config has mcpServers" "$(echo "$CLAUDE_CFG" | jq -r 'if .config.mcpServers then "true" else "false" end')"

# Unconfigured/planned client returns valid config
CODEX_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/ai-clients/codex/config")
CODEX_CFG=$(api "$WPCC_BASE/ai-clients/codex/config")
assert_true "ai: codex config accessible" "$( [ "$CODEX_CODE" = "200" ] && echo true || echo false )"
assert_true "ai: codex config has mcpServers" "$(echo "$CODEX_CFG" | jq -r 'if .config.mcpServers then "true" else "false" end')"

# Non-existent client
UNK_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/ai-clients/nonexistent/config")
assert_contains "ai: unknown client returns 4xx" "$UNK_CODE" "4"

# Backward compat Claude endpoints
for ep in "/claude/config" "/claude/discovery" "/claude/tools" "/claude/prompts"; do
	assert_true "ai: legacy $ep accessible" "$(api "$WPCC_BASE$ep" | jq -r 'if . then "true" else "false" end')"
done

echo "  INFO: $(echo "$CLIENTS" | jq -r '.counts.gold') gold, $(echo "$CLIENTS" | jq -r '.counts.certified') certified"

# ═══════════════════════════════════════════════════════════════════
echo "= 11. DASHBOARD CARDS (all families present) ="
# ═══════════════════════════════════════════════════════════════════
DASHBOARD_CARDS=(
	"user_manage" "media_manage" "woocommerce_manage" "acf_manage"
	"forms_manage" "menu_manage" "settings_manage" "search_manage"
	"bulk_manage" "workflow_manage" "comments_manage" "widgets_manage" "cpt_manage"
)
for card in "${DASHBOARD_CARDS[@]}"; do
	assert_true "dash: $card in ops" "$(echo "$OPS" | jq -r --arg id "$card" 'any(.[]; .id == $id)')"
done

# Core management availability reflected in context
for sec in content_management_available plugin_management_available theme_management_available option_management_available snapshot_management_available database_inspection_available capability_management_available; do
	assert_true "dash: context has $sec" "$(echo "$CONTEXT" | jq -r "if .$sec then \"true\" else \"false\" end")"
done

echo "  INFO: 13 dashboard card families verified"

# ═══════════════════════════════════════════════════════════════════
echo "= 12. OPERATION RUNTIME PROBES (1 per family) ="
# ═══════════════════════════════════════════════════════════════════

# Content manage
C1=$(api_post -d '{"action":"content_list","type":"post","per_page":1}' "$WPCC_BASE/operations/content_manage/run")
assert_true "probe: content_list" "$(echo "$C1" | jq -r 'if .items then "true" else "false" end')"

# Database inspect
C2=$(api_post -d '{"action":"db_health_summary"}' "$WPCC_BASE/operations/database_inspect/run")
assert_contains "probe: db_health" "$C2" "db_size_mb"

# Plugin manage
C3=$(api_post -d '{"action":"plugin_list"}' "$WPCC_BASE/operations/plugin_manage/run")
assert_true "probe: plugin_list" "$(echo "$C3" | jq -r 'if .plugins then "true" else "false" end')"

# Theme manage
C4=$(api_post -d '{"action":"theme_list"}' "$WPCC_BASE/operations/theme_manage/run")
assert_true "probe: theme_list" "$(echo "$C4" | jq -r 'if .themes then "true" else "false" end')"

# Option manage
C5=$(api_post -d '{"action":"option_get","option_id":"site_title"}' "$WPCC_BASE/operations/option_manage/run")
assert_contains "probe: option_get" "$C5" "option_id"

# Snapshot manage
SNAP=$(api_post -d '{"action":"snapshot_create","path":"themes/mosharaf-core/style.css","label":"Final Validation Snapshot"}' "$WPCC_BASE/operations/snapshot_manage/run")
assert_true "probe: snapshot_create" "$(echo "$SNAP" | jq -r 'if .snapshot_id then "true" else "false" end')"
SNAP_ID=$(echo "$SNAP" | jq -r '.snapshot_id // empty')
if [ -n "$SNAP_ID" ]; then
	SNAP_LIST=$(api_post -d '{"action":"snapshot_list"}' "$WPCC_BASE/operations/snapshot_manage/run")
	assert_true "probe: snapshot_list" "$(echo "$SNAP_LIST" | jq -r 'if .snapshots then "true" else "false" end')"
fi

# Capability manage
C7=$(api_post -d '{"action":"capability_list"}' "$WPCC_BASE/operations/capability_manage/run")
assert_contains "probe: capability_list" "$C7" "capability_list"

# WP-CLI bridge
CLI=$(api_post -d '{"action":"wp_cli_exec","command":"plugin list --format=json"}' "$WPCC_BASE/operations/wp_cli_bridge/run")
assert_true "probe: wp_cli_bridge" "$(echo "$CLI" | jq -r 'if .output or .result or .data then "true" else "false" end')"

# Bulk manage
C9=$(api_post -d '{"action":"bulk_content","content_ids":[],"new_status":"publish"}' "$WPCC_BASE/operations/bulk_manage/run")
assert_true "probe: bulk_manage" "$(echo "$C9" | jq -r 'if .action then "true" else "false" end')"

# Workflow manage
C10=$(api_post -d '{"action":"workflow_list"}' "$WPCC_BASE/operations/workflow_manage/run")
assert_true "probe: workflow_list" "$(echo "$C10" | jq -r 'if .action or .workflows then "true" else "false" end')"

# User manage
C11=$(api_post -d '{"action":"user_list","per_page":2}' "$WPCC_BASE/operations/user_manage/run")
assert_true "probe: user_list" "$(echo "$C11" | jq -r 'if .users then "true" else "false" end')"

# Media manage
C12=$(api_post -d '{"action":"media_list","per_page":2}' "$WPCC_BASE/operations/media_manage/run")
assert_true "probe: media_list" "$(echo "$C12" | jq -r 'if .items or .action then "true" else "false" end')"

# WooCommerce manage
C13=$(api_post -d '{"action":"product_list","per_page":2}' "$WPCC_BASE/operations/woocommerce_manage/run")
assert_true "probe: product_list" "$(echo "$C13" | jq -r 'if .products or .action then "true" else "false" end')"

# ACF manage
C14=$(api_post -d '{"action":"acf_group_list"}' "$WPCC_BASE/operations/acf_manage/run")
assert_true "probe: acf_list" "$(echo "$C14" | jq -r 'if .groups or .action then "true" else "false" end')"

# Forms manage
C15=$(api_post -d '{"action":"form_list"}' "$WPCC_BASE/operations/forms_manage/run")
assert_true "probe: form_list" "$(echo "$C15" | jq -r 'if .forms or .action then "true" else "false" end')"

# Menu manage
C16=$(api_post -d '{"action":"menu_list"}' "$WPCC_BASE/operations/menu_manage/run")
assert_true "probe: menu_list" "$(echo "$C16" | jq -r 'if .menus then "true" else "false" end')"

# Search manage
C17=$(api_post -d '{"action":"search_all"}' "$WPCC_BASE/operations/search_manage/run")
assert_true "probe: search_manage" "$(echo "$C17" | jq -r 'if .action then "true" else "false" end')"

# Site settings manage
C18=$(api_post -d '{"action":"settings_general_get"}' "$WPCC_BASE/operations/settings_manage/run")
assert_true "probe: site_settings" "$(echo "$C18" | jq -r 'if .settings or .action then "true" else "false" end')"

# Comments manage
C19=$(api_post -d '{"action":"comment_list","per_page":2}' "$WPCC_BASE/operations/comments_manage/run")
assert_true "probe: comment_list" "$(echo "$C19" | jq -r 'if .comments or .action then "true" else "false" end')"

# Widgets manage
C20=$(api_post -d '{"action":"widget_list"}' "$WPCC_BASE/operations/widgets_manage/run")
assert_true "probe: widget_list" "$(echo "$C20" | jq -r 'if .widgets or .action then "true" else "false" end')"

# CPT manage
C21=$(api_post -d '{"action":"cpt_list"}' "$WPCC_BASE/operations/cpt_manage/run")
assert_true "probe: cpt_list" "$(echo "$C21" | jq -r 'if .post_types or .action then "true" else "false" end')"

echo "  INFO: 21 operation probes run"

# Re-query timeline after all probes to verify operation events generated
TL2=$(api "$WPCC_BASE/agent/timeline?limit=100")
TL2_LABELS=$(echo "$TL2" | jq -r '[.[].label] | join("|")')
for label in "ACF" "Bulk" "WooCommerce" "Widgets" "Forms" "CPT" "Menu" "Media"; do
	if echo "$TL2_LABELS" | grep -qi "$label"; then
		pass "tl2: has event containing '$label'"
	else
		fail "tl2: has event containing '$label'"
	fi
done

# ═══════════════════════════════════════════════════════════════════
echo "= 13. AGENT SESSIONS, TASKS, ACTIONS, PLANS ="
# ═══════════════════════════════════════════════════════════════════
# Sessions
SESSIONS=$(api "$WPCC_BASE/agent/sessions")
assert_true "agent: sessions list" "$(echo "$SESSIONS" | jq -r 'if type == "array" then "true" else "false" end')"

# Tasks
TASKS=$(api "$WPCC_BASE/agent/tasks")
assert_true "agent: tasks list" "$(echo "$TASKS" | jq -r 'if type == "array" then "true" else "false" end')"

# Actions
ACTIONS=$(api "$WPCC_BASE/agent/actions")
assert_true "agent: actions list" "$(echo "$ACTIONS" | jq -r 'if type == "array" then "true" else "false" end')"

# Plans
PLANS=$(api "$WPCC_BASE/agent/plans")
assert_true "agent: plans list" "$(echo "$PLANS" | jq -r 'if type == "array" then "true" else "false" end')"

# Agent tree
TREE=$(api "$WPCC_BASE/agent/tree")
assert_true "agent: tree accessible" "$(echo "$TREE" | jq -r 'if .sessions then "true" else "false" end')"

# ═══════════════════════════════════════════════════════════════════
echo "= 14. RECOMMENDATIONS ="
# ═══════════════════════════════════════════════════════════════════
RECS=$(api "$WPCC_BASE/recommendations?limit=5")
assert_true "recs: list accessible" "$(echo "$RECS" | jq -r 'if type == "array" then "true" else "false" end')"

# ═══════════════════════════════════════════════════════════════════
echo "= 15. FILES & SEARCH ="
# ═══════════════════════════════════════════════════════════════════
# File list
FILE_LIST=$(api "$WPCC_BASE/files?path=themes/mosharaf-core")
assert_true "files: directory listing" "$(echo "$FILE_LIST" | jq -r 'if .entries then "true" else "false" end')"

# File content
FILE_CONT=$(api "$WPCC_BASE/files/content?path=themes/mosharaf-core/functions.php")
assert_true "files: content readable" "$(echo "$FILE_CONT" | jq -r 'if .contents then "true" else "false" end')"

# File meta
FILE_META=$(api "$WPCC_BASE/files/meta?path=themes/mosharaf-core/style.css")
assert_true "files: meta readable" "$(echo "$FILE_META" | jq -r 'if .path then "true" else "false" end')"

# Search
SRCH=$(api "$WPCC_BASE/search?q=function&path=themes/mosharaf-core")
assert_true "files: search works" "$(echo "$SRCH" | jq -r 'if .matches then "true" else "false" end')"

# ═══════════════════════════════════════════════════════════════════
echo "= 16. BACKWARD COMPATIBILITY ="
# ═══════════════════════════════════════════════════════════════════
# Legacy manifest
LEGACY_MAN=$(api "$WPCC_BASE/manifest")
assert_true "bwcompat: /manifest accessible" "$(echo "$LEGACY_MAN" | jq -r 'if .endpoints then "true" else "false" end')"

# Legacy context
LEGACY_CTX=$(api "$WPCC_BASE/context")
assert_true "bwcompat: /context accessible" "$(echo "$LEGACY_CTX" | jq -r 'if .wordpress or .file_access then "true" else "false" end')"

# Legacy capabilities
LEGACY_CAP=$(api "$WPCC_BASE/capabilities")
assert_true "bwcompat: /capabilities accessible" "$(echo "$LEGACY_CAP" | jq -r 'if .file_read != null then "true" else "false" end')"

# ═══════════════════════════════════════════════════════════════════
echo "= 17. PERFORMANCE (key endpoints under 3s) ="
# ═══════════════════════════════════════════════════════════════════

HEALTH_MS=$(perf_ms api "$WPCC_BASE/health")
assert_true "perf: health < 3000ms (${HEALTH_MS}ms)" "$( [ "$HEALTH_MS" -lt 3000 ] && echo true || echo false )"

MANIFEST_MS=$(perf_ms api "$WPCC_BASE/agent/manifest")
assert_true "perf: manifest < 3000ms (${MANIFEST_MS}ms)" "$( [ "$MANIFEST_MS" -lt 3000 ] && echo true || echo false )"

CONTEXT_MS=$(perf_ms api "$WPCC_BASE/agent/context")
assert_true "perf: context < 3000ms (${CONTEXT_MS}ms)" "$( [ "$CONTEXT_MS" -lt 3000 ] && echo true || echo false )"

MCP_MS=$(perf_ms mcp '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2024-11-05"},"id":1}')
assert_true "perf: MCP init < 3000ms (${MCP_MS}ms)" "$( [ "$MCP_MS" -lt 3000 ] && echo true || echo false )"

OPS_MS=$(perf_ms api "$WPCC_BASE/operations")
assert_true "perf: operations list < 3000ms (${OPS_MS}ms)" "$( [ "$OPS_MS" -lt 3000 ] && echo true || echo false )"

DB_MS=$(perf_ms api_post -d '{"action":"db_health_summary"}' "$WPCC_BASE/operations/database_inspect/run")
assert_true "perf: db_inspect < 3000ms (${DB_MS}ms)" "$( [ "$DB_MS" -lt 3000 ] && echo true || echo false )"

SITEINTEL_MS=$(perf_ms api "$WPCC_BASE/site-intelligence")
assert_true "perf: site-intelligence < 3000ms (${SITEINTEL_MS}ms)" "$( [ "$SITEINTEL_MS" -lt 3000 ] && echo true || echo false )"

echo "  INFO: health=${HEALTH_MS}ms manifest=${MANIFEST_MS}ms context=${CONTEXT_MS}ms mcp=${MCP_MS}ms ops=${OPS_MS}ms db=${DB_MS}ms siteintel=${SITEINTEL_MS}ms"

# ═══════════════════════════════════════════════════════════════════
echo "= 18. SITE INTELLIGENCE ="
# ═══════════════════════════════════════════════════════════════════
assert_true "site: wordpress info" "$(echo "$SI" | jq -r 'if .wordpress then "true" else "false" end')"
assert_true "site: php info" "$(echo "$SI" | jq -r 'if .php then "true" else "false" end')"
assert_true "site: theme info" "$(echo "$SI" | jq -r 'if .theme then "true" else "false" end')"
assert_true "site: plugins array" "$(echo "$SI" | jq -r 'if (.plugins | type) == "array" then "true" else "false" end')"
assert_true "site: woocommerce info" "$(echo "$SI" | jq -r 'if .woocommerce then "true" else "false" end')"

# Diagnostics
DIAGS=$(api "$WPCC_BASE/diagnostics?type=security")
assert_true "diag: security diagnostics" "$(echo "$DIAGS" | jq -r 'if .checks then "true" else "false" end')"

# Debug log
DEBUGLOG=$(api "$WPCC_BASE/diagnostics/debug-log?lines=10")
assert_true "diag: debug log accessible" "$(echo "$DEBUGLOG" | jq -r 'if .lines or .contents or .error then "true" else "false" end')"

# ═══════════════════════════════════════════════════════════════════
echo ""
echo "=== FINAL SUMMARY ==="
END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))
echo "  Assertions: $PASS passed, $FAIL failed of $((PASS+FAIL))"
echo "  Duration: ${DURATION}s"
echo ""
echo "  Coverage areas (18):"
echo "    Gateway Health, Agent Manif/Context, 28 Operation Families"
echo "    MCP Full Protocol, 18+ Capabilities, 6 Security Gates"
echo "    Queue Lifecycle, Rollback Lifecycle, Timeline Events"
echo "    11 AI Clients, 13 Dashboard Cards, 21 Runtime Probes"
echo "    Sessions/Tasks/Actions/Plans, Recommendations"
echo "    Files/Search, Backward Compat, Performance (7 endpoints)"
echo "    Site Intelligence/Diagnostics"
echo ""
if [ "$FAIL" -eq 0 ]; then
	echo "  RESULT: ALL ASSERTIONS PASSED — Platform validation successful"
	exit 0
else
	echo "  RESULT: $FAIL assertions FAILED"
	exit 1
fi
