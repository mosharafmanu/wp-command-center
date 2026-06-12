#!/usr/bin/env bash
# Step 76 - Token efficiency and context-mode validation.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
eq(){ local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
true(){ local d="$1" a="$2"; [ "$a" = "true" ] && pass "$d" || fail "$d"; }
mcp(){ curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d "$1" "$WPCC_BASE/mcp"; }

echo "Token Efficiency & Context Optimization - $(date)"

echo "== 1. Resource context modes =="
CTX_COMPACT=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://context"},"id":1}')
CTX_STANDARD=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://context","context_mode":"standard"},"id":2}')
CTX_C_TEXT=$(echo "$CTX_COMPACT" | jq -r '.result.contents[0].text')
CTX_S_TEXT=$(echo "$CTX_STANDARD" | jq -r '.result.contents[0].text')
eq "context: compact is default" "compact" "$(echo "$CTX_C_TEXT" | jq -r '.context_mode')"
true "context: compact smaller than standard" "$([ ${#CTX_C_TEXT} -lt ${#CTX_S_TEXT} ] && echo true || echo false)"
true "context: compact has site counts" "$(echo "$CTX_C_TEXT" | jq -r '(.content.posts | type) == "number" and (.users | type) == "number"')"
true "context: standard preserves full context" "$(echo "$CTX_S_TEXT" | jq -r 'has("operations") and has("recent_audit_entries")')"

echo "== 2. Manifest summary =="
MAN_COMPACT=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://manifest"},"id":3}')
MAN_STANDARD=$(mcp '{"jsonrpc":"2.0","method":"resources/read","params":{"uri":"wpcc://manifest","context_mode":"standard"},"id":4}')
MAN_C_TEXT=$(echo "$MAN_COMPACT" | jq -r '.result.contents[0].text')
MAN_S_TEXT=$(echo "$MAN_STANDARD" | jq -r '.result.contents[0].text')
eq "manifest: compact is default" "compact" "$(echo "$MAN_C_TEXT" | jq -r '.context_mode')"
true "manifest: compact smaller than standard" "$([ ${#MAN_C_TEXT} -lt ${#MAN_S_TEXT} ] && echo true || echo false)"
true "manifest: operation count present" "$(echo "$MAN_C_TEXT" | jq -r '.operations.total > 0')"

echo "== 3. Tool discovery modes =="
TOOLS_COMPACT=$(mcp '{"jsonrpc":"2.0","method":"tools/list","params":{},"id":5}')
TOOLS_STANDARD=$(mcp '{"jsonrpc":"2.0","method":"tools/list","params":{"context_mode":"standard"},"id":6}')
true "tools: every tool supports context_mode" "$(echo "$TOOLS_COMPACT" | jq -r '[.result.tools[].inputSchema.properties.context_mode] | all')"
true "tools: search supports max_results" "$(echo "$TOOLS_COMPACT" | jq -r '.result.tools[] | select(.name=="search_manage") | .inputSchema.properties.max_results.default == 20')"
true "tools: compact discovery smaller" "$([ ${#TOOLS_COMPACT} -lt ${#TOOLS_STANDARD} ] && echo true || echo false)"

echo "== 4. Tool response modes =="
CONTENT_COMPACT=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_manage","arguments":{"action":"content_list","type":"post","per_page":20}},"id":7}')
CONTENT_STANDARD=$(mcp '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"content_manage","arguments":{"action":"content_list","type":"post","per_page":20,"context_mode":"standard"}},"id":8}')
CC_TEXT=$(echo "$CONTENT_COMPACT" | jq -r '.result.content[0].text')
CS_TEXT=$(echo "$CONTENT_STANDARD" | jq -r '.result.content[0].text')
true "tool: compact list summarized" "$(echo "$CC_TEXT" | jq -r 'if (.items | type)=="object" then .items.truncated == true else true end')"
true "tool: standard list remains array" "$(echo "$CS_TEXT" | jq -r '(.items | type)=="array"')"
true "tool: compact no larger than standard" "$([ ${#CC_TEXT} -le ${#CS_TEXT} ] && echo true || echo false)"

echo "== 5. Search limits and cursor =="
SEARCH_ONE=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d '{"action":"search_content","search":"a","max_results":2}' "$WPCC_BASE/operations/search_manage/run")
true "search: max_results enforced" "$(echo "$SEARCH_ONE" | jq -r '.count <= 2 and (.items | length) <= 2')"
CURSOR=$(echo "$SEARCH_ONE" | jq -r '.next_cursor // empty')
if [ -n "$CURSOR" ]; then
	SEARCH_TWO=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H 'Content-Type: application/json' -d "$(jq -nc --arg c "$CURSOR" '{action:"search_content",search:"a",max_results:2,cursor:$c}')" "$WPCC_BASE/operations/search_manage/run")
	true "search: cursor returns next page" "$(echo "$SEARCH_TWO" | jq -r '.count <= 2 and (.items | type)=="array"')"
else
	pass "search: cursor not needed for small result set"
fi

echo "== 6. AI client defaults =="
for client in claude chatgpt codex gemini cursor continue opencode aider roo_code windsurf command_code; do
	CFG=$(curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE/ai-clients/$client/config")
	eq "client: $client defaults compact" "compact" "$(echo "$CFG" | jq -r '.config.mcpServers["wp-command-center"].env.WPCC_CONTEXT_MODE')"
done

echo "== 7. Analyzer =="
ANALYSIS=$(wp eval '
$optimizer = new \WPCommandCenter\Mcp\ContextModeOptimizer();
$analyzer = new \WPCommandCenter\Mcp\TokenEfficiencyAnalyzer();
$before = array_fill( 0, 100, [ "id" => 1, "description" => str_repeat( "x", 100 ) ] );
echo wp_json_encode( $analyzer->compare( $before, $optimizer->optimize( $before, "compact" ) ) );
' --path="$WP_PATH" 2>/dev/null)
true "analyzer: reports bytes and tokens" "$(echo "$ANALYSIS" | jq -r '.before.payload_bytes > 0 and .before.estimated_tokens > 0')"
true "analyzer: reports reduction" "$(echo "$ANALYSIS" | jq -r '.reduction_percentage > 50')"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
