#!/usr/bin/env bash
# Tool Schema Compliance — regression suite for Finding A
# (tools/list inputSchema.required must contain parameter-name strings,
# not array indices, to be valid JSON Schema / pass strict Zod validation
# in MCP clients such as Claude Desktop).
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

echo "Tool Schema Compliance — $(date)"
echo ""

TOOLS=$(mcp '{"jsonrpc":"2.0","method":"tools/list","id":1}')
TOOL_COUNT=$(echo "$TOOLS" | jq -r '.result.tools | length')

echo "== 1. Baseline =="
assert_true "tools/list returns tools" "$(if [ "$TOOL_COUNT" -gt 0 ] 2>/dev/null; then echo true; else echo false; fi)"

echo "== 2. inputSchema.type == 'object' for every tool =="
BAD_TYPE=$(echo "$TOOLS" | jq -r '[.result.tools[] | select(.inputSchema.type != "object")] | length')
assert_eq "all tools: inputSchema.type is object" "0" "$BAD_TYPE"

echo "== 3. inputSchema.properties is an object for every tool =="
BAD_PROPS=$(echo "$TOOLS" | jq -r '[.result.tools[] | select((.inputSchema.properties | type) != "object")] | length')
assert_eq "all tools: inputSchema.properties is an object" "0" "$BAD_PROPS"

echo "== 4. inputSchema.required is an array for every tool =="
BAD_REQ_TYPE=$(echo "$TOOLS" | jq -r '[.result.tools[] | select((.inputSchema.required | type) != "array")] | length')
assert_eq "all tools: inputSchema.required is an array" "0" "$BAD_REQ_TYPE"

echo "== 5. Every required[] entry is a string, not a numeric index =="
NON_STRING=$(echo "$TOOLS" | jq -r '[.result.tools[] | .inputSchema.required[]? | select(type != "string")] | length')
assert_eq "all tools: required[] entries are strings" "0" "$NON_STRING"

echo "== 6. Every required[] entry names a declared property =="
ORPHAN_REQ=$(echo "$TOOLS" | jq -r '
  [.result.tools[]
   | . as $t
   | ($t.inputSchema.required // [])[] as $r
   | select(($t.inputSchema.properties | has($r)) | not)
  ] | length
')
assert_eq "all tools: required[] entries reference declared properties" "0" "$ORPHAN_REQ"

echo "== 7. Spot checks — single / contiguous / non-contiguous required params =="
assert_eq "content_seed: required == [type]" '["type"]' \
  "$(echo "$TOOLS" | jq -c '.result.tools[] | select(.name == "content_seed") | .inputSchema.required')"
assert_eq "acf_seed: required == [post_id,fields]" '["post_id","fields"]' \
  "$(echo "$TOOLS" | jq -c '.result.tools[] | select(.name == "acf_seed") | .inputSchema.required')"
assert_eq "woo_product_seed: required == [name,regular_price] (non-contiguous indices 0,2)" '["name","regular_price"]' \
  "$(echo "$TOOLS" | jq -c '.result.tools[] | select(.name == "woo_product_seed") | .inputSchema.required')"
assert_eq "safe_search_replace: required == [search,replace,tables] (non-contiguous indices 0,1,3)" '["search","replace","tables"]' \
  "$(echo "$TOOLS" | jq -c '.result.tools[] | select(.name == "safe_search_replace") | .inputSchema.required')"

echo ""
echo "== Full tool/required matrix (for compliance report) =="
echo "$TOOLS" | jq -r '.result.tools[] | "\(.name): required=\(.inputSchema.required)"'

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
