#!/usr/bin/env bash
#
# STEP 103.0A — Live File Read/Search Reliability acceptance suite.
#
# Proves agents can confidently inspect large live files through MCP/REST:
#  - code_search finds text in large files and NEVER silently returns 0 (skips
#    are reported with structured reasons + sizes), with line numbers + a
#    search-to-read bridge (read_hint).
#  - file_read is paginated (line range, byte range) with cursor metadata.
#  - binary files and path restrictions still rejected safely.
#  - REST + MCP parity.
#
# Requires: curl, jq, awk, wpcc-env.sh.
# Usage: bash tests/test-file-read-search.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

WP_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"
SB="$PLUGINS_DIR/wpcc-read-sandbox"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_true() { local d="$1" a="$2"; [ "$a" = "true" ] && pass "$d" || fail "$d (got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }

pj() { printf '%s' "$1" | jq -r "$2"; }
rest_get()  { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$1"; }
mcp()       { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
mcp_text()  { mcp "$1" | jq -r '.result.content[0].text // empty'; }

cleanup() { rm -rf "$SB" 2>/dev/null; }
trap cleanup EXIT

mkdir -p "$SB"
# Large CSS (~750KB, 15000 lines), unique marker at line 7500.
awk 'BEGIN{for(i=1;i<=15000;i++){ if(i==7500) print ".WPCC_BIGMARKER{display:none}"; else printf ".selector-class-%d{color:#ffffff;background:#000000}\n", i }}' > "$SB/big.css"
# Numbered file for line/byte paging.
awk 'BEGIN{for(i=1;i<=1000;i++) printf "line %d content\n", i}' > "$SB/numbered.txt"
# Binary file with a searchable (.css) extension.
printf 'a\0b .WPCC_BIGMARKER hidden-binary' > "$SB/bin.css"

CSS="plugins/wpcc-read-sandbox/big.css"
NUM="plugins/wpcc-read-sandbox/numbered.txt"
BIN="plugins/wpcc-read-sandbox/bin.css"
CSS_SIZE=$(wc -c < "$SB/big.css" | tr -d ' ')
echo "big.css size = ${CSS_SIZE} bytes"

echo "== 1. code_search finds text inside a large CSS file (>250KB) =="
assert_true "test CSS is larger than 250KB" "$( [ "$CSS_SIZE" -gt 256000 ] && echo true || echo false )"
R=$(rest_get "/search?q=WPCC_BIGMARKER&type=text&path=plugins/wpcc-read-sandbox")
assert_true "large-CSS search finds the marker" "$(pj "$R" '(.match_count // 0) > 0')"
assert_true "match is in big.css" "$(pj "$R" '[.matches[].file] | any(. == "plugins/wpcc-read-sandbox/big.css")')"
assert_true "files_searched reported (>=1)" "$(pj "$R" '(.files_searched // 0) >= 1')"

echo "== 2. code_search reports a STRUCTURED skip reason (binary), never silent =="
assert_true "files_skipped >= 1" "$(pj "$R" '(.files_skipped // 0) >= 1')"
assert_eq "binary file reported in skipped[] with reason" "binary" "$(pj "$R" '[.skipped[] | select(.file=="plugins/wpcc-read-sandbox/bin.css")][0].reason // "NONE"')"
assert_true "skipped entry includes size_bytes" "$(pj "$R" '[.skipped[] | select(.file=="plugins/wpcc-read-sandbox/bin.css")][0].size_bytes >= 0')"
assert_eq "result not marked complete when a file was skipped" "false" "$(pj "$R" '.complete')"

echo "== 3. code_search returns line numbers + per-file match counts =="
assert_eq "match carries the correct line_number" "7500" "$(pj "$R" '[.matches[] | select(.file=="plugins/wpcc-read-sandbox/big.css")][0].line_number')"
assert_true "matched_files reports per-file count" "$(pj "$R" '[.matched_files[] | select(.file=="plugins/wpcc-read-sandbox/big.css")][0].match_count >= 1')"
assert_true "match includes a read_hint" "$(pj "$R" '[.matches[] | select(.file=="plugins/wpcc-read-sandbox/big.css")][0].read_hint.line_start >= 7490')"

echo "== 4. file_read first chunk of a large file (line mode) =="
R=$(rest_get "/files/content?path=$NUM&line_start=1&line_count=10")
assert_eq "first chunk returns 10 lines" "10" "$(pj "$R" '.returned_lines')"
assert_eq "first line is line 1" "true" "$(pj "$R" '.contents | test("^line 1 content")')"
assert_eq "total_lines known (1000)" "1000" "$(pj "$R" '.total_lines')"
assert_eq "first chunk is truncated" "true" "$(pj "$R" '.truncated')"

echo "== 5. file_read middle chunk via line_start/line_count =="
R=$(rest_get "/files/content?path=$NUM&line_start=500&line_count=3")
assert_eq "middle chunk returns 3 lines" "3" "$(pj "$R" '.returned_lines')"
assert_true "middle chunk contains line 500" "$(pj "$R" '.contents | test("line 500 content")')"
assert_true "middle chunk excludes line 1" "$(pj "$R" '.contents | test("^line 1 content") | not')"
assert_eq "line mode reported" "line" "$(pj "$R" '.mode')"

echo "== 6. file_read by byte offset/limit =="
R=$(rest_get "/files/content?path=$NUM&byte_offset=0&byte_limit=100")
assert_eq "byte mode returns 100 bytes" "100" "$(pj "$R" '.returned_bytes')"
assert_eq "byte mode reported" "byte" "$(pj "$R" '.mode')"
assert_true "byte mode truncated" "$(pj "$R" '.truncated')"

echo "== 7. file_read returns next-cursor metadata =="
R=$(rest_get "/files/content?path=$NUM&line_start=1&line_count=10")
assert_eq "next_line_start cursor present" "11" "$(pj "$R" '.next_line_start')"
R=$(rest_get "/files/content?path=$NUM&byte_offset=0&byte_limit=100")
assert_eq "next_byte_offset cursor present" "100" "$(pj "$R" '.next_byte_offset')"
assert_nonempty "total_bytes present" "$(pj "$R" '.total_bytes')"

echo "== 8. search-to-read bridge: follow a match's read_hint into file_read =="
R=$(rest_get "/search?q=WPCC_BIGMARKER&type=text&path=plugins/wpcc-read-sandbox")
HSTART=$(pj "$R" '[.matches[] | select(.file=="plugins/wpcc-read-sandbox/big.css")][0].read_hint.line_start')
HCOUNT=$(pj "$R" '[.matches[] | select(.file=="plugins/wpcc-read-sandbox/big.css")][0].read_hint.line_count')
R2=$(rest_get "/files/content?path=$CSS&line_start=$HSTART&line_count=$HCOUNT")
assert_true "read_hint window contains the matched marker" "$(pj "$R2" '.contents | test("WPCC_BIGMARKER")')"

echo "== 9. Binary files rejected safely on read =="
R=$(rest_get "/files/content?path=$BIN")
assert_eq "binary read → wpcc_binary_file" "wpcc_binary_file" "$(pj "$R" '.code // "NONE"')"

echo "== 10. Path restrictions still enforced =="
R=$(rest_get "/files/content?path=plugins/wpcc-read-sandbox/../../../wp-config.php&line_start=1&line_count=5")
assert_true "traversal to wp-config blocked" "$(pj "$R" '(.code // "") | test("wpcc_(invalid_path|file_blocked|path_not_allowed|not_found)")')"

echo "== 11. MCP schema documents the new pagination params =="
TOOLS=$(mcp '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"context_mode":"standard"}}')
FM=$(printf '%s' "$TOOLS" | jq -c '.result.tools[] | select(.name=="file_manage")')
for p in line_start line_count byte_offset byte_limit context_before context_after; do
  assert_true "file_manage schema documents $p" "$(printf '%s' "$FM" | jq --arg p "$p" '.inputSchema.properties | has($p)')"
done
CS=$(printf '%s' "$TOOLS" | jq -r '.result.tools[] | select(.name=="code_search") | .description')
assert_true "code_search description mentions skip reporting" "$(printf '%s' "$CS" | grep -qi "files_skipped\|skipped" && echo true || echo false)"

echo "== 12. REST + MCP parity (line-mode read) =="
RR=$(rest_get "/files/content?path=$NUM&line_start=500&line_count=3")
RM=$(mcp_text "$(jq -nc --arg p "$NUM" '{jsonrpc:"2.0",id:2,method:"tools/call",params:{name:"file_manage",arguments:{action:"file_read",path:$p,line_start:500,line_count:3}}}')")
assert_eq "REST and MCP return the same returned_lines" "$(pj "$RR" '.returned_lines')" "$(pj "$RM" '.returned_lines')"
assert_eq "REST and MCP return the same next_line_start" "$(pj "$RR" '.next_line_start')" "$(pj "$RM" '.next_line_start')"
assert_eq "REST and MCP return the same total_lines" "$(pj "$RR" '.total_lines')" "$(pj "$RM" '.total_lines')"
# parity for search skip reporting
SR=$(rest_get "/search?q=WPCC_BIGMARKER&type=text&path=plugins/wpcc-read-sandbox")
SM=$(mcp_text "$(jq -nc '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:"code_search",arguments:{action:"search_text",query:"WPCC_BIGMARKER",path:"plugins/wpcc-read-sandbox"}}}')")
assert_eq "REST and MCP report the same files_skipped" "$(pj "$SR" '.files_skipped')" "$(pj "$SM" '.files_skipped')"

echo
echo "================================================"
echo "  File Read/Search Reliability: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
