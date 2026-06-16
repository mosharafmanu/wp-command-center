#!/usr/bin/env bash
#
# STEP 103.2 — Agent Search UX Hardening acceptance suite.
#
# B-1: compact mode must never let an agent conclude "only 5 matches exist" when
#      more exist — truncated lists use a self-describing envelope
#      { truncated, has_more, total_count, returned, items } (+ back-compat
#      count/preview), and the top-level match_count is always the true total.
# B-2: code_search can target a SINGLE FILE (no directory scan), as well as a
#      directory or all roots, preserving all security/skip/binary guards.
#
# Requires: curl, jq, awk, wpcc-env.sh.
# Usage: bash tests/test-agent-search-ux.sh

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"
SB="$PLUGINS_DIR/wpcc-sux"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
aeq(){ local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
atrue(){ local d="$1" a="$2"; [ "$a" = "true" ] && pass "$d" || fail "$d (got '$a')"; }

pj(){ printf '%s' "$1" | jq -r "$2"; }
mt(){ curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
rest_get(){ curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$1"; }
# build a code_search tools/call with explicit args
cs(){ jq -nc --argjson a "$1" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"code_search",arguments:$a}}'; }

cleanup(){ rm -rf "$SB" 2>/dev/null; }
trap cleanup EXIT

mkdir -p "$SB"
# 12 matches of TARGETTOKEN in a single file (>5 to trigger compact envelope).
awk 'BEGIN{for(i=1;i<=12;i++) printf ".rule-%d{color:#fff} /* TARGETTOKEN */\n", i}' > "$SB/many.css"
# a second file so directory search spans >1 file
printf '.other{color:#000} /* TARGETTOKEN */\n' > "$SB/other.css"
# 3 matches only (<=5) → list stays a plain array
awk 'BEGIN{for(i=1;i<=3;i++) printf ".s-%d{x} /* FEWTOKEN */\n", i}' > "$SB/few.css"
# large single file (>250KB) with one marker
awk 'BEGIN{for(i=1;i<=15000;i++){ if(i==9000) print ".BIGMARK{}"; else printf ".c-%d{color:#fff;background:#000}\n", i }}' > "$SB/big.css"
# binary file with searchable extension
printf 'x\0y TARGETTOKEN' > "$SB/bin.css"

MANY="plugins/wpcc-sux/many.css"
FEW="plugins/wpcc-sux/few.css"
BIG="plugins/wpcc-sux/big.css"
BIN="plugins/wpcc-sux/bin.css"
DIR="plugins/wpcc-sux"

echo "== 1+3. Compact-mode truncation: envelope, never 'only 5' =="
R=$(mt "$(cs "$(jq -nc --arg p "$MANY" '{action:"search_text",query:"TARGETTOKEN",path:$p,max_results:50,context_mode:"compact"}')")")
aeq "top-level match_count is the TRUE total (12)" "12" "$(pj "$R" '.match_count')"
aeq "compact matches is an envelope (object)" "object" "$(pj "$R" '.matches|type')"
atrue "envelope.truncated" "$(pj "$R" '.matches.truncated')"
atrue "envelope.has_more" "$(pj "$R" '.matches.has_more')"
aeq "envelope.total_count = 12" "12" "$(pj "$R" '.matches.total_count')"
aeq "envelope.returned = 5" "5" "$(pj "$R" '.matches.returned')"
aeq "envelope.items is an array of 5" "5" "$(pj "$R" '.matches.items|length')"

echo "== 3b. <=5 results stay a plain array (no envelope) =="
R=$(mt "$(cs "$(jq -nc --arg p "$FEW" '{action:"search_text",query:"FEWTOKEN",path:$p,max_results:50,context_mode:"compact"}')")")
aeq "few-match matches is a plain array" "array" "$(pj "$R" '.matches|type')"
aeq "few-match match_count = 3" "3" "$(pj "$R" '.matches|length')"

echo "== 2. Large result set: total_count reflects reality =="
R=$(mt "$(cs "$(jq -nc --arg p "$DIR" '{action:"search_text",query:"TARGETTOKEN",path:$p,max_results:50,context_mode:"compact"}')")")
atrue "dir search total_count >= 13 (12+1 across 2 files)" "$(pj "$R" '.match_count >= 13')"
atrue "envelope total_count matches top match_count" "$(pj "$R" '.matches.total_count == .match_count')"

echo "== 10. Backward compatibility: compact envelope keeps count/preview aliases =="
R=$(mt "$(cs "$(jq -nc --arg p "$MANY" '{action:"search_text",query:"TARGETTOKEN",path:$p,max_results:50,context_mode:"compact"}')")")
aeq "alias count == total_count" "12" "$(pj "$R" '.matches.count')"
aeq "alias preview length == 5" "5" "$(pj "$R" '.matches.preview|length')"

echo "== 4. Single-FILE search (no directory scan) =="
R=$(mt "$(cs "$(jq -nc --arg p "$MANY" '{action:"search_text",query:"TARGETTOKEN",path:$p,max_results:50,context_mode:"standard"}')")")
aeq "scope reported as file" "file" "$(pj "$R" '.scope')"
aeq "exactly 1 file searched" "1" "$(pj "$R" '.files_searched')"
aeq "single-file match_count = 12" "12" "$(pj "$R" '.match_count')"
aeq "first match line_number accurate (1)" "1" "$(pj "$R" '.matches[0].line_number')"
atrue "match carries a read_hint" "$(pj "$R" '.matches[0].read_hint.line_start >= 1')"

echo "== 5. Directory search still works (scope=directory, >1 file) =="
R=$(mt "$(cs "$(jq -nc --arg p "$DIR" '{action:"search_text",query:"TARGETTOKEN",path:$p,max_results:50,context_mode:"standard"}')")")
aeq "scope reported as directory" "directory" "$(pj "$R" '.scope')"
atrue "directory searched >1 file" "$(pj "$R" '.files_searched > 1')"
atrue "files_scanned back-compat alias present" "$(pj "$R" '.files_scanned >= 1')"

echo "== 6. Large-file single-file search (>250KB) =="
SZ=$(wc -c < "$SB/big.css" | tr -d ' ')
atrue "big.css > 250KB" "$( [ "$SZ" -gt 256000 ] && echo true || echo false )"
R=$(mt "$(cs "$(jq -nc --arg p "$BIG" '{action:"search_text",query:"BIGMARK",path:$p,max_results:5,context_mode:"standard"}')")")
aeq "large single-file scope=file" "file" "$(pj "$R" '.scope')"
aeq "large single-file not skipped" "0" "$(pj "$R" '.files_skipped')"
aeq "large single-file finds marker at line 9000" "9000" "$(pj "$R" '.matches[0].line_number')"

echo "== 7. Binary single-file rejection (never silent) =="
R=$(mt "$(cs "$(jq -nc --arg p "$BIN" '{action:"search_text",query:"TARGETTOKEN",path:$p,max_results:5,context_mode:"standard"}')")")
aeq "binary single-file: 0 searched" "0" "$(pj "$R" '.files_searched')"
aeq "binary single-file: 1 skipped" "1" "$(pj "$R" '.files_skipped')"
aeq "binary skip reason reported" "binary" "$(pj "$R" '.skipped[0].reason')"
aeq "binary search not marked complete" "false" "$(pj "$R" '.complete')"
# file_read binary protection still holds
RB=$(mt "$(jq -nc --arg p "$BIN" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"file_manage",arguments:{action:"file_read",path:$p,context_mode:"standard"}}}')")
aeq "binary file_read still rejected" "wpcc_binary_file" "$(pj "$RB" '.code // "NONE"')"

echo "== 8. Invalid paths still blocked =="
R=$(mt "$(cs "$(jq -nc '{action:"search_text",query:"x",path:"plugins/wpcc-sux/../../../wp-config.php",context_mode:"standard"}')")")
atrue "traversal blocked" "$(pj "$R" '(.code // "") | test("wpcc_(file_blocked|invalid_path|path_not_allowed|not_found)")')"
R=$(mt "$(cs "$(jq -nc '{action:"search_text",query:"x",path:"plugins/wpcc-sux/nope-missing.css",context_mode:"standard"}')")")
atrue "non-existent path errors clearly" "$(pj "$R" '(.code // "") | test("wpcc_(not_found|file_blocked|invalid_path)")')"

echo "== 9. REST + MCP parity (data identical; compact is an MCP transport concern) =="
RM=$(mt "$(cs "$(jq -nc --arg p "$MANY" '{action:"search_text",query:"TARGETTOKEN",path:$p,max_results:50,context_mode:"standard"}')")")
RR=$(rest_get "/search?q=TARGETTOKEN&type=text&path=$MANY&max_results=50")
aeq "REST match_count == MCP-standard match_count" "$(pj "$RM" '.match_count')" "$(pj "$RR" '.match_count')"
aeq "REST scope == MCP scope (file)" "$(pj "$RM" '.scope')" "$(pj "$RR" '.scope')"
RC=$(mt "$(cs "$(jq -nc --arg p "$MANY" '{action:"search_text",query:"TARGETTOKEN",path:$p,max_results:50,context_mode:"compact"}')")")
aeq "MCP-compact total_count == REST match_count" "$(pj "$RR" '.match_count')" "$(pj "$RC" '.matches.total_count')"

echo
echo "================================================"
echo "  Agent Search UX: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
