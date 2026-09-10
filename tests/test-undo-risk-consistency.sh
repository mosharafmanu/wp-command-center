#!/usr/bin/env bash
# Finding C — an undo request must report one risk level everywhere.
#
# `change_history` is a read operation: its operation-wide tier is 'diagnostic'.
# Its single write, `rollback_target`, declares 'high' in action_risks. The
# approval gate resolved 'high' and correctly demanded human approval — and then
# OperationManager::create_request() stored the operation-wide 'diagnostic' on
# the row it created. So:
#
#   MCP  approval_manage request_get  ->  "risk_level": "diagnostic"
#   UI   Approvals detail             ->  HIGH RISK
#
# for one and the same request, because the screen recomputes from the registry
# through SecurityModeManager::effective_risk() and MCP read the column back.
#
# The fix makes create_request() resolve through the same helper, and normalises
# the value again on read so rows written before the fix stop lying too. What is
# asserted here:
#
#   1. A HIGH-risk undo stores 'high' and reports 'high' on every surface.
#   2. Rows that already hold the wrong value read as 'high' anyway.
#   3. An unknown operation or an unrecognised value is never read as safe.
#   4. Nothing about approval changed: undo still creates its own request, still
#      waits for a human, and still cannot be self-approved by the token that
#      asked for it.
#
# Risk labels are display-only. Approval gating is computed from the registry at
# the chokepoint and never from this column — section 4 is what proves it.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
VIEW="$SCRIPT_DIR/../includes/Admin/views/approval-center.php"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }
has()  { local d="$1" p="$2" f="$3"; if grep -qE "$p" "$f"; then pass "$d"; else fail "$d"; fi; }
mcp()  { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp"; }
mcpj() { mcp "$1" | jq -r '.result.content[0].text' 2>/dev/null; }

# This suite proves Standard/client approval semantics even when a wider runner
# intentionally establishes a developer-mode baseline for unrelated tests.
# Preserve the option byte-for-byte and restore it on every exit path.
WPCC_UNDO_MODE_SNAPSHOT=$(wp eval '
$name="wpcc_security_mode";
echo base64_encode( serialize( [ "exists" => false !== get_option( $name, false ), "value" => get_option( $name, null ) ] ) );
' --path="$WP_PATH" 2>/dev/null)
export WPCC_UNDO_MODE_SNAPSHOT
restore_mode() {
	wp eval '
	$s=unserialize(base64_decode((string)getenv("WPCC_UNDO_MODE_SNAPSHOT")),["allowed_classes"=>false]);
	if(!empty($s["exists"])){update_option("wpcc_security_mode",$s["value"]);}else{delete_option("wpcc_security_mode");}
	' --path="$WP_PATH" >/dev/null 2>&1
}
trap restore_mode EXIT
wp option update wpcc_security_mode client --path="$WP_PATH" >/dev/null

echo "Undo approval risk consistency (Finding C) — $(date)"
echo ""

# ── The registry facts this finding turns on ─────────────────────────────────
echo "== 1. The two risks that disagreed =="
REG=$(wp eval '
use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\SecurityModeManager;
$op = ( new OperationRegistry() )->get_operation( "change_history" );
echo wp_json_encode( [
	"operation_tier" => $op["risk_level"] ?? null,
	"undo_action"    => SecurityModeManager::effective_risk( $op, "rollback_target" ),
	"a_read_action"  => SecurityModeManager::effective_risk( $op, "history_list" ),
	// The gate the chokepoint applies, at the risk the undo actually carries.
	"undo_gated"     => SecurityModeManager::requires_approval( SecurityModeManager::effective_risk( $op, "rollback_target" ) ),
	"mode"           => SecurityModeManager::current(),
] );
' --path="$WP_PATH" 2>/dev/null)

assert_eq "change_history's operation tier is diagnostic" "diagnostic" "$(echo "$REG" | jq -r '.operation_tier')"
assert_eq "its undo action declares high"                 "high"       "$(echo "$REG" | jq -r '.undo_action')"
assert_eq "its read actions stay diagnostic"              "diagnostic" "$(echo "$REG" | jq -r '.a_read_action')"

# ── The request record, end to end ───────────────────────────────────────────
echo ""
echo "== 2. A HIGH-risk undo records HIGH, on every surface =="
RECORD=$(wp eval '
global $wpdb;
use WPCommandCenter\Operations\OperationManager;
use WPCommandCenter\Admin\ApprovalAdminQuery;

$save = get_option( "wpcc_security_mode", "" );
update_option( "wpcc_security_mode", "client" );

$mgr   = new OperationManager();
$query = new ApprovalAdminQuery();
$RT    = $wpdb->prefix . "wpcc_operation_requests";

// The request an assistant creates when asked to undo a HIGH-risk change.
$r  = $mgr->create_request(
	"change_history",
	[ "action" => "rollback_target", "change_id" => wp_generate_uuid4(), "reason" => "finding C" ],
	[ "actor" => [ "type" => "mcp", "token_id" => "f-c-probe" ] ]
);
$id = $r["request_id"];

$out = [
	// What the row physically holds.
	"stored"  => $wpdb->get_var( $wpdb->prepare( "SELECT risk_level FROM $RT WHERE request_id = %s", $id ) ),
	// What the domain layer hands back — the shape MCP serialises.
	"domain"  => $mgr->get_request( $id )["risk_level"],
	// What the Approvals screen renders from.
	"ui"      => $query->detail( $id )["request"]["risk_level"],
	// And through the list, which is a separate query path.
	"listed"  => ( $mgr->list_requests( [ "operation_id" => "change_history", "limit" => 1 ] )[0]["risk_level"] ?? null ),
	"status"  => $mgr->get_request( $id )["status"],
];

// A read action on the same operation must NOT be dragged up to high.
$read = $mgr->create_request(
	"change_history",
	[ "action" => "history_list" ],
	[ "actor" => [ "type" => "mcp", "token_id" => "f-c-probe" ] ]
);
$out["read_action_risk"] = $mgr->get_request( $read["request_id"] )["risk_level"];

// ── Legacy: a row written before the fix, still holding the wrong value ──
$legacy = wp_generate_uuid4();
$wpdb->insert( $RT, [
	"request_id"   => $legacy,
	"operation_id" => "change_history",
	"status"       => "pending_review",
	"payload"      => wp_json_encode( [ "action" => "rollback_target", "change_id" => "x" ] ),
	"risk_level"   => "diagnostic",
	"created_at"   => time(),
] );
$out["legacy_stored"] = $wpdb->get_var( $wpdb->prepare( "SELECT risk_level FROM $RT WHERE request_id = %s", $legacy ) );
$out["legacy_domain"] = $mgr->get_request( $legacy )["risk_level"];
$out["legacy_ui"]     = $query->detail( $legacy )["request"]["risk_level"];

// ── Unexpected values must never read as safe ──
$junk = wp_generate_uuid4();
$wpdb->insert( $RT, [
	"request_id"   => $junk,
	"operation_id" => "an_operation_that_no_longer_exists",
	"status"       => "pending_review",
	"payload"      => wp_json_encode( [ "action" => "whatever" ] ),
	"risk_level"   => "banana",
	"created_at"   => time(),
] );
$out["unknown_op_junk_risk"] = $mgr->get_request( $junk )["risk_level"];

// An unregistered operation that DID record a real risk keeps it.
$gone = wp_generate_uuid4();
$wpdb->insert( $RT, [
	"request_id"   => $gone,
	"operation_id" => "an_operation_that_no_longer_exists",
	"status"       => "pending_review",
	"payload"      => wp_json_encode( [ "action" => "whatever" ] ),
	"risk_level"   => "low",
	"created_at"   => time(),
] );
$out["unknown_op_kept_risk"] = $mgr->get_request( $gone )["risk_level"];

foreach ( [ $id, $read["request_id"], $legacy, $junk, $gone ] as $rid ) {
	$wpdb->delete( $RT, [ "request_id" => $rid ] );
	$wpdb->delete( $wpdb->prefix . "wpcc_operation_queue", [ "request_id" => $rid ] );
}
update_option( "wpcc_security_mode", "" !== $save ? $save : "client" );

echo wp_json_encode( $out );
' --path="$WP_PATH" 2>/dev/null)

k() { echo "$RECORD" | jq -r "$1"; }

assert_eq "the stored column says high"                  "high" "$(k '.stored')"
assert_eq "the domain layer says high"                   "high" "$(k '.domain')"
assert_eq "the Approvals screen says high"               "high" "$(k '.ui')"
assert_eq "the request list says high"                   "high" "$(k '.listed')"
assert_eq "all four agree"                               "true" "$(k '[.stored,.domain,.ui,.listed] | unique | length == 1')"
assert_eq "a read action on the same operation stays diagnostic" "diagnostic" "$(k '.read_action_risk')"
assert_eq "the undo still starts pending_review"         "pending_review" "$(k '.status')"

echo ""
echo "== 3. Legacy and unexpected values =="
assert_eq "a pre-fix row still physically holds diagnostic" "diagnostic" "$(k '.legacy_stored')"
assert_eq "but it is read as high"                          "high"       "$(k '.legacy_domain')"
assert_eq "and the screen shows high for it too"            "high"       "$(k '.legacy_ui')"
assert_eq "legacy row now agrees with the UI"               "true"       "$(k '.legacy_domain == .legacy_ui')"
assert_eq "an unrecognised value reads as high, not safe"   "high"       "$(k '.unknown_op_junk_risk')"
assert_eq "a de-registered operation keeps its real risk"   "low"        "$(k '.unknown_op_kept_risk')"

echo ""
echo "== 4. Nothing about approval changed =="
# The whole point of the finding is a label. If fixing it had touched gating,
# this is where it would show.
assert_eq "an undo still requires approval in Standard protection" "true" "$(echo "$REG" | jq -r '.undo_gated')"

LIVE=$(mcpj '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"change_history","arguments":{"action":"history_list","reversible_only":true,"limit":1}},"id":1}')
CHANGE_ID=$(echo "$LIVE" | jq -r '.changes[0].change_id // ""')

if [ -z "$CHANGE_ID" ] || [ "$CHANGE_ID" = "null" ]; then
	echo "  SKIP: no reversible change on this site to undo (sections 5-6)"
else
	UNDO=$(mcpj "{\"jsonrpc\":\"2.0\",\"method\":\"tools/call\",\"params\":{\"name\":\"change_history\",\"arguments\":{\"action\":\"rollback_target\",\"change_id\":\"$CHANGE_ID\",\"reason\":\"Finding C regression probe\"}},\"id\":2}")
	UNDO_ID=$(echo "$UNDO" | jq -r '.request_id // ""')

	assert_eq "undo over MCP is answered with pending_approval" "pending_approval" "$(echo "$UNDO" | jq -r '.status')"
	assert_eq "the pending_approval response says high"         "high"             "$(echo "$UNDO" | jq -r '.risk_level')"
	assert_true "undo created its own separate approval request" \
		"$( [ -n "$UNDO_ID" ] && [ "$UNDO_ID" != "null" ] && echo true || echo false )"

	echo ""
	echo "== 5. MCP and the UI report the same risk for that live request =="
	GET=$(mcpj "{\"jsonrpc\":\"2.0\",\"method\":\"tools/call\",\"params\":{\"name\":\"approval_manage\",\"arguments\":{\"action\":\"request_get\",\"request_id\":\"$UNDO_ID\"}},\"id\":3}")
	MCP_RISK=$(echo "$GET" | jq -r '.request.risk_level')
	UI_RISK=$(wp eval "echo ( new \WPCommandCenter\Admin\ApprovalAdminQuery() )->detail( '$UNDO_ID' )['request']['risk_level'];" --path="$WP_PATH" 2>/dev/null)

	assert_eq "MCP request_get says high — the reported symptom" "high" "$MCP_RISK"
	assert_eq "the Approvals screen says high"                   "high" "$UI_RISK"
	assert_eq "MCP and UI agree"                                 "true" "$( [ "$MCP_RISK" = "$UI_RISK" ] && echo true || echo false )"

	echo ""
	echo "== 6. The undo cannot approve itself =="
	# The token that asked for the undo tries to approve it. Standard protection
	# requires a human; this must be refused and the request left untouched.
	SELF=$(mcpj "{\"jsonrpc\":\"2.0\",\"method\":\"tools/call\",\"params\":{\"name\":\"approval_manage\",\"arguments\":{\"action\":\"request_approve\",\"request_id\":\"$UNDO_ID\"}},\"id\":4}")
	assert_eq "an MCP token cannot approve the undo" "true" \
		"$(echo "$SELF" | jq -r 'if (.isError // false) or (.code // "") == "wpcc_approval_requires_human" then "true" else "false" end')"

	STATE=$(wp eval "
	\$r = ( new \WPCommandCenter\Operations\OperationManager() )->get_request( '$UNDO_ID' );
	echo wp_json_encode( [ 'status' => \$r['status'], 'risk' => \$r['risk_level'] ] );
	" --path="$WP_PATH" 2>/dev/null)
	assert_eq "the undo is still waiting for a human" "pending_review" "$(echo "$STATE" | jq -r '.status')"
	assert_eq "and still recorded as high"            "high"           "$(echo "$STATE" | jq -r '.risk')"

	# Leave the site as found: cancel the probe undo so nothing is applied.
	wp eval "
	global \$wpdb;
	( new \WPCommandCenter\Operations\OperationManager() )->cancel_request( '$UNDO_ID', [ 'actor' => [ 'type' => 'admin', 'wp_user_id' => 1, 'user_login' => 'admin' ] ] );
	\$wpdb->delete( \$wpdb->prefix . 'wpcc_operation_requests', [ 'request_id' => '$UNDO_ID' ] );
	\$wpdb->delete( \$wpdb->prefix . 'wpcc_operation_queue', [ 'request_id' => '$UNDO_ID' ] );
	" --path="$WP_PATH" >/dev/null 2>&1
fi

echo ""
echo "== 7. The UI label map is unchanged — the fix was to the value, not the words =="
has "high maps to High Risk"        "high:       <\?php echo wp_json_encode\( __\( 'High Risk'" "$VIEW"
has "diagnostic still has a label"  "diagnostic: i18n.readOnly"                                 "$VIEW"
has "the screen resolves risk from the registry" "SecurityModeManager::effective_risk" "$SCRIPT_DIR/../includes/Admin/ApprovalAdminQuery.php"
has "and so does request creation"  "SecurityModeManager::effective_risk" "$SCRIPT_DIR/../includes/Operations/OperationManager.php"

echo ""
echo "==================================="
echo "  PASSED: $PASS"
echo "  FAILED: $FAIL"
echo "==================================="
[ "$FAIL" -eq 0 ]
