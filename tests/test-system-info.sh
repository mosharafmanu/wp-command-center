#!/usr/bin/env bash
# Step 81 — system_info runtime tests
# Verifies: pure-PHP execution, all fields present, diagnostic risk tier,
# available in every Security Mode (never gated), listed in manifest.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0

pass()       { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail()       { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()  { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_ne()  { local d="$1" e="$2" a="$3"; if [ "$e" != "$a" ]; then pass "$d"; else fail "$d (expected not '$e', got '$a')"; fi; }
assert_nonempty() { local d="$1" a="$2"; if [ -n "$a" ] && [ "$a" != "null" ]; then pass "$d"; else fail "$d (got empty/null)"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d (expected true, got '$a')"; fi; }

mcp() {
    curl -s -X POST \
        -H "Authorization: Bearer $WPCC_TOKEN" \
        -H "Content-Type: application/json" \
        -d "$1" \
        "$WPCC_BASE/mcp"
}

set_mode() {
    wp eval "update_option('wpcc_security_mode', '$1');" --path="$WP_PATH" >/dev/null 2>&1
}

# ===================================================================
echo "== 1. Registry: system_info is registered as diagnostic =="
RISK=$(wp eval "
\$reg = new \WPCommandCenter\Operations\OperationRegistry();
\$op  = \$reg->get_operation('system_info');
echo \$op ? \$op['risk_level'] : 'NOT_FOUND';
" --path="$WP_PATH" 2>/dev/null)
assert_eq "registry: system_info risk_level is diagnostic" "diagnostic" "$RISK"

AVAIL=$(wp eval "
\$reg = new \WPCommandCenter\Operations\OperationRegistry();
\$op  = \$reg->get_operation('system_info');
echo \$op && \$op['available'] ? 'true' : 'false';
" --path="$WP_PATH" 2>/dev/null)
assert_true "registry: system_info available=true" "$AVAIL"

# ===================================================================
echo "== 2. Manifest: system_info appears in tools/list =="
set_mode "developer"
MANIFEST=$(mcp '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}')
LISTED=$(echo "$MANIFEST" | jq -r '.result.tools[] | select(.name=="system_info") | .name')
assert_eq "manifest: system_info listed in tools/list" "system_info" "$LISTED"

# ===================================================================
echo "== 3. Developer Mode: system_info executes immediately =="
RESP=$(mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"system_info","arguments":{}}}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
ERR=$(echo "$RESP" | jq -r '.error.code // empty')

assert_ne "developer: no JSON-RPC error" "-32000" "$ERR"
assert_nonempty "developer: data returned" "$DATA"

WP_VER=$(echo "$DATA" | jq -r '.wordpress_version // empty')
PHP_VER=$(echo "$DATA" | jq -r '.php_version // empty')
SITE_URL=$(echo "$DATA" | jq -r '.site_url // empty')
STATUS=$(echo "$DATA" | jq -r '.status // empty')

assert_nonempty "developer: wordpress_version present"   "$WP_VER"
assert_nonempty "developer: php_version present"         "$PHP_VER"
assert_nonempty "developer: site_url present"            "$SITE_URL"
assert_ne       "developer: not pending_approval" "pending_approval" "$STATUS"

# ===================================================================
echo "== 4. All fields present =="
MYSQL_VER=$(echo "$DATA" | jq -r '.mysql_version // empty')
THEME=$(echo "$DATA" | jq -r '.active_theme.name // empty')
PLUGINS=$(echo "$DATA" | jq -r '.active_plugins_count // empty')
TIMEZONE=$(echo "$DATA" | jq -r '.timezone // empty')
ENV=$(echo "$DATA" | jq -r '.environment_type // empty')
LOCALE=$(echo "$DATA" | jq -r '.locale // empty')
MEMLIMIT=$(echo "$DATA" | jq -r '.memory_limit // empty')

assert_nonempty "fields: mysql_version"        "$MYSQL_VER"
assert_nonempty "fields: active_theme.name"    "$THEME"
assert_nonempty "fields: active_plugins_count" "$PLUGINS"
assert_nonempty "fields: timezone"             "$TIMEZONE"
assert_nonempty "fields: environment_type"     "$ENV"
assert_nonempty "fields: locale"               "$LOCALE"
assert_nonempty "fields: memory_limit"         "$MEMLIMIT"

# ===================================================================
echo "== 5. Client Mode: system_info executes immediately (diagnostic, never gated) =="
set_mode "client"
RESP=$(mcp '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"system_info","arguments":{}}}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
ERR=$(echo "$RESP" | jq -r '.error.code // empty')
STATUS=$(echo "$DATA" | jq -r '.status // empty')

assert_ne "client: no JSON-RPC error"         "-32000" "$ERR"
assert_ne "client: not pending_approval"      "pending_approval" "$STATUS"
assert_nonempty "client: wordpress_version returned" "$(echo "$DATA" | jq -r '.wordpress_version // empty')"

# ===================================================================
echo "== 6. Enterprise Mode: system_info executes immediately (diagnostic, never gated) =="
set_mode "enterprise"
RESP=$(mcp '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"system_info","arguments":{}}}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
ERR=$(echo "$RESP" | jq -r '.error.code // empty')
STATUS=$(echo "$DATA" | jq -r '.status // empty')

assert_ne "enterprise: no JSON-RPC error"       "-32000" "$ERR"
assert_ne "enterprise: not pending_approval"    "pending_approval" "$STATUS"
assert_nonempty "enterprise: wordpress_version returned" "$(echo "$DATA" | jq -r '.wordpress_version // empty')"

# ===================================================================
echo "== 7. shell_capabilities field reflects server environment =="
set_mode "developer"
RESP=$(mcp '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"system_info","arguments":{}}}')
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
SHELL_CAPS=$(echo "$DATA" | jq -r '.shell_capabilities // empty')
assert_nonempty "shell_capabilities field present" "$SHELL_CAPS"

# ===================================================================
echo "== Cleanup: restore developer mode =="
set_mode "developer"
RESTORED=$(wp eval "echo get_option('wpcc_security_mode', 'developer');" --path="$WP_PATH" 2>/dev/null)
assert_eq "mode restored to developer" "developer" "$RESTORED"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
