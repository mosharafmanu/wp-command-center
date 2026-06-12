#!/usr/bin/env bash
# Step 51 — Documentation Consistency test suite
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d"; fi; }
api() { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$@"; }

MANIFEST=$(api "$WPCC_BASE/agent/manifest")
CONTEXT=$(api "$WPCC_BASE/agent/context")
DISCOVERY=$(api "$WPCC_BASE/claude/discovery")
CLIENTS=$(api "$WPCC_BASE/ai-clients")

echo "== 1. Endpoint documentation references ===="
ENDPOINTS=$(echo "$MANIFEST" | jq -r '.endpoints[].path')
ENDPOINT_COUNT=$(echo "$ENDPOINTS" | wc -l | tr -d ' ')
assert_true "docs: 80+ endpoints exist" "$( [ "$ENDPOINT_COUNT" -ge 80 ] && echo true || echo false )"

# Verify key endpoints exist
for ep in "/health" "/agent/manifest" "/agent/context" "/capabilities" "/patches" "/operations" "/files" "/search" "/ai-clients" "/claude/config"; do
	assert_true "docs: endpoint $ep exists" "$(echo "$ENDPOINTS" | grep -qF "$ep" && echo true || echo false)"
done

echo "== 2. Capability documentation references ===="
ALLCAPS=$(echo "$DISCOVERY" | jq -r '.capabilities.capabilities | join(" ")')
for cap in "content.manage" "database.inspect" "plugin.manage" "theme.manage" "option.manage" "snapshot.manage" "wpcli.execute" "capability.admin" "system.admin"; do
	assert_contains "docs: capability $cap exists" "$ALLCAPS" "$cap"
done

echo "== 3. Operation documentation references ===="
OPS=$(echo "$MANIFEST" | jq -r '.operations[].id')
for op in content_manage plugin_manage theme_manage option_manage snapshot_manage database_inspect wp_cli_bridge safe_search_replace safe_updates media_import content_seed acf_seed cf7_seed woo_product_seed capability_manage; do
	assert_true "docs: operation $op exists" "$(echo "$OPS" | grep -qFx "$op" && echo true || echo false)"
done

echo "== 4. AI Client documentation references ===="
for client_id in claude codex gemini cursor continue opencode aider roo_code windsurf; do
	assert_true "docs: client $client_id exists" "$(echo "$CLIENTS" | jq -r --arg id "$client_id" 'if .clients[$id] then "true" else "false" end')"
done

echo "== 5. Context section references ===="
for section in health capabilities site_summary context operations open_recommendations recent_patches recent_actions mcp_server_available mcp_endpoint ai_clients environment_mode; do
	assert_true "docs: context has $section" "$(echo "$CONTEXT" | jq -r "if .$section then \"true\" else \"false\" end")"
done

echo "== 6. MCP resource references ===="
MCP_RESOURCES=$(echo "$DISCOVERY" | jq -r '.resources[].uri')
for uri in "wpcc://manifest" "wpcc://context" "wpcc://capabilities" "wpcc://operations" "wpcc://queue" "wpcc://results" "wpcc://recommendations"; do
	assert_contains "docs: MCP resource $uri exists" "$MCP_RESOURCES" "$uri"
done

echo "== 7. Documentation file existence ===="
DOC_DIR="$SCRIPT_DIR/../docs"
for doc in OVERVIEW ARCHITECTURE INSTALLATION SECURITY MCP OPERATIONS API CAPABILITIES AI-INTEGRATIONS TROUBLESHOOTING QUICKSTART; do
	assert_true "docs: $doc.md exists" "$( [ -f "$DOC_DIR/$doc.md" ] && echo true || echo false )"
done

echo "== 8. SDK file existence ===="
for sdk_file in "sdk/php/Client.php" "sdk/javascript/client.js"; do
	assert_true "docs: $sdk_file exists" "$( [ -f "$SCRIPT_DIR/../$sdk_file" ] && echo true || echo false )"
done

echo "== 9. Example file existence ===="
for ex in "create-content.sh" "mcp-discovery.sh" "plugin-lifecycle.sh"; do
	assert_true "docs: example $ex exists" "$( [ -f "$SCRIPT_DIR/../examples/$ex" ] && echo true || echo false )"
done

echo "== 10. OpenAPI spec exists ===="
assert_true "docs: openapi.json exists" "$( [ -f "$SCRIPT_DIR/../openapi.json" ] && echo true || echo false )"

echo "== 11. Backward compat — legacy Claude endpoints ===="
for ep in "/claude/config" "/claude/discovery" "/claude/tools" "/claude/prompts"; do
	assert_true "docs: legacy $ep works" "$(api "$WPCC_BASE$ep" | jq -r 'if . then "true" else "false" end')"
done

echo ""
echo "== Summary ===="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
