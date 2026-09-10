#!/usr/bin/env bash
# Finding A — "N access tokens ready" must count usable tokens, not records.
#
# The Set up screen counted every row in the token manifest, so a site with one
# working token and four revoked ones was told "5 access tokens ready" — and the
# same number gates the configuration and connection-test cards, so a site whose
# only token had expired was offered a setup flow it could not complete.
#
# The rule now lives in exactly one place, AuthTokens::is_usable(): status is
# 'active' AND expires_at is either absent or still in the future. That is the
# same rule AuthTokens::validate() enforces at request time and the same one
# AuthTokens::status_badge() prints, so the three can no longer disagree.
#
# Covered here:
#   1. is_usable() on each individual lifecycle state.
#   2. usable_only() over a mixed set — the reported scenario.
#   3. The view derives its count from usable_only(), and the string it prints
#      is _n()-driven so 1 reads "1 access token ready".
#   4. The other two counters that promised "active" and meant it —
#      AdoptionStatus::active_token_count() and ConnectionStatus's
#      active_tokens — agree with the same rule against real manifest records.
#   5. Counters that deliberately count ALL records still do.
set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/../wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."
VIEW="$SCRIPT_DIR/../includes/Admin/views/ai-integrations.php"
PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; if [ "$e" = "$a" ]; then pass "$d"; else fail "$d (expected '$e', got '$a')"; fi; }
assert_true() { local d="$1" a="$2"; if [ "$a" = "true" ]; then pass "$d"; else fail "$d"; fi; }

echo "Access-token ready count (Finding A) — $(date)"
echo ""

