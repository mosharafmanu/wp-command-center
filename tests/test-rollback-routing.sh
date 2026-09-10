#!/usr/bin/env bash
#
# Governed rollback routing — REAL_TEST_FINDINGS.md #2.
#
# WHAT WENT WRONG IN THE FIELD
#
# A site tagline was changed through MCP, approved by a human, applied, and recorded as
# reversible with a rollback_id. A rollback was then requested through `rollback_manage`
# — reasonably, since that tool has an action called `rollback_apply`. WPCC queued it,
# classified it high risk, showed it to an administrator, took their approval, and only
# THEN refused it: `rollback_manage` reverses file patches and nothing else.
#
# The refusal was correct. Its timing was not. A human approval — the scarcest signal in
# this product — was spent deciding about work the system already knew it would refuse.
#
# WHAT IS ASSERTED HERE
#
#   1. A non-patch rollback sent to the patch-only executor is refused BEFORE any
#      approval request exists, and the error names the real change kind and the correct
#      call. (The count of approval requests must not move.)
#   2. The correct route still works end to end: request -> human approval -> execution
#      -> original value actually restored.
#   3. The approval boundary is intact: the rollback is gated, not auto-applied.
#   4. Patch rollbacks still reach rollback_manage with patch_id semantics unchanged.
#   5. A refused rollback changes nothing and is not reported as success.
#
# The suite restores whatever protection mode the site was in, via the shared guard.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$PLUGIN_DIR/wpcc-env.sh"
source "$SCRIPT_DIR/lib/mode-guard.sh"
wpcc_mode_guard_init "$WP_PATH"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_contains() { local d="$1" h="$2" n="$3"; if [[ "$h" == *"$n"* ]]; then pass "$d"; else fail "$d (missing '$n' in: ${h:0:160})"; fi; }

wpe() { wp --path="$WP_PATH" eval "$1" 2>/dev/null; }
mcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }

# Pull one field out of an MCP tools/call envelope without assuming the inner text is
# valid JSON for every error shape.
field() { python3 -c "
import json,sys
raw=sys.stdin.read()
try:
    d=json.loads(raw); t=d['result']['content'][0]['text']
    try: obj=json.loads(t)
    except Exception: obj={'_raw': t}
except Exception:
    obj={}
v=obj.get('$1')
print('' if v is None else v)
"; }

requests_count() { wpe 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpcc_operation_requests");'; }
tagline()        { wp --path="$WP_PATH" option get blogdescription 2>/dev/null; }

echo "Governed rollback routing — $(date)"
echo ""

ORIGINAL_TAGLINE="$(tagline)"
restore_tagline() { wp --path="$WP_PATH" option update blogdescription "$ORIGINAL_TAGLINE" >/dev/null 2>&1; }
trap restore_tagline EXIT

# ===================================================================
echo "== 1. Create a reversible NON-PATCH change (developer mode) =="

wp --path="$WP_PATH" option update wpcc_security_mode developer >/dev/null
MARKER="WPCC rollback routing $$"
R="$(mcp "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"option_manage\",\"arguments\":{\"action\":\"option_update\",\"option_id\":\"tagline\",\"value\":\"$MARKER\"}}}")"
ROLLBACK_ID="$(printf '%s' "$R" | field rollback_id)"

assert_eq       "change applied"            "$MARKER" "$(tagline)"
[ -n "$ROLLBACK_ID" ] && pass "change is reversible (rollback_id issued)" || fail "change is reversible (rollback_id issued)"

# ===================================================================
echo ""
echo "== 2. Wrong executor is refused BEFORE any approval (protected mode) =="

wp --path="$WP_PATH" option update wpcc_security_mode client >/dev/null
BEFORE="$(requests_count)"

# 2a — the finding's exact call: a rollback_id handed to the patch-only tool.
R="$(mcp "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"rollback_manage\",\"arguments\":{\"action\":\"rollback_apply\",\"rollback_id\":\"$ROLLBACK_ID\"}}}")"
assert_eq "rollback_id at patch executor: refused" "wpcc_not_a_patch_rollback" "$(printf '%s' "$R" | field code)"
assert_eq "rollback_id at patch executor: not queued for approval" "" "$(printf '%s' "$R" | field status)"

