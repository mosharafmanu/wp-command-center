#!/usr/bin/env bash
#
# STEP 89 — MCP Error Surface Hardening acceptance suite.
#
# Tool/business/authorization failures must be returned as MCP `isError`
# results — { isError:true, code:"wpcc_*", message:"…" } in the tool content,
# with result.isError=true — so AI agents can read and explain them. Genuine
# JSON-RPC transport failures (unknown method/resource) stay as -326xx errors.
#
# Acceptance scenarios: header-breaking patch, invalid rollback, missing plugin,
# missing media, permission-denied patch (read-only token). Plus protocol
# preservation and REST parity.
#
# Requires: curl, jq, python3, wp (read-only token setup), wpcc-env.sh.
# Usage: bash tests/test-mcp-error-surface.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
WP_ROOT="$WP_PATH"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

mcp() { local token="${2:-$WPCC_TOKEN}"; curl -s -X POST -H "Authorization: Bearer $token" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
# Extract fields from an isError tool result.
is_error()  { echo "$1" | jq -r '.result.isError // false'; }
err_code()  { echo "$1" | jq -r '.result.content[0].text | fromjson | .code // empty' 2>/dev/null; }
err_msg()   { echo "$1" | jq -r '.result.content[0].text | fromjson | .message // empty' 2>/dev/null; }
err_flag()  { echo "$1" | jq -r '.result.content[0].text | fromjson | .isError // false' 2>/dev/null; }

# Disposable bootstrap plugin for the header-breaking case.
TD="$WP_ROOT/wp-content/plugins/wpcc-err-surface"
cleanup() {
  rm -rf "$TD" 2>/dev/null
  wp eval '$a=new \WPCommandCenter\Security\AuthTokens(); foreach($a->list() as $t){ if("STEP89 RO"===$t["label"]) $a->delete($t["id"]); }' --path="$WP_PATH" >/dev/null 2>&1
}
trap cleanup EXIT
mkdir -p "$TD"
printf '<?php\n/**\n * Plugin Name: WPCC Err Surface\n */\n$x="a";\n' > "$TD/wpcc-err-surface.php"

echo "== 1. Header-breaking patch → isError with wpcc_patch_breaks_header =="
REQ=$(jq -n --arg p "plugins/wpcc-err-surface/wpcc-err-surface.php" --arg m $'<?php\n$x="b";\n' \
  '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"patch_manage",arguments:{action:"patch_create",files:[{path:$p,modified:$m}],explanation:"x"}}}')
R=$(mcp "$REQ")
assert_eq "header-break: result.isError true" "true" "$(is_error "$R")"
assert_eq "header-break: content isError true" "true" "$(err_flag "$R")"
assert_eq "header-break: code" "wpcc_patch_breaks_header" "$(err_code "$R")"
[ -n "$(err_msg "$R")" ] && pass "header-break: has message" || fail "header-break: missing message"

echo "== 2. Invalid rollback → isError with wpcc_patch_not_found =="
R=$(mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"rollback_manage","arguments":{"action":"rollback_apply","patch_id":"00000000-0000-0000-0000-000000000000"}}}')
assert_eq "rollback: result.isError true" "true" "$(is_error "$R")"
assert_eq "rollback: code" "wpcc_patch_not_found" "$(err_code "$R")"

echo "== 3. Missing plugin → isError with wpcc_plugin_not_found =="
R=$(mcp '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_delete","slug":"no-such-plugin-xyz","confirm":true,"confirmation_phrase":"DELETE_PLUGIN","reason":"t"}}}')
assert_eq "missing-plugin: result.isError true" "true" "$(is_error "$R")"
assert_eq "missing-plugin: code" "wpcc_plugin_not_found" "$(err_code "$R")"

echo "== 4. Missing media → isError with a structured code =="
R=$(mcp '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"media_manage","arguments":{"action":"media_get","media_id":999999999}}}')
assert_eq "missing-media: result.isError true" "true" "$(is_error "$R")"
CODE=$(err_code "$R")
{ [ -n "$CODE" ] && echo "$CODE" | grep -qiE "media|not_found|invalid"; } && pass "missing-media: structured code ($CODE)" || fail "missing-media: code '$CODE'"

echo "== 5. Permission-denied patch (read-only token) → isError wpcc_token_read_only =="
RO_TOKEN=$(wp eval '$a=new \WPCommandCenter\Security\AuthTokens(); $r=$a->create("STEP89 RO", \WPCommandCenter\Security\AuthTokens::SCOPE_READ_ONLY, null, 1); echo is_wp_error($r)?"":$r["token"];' --path="$WP_PATH" 2>/dev/null)
[ -n "$RO_TOKEN" ] && pass "setup: read-only token created" || fail "setup: read-only token"
R=$(mcp '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"patch_manage","arguments":{"action":"patch_status","patch_id":"x"}}}' "$RO_TOKEN")
assert_eq "perm-denied: result.isError true" "true" "$(is_error "$R")"
assert_eq "perm-denied: code" "wpcc_token_read_only" "$(err_code "$R")"
echo "$(err_msg "$R")" | grep -qi "read-only" && pass "perm-denied: message mentions read-only" || fail "perm-denied: message"

echo "== 6. Transport failures preserved as JSON-RPC errors =="
R=$(mcp '{"jsonrpc":"2.0","id":6,"method":"does/not/exist"}')
assert_eq "protocol: unknown method → -32601" "-32601" "$(echo "$R" | jq -r '.error.code // empty')"
assert_eq "protocol: no isError result on transport error" "false" "$(echo "$R" | jq -r 'if .result then true else false end')"

echo "== 7. Success path unchanged (no isError) =="
R=$(mcp '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"file_manage","arguments":{"action":"file_tree"}}}')
assert_eq "success: result present, isError absent/false" "false" "$(echo "$R" | jq -r '.result.isError // false')"

echo "== 8. REST parity: same failure returns structured code over REST =="
R=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d "$(jq -n --arg p "plugins/wpcc-err-surface/wpcc-err-surface.php" --arg m $'<?php\n$x="b";\n' '{action:"patch_create",files:[{path:$p,modified:$m}],explanation:"x"}')" \
  "$WPCC_BASE/operations/patch_manage/run")
assert_eq "rest: structured code" "wpcc_patch_breaks_header" "$(echo "$R" | jq -r '.code // empty')"

echo
echo "================================================"
echo "  MCP Error Surface: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
