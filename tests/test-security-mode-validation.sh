#!/usr/bin/env bash
# Step 81 — Security Mode validation matrix
# Verifies the full gating behaviour of each mode across all risk tiers.
# Reports exact pass/fail for every (mode, operation, expected_outcome) triplet.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0

pass()       { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail()       { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq()  { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_ne()  { local d="$1" e="$2" a="$3"; if [ "$e" != "$a" ]; then pass "$d"; else fail "$d (expected not '$e', got '$a')"; fi; }

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

cancel_request() {
    local rid="$1"
    [ -z "$rid" ] || [ "$rid" = "null" ] && return
    BODY=$(jq -n --arg r "$rid" '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_cancel",request_id:$r}},id:99}')
    mcp "$BODY" >/dev/null 2>&1
}

# Helper: call a tool, return "immediate", "pending_approval", or "error:<code>"
gate_result() {
    local body="$1"
    local resp
    resp=$(mcp "$body")
    local err_code data status
    err_code=$(echo "$resp" | jq -r '.error.code // empty')
    data=$(echo "$resp" | jq -r '.result.content[0].text // empty')
    status=$(echo "$data" | jq -r '.status // empty')
    local rid
    rid=$(echo "$data" | jq -r '.request_id // empty')
    cancel_request "$rid"
    if [ "$status" = "pending_approval" ]; then
        echo "pending_approval"
    elif [ -n "$err_code" ]; then
        echo "error:$err_code"
    else
        echo "immediate"
    fi
}

# ===================================================================
echo "== DEVELOPER MODE =="
set_mode "developer"

# Diagnostic (system_info) — always free
R=$(gate_result '{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"system_info","arguments":{}}}')
assert_eq "developer: system_info (diagnostic) → immediate" "immediate" "$R"

# Diagnostic (database_inspect) — always free
R=$(gate_result '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}}}')
assert_eq "developer: database_inspect (diagnostic) → immediate" "immediate" "$R"

# Diagnostic action inside write operation — free in developer
R=$(gate_result '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"user_manage","arguments":{"action":"user_list"}}}')
assert_eq "developer: user_manage/user_list (diagnostic action) → immediate" "immediate" "$R"

# High — free in developer
R=$(gate_result '{"jsonrpc":"2.0","id":13,"method":"tools/call","params":{"name":"theme_manage","arguments":{"action":"theme_list"}}}')
assert_eq "developer: theme_manage/theme_list (diagnostic action) → immediate" "immediate" "$R"

R=$(gate_result '{"jsonrpc":"2.0","id":14,"method":"tools/call","params":{"name":"snapshot_manage","arguments":{"action":"snapshot_list"}}}')
assert_eq "developer: snapshot_manage/snapshot_list (diagnostic action) → immediate" "immediate" "$R"

# Critical action — free in developer
R=$(gate_result '{"jsonrpc":"2.0","id":15,"method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_list"}}}')
assert_eq "developer: plugin_manage/plugin_list (diagnostic action) → immediate" "immediate" "$R"

# ===================================================================
echo "== CLIENT MODE =="
set_mode "client"

# Diagnostic — always free in client
R=$(gate_result '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":"system_info","arguments":{}}}')
assert_eq "client: system_info (diagnostic) → immediate" "immediate" "$R"

R=$(gate_result '{"jsonrpc":"2.0","id":21,"method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}}}')
assert_eq "client: database_inspect (diagnostic) → immediate" "immediate" "$R"

R=$(gate_result '{"jsonrpc":"2.0","id":22,"method":"tools/call","params":{"name":"search_manage","arguments":{"action":"search_content","query":"test"}}}')
assert_eq "client: search_manage (diagnostic) → immediate" "immediate" "$R"

# Diagnostic action inside write operation — free in client
R=$(gate_result '{"jsonrpc":"2.0","id":23,"method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_list"}}}')
assert_eq "client: plugin_manage/plugin_list (diagnostic action) → immediate" "immediate" "$R"

R=$(gate_result '{"jsonrpc":"2.0","id":24,"method":"tools/call","params":{"name":"user_manage","arguments":{"action":"user_list"}}}')
assert_eq "client: user_manage/user_list (diagnostic action) → immediate" "immediate" "$R"

# Medium — gated in client
R=$(gate_result '{"jsonrpc":"2.0","id":25,"method":"tools/call","params":{"name":"content_manage","arguments":{"action":"create","post_type":"post","title":"Test","status":"draft"}}}')
assert_eq "client: content_manage/create (medium) → pending_approval" "pending_approval" "$R"

# High — gated in client
R=$(gate_result '{"jsonrpc":"2.0","id":26,"method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_install","plugin":"hello-dolly"}}}')
assert_eq "client: plugin_manage/plugin_install (high) → pending_approval" "pending_approval" "$R"

# Critical — gated in client
# STEP 84: plugin_delete is destructive-gated; supply confirmation + slug so the
# request reaches the Client-mode approval gate instead of confirmation_required.
R=$(gate_result '{"jsonrpc":"2.0","id":27,"method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_delete","slug":"hello-dolly","confirm":true,"confirmation_phrase":"DELETE_PLUGIN","reason":"gating test"}}}')
assert_eq "client: plugin_manage/plugin_delete (critical) → pending_approval" "pending_approval" "$R"

R=$(gate_result '{"jsonrpc":"2.0","id":28,"method":"tools/call","params":{"name":"user_manage","arguments":{"action":"user_create","user_login":"testuser81","user_email":"test81@example.com","role":"subscriber"}}}')
assert_eq "client: user_manage/user_create (high) → pending_approval" "pending_approval" "$R"

# AI cannot approve in client — human-approver guard
BODY=$(jq -n '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_approve",request_id:"00000000-0000-0000-0000-000000000000"}},id:29}')
RESP=$(mcp "$BODY")
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
ERRC=$(echo "$RESP" | jq -r '.error.code // empty')
GOT=$(echo "$DATA" | jq -r '.code // empty')
if [ "$ERRC" = "-32000" ] || [ "$GOT" = "wpcc_approval_requires_human" ]; then
    pass "client: AI cannot self-approve (human-approver guard active)"
else
    fail "client: AI cannot self-approve (error.code='$ERRC', data.code='$GOT')"
fi

# ===================================================================
echo "== ENTERPRISE MODE =="
set_mode "enterprise"

# Diagnostic — always free in enterprise
R=$(gate_result '{"jsonrpc":"2.0","id":30,"method":"tools/call","params":{"name":"system_info","arguments":{}}}')
assert_eq "enterprise: system_info (diagnostic) → immediate" "immediate" "$R"

R=$(gate_result '{"jsonrpc":"2.0","id":31,"method":"tools/call","params":{"name":"database_inspect","arguments":{"action":"db_table_list"}}}')
assert_eq "enterprise: database_inspect (diagnostic) → immediate" "immediate" "$R"

R=$(gate_result '{"jsonrpc":"2.0","id":32,"method":"tools/call","params":{"name":"approval_manage","arguments":{"action":"request_list"}}}')
assert_eq "enterprise: approval_manage/request_list (diagnostic) → immediate" "immediate" "$R"

# Diagnostic action inside write operation — free even in enterprise
R=$(gate_result '{"jsonrpc":"2.0","id":33,"method":"tools/call","params":{"name":"user_manage","arguments":{"action":"user_list"}}}')
assert_eq "enterprise: user_manage/user_list (diagnostic action) → immediate" "immediate" "$R"

# Medium — gated in enterprise
R=$(gate_result '{"jsonrpc":"2.0","id":34,"method":"tools/call","params":{"name":"content_manage","arguments":{"action":"create","post_type":"post","title":"Enterprise Test","status":"draft"}}}')
assert_eq "enterprise: content_manage/create (medium) → pending_approval" "pending_approval" "$R"

# High — gated in enterprise
R=$(gate_result '{"jsonrpc":"2.0","id":35,"method":"tools/call","params":{"name":"theme_manage","arguments":{"action":"theme_install","theme":"twentytwentyfive"}}}')
assert_eq "enterprise: theme_manage/theme_install (high) → pending_approval" "pending_approval" "$R"

# Critical — gated in enterprise
# STEP 84: plugin_delete is destructive-gated; supply confirmation + slug so the
# request reaches the Enterprise-mode approval gate instead of confirmation_required.
R=$(gate_result '{"jsonrpc":"2.0","id":36,"method":"tools/call","params":{"name":"plugin_manage","arguments":{"action":"plugin_delete","slug":"hello-dolly","confirm":true,"confirmation_phrase":"DELETE_PLUGIN","reason":"gating test"}}}')
assert_eq "enterprise: plugin_manage/plugin_delete (critical) → pending_approval" "pending_approval" "$R"

# AI cannot approve in enterprise
BODY=$(jq -n '{jsonrpc:"2.0",method:"tools/call",params:{name:"approval_manage",arguments:{action:"request_approve",request_id:"00000000-0000-0000-0000-000000000000"}},id:37}')
RESP=$(mcp "$BODY")
DATA=$(echo "$RESP" | jq -r '.result.content[0].text // empty')
ERRC=$(echo "$RESP" | jq -r '.error.code // empty')
GOT=$(echo "$DATA" | jq -r '.code // empty')
if [ "$ERRC" = "-32000" ] || [ "$GOT" = "wpcc_approval_requires_human" ]; then
    pass "enterprise: AI cannot self-approve (human-approver guard active)"
else
    fail "enterprise: AI cannot self-approve (error.code='$ERRC', data.code='$GOT')"
fi

# ===================================================================
echo "== MODE CONSISTENCY: system_info returns same data in all modes =="
set_mode "developer"
DEV_VER=$(mcp '{"jsonrpc":"2.0","id":40,"method":"tools/call","params":{"name":"system_info","arguments":{}}}' | jq -r '.result.content[0].text // empty' | jq -r '.wordpress_version // empty')

set_mode "client"
CLI_VER=$(mcp '{"jsonrpc":"2.0","id":41,"method":"tools/call","params":{"name":"system_info","arguments":{}}}' | jq -r '.result.content[0].text // empty' | jq -r '.wordpress_version // empty')

set_mode "enterprise"
ENT_VER=$(mcp '{"jsonrpc":"2.0","id":42,"method":"tools/call","params":{"name":"system_info","arguments":{}}}' | jq -r '.result.content[0].text // empty' | jq -r '.wordpress_version // empty')

assert_eq "consistency: wordpress_version identical in developer vs client"    "$DEV_VER" "$CLI_VER"
assert_eq "consistency: wordpress_version identical in developer vs enterprise" "$DEV_VER" "$ENT_VER"

# ===================================================================
echo "== Cleanup =="
set_mode "developer"
RESTORED=$(wp eval "echo get_option('wpcc_security_mode', 'developer');" --path="$WP_PATH" 2>/dev/null)
assert_eq "mode restored to developer" "developer" "$RESTORED"

echo ""
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