# 2b — same id in patch_id. This one can be resolved against the change log, so the
# error must name the ACTUAL kind and the operation that can undo it.
R="$(mcp "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"rollback_manage\",\"arguments\":{\"action\":\"rollback_apply\",\"patch_id\":\"$ROLLBACK_ID\"}}}")"
MSG="$(printf '%s' "$R" | field message)"
assert_eq       "non-patch id in patch_id: refused"        "wpcc_not_a_patch_rollback" "$(printf '%s' "$R" | field code)"
assert_contains "error names the real change kind"         "$MSG" "runtime_option"
assert_contains "error names the operation that can undo it" "$MSG" "change_history"
assert_contains "error names the correct action"           "$MSG" "rollback_target"

# THE regression this finding is about: no human decision may be consumed by a request
# the system already knows it will refuse.
assert_eq "no approval request created by either refusal" "$BEFORE" "$(requests_count)"

# Nothing may have changed on the site.
assert_eq "site unchanged after refusals" "$MARKER" "$(tagline)"

# ===================================================================
echo ""
echo "== 3. Correct route is gated, then restores the value =="

R="$(mcp "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"tools/call\",\"params\":{\"name\":\"change_history\",\"arguments\":{\"action\":\"rollback_target\",\"rollback_id\":\"$ROLLBACK_ID\"}}}")"
REQ_ID="$(printf '%s' "$R" | field request_id)"

assert_eq "correct route requires approval" "pending_approval" "$(printf '%s' "$R" | field status)"
assert_eq "rollback is classified high risk" "high" "$(printf '%s' "$R" | field risk_level)"
[ -n "$REQ_ID" ] && pass "approval request created" || fail "approval request created"

# The assistant must not be able to apply it by asking again.
assert_eq "still not applied while pending" "$MARKER" "$(tagline)"

# Human approval + execution, exactly as the Approval Center does it (admin actor shape).
wpe "
wp_set_current_user(1);
\$m = new WPCommandCenter\\Operations\\OperationManager();
\$actor = ['wp_user_id'=>1,'user_login'=>'admin','source'=>'admin_ui'];
\$m->approve_request('$REQ_ID', ['actor'=>\$actor]);
\$r = \$m->execute_request('$REQ_ID', \$actor);
echo is_wp_error(\$r) ? 'error' : 'ok';
" >/dev/null

assert_eq "original value restored after human approval" "$ORIGINAL_TAGLINE" "$(tagline)"

# ===================================================================
echo ""
echo "== 4. Approval boundary — MCP cannot self-approve =="

# A token may not resolve its own approval request. This is the property that makes the
# whole gate meaningful, so it is asserted here rather than assumed.
#
# The action name is `request_approve` (ApprovalRegistry::A_REQUEST_APPROVE). Asserting
# on the EXACT refusal code matters: an earlier draft of this test used an action that
# does not exist and passed on `wpcc_invalid_approval_action`, which proves only that
# typos are rejected. A self-approval attempt must be refused for being a self-approval.
NEW_REQ="$(mcp "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"option_manage\",\"arguments\":{\"action\":\"option_update\",\"option_id\":\"tagline\",\"value\":\"$MARKER approval-boundary\"}}}" | field request_id)"
R="$(mcp "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"approval_manage\",\"arguments\":{\"action\":\"request_approve\",\"request_id\":\"$NEW_REQ\"}}}")"
assert_eq "assistant cannot approve its own request" "wpcc_approval_requires_human" "$(printf '%s' "$R" | field code)"
assert_eq "and the site is untouched by the attempt" "$ORIGINAL_TAGLINE" "$(tagline)"

# ===================================================================
echo ""
echo "== 5. Patch rollbacks keep their own semantics =="

# An unknown patch id must still be a patch-not-found, NOT the non-patch guidance —
# proving the new pre-flight routes on the recorded change kind rather than assuming
# every unresolved id belongs elsewhere.
R="$(mcp '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"rollback_manage","arguments":{"action":"rollback_apply","patch_id":"00000000-0000-0000-0000-000000000000"}}}')"
assert_eq "unknown patch id still reports patch_not_found" "wpcc_patch_not_found" "$(printf '%s' "$R" | field code)"

# Read actions are never pre-flighted away.
R="$(mcp '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"rollback_manage","arguments":{"action":"rollback_list"}}}')"
assert_eq "rollback_list still works" "rollback_list" "$(printf '%s' "$R" | field action)"

echo ""
echo "------------------------------------------------"
echo "Rollback routing: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
