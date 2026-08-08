#!/usr/bin/env bash
#
# ISSUE 13 — Universal Structured Error Envelope acceptance suite.
#
# A file_manage EOF-boundary read that used to crash generically ("Tool
# execution failed", no error envelope) proved that a Throwable escaping
# anywhere in McpServerRuntime::tools_call() BEFORE/AFTER the handler itself
# (capability checks, idempotency, redaction, context-mode optimization) fell
# through to McpRestApi's outer catch as a bare JSON-RPC transport error
# instead of a structured in-band isError tool result — the shape MCP clients
# render as readable tool output. OperationExecutor already convert a
# handler's own Throwable into wpcc_handler_exception; the gap was the
# transport layer WRAPPING every tool call, which is shared by all 42 tools.
#
# This suite forces a real Throwable (via the wpcc_test_force_tool_exception
# filter — inert unless a test explicitly hooks it; no external caller can
# reach it) for a representative spread of runtimes and asserts every one
# comes back as a structured {isError:true, code, message} result, never a
# bare transport error.
#
# Requires: wp-cli, wpcc-env.sh.
# Usage: bash tests/test-error-envelope-universal.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

echo "== Forcing a Throwable in each runtime, asserting a structured isError envelope =="

RESULT=$(wp eval '
$tools = [
  "file_manage"          => ["action" => "file_tree"],
  "media_manage"         => ["action" => "media_search"],
  "patch_manage"         => ["action" => "patch_status", "patch_id" => "x"],
  "safe_search_replace"  => ["search" => "a", "replace" => "b", "tables" => ["wp_options"], "dry_run" => true],
  "acf_manage"           => ["action" => "acf_inventory"],
  "term_manage"          => ["action" => "term_list", "taxonomy" => "category"],
  "cache_manage"         => ["action" => "cache_status"],
  "seo_manage"           => ["action" => "seo_get", "post_id" => 1],
  "code_search"          => ["action" => "search_text", "query" => "x"],
];

foreach ( array_keys( $tools ) as $tool ) {
  add_filter( "wpcc_test_force_tool_exception", function ( $forced, $t, $args ) use ( $tool ) {
    if ( $t === $tool ) {
      return new \RuntimeException( "forced-fault-for-$tool" );
    }
    return $forced;
  }, 10, 3 );
}

$rt = new \WPCommandCenter\Mcp\McpServerRuntime();
$out = [];
foreach ( $tools as $tool => $args ) {
  $req = [ "jsonrpc" => "2.0", "id" => 1, "method" => "tools/call", "params" => [ "name" => $tool, "arguments" => $args ] ];
  $res = $rt->handle( $req, [] );
  $text = $res["result"]["content"][0]["text"] ?? null;
  $decoded = $text ? json_decode( $text, true ) : null;
  $out[] = [
    "tool"          => $tool,
    "has_top_error" => isset( $res["error"] ),
    "is_error_flag" => $res["result"]["isError"] ?? null,
    "code"          => $decoded["code"] ?? null,
    "message"       => $decoded["message"] ?? null,
  ];
}
echo json_encode( $out );
' --path="$WP_ROOT" 2>/dev/null)

if [ -z "$RESULT" ]; then
  fail "wp eval produced no output"
else
  COUNT=$(echo "$RESULT" | jq 'length')
  for i in $(seq 0 $((COUNT - 1))); do
    ROW=$(echo "$RESULT" | jq -c ".[$i]")
    TOOL=$(echo "$ROW" | jq -r '.tool')
    TOP_ERR=$(echo "$ROW" | jq -r '.has_top_error')
    IS_ERR=$(echo "$ROW" | jq -r '.is_error_flag')
    CODE=$(echo "$ROW" | jq -r '.code // empty')
    MSG=$(echo "$ROW" | jq -r '.message // empty')

    [ "$TOP_ERR" = "false" ] && pass "$TOOL: no bare JSON-RPC transport error" || fail "$TOOL: leaked a top-level transport error"
    [ "$IS_ERR" = "true" ] && pass "$TOOL: result.isError=true" || fail "$TOOL: result.isError not true (got '$IS_ERR')"
    [ "$CODE" = "wpcc_tool_exception" ] && pass "$TOOL: structured code wpcc_tool_exception" || fail "$TOOL: code '$CODE'"
    [ "$MSG" = "forced-fault-for-$TOOL" ] && pass "$TOOL: message carries the original exception text" || fail "$TOOL: message '$MSG'"
  done
fi

echo "== Success path unaffected (no filter hooked) =="
R=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"file_manage","arguments":{"action":"file_tree"}}}' \
  "$WPCC_BASE/mcp")
ISERR=$(echo "$R" | jq -r '.result.isError // false')
[ "$ISERR" = "false" ] && pass "unaffected: success path has no isError" || fail "unaffected: success path isError=$ISERR"

echo
echo "================================================"
echo "  Universal Error Envelope: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
