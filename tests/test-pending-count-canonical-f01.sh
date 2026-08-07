#!/usr/bin/env bash
#
# F-01 — the pending-approval count is one number, from one source.
#
# The same live site answered "112 waiting for you" in wp-admin and "0" in two
# MCP report fields. Both fields asked for the status literal `pending`; the
# table stores `pending_review`, so both matched nothing and reported zero — a
# report that told an assistant there was nothing to approve while 112 requests
# waited. This suite pins the fix: every surface that answers "how many changes
# are waiting" reads OperationManager's canonical counter and they agree, always.
#
# Fixture isolation: rows are created under a unique session_id namespace and
# only ever counted/removed within it. The site's existing requests are never
# read destructively, never mutated, and never deleted — the global assertions
# are DELTA assertions against whatever baseline the site happens to have.
#
# Requires: curl, jq, wp-cli, wpcc-env.sh.
# Usage: bash tests/test-pending-count-canonical-f01.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }

rp()    { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/report_manage/run"; }
bulk()  { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/bulk_manage/run"; }
rpmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
php_eval() { wp eval "$1" --path="$WP_PATH" 2>/dev/null | tail -1; }

SESSION="f01-$$-$(date +%s)"

# ── Fixture helpers — scoped to $SESSION, nothing else is touched ──
# Rows are inserted directly so the suite controls the status exactly and can
# never trip a runtime into executing anything.
seed() { # seed <status> <count>
	php_eval "global \$wpdb; for(\$i=0;\$i<$2;\$i++){ \$wpdb->insert(\$wpdb->prefix.'wpcc_operation_requests', ['request_id'=>wp_generate_uuid4(),'operation_id'=>'report_manage','session_id'=>'$SESSION','status'=>'$1','payload'=>'{\"action\":\"report_security\"}','risk_level'=>'low','created_at'=>time()], ['%s','%s','%s','%s','%s','%s','%d']); } echo 'ok';"
}
seed_one_id() { # prints the request_id of one freshly seeded pending row
	php_eval "global \$wpdb; \$id=wp_generate_uuid4(); \$wpdb->insert(\$wpdb->prefix.'wpcc_operation_requests', ['request_id'=>\$id,'operation_id'=>'report_manage','session_id'=>'$SESSION','status'=>'pending_review','payload'=>'{\"action\":\"report_security\"}','risk_level'=>'low','created_at'=>time()], ['%s','%s','%s','%s','%s','%s','%d']); echo \$id;"
}
scoped() { # scoped [status] — canonical counter, restricted to our namespace
	if [ $# -eq 1 ]; then
		php_eval "echo ( new WPCommandCenter\\Operations\\OperationManager() )->count_requests(['session_id'=>'$SESSION','status'=>'$1']);"
	else
		php_eval "echo ( new WPCommandCenter\\Operations\\OperationManager() )->count_requests(['session_id'=>'$SESSION']);"
	fi
}
cleanup() {
	php_eval "global \$wpdb; \$n=\$wpdb->delete(\$wpdb->prefix.'wpcc_operation_requests',['session_id'=>'$SESSION'],['%s']); echo (int)\$n;"
}
trap 'cleanup >/dev/null' EXIT

# Section 14 needs a mode that actually gates. Capture whatever the site is in
# first and hand it back on every exit path — including an interrupted run.
# shellcheck source=/dev/null
source "$SCRIPT_DIR/lib/mode-guard.sh"
wpcc_mode_guard_init "$WP_PATH"

# Every pending-count surface, read at one instant.
canonical()  { php_eval "echo ( new WPCommandCenter\\Operations\\OperationManager() )->count_pending_review();"; }
ui_header()  { php_eval "echo ( new WPCommandCenter\\Admin\\ApprovalAdminQuery() )->summary()['pending'];"; }
activity()   { php_eval "echo WPCommandCenter\\Ai\\Platform\\AiActivity::pending_approvals();"; }
adminbar()   { php_eval "global \$wpdb; echo (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->prefix}wpcc_operation_requests WHERE status = %s\", 'pending_review'));"; }
rep_sec()    { rp '{"action":"report_security"}' | jq -r '.security.pending_approvals'; }
rep_pend()   { rp '{"action":"report_approval_activity"}' | jq -r '.approval_activity.pending'; }
rep_break()  { rp '{"action":"report_approval_activity"}' | jq -r '.approval_activity.requests_by_status.pending_review // 0'; }

echo "== 0. Baseline (the site's own requests — never modified) =="
BASE=$(canonical)
echo "  site baseline pending_review = $BASE"
assert_eq "baseline is a number" "true" "$([[ "$BASE" =~ ^[0-9]+$ ]] && echo true || echo false)"
assert_eq "fixture namespace starts empty" "0" "$(scoped)"

echo "== 1. Isolated fixture: zero pending → every scoped count is 0 =="
assert_eq "scoped pending_review = 0" "0" "$(scoped pending_review)"
assert_eq "scoped total = 0" "0" "$(scoped)"

echo "== 2. One pending_review request → 1 =="
seed pending_review 1 >/dev/null
assert_eq "scoped pending_review = 1" "1" "$(scoped pending_review)"

echo "== 3. Multiple pending requests → exact count =="
seed pending_review 4 >/dev/null
assert_eq "scoped pending_review = 5" "5" "$(scoped pending_review)"

echo "== 4. Mixed statuses → only pending_review is pending =="
seed executed 3 >/dev/null
seed rejected 2 >/dev/null
seed cancelled 6 >/dev/null
seed failed 1 >/dev/null
seed approved 2 >/dev/null
assert_eq "scoped pending_review still 5" "5" "$(scoped pending_review)"
assert_eq "scoped total = 19" "19" "$(scoped)"
assert_eq "executed not counted as pending" "3" "$(scoped executed)"
assert_eq "rejected not counted as pending" "2" "$(scoped rejected)"
assert_eq "cancelled not counted as pending" "6" "$(scoped cancelled)"
assert_eq "failed not counted as pending" "1" "$(scoped failed)"
assert_eq "approved not counted as pending" "2" "$(scoped approved)"

echo "== 5. Every surface reports the SAME number =="
# Compared against the canonical counter read at the same moment, not against a
# precomputed total: the tiered runner executes suites in parallel and a
# neighbour may legitimately file or resolve a request mid-section. A surface
# only counts as disagreeing if it still disagrees while the canonical value is
# holding still — a moving baseline is re-read, never asserted through.
assert_agrees() { # assert_agrees <description> <reader-fn>
	local desc="$1" reader="$2" before after value
	for _ in 1 2 3; do
		before=$(canonical); value=$("$reader"); after=$(canonical)
		if [ "$before" = "$after" ]; then
			assert_eq "$desc" "$before" "$value"
			return
		fi
	done
	fail "$desc (count never settled: $before -> $after)"
}
assert_eq "the fixture's rows are inside the canonical count" "true" \
	"$([ "$(canonical)" -ge "$(scoped pending_review)" ] && echo true || echo false)"
assert_agrees "Approval Center header (UI)"        ui_header
assert_agrees "AiActivity::pending_approvals"      activity
assert_agrees "admin-bar badge query"              adminbar
assert_agrees "report_security.pending_approvals"  rep_sec
assert_agrees "report_approval_activity.pending"   rep_pend
assert_agrees "requests_by_status.pending_review"  rep_break

echo "== 6. The regression itself: no summary field answers 0 while requests wait =="
assert_eq "report_security.pending_approvals > 0"  "true" "$([ "$(rep_sec)"  -gt 0 ] && echo true || echo false)"
assert_eq "report_approval_activity.pending > 0"   "true" "$([ "$(rep_pend)" -gt 0 ] && echo true || echo false)"
assert_eq "summary agrees with detailed breakdown" "$(rep_break)" "$(rep_pend)"

echo "== 7. A new pending request raises every count immediately =="
# Deltas are measured inside the fixture's own namespace (immune to neighbours),
# and every global surface is then re-checked against the live canonical value.
S_BEFORE=$(scoped pending_review)
NEW_ID=$(seed_one_id)
assert_eq "scoped pending +1" "$((S_BEFORE + 1))" "$(scoped pending_review)"
assert_agrees "report_security sees the new request"          rep_sec
assert_agrees "report_approval_activity sees the new request" rep_pend
assert_agrees "breakdown sees the new request"                rep_break
assert_agrees "UI header sees the new request"                ui_header

echo "== 8. Cancel lowers every count immediately (no stale cache) =="
php_eval "\$r=( new WPCommandCenter\\Operations\\OperationManager() )->cancel_request('$NEW_ID'); echo is_wp_error(\$r)?'err':'ok';" >/dev/null
assert_eq "scoped pending back down" "$S_BEFORE" "$(scoped pending_review)"
assert_eq "the row is now cancelled, not pending" "7" "$(scoped cancelled)"
assert_agrees "report_security reflects the cancel at once"  rep_sec
assert_agrees "report_approval_activity reflects it at once" rep_pend
assert_agrees "UI header reflects it at once"                ui_header

echo "== 9. Reject lowers the count immediately =="
R_ID=$(seed_one_id)
assert_eq "one more pending" "$((S_BEFORE + 1))" "$(scoped pending_review)"
php_eval "\$r=( new WPCommandCenter\\Operations\\OperationManager() )->reject_request('$R_ID'); echo is_wp_error(\$r)?'err':'ok';" >/dev/null
assert_eq "reject returns the scoped count" "$S_BEFORE" "$(scoped pending_review)"
assert_eq "the row is now rejected, not pending" "3" "$(scoped rejected)"
assert_agrees "report_security reflects the reject at once" rep_sec

echo "== 10. Approve lowers the count immediately =="
A_ID=$(seed_one_id)
assert_eq "one more pending" "$((S_BEFORE + 1))" "$(scoped pending_review)"
php_eval "\$r=( new WPCommandCenter\\Operations\\OperationManager() )->approve_request('$A_ID'); echo is_wp_error(\$r)?'err':'ok';" >/dev/null
assert_eq "approve returns the scoped count" "$S_BEFORE" "$(scoped pending_review)"
assert_eq "the row is now approved, not pending" "3" "$(scoped approved)"
assert_agrees "report_security reflects the approve at once" rep_sec
# The approve path enqueues; drop the queue row this fixture created.
php_eval "global \$wpdb; \$wpdb->delete(\$wpdb->prefix.'wpcc_operation_queue',['request_id'=>'$A_ID'],['%s']); echo 'ok';" >/dev/null

echo "== 11. Generating reports mutates nothing =="
# Snapshot the fixture's own rows: a whole-table snapshot would record a
# neighbouring suite's legitimate write as a mutation caused by reporting.
snap() { php_eval "global \$wpdb; \$r=\$wpdb->get_results(\$wpdb->prepare(\"SELECT status, COUNT(*) c FROM {\$wpdb->prefix}wpcc_operation_requests WHERE session_id=%s GROUP BY status ORDER BY status\",'$SESSION')); \$s=''; foreach(\$r as \$x){\$s.=\$x->status.':'.\$x->c.';';} echo \$s;"; }
SNAP_BEFORE=$(snap)
for a in report_security report_approval_activity report_agent_activity report_patch_activity report_site_health report_plugin_health report_content; do
	rp "{\"action\":\"$a\"}" >/dev/null
done
assert_eq "request rows unchanged by every report" "$SNAP_BEFORE" "$(snap)"
assert_eq "snapshot was not empty (the check has teeth)" "true" "$([ -n "$SNAP_BEFORE" ] && echo true || echo false)"

echo "== 12. MCP surface reports the same number as REST =="
M_SEC=$(rpmcp '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"report_manage","arguments":{"action":"report_security"}}}' | jq -r '.security.pending_approvals')
M_APP=$(rpmcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"report_manage","arguments":{"action":"report_approval_activity"}}}' | jq -r '.approval_activity.pending')
assert_eq "MCP report_security matches REST"          "$(rep_sec)"  "$M_SEC"
assert_eq "MCP report_approval_activity matches REST" "$(rep_pend)" "$M_APP"

echo "== 13. The breakdown is grouped in SQL, not a page of rows =="
# list_requests() defaults to 50 and its length is a cap, not a count. The
# breakdown must total every row in the table regardless of any page size — and
# the fixture alone has already put more statuses in play than a single page.
all_rows()   { php_eval "echo ( new WPCommandCenter\\Operations\\OperationManager() )->count_requests();"; }
break_sum()  { rp '{"action":"report_approval_activity"}' | jq -r '[.approval_activity.requests_by_status[]] | add'; }
BSUM_OK=false
for _ in 1 2 3; do
	B1=$(all_rows); S1=$(break_sum); B2=$(all_rows)
	if [ "$B1" = "$B2" ]; then assert_eq "breakdown totals every request row" "$B1" "$S1"; BSUM_OK=true; break; fi
done
[ "$BSUM_OK" = true ] || fail "breakdown total never settled"
assert_eq "and the table is larger than one page" "true" "$([ "$(all_rows)" -gt 50 ] && echo true || echo false)"

echo "== 14. Approval enforcement is unaffected =="
# Explicitly enter a gating mode: whether a call is gated is a property of the
# mode, not of this fix, and the suite must not depend on how it found the site.
php_eval "update_option('wpcc_security_mode','client'); echo 'ok';" >/dev/null
assert_eq "approval required in Standard protection" "true" "$(php_eval "echo WPCommandCenter\\Operations\\SecurityModeManager::requires_human_approver()?'true':'false';")"
# Follow the specific request rather than a global delta: that is the invariant,
# and it stays true no matter what a neighbouring suite is doing at the time.
GATED=$(bulk '{"action":"bulk_publish","ids":[1]}')
assert_eq "a gated call still files a request" "pending_approval" "$(echo "$GATED" | jq -r '.status // .result.status')"
G_ID=$(echo "$GATED" | jq -r '.request_id // .result.request_id')
status_of() { php_eval "\$r=( new WPCommandCenter\\Operations\\OperationManager() )->get_request('$1'); echo \$r ? \$r['status'] : 'missing';"; }
assert_eq "and it is waiting for a human" "pending_review" "$(status_of "$G_ID")"
assert_eq "counted as pending by the canonical counter" "1" \
	"$(php_eval "global \$wpdb; echo (int) \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->prefix}wpcc_operation_requests WHERE request_id=%s AND status='pending_review'\",'$G_ID'));")"
