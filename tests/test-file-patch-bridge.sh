#!/usr/bin/env bash
#
# STEP 87 — REST + MCP File/Patch Bridge acceptance suite.
#
# Proves the File Access, Code Search, Patch Engine, Snapshot, and Rollback
# systems work through BOTH REST (+token) and MCP, via the same shared service
# layer (OperationExecutor → FileAccessApi / CodeSearch / PatchManager /
# PatchApproval / SnapshotManager).
#
# Covers the 15 acceptance tests in the STEP 87 spec.
#
# Requires: curl, jq, wp (for security-mode switching only), wpcc-env.sh.
# Usage: bash tests/test-file-patch-bridge.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

WP_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
WP_PATH="$WP_ROOT"
PLUGINS_DIR="$WP_ROOT/wp-content/plugins"
AUDIT_LOG="$WP_ROOT/wp-content/uploads/wpcc-audit/audit.log"

SANDBOX="$PLUGINS_DIR/wpcc-bridge-sandbox"
DANGER_DIR="$PLUGINS_DIR/wpcc-test-danger"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }

# printf|jq avoids echo interpreting \n escapes inside JSON string values (diffs).
pj() { printf '%s' "$1" | jq -r "$2"; }

rest()      { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$2" "$WPCC_BASE/operations/$1/run"; }
rest_get()  { curl -s -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$1"; }
mcp()       { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
mcp_text()  { mcp "$1" | jq -r '.result.content[0].text // empty'; }
set_mode()  { wp eval "update_option('wpcc_security_mode', '$1');" --path="$WP_PATH" >/dev/null 2>&1; }

cleanup() { set_mode "developer"; rm -rf "$SANDBOX" "$DANGER_DIR" 2>/dev/null; }
trap cleanup EXIT

set_mode "developer"
mkdir -p "$SANDBOX/sub"
printf '<?php\n$wpcc_value = "v1";\n' > "$SANDBOX/sub/target.php"
REL="plugins/wpcc-bridge-sandbox/sub/target.php"
V2=$'<?php\n$wpcc_value = "v2";\n'
BROKEN=$'<?php\n$wpcc_value = \n'

AUDIT_BEFORE=0; [ -f "$AUDIT_LOG" ] && AUDIT_BEFORE=$(wc -l < "$AUDIT_LOG" | tr -d ' ')

echo "== 1. REST file read works with token =="
R=$(rest_get "/files/content?path=$REL")
assert_nonempty "REST /files/content returns contents" "$(pj "$R" '.contents // empty')"

echo "== 2. MCP file read works with token =="
R=$(mcp_text '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"file_manage","arguments":{"action":"file_read","path":"'"$REL"'"}}}')
assert_eq "MCP file_manage/file_read action" "file_read" "$(pj "$R" '.action // empty')"
assert_nonempty "MCP file_read returns contents" "$(pj "$R" '.contents // empty')"

echo "== 3. REST code search works =="
R=$(rest_get "/search?q=wpcc_value&type=text&path=plugins/wpcc-bridge-sandbox")
assert_eq "REST /search finds the symbol" "true" "$(pj "$R" '(.matches | length) > 0')"

echo "== 4. MCP code search works =="
R=$(mcp_text '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"code_search","arguments":{"action":"search_text","query":"wpcc_value","path":"plugins/wpcc-bridge-sandbox"}}}')
assert_eq "MCP code_search match_count > 0" "true" "$(pj "$R" '(.match_count // 0) > 0')"

echo "== 5. REST patch preview works =="
REQ=$(jq -n --arg p "$REL" --arg m "$V2" '{action:"patch_preview",files:[{path:$p,modified:$m}]}')
R=$(rest patch_manage "$REQ")
assert_eq "REST patch_preview syntax_ok" "true" "$(pj "$R" '.syntax_ok')"
assert_eq "REST patch_preview shows change" "true" "$(pj "$R" '.files[0].changed')"

echo "== 6. MCP patch preview works =="
REQ=$(jq -n --arg p "$REL" --arg m "$V2" '{jsonrpc:"2.0",id:3,method:"tools/call",params:{name:"patch_manage",arguments:{action:"patch_preview",files:[{path:$p,modified:$m}]}}}')
R=$(mcp_text "$REQ")
assert_eq "MCP patch_preview syntax_ok" "true" "$(pj "$R" '.syntax_ok')"

echo "== 7. REST patch apply creates patch_id and rollback_id =="
REQ=$(jq -n --arg p "$REL" --arg m "$V2" '{action:"patch_create",files:[{path:$p,modified:$m}],explanation:"step87 rest"}')
PID=$(pj "$(rest patch_manage "$REQ")" '.patch_id')
assert_nonempty "REST patch_create patch_id" "$PID"
R=$(rest patch_manage "$(jq -n --arg id "$PID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "REST patch_apply status applied" "applied" "$(pj "$R" '.status')"
assert_nonempty "REST patch_apply patch_id" "$(pj "$R" '.patch_id')"
assert_nonempty "REST patch_apply rollback_id" "$(pj "$R" '.rollback_id')"

echo "== 9. File change is verified =="
assert_eq "applied file on disk is v2" "1" "$(grep -c 'v2' "$SANDBOX/sub/target.php")"
R=$(rest patch_manage "$(jq -n --arg id "$PID" '{action:"patch_verify",patch_id:$id}')")
assert_eq "patch_verify syntax_ok" "true" "$(pj "$R" '.syntax_ok')"

echo "== 10. Rollback restores the previous file =="
R=$(rest rollback_manage "$(jq -n --arg id "$PID" '{action:"rollback_apply",patch_id:$id}')")
assert_eq "rollback_apply restored" "true" "$(pj "$R" '.restored')"
assert_eq "file restored to v1" "1" "$(grep -c 'v1' "$SANDBOX/sub/target.php")"

echo "== 8. MCP patch apply creates patch_id and rollback_id =="
REQ=$(jq -n --arg p "$REL" --arg m "$V2" '{jsonrpc:"2.0",id:4,method:"tools/call",params:{name:"patch_manage",arguments:{action:"patch_create",files:[{path:$p,modified:$m}],explanation:"step87 mcp"}}}')
MPID=$(pj "$(mcp_text "$REQ")" '.patch_id')
assert_nonempty "MCP patch_create patch_id" "$MPID"
REQ=$(jq -n --arg id "$MPID" '{jsonrpc:"2.0",id:5,method:"tools/call",params:{name:"patch_manage",arguments:{action:"patch_apply",patch_id:$id}}}')
R=$(mcp_text "$REQ")
assert_eq "MCP patch_apply status applied" "applied" "$(pj "$R" '.status')"
assert_nonempty "MCP patch_apply rollback_id" "$(pj "$R" '.rollback_id')"
# restore
rest rollback_manage "$(jq -n --arg id "$MPID" '{action:"rollback_apply",patch_id:$id}')" >/dev/null

echo "== 11. PHP syntax error blocks patch apply =="
REQ=$(jq -n --arg p "$REL" --arg m "$BROKEN" '{action:"patch_create",files:[{path:$p,modified:$m}],explanation:"broken"}')
BPID=$(pj "$(rest patch_manage "$REQ")" '.patch_id')
R=$(rest patch_manage "$(jq -n --arg id "$BPID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "broken patch apply status failed (auto-reverted)" "failed" "$(pj "$R" '.status')"
assert_eq "file left at v1 after blocked apply" "1" "$(grep -c 'v1' "$SANDBOX/sub/target.php")"

echo "== 12. Client Mode creates pending approval for patch apply =="
REQ=$(jq -n --arg p "$REL" --arg m "$V2" '{action:"patch_create",files:[{path:$p,modified:$m}],explanation:"mode test"}')
CPID=$(pj "$(rest patch_manage "$REQ")" '.patch_id')
set_mode "client"
R=$(rest patch_manage "$(jq -n --arg id "$CPID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "client: patch_apply → pending_approval" "pending_approval" "$(pj "$R" '.status')"

echo "== 13. Enterprise Mode creates pending approval for patch apply =="
set_mode "enterprise"
R=$(rest patch_manage "$(jq -n --arg id "$CPID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "enterprise: patch_apply → pending_approval" "pending_approval" "$(pj "$R" '.status')"
set_mode "developer"

echo "== Bonus: dangerous file (plugin main file) requires confirmation =="
mkdir -p "$DANGER_DIR"
printf '<?php\n/**\n * Plugin Name: WPCC Danger\n */\n$x = "a";\n' > "$DANGER_DIR/wpcc-test-danger.php"
DREL="plugins/wpcc-test-danger/wpcc-test-danger.php"
DNEW=$'<?php\n/**\n * Plugin Name: WPCC Danger\n */\n$x = "b";\n'
REQ=$(jq -n --arg p "$DREL" --arg m "$DNEW" '{action:"patch_create",files:[{path:$p,modified:$m}],explanation:"danger"}')
DPID=$(pj "$(rest patch_manage "$REQ")" '.patch_id')
R=$(rest patch_manage "$(jq -n --arg id "$DPID" '{action:"patch_apply",patch_id:$id}')")
assert_eq "dangerous apply without confirmation → confirmation_required" "confirmation_required" "$(pj "$R" '.status')"
assert_eq "dangerous apply advertises APPLY_PATCH phrase" "APPLY_PATCH" "$(pj "$R" '.confirmation_phrase')"
R=$(rest patch_manage "$(jq -n --arg id "$DPID" '{action:"patch_apply",patch_id:$id,confirm:true,confirmation_phrase:"APPLY_PATCH",reason:"approved edit"}')")
assert_eq "dangerous apply WITH confirmation → applied" "applied" "$(pj "$R" '.status')"
rest rollback_manage "$(jq -n --arg id "$DPID" '{action:"rollback_apply",patch_id:$id}')" >/dev/null

echo "== 14. Audit trail records all file operations =="
if [ -f "$AUDIT_LOG" ]; then
  NEW=$(tail -n "+$((AUDIT_BEFORE+1))" "$AUDIT_LOG")
  # Pure-bash substring checks (no printf|grep pipe): under `set -o pipefail`,
  # `printf "$NEW" | grep -q` can report a spurious miss when grep -q exits on an
  # early match while printf is still streaming a large window — the audit window
  # grew with multi-file change sets, which exposed that flakiness. The event is
  # present either way; this just evaluates it reliably in-process.
  [[ "$NEW" == *"file.read"* ]]        && pass "audit: file.read recorded"        || fail "audit: file.read missing"
  [[ "$NEW" == *"code.search"* ]]      && pass "audit: code.search recorded"      || fail "audit: code.search missing"
  [[ "$NEW" == *'"patch.applied"'* ]]  && pass "audit: patch.applied recorded"    || fail "audit: patch.applied missing"
  [[ "$NEW" == *"patch.rolled_back"* ]] && pass "audit: patch.rolled_back recorded" || fail "audit: patch.rolled_back missing"
else
  fail "audit: log file not found"
fi

echo "== 15. No SSH or WP-CLI required for normal REST/MCP operation =="
# Syntax validation is enforced through the API itself (tokenizer fallback needs
# no shell); the broken-patch block above proved apply is gated without SSH.
R=$(rest patch_manage "$(jq -n --arg p "$REL" --arg m "$BROKEN" '{action:"patch_preview",files:[{path:$p,modified:$m}]}')")
assert_eq "syntax validation works via API (no shell needed)" "false" "$(pj "$R" '.syntax_ok')"
METHOD=$(pj "$R" '.files[0].syntax.method')
{ [ "$METHOD" = "tokenizer" ] || [ "$METHOD" = "php -l" ]; } && pass "syntax method available without SSH ($METHOD)" || fail "syntax method unexpected ($METHOD)"

echo "== Path normalization: wp-content-prefixed + absolute paths accepted =="
for P in "$REL" "wp-content/$REL" "$WP_ROOT/wp-content/$REL"; do
  R=$(rest file_manage "$(jq -n --arg p "$P" '{action:"file_read",path:$p}')")
  assert_eq "file_read accepts '${P:0:24}...'" "plugins/wpcc-bridge-sandbox/sub/target.php" "$(pj "$R" '.path // "ERR"')"
done
# Security must still hold after normalization.
R=$(rest file_manage '{"action":"file_read","path":"wp-content/../wp-config.php"}')
assert_eq "traversal via wp-content/.. still blocked" "wpcc_invalid_path" "$(pj "$R" '.code // "NOT_BLOCKED"')"
R=$(rest file_manage "$(jq -n --arg p "$WP_ROOT/wp-config.php" '{action:"file_read",path:$p}')")
assert_eq "absolute wp-config.php still blocked" "true" "$(pj "$R" '(.code // "") | test("wpcc_(not_found|file_blocked|path_not_allowed)")')"

echo
echo "================================================"
echo "  File/Patch Bridge: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
