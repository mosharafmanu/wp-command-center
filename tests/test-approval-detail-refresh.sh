#!/usr/bin/env bash
# Finding B — the approval detail screen must show the decision it just recorded.
#
# Approve or Reject on ?view=<request_id> posted the decision, the engine applied
# it correctly, and the page went on saying "pending_review · WAITING FOR YOU"
# with both buttons live and the pre-decision audit trail — until the customer
# reloaded by hand. The admin-bar counter stayed stale as well, because
# loadSummary() returned early whenever #wpcc-approval-summary was absent, and
# that element is absent on exactly one screen: this one.
#
# The fix re-reads the request from the server after a recorded decision and
# re-renders from that response. So there are two things to prove:
#
#   A. The server-side record the refresh re-reads is already authoritative the
#      instant the decision returns — status, resolver, timestamps, audit trail,
#      execution result and the pending count. If it were not, no client change
#      could fix this. (Sections 1–4, behavioural.)
#   B. The screen is actually wired to that re-read, on BOTH decisions, and only
#      after a decision that succeeded. (Section 5, wiring.)
#
# Nothing here changes approval authorization: every decision below goes through
# AdminRestApi::handle_action(), the same entry point the buttons post to.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
VIEW="$SCRIPT_DIR/../includes/Admin/views/approval-center.php"
MENU="$SCRIPT_DIR/../includes/Admin/AdminMenu.php"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
has()  { local d="$1" p="$2" f="$3"; if grep -qE "$p" "$f"; then pass "$d"; else fail "$d"; fi; }

echo "Approval detail refresh after a decision (Finding B) — $(date)"
echo ""