# ===================================================================
echo "== 1. is_usable() — one lifecycle state at a time =="
# Pure static rule: exercised against literal records so the result does not
# depend on whatever this site's manifest happens to hold.
STATES=$(wp eval '
use WPCommandCenter\Security\AuthTokens;
$now = time();
$cases = [
	"active_no_expiry"     => [ "status" => "active",  "expires_at" => null ],
	"active_future_expiry" => [ "status" => "active",  "expires_at" => $now + 3600 ],
	"active_expired"       => [ "status" => "active",  "expires_at" => $now - 3600 ],
	"revoked"              => [ "status" => "revoked", "expires_at" => null ],
	"revoked_and_expired"  => [ "status" => "revoked", "expires_at" => $now - 3600 ],
	"unknown_status"       => [ "status" => "",        "expires_at" => null ],
];
$out = [];
foreach ( $cases as $name => $record ) {
	$out[ $name ] = AuthTokens::is_usable( $record );
}
echo wp_json_encode( $out );
' --path="$WP_PATH" 2>/dev/null)

assert_eq "active, no expiry — usable"            "true"  "$(echo "$STATES" | jq -r '.active_no_expiry')"
assert_eq "active, expires later — usable"        "true"  "$(echo "$STATES" | jq -r '.active_future_expiry')"
assert_eq "active but past expires_at — NOT usable" "false" "$(echo "$STATES" | jq -r '.active_expired')"
assert_eq "revoked — NOT usable"                  "false" "$(echo "$STATES" | jq -r '.revoked')"
assert_eq "revoked and expired — NOT usable"      "false" "$(echo "$STATES" | jq -r '.revoked_and_expired')"
assert_eq "unrecognised status — NOT usable"      "false" "$(echo "$STATES" | jq -r '.unknown_status')"

# The badge and the count must agree, or the screen contradicts itself: a row
# rendered "Expired" while the summary above it counted that same token "ready".
BADGES=$(wp eval '
use WPCommandCenter\Security\AuthTokens;
$now = time();
$rows = [
	"active"  => [ "status" => "active",  "expires_at" => null ],
	"expired" => [ "status" => "active",  "expires_at" => $now - 3600 ],
	"revoked" => [ "status" => "revoked", "expires_at" => null ],
];
$out = [];
foreach ( $rows as $name => $record ) {
	$badge = wp_strip_all_tags( AuthTokens::status_badge( $record ) );
	$out[ $name ] = [ "badge" => $badge, "usable" => AuthTokens::is_usable( $record ) ];
}
echo wp_json_encode( $out );
' --path="$WP_PATH" 2>/dev/null)

assert_eq "badge says Active and count agrees"  "true"  "$(echo "$BADGES" | jq -r '.active.badge == "Active" and .active.usable')"
assert_eq "badge says Expired and count agrees" "true"  "$(echo "$BADGES" | jq -r '.expired.badge == "Expired" and (.expired.usable | not)')"
assert_eq "badge says Revoked and count agrees" "true"  "$(echo "$BADGES" | jq -r '.revoked.badge == "Revoked" and (.revoked.usable | not)')"

# ===================================================================
echo ""
echo "== 2. usable_only() over a mixed set — the reported scenario =="
MIXED=$(wp eval '
use WPCommandCenter\Security\AuthTokens;
$now = time();
// The certification screenshot: one working token, three that are not.
$manifest = [
	[ "id" => "keep",  "status" => "active",  "expires_at" => null ],
	[ "id" => "gone1", "status" => "revoked", "expires_at" => null ],
	[ "id" => "gone2", "status" => "revoked", "expires_at" => $now + 3600 ],
	[ "id" => "gone3", "status" => "active",  "expires_at" => $now - 60 ],
];
$usable = AuthTokens::usable_only( $manifest );
echo wp_json_encode( [
	"all"    => count( $manifest ),
	"usable" => count( $usable ),
	"ids"    => wp_list_pluck( $usable, "id" ),
	// Re-indexed, so the caller can rely on [0] existing.
	"keys"   => array_keys( $usable ),
	"none"   => count( AuthTokens::usable_only( [] ) ),
	"all_bad" => count( AuthTokens::usable_only( [
		[ "id" => "a", "status" => "revoked", "expires_at" => null ],
		[ "id" => "b", "status" => "active",  "expires_at" => $now - 1 ],
	] ) ),
] );
' --path="$WP_PATH" 2>/dev/null)

assert_eq "mixed set holds 4 records"        "4"      "$(echo "$MIXED" | jq -r '.all')"
assert_eq "only 1 of them is usable"         "1"      "$(echo "$MIXED" | jq -r '.usable')"
assert_eq "and it is the right one"          "keep"   "$(echo "$MIXED" | jq -r '.ids[0]')"
assert_eq "result is re-indexed from 0"      "[0]"    "$(echo "$MIXED" | jq -c '.keys')"
assert_eq "empty manifest counts 0"          "0"      "$(echo "$MIXED" | jq -r '.none')"
assert_eq "revoked + expired only counts 0"  "0"      "$(echo "$MIXED" | jq -r '.all_bad')"

# ===================================================================
echo ""
echo "== 3. The Set up view counts usable tokens and says so in English =="
# The count that prints "N access tokens ready" and the count that gates the
# configuration / connection-test cards are the same variable, so asserting its
# derivation covers both. Source assertions, because rendering this view needs
# an authenticated admin screen; the behaviour it derives is proven above.
assert_true "view derives its count from AuthTokens::usable_only()" \
	"$(grep -q 'wpcc_usable_tokens = AuthTokens::usable_only(' "$VIEW" && echo true || echo false)"
assert_true "view counts that list, not the raw manifest" \
	"$(grep -q 'wpcc_cfg_tok_count = count( \$wpcc_usable_tokens )' "$VIEW" && echo true || echo false)"
assert_true "no second, private definition of 'usable' left in the view" \
	"$(grep -qE "\\\$t\['status'\] \?\? '' \) !== 'active'" "$VIEW" && echo false || echo true)"

# Grammar: 1 must read "token", 2+ "tokens". _n() is what makes that true for
# every locale, so assert the view calls it and that it resolves correctly.
assert_true "count string goes through _n()" \
	"$(grep -q "_n( '%d access token ready.', '%d access tokens ready.'" "$VIEW" && echo true || echo false)"
GRAMMAR=$(wp eval '
echo wp_json_encode( [
	"one"  => sprintf( _n( "%d access token ready.", "%d access tokens ready.", 1, "action-steward" ), 1 ),
	"two"  => sprintf( _n( "%d access token ready.", "%d access tokens ready.", 2, "action-steward" ), 2 ),
] );
' --path="$WP_PATH" 2>/dev/null)
assert_eq "1 usable token reads singular"  "1 access token ready."   "$(echo "$GRAMMAR" | jq -r '.one')"
assert_eq "2 usable tokens read plural"    "2 access tokens ready."  "$(echo "$GRAMMAR" | jq -r '.two')"

# ===================================================================
echo ""
echo "== 4. Real manifest records — the counters agree with validate() =="
# Three tokens are created against the live manifest and removed at the end.
# Deltas are measured rather than absolute counts, so whatever this site already
# holds does not decide the result.
COUNTERS=$(wp eval '
use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Admin\AdoptionStatus;
use WPCommandCenter\Admin\ConnectionStatus;

$auth = new AuthTokens();
$before = [
	"all"        => AdoptionStatus::token_count(),
	"active"     => AdoptionStatus::active_token_count(),
	"connection" => ConnectionStatus::get()["active_tokens"],
];

$ids = [];
$mk = function ( string $label, ?int $expires ) use ( $auth, &$ids ) {
	$r = $auth->create( $label, AuthTokens::SCOPE_READ_ONLY, $expires, 1 );
	if ( is_wp_error( $r ) ) {
		return null;
	}
	$ids[] = $r["record"]["id"];
	return $r;
};

$live    = $mk( "F-A usable",  null );
$expired = $mk( "F-A expired", time() - 3600 );
$doomed  = $mk( "F-A revoked", null );
$auth->revoke( $ids[2] ?? "" );

$after = [
	"all"        => AdoptionStatus::token_count(),
	"active"     => AdoptionStatus::active_token_count(),
	"connection" => ConnectionStatus::get()["active_tokens"],
];

// Does validate() agree with is_usable() on these same three?
$verdicts = [
	"usable"  => ! is_wp_error( $auth->validate( $live["token"] ) ),
	"expired" => ! is_wp_error( $auth->validate( $expired["token"] ) ),
	"revoked" => ! is_wp_error( $auth->validate( $doomed["token"] ) ),
];

foreach ( $ids as $id ) {
	$auth->delete( $id );
}

echo wp_json_encode( [
	"all_delta"        => $after["all"] - $before["all"],
	"active_delta"     => $after["active"] - $before["active"],
	"connection_delta" => $after["connection"] - $before["connection"],
	"validate"         => $verdicts,
	"cleaned"          => AdoptionStatus::token_count() === $before["all"],
] );
' --path="$WP_PATH" 2>/dev/null)

assert_eq "3 records added to the manifest"                   "3"     "$(echo "$COUNTERS" | jq -r '.all_delta')"
assert_eq "only 1 counts as active (expired + revoked do not)" "1"    "$(echo "$COUNTERS" | jq -r '.active_delta')"
assert_eq "connection status counts the same 1"               "1"     "$(echo "$COUNTERS" | jq -r '.connection_delta')"
assert_eq "validate() accepts the usable token"               "true"  "$(echo "$COUNTERS" | jq -r '.validate.usable')"
assert_eq "validate() refuses the expired token"              "false" "$(echo "$COUNTERS" | jq -r '.validate.expired')"
assert_eq "validate() refuses the revoked token"              "false" "$(echo "$COUNTERS" | jq -r '.validate.revoked')"
assert_eq "test tokens removed again"                         "true"  "$(echo "$COUNTERS" | jq -r '.cleaned')"

# ===================================================================
echo ""
echo "== 5. Counters that mean 'every record' are unchanged =="
# The token audit list in Settings exists to show revoked and expired tokens.
# Narrowing it would hide exactly what it is for.
AUDIT=$(wp eval '
use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Admin\TokenCapabilityAdminQuery;
use WPCommandCenter\Admin\AdoptionStatus;
$all  = ( new AuthTokens() )->list();
$list = ( new TokenCapabilityAdminQuery() )->tokens();
echo wp_json_encode( [
	"audit_total"   => $list["total_count"],
	"manifest_size" => count( $all ),
	"adoption_all"  => AdoptionStatus::token_count(),
	"statuses"      => array_values( array_unique( wp_list_pluck( $list["items"], "status" ) ) ),
] );
' --path="$WP_PATH" 2>/dev/null)

assert_eq "token audit list still totals every record" "true" \
	"$(echo "$AUDIT" | jq -r '.audit_total == .manifest_size')"
assert_eq "AdoptionStatus::token_count() still totals every record" "true" \
	"$(echo "$AUDIT" | jq -r '.adoption_all == .manifest_size')"
# Not "there is a revoked token here" — this site may hold none. The claim is
# that the audit list reports each record's own effective status rather than
# filtering to the usable ones, so a revoked or expired token can still appear.
assert_eq "audit list reports per-record effective status" "true" \
	"$(echo "$AUDIT" | jq -r '(.statuses | length) > 0 and all(.statuses[]; . == "active" or . == "revoked" or . == "expired")')"
assert_eq "audit list is not narrowed to usable tokens" "true" \
	"$(echo "$AUDIT" | jq -r '.audit_total >= (.statuses | length)')"

echo ""
echo "==================================="
echo "  PASSED: $PASS"
echo "  FAILED: $FAIL"
echo "==================================="
[ "$FAIL" -eq 0 ]