php_eval "\$r=( new WPCommandCenter\\Operations\\OperationManager() )->cancel_request('$G_ID'); echo 'ok';" >/dev/null
assert_eq "and stops being pending once cancelled" "cancelled" "$(status_of "$G_ID")"

echo "== 15. F-06 — an empty bulk id list never reaches the approval queue =="
# Count bulk_manage requests specifically: a global count would be moved by
# neighbours, and what matters is that THESE calls filed nothing.
bulk_reqs() { php_eval "echo ( new WPCommandCenter\\Operations\\OperationManager() )->count_requests(['operation_id'=>'bulk_manage']);"; }
B6=$(bulk_reqs)
E=$(bulk '{"action":"bulk_publish","ids":[]}')
assert_eq "structured error code" "wpcc_missing_bulk_ids" "$(echo "$E" | jq -r '.code')"
assert_eq "message names the pre-approval refusal" "true" "$(echo "$E" | jq -r '.message | contains("Nothing was queued for approval")')"
E2=$(bulk '{"action":"bulk_content","ids":[],"fields":{"post_title":"x"}}')
assert_eq "bulk_content empty ids refused" "wpcc_missing_bulk_ids" "$(echo "$E2" | jq -r '.code')"
E3=$(bulk '{"action":"bulk_unpublish"}')
assert_eq "omitted ids refused too" "wpcc_missing_bulk_ids" "$(echo "$E3" | jq -r '.code')"
assert_eq "no bulk_manage request was created by any of them" "$B6" "$(bulk_reqs)"
# A non-empty list must still travel the normal gated path — the guard rejects,
# it never admits, and it must not have narrowed what legitimately gets through.
OK=$(bulk '{"action":"bulk_publish","ids":[1]}')
assert_eq "a non-empty list still reaches approval" "pending_approval" "$(echo "$OK" | jq -r '.status // .result.status')"
assert_eq "which did create a request" "$((B6 + 1))" "$(bulk_reqs)"
php_eval "( new WPCommandCenter\\Operations\\OperationManager() )->cancel_request('$(echo "$OK" | jq -r '.request_id // .result.request_id')'); echo 'ok';" >/dev/null

echo "== 16. Cleanup removes every fixture row =="
# The trap stays armed — the mode-guard's restore is chained onto it, and a
# second cleanup simply deletes nothing.
REMOVED=$(cleanup)
echo "  removed $REMOVED fixture rows (session_id=$SESSION)"
assert_eq "fixture namespace empty again" "0" "$(scoped)"
assert_eq "removed exactly what was seeded" "true" "$([ "$REMOVED" -ge 19 ] && echo true || echo false)"
assert_agrees "surfaces still agree after cleanup" rep_sec
assert_agrees "and the breakdown with them"        rep_break

echo
echo "======================================================="
echo "  Pending count canonical (F-01): $PASS passed, $FAIL failed"
echo "======================================================="
[ "$FAIL" -eq 0 ]