# ── The decision round-trip, run through the admin write surface ──────────────
# Standard protection for the duration so a decision is a real decision; the
# operator's own mode is restored at the end whatever happens.
RESULT=$(wp eval '
global $wpdb;
rest_get_server();
wp_set_current_user( 1 );

$RT       = $wpdb->prefix . "wpcc_operation_requests";
$savemode = get_option( "wpcc_security_mode", "" );
update_option( "wpcc_security_mode", "client" );

$api   = new \WPCommandCenter\Admin\AdminRestApi();
$query = new \WPCommandCenter\Admin\ApprovalAdminQuery();
$mgr   = new \WPCommandCenter\Operations\OperationManager();
$made  = [];
$tagline_before = get_option( "blogdescription" );

// A pending request, created exactly as an assistant creates one: a real
// visitor-affecting write, so approving it actually executes and the screen has
// a genuine executed state to re-read.
$mk = function ( string $value ) use ( $mgr, &$made ) {
	$r = $mgr->create_request(
		"settings_manage",
		[ "action" => "settings_general_update", "tagline" => $value, "reason" => "finding B" ],
		[ "actor" => [ "type" => "mcp", "token_id" => "f-b-probe" ] ]
	);
	$id = is_array( $r ) ? $r["request_id"] : "";
	if ( $id ) { $made[] = $id; }
	return $id;
};
$act = function ( string $id, string $action ) use ( $api ) {
	$req = new WP_REST_Request( "POST", "/x" );
	$req->set_param( "id", $id );
	return $api->handle_action( $req, $action )->get_data();
};
// Exactly what loadDetail() re-reads, reduced to the facts the screen prints.
$snap = function ( string $id ) use ( $query ) {
	$d = $query->detail( $id );
	if ( null === $d ) { return null; }
	$r = $d["request"];
	return [
		"status"      => $r["status"] ?? null,
		"resolved_by" => $r["resolved_by"] ?? null,
		"approved_at" => ! empty( $r["approved_at"] ),
		"rejected_at" => ! empty( $r["rejected_at"] ),
		"executed_at" => ! empty( $r["executed_at"] ),
		"audit"       => array_values( array_map( static fn( $a ) => $a["action"], $d["audit"] ?? [] ) ),
		"actors"      => array_values( array_unique( array_map( static fn( $a ) => (string) ( $a["actor"] ?? "" ), $d["audit"] ?? [] ) ) ),
		"results"     => count( $d["results"] ?? [] ),
	];
};

$out = [];

// ── 1. Reject ────────────────────────────────────────────────────────────────
$rej = $mk( "F-B rejected probe" );
$out["reject_before"] = $snap( $rej );
$out["reject_resp"]   = (bool) ( $act( $rej, "reject" )["success"] ?? false );
$out["reject_after"]  = $snap( $rej );
// Rejecting must leave the site alone — the screen reporting "rejected" and the
// site having changed anyway would be the same contradiction from the other side.
$out["reject_untouched"] = get_option( "blogdescription" ) === $tagline_before;

// ── 2. Approve → executed ────────────────────────────────────────────────────
$app = $mk( "F-B executed probe" );
$out["approve_before"] = $snap( $app );
$ar = $act( $app, "approve" );
$out["approve_resp"]   = (bool) ( $ar["success"] ?? false );
$out["approve_after"]  = $snap( $app );
$out["approve_applied"] = get_option( "blogdescription" ) === "F-B executed probe";

// ── 3. A decision that cannot be recorded must change nothing ────────────────
// Re-deciding an already-resolved request: the response reports failure and the
// record is untouched, so a UI that refreshes only on success shows the truth.
$out["redecide_resp"]  = (bool) ( $act( $rej, "approve" )["success"] ?? false );
$out["redecide_after"] = $snap( $rej );
// And an id that does not exist at all.
$out["unknown_resp"]   = (bool) ( $act( "00000000-0000-0000-0000-000000000000", "reject" )["success"] ?? false );
$out["unknown_detail"] = null === $query->detail( "00000000-0000-0000-0000-000000000000" );

// ── 4. The pending count the badges read ─────────────────────────────────────
$open = $mk( "F-B counted probe" );
$out["pending_with_open"] = (int) $query->summary()["pending"];
$act( $open, "reject" );
$out["pending_after"]     = (int) $query->summary()["pending"];

foreach ( $made as $id ) {
	$wpdb->delete( $RT, [ "request_id" => $id ] );
	$wpdb->delete( $wpdb->prefix . "wpcc_operation_queue", [ "request_id" => $id ] );
}
update_option( "blogdescription", $tagline_before );
update_option( "wpcc_security_mode", "" !== $savemode ? $savemode : "client" );
$out["tagline_restored"] = get_option( "blogdescription" ) === $tagline_before;

echo wp_json_encode( $out );
' --path="$WP_PATH" 2>/dev/null)

j() { echo "$RESULT" | jq -r "$1"; }

echo "== 1. Reject — the record the page re-reads is already correct =="
assert_eq "starts pending_review"                "pending_review" "$(j '.reject_before.status')"
assert_eq "decision reported success"            "true"           "$(j '.reject_resp')"
assert_eq "status is rejected, not pending"      "rejected"       "$(j '.reject_after.status')"
assert_eq "rejected_at stamped"                  "true"           "$(j '.reject_after.rejected_at')"
assert_eq "resolver attributed"                  "true"           "$(j '.reject_after.resolved_by != null and .reject_after.resolved_by != ""')"
assert_eq "audit trail gained the rejection"     "true"           "$(j '(.reject_after.audit | index("admin.approval.rejected")) != null')"
assert_eq "audit grew — the old trail is stale"  "true"           "$(j '(.reject_after.audit|length) > (.reject_before.audit|length)')"
assert_eq "not executed"                         "false"          "$(j '.reject_after.executed_at')"
assert_eq "and the site was not changed"         "true"           "$(j '.reject_untouched')"

echo ""
echo "== 2. Approve — status, timestamps and execution result all move =="
assert_eq "starts pending_review"                "pending_review" "$(j '.approve_before.status')"
assert_eq "decision reported success"            "true"           "$(j '.approve_resp')"
assert_eq "no longer pending"                    "true"           "$(j '.approve_after.status != "pending_review"')"
assert_eq "settled as executed"                  "executed"       "$(j '.approve_after.status')"
assert_eq "approved_at stamped"                  "true"           "$(j '.approve_after.approved_at')"
assert_eq "executed_at stamped"                  "true"           "$(j '.approve_after.executed_at')"
assert_eq "resolver attributed"                  "true"           "$(j '.approve_after.resolved_by != null and .approve_after.resolved_by != ""')"
assert_eq "audit trail gained the approval"      "true"           "$(j '(.approve_after.audit | index("admin.approval.approved")) != null')"
assert_eq "audit trail gained the execution"     "true"           "$(j '(.approve_after.audit | index("operation.execution.started")) != null')"
assert_eq "an execution result now exists"       "true"           "$(j '.approve_after.results > 0')"
assert_eq "the change actually reached the site" "true"           "$(j '.approve_applied')"
# The request was raised by an assistant and decided by a person. Both must
# survive in the trail the refreshed screen prints.
assert_eq "assistant and human both attributed"  "true"           "$(j '(.approve_after.actors|length) > 1')"

echo ""
echo "== 3. A failed decision leaves the record alone =="
assert_eq "re-deciding a resolved request fails" "false"    "$(j '.redecide_resp')"
assert_eq "and it is still rejected"             "rejected" "$(j '.redecide_after.status')"
assert_eq "unknown request id fails"             "false"    "$(j '.unknown_resp')"
assert_eq "unknown request id has no detail"     "true"     "$(j '.unknown_detail')"

echo ""
echo "== 4. The pending count the badges read drops on decision =="
assert_eq "count falls by exactly one" "true" "$(j '.pending_with_open - .pending_after == 1')"

echo ""
echo "== 5. The screen is wired to that re-read — both decisions, success only =="
has "single authoritative refresh helper"      "function refreshAfterDecision\( notice \)" "$VIEW"
has "it re-reads the request from the server"  "loadDetail\( detailId, notice \)"          "$VIEW"
# loadSummary() must be the FIRST thing the helper does, so the counts move even
# when there is no detail view to repaint (the list screen).
assert_true "it re-reads the counts too" \
	"$(awk '/function refreshAfterDecision/{f=1} f && /loadSummary\(\)/{print "yes"; exit} f && /^\t}/{exit}' "$VIEW" | grep -q yes && echo true || echo false)"
has "approve routes through it"                "refreshAfterDecision\( \{"                 "$VIEW"
has "reject routes through it too"             "refreshAfterDecision\( \{ msg: i18n.rejected" "$VIEW"
has "loadDetail carries the confirmation"      "function loadDetail\( id, notice \)"       "$VIEW"

# The old reject path patched nothing but the summary and left the screen live.
# If this string comes back, so has the bug.
if grep -qE "showResult\( result, i18n.rejected, 'success' \);" "$VIEW" && \
   ! grep -qE "refreshAfterDecision\( \{ msg: i18n.rejected" "$VIEW"; then
	fail "reject still ends at showResult() with no refresh"
else
	pass "reject no longer ends at showResult() with no refresh"
fi

# Only a recorded decision refreshes. A 403, a validation error or a thrown
# request must leave the controls in place so the customer can try again.
REFRESH_CALLS=$(grep -c "refreshAfterDecision(" "$VIEW")
assert_eq "refresh is called from exactly the two decision paths" "2" \
	"$(( REFRESH_CALLS - 1 ))"   # minus its own definition
assert_true "no refresh in the error branches" \
	"$(awk '/showResult\( ctx.result, actionError|showResult\( result, actionError|showResult\( ctx.result, i18n.reqFailed|showResult\( result, i18n.reqFailed/{c=6} c>0 && /refreshAfterDecision/{print "found"} {if(c>0)c--}' "$VIEW" | grep -q found && echo false || echo true)"

echo ""
echo "== 6. The admin-bar counter can be corrected without a reload =="
has "toolbar count span is addressable"        "id=\"wpcc-adminbar-pending-count\"" "$MENU"
has "updateBadge writes the toolbar count"     "wpcc-adminbar-pending-count"        "$VIEW"
has "it hides the node when nothing is left"   "wp-admin-bar-wpcc-pending-approvals" "$VIEW"
# The regression itself: the early return that skipped the badges whenever the
# summary-chip container was missing, which is the case on every detail screen.
assert_true "badge update no longer waits on the summary chip container" \
	"$(awk '/function loadSummary/{f=1} f && /updateBadge\( s.pending \)/{print "badge"; exit} f && /if \( ! el \)/{print "elcheck"; exit}' "$VIEW" | grep -q badge && echo true || echo false)"

echo ""
echo "==================================="
echo "  PASSED: $PASS"
echo "  FAILED: $FAIL"
echo "==================================="
[ "$FAIL" -eq 0 ]
