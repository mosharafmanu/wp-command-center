#!/usr/bin/env bash
#
# Phase B / C1 — Admin permission-callback consolidation acceptance suite.
#
# C1 collapses the six near-identical AdminRestApi permission callbacks into ONE
# resolver (gate()) plus a LOCAL FEATURE_KEYS map, keeping the six per-surface
# methods as thin named bindings the route registrations point at. This suite
# proves the consolidation is BEHAVIOR-PRESERVING:
#
#   - the single resolver + local map exist; all six callback names are retained
#   - route wiring is unchanged (each callback still bound to its routes)
#   - functional gate parity: with manage_options, all gates open => every callback
#     true; each FeatureGate key off => only that surface's callback denies, the
#     other surfaces AND the legacy capability-only routes stay allowed; restored
#   - the legacy /admin/approvals* gate stays capability-only (no feature seam) —
#     the intentional asymmetry C1 must NOT flatten
#   - DashboardAdminQuery keeps its OWN map (no shared cross-file gate map yet)
#   - invariants untouched (this is a permission-layer refactor only)
#
# Requires: php, rg, wp-cli, wpcc-env.sh.
# Usage: bash tests/test-admin-permissions.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"

# shellcheck source=/dev/null
[ -f "$PLUGIN_DIR/wpcc-env.sh" ] && source "$PLUGIN_DIR/wpcc-env.sh"

RESTAPI="$PLUGIN_DIR/includes/Admin/AdminRestApi.php"
DASHQ="$PLUGIN_DIR/includes/Admin/DashboardAdminQuery.php"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lint() { if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe()  { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }
cnt()  { rg -c -e "$1" "$2" 2>/dev/null || echo 0; }

echo "== 1. PHP lint =="
lint "AdminRestApi lints" "$RESTAPI"

echo
echo "== 2. Single resolver + local FEATURE_KEYS map (C1 structure) =="
has "single resolver gate() present"      "function gate\( \?string \\\$surface"  "$RESTAPI"
has "local FEATURE_KEYS map present"      "private const FEATURE_KEYS"            "$RESTAPI"
has "map: approvals -> approval_center"   "'approvals'\s*=> 'approval_center'"     "$RESTAPI"
has "map: operations -> operations_explorer" "'operations'\s*=> 'operations_explorer'" "$RESTAPI"
has "map: tokens -> token_capability_manager" "'tokens'\s*=> 'token_capability_manager'" "$RESTAPI"
has "map: change_history -> change_history"   "'change_history'\s*=> 'change_history'"   "$RESTAPI"
has "map: dashboard -> dashboard_overview"    "'dashboard'\s*=> 'dashboard_overview'"    "$RESTAPI"
# The duplicated inline gating logic is gone: manage_options && FeatureGate::allows
# should appear ONCE (inside gate()), not six times.
GATE_LOGIC="$(cnt "current_user_can\( 'manage_options' \)" "$RESTAPI")"
assert_eq "gating logic consolidated to one site" "1" "$GATE_LOGIC"

echo
echo "== 3. All six callback names retained (tested contract + route bindings) =="
has "check_permission retained"            "function check_permission\b"           "$RESTAPI"
has "check_history_permission retained"    "function check_history_permission\b"   "$RESTAPI"
has "check_approval_permission retained"   "function check_approval_permission\b"  "$RESTAPI"
has "check_tokens_permission retained"     "function check_tokens_permission\b"    "$RESTAPI"
has "check_operations_permission retained" "function check_operations_permission\b" "$RESTAPI"
has "check_dashboard_permission retained"  "function check_dashboard_permission\b" "$RESTAPI"

echo
echo "== 4. Route wiring unchanged (each callback still bound) =="
# Reference counts are the registrations only (the function defs are matched with \b
# above); here we just assert each callback is still used as a permission_callback.
has "legacy approvals bound to check_permission"  "\[ \\\$this, 'check_permission' \]"           "$RESTAPI"
has "approval center bound"  "\[ \\\$this, 'check_approval_permission' \]"   "$RESTAPI"
has "change history bound"   "\[ \\\$this, 'check_history_permission' \]"    "$RESTAPI"
has "tokens bound"           "\[ \\\$this, 'check_tokens_permission' \]"     "$RESTAPI"
has "operations bound"       "\[ \\\$this, 'check_operations_permission' \]" "$RESTAPI"
has "dashboard bound"        "\[ \\\$this, 'check_dashboard_permission' \]"  "$RESTAPI"

echo
echo "== 5. DashboardAdminQuery keeps its OWN map (no shared cross-file gate map) =="
has "DashboardAdminQuery still has its own FEATURE_KEYS" "private const FEATURE_KEYS" "$DASHQ"

echo
echo "== 6. Functional gate parity (manage_options admin; per-key isolation) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	# One bootstrap: set an admin, probe every callback with all gates open, then
	# with each FeatureGate key flipped off in isolation, then restored.
	RES="$(wpe '
		$admin = get_users( ["role"=>"administrator","number"=>1] );
		if ( empty( $admin ) ) { echo "{}"; return; }
		wp_set_current_user( $admin[0]->ID );
		$api = new \WPCommandCenter\Admin\AdminRestApi();
		$probe = function() use ( $api ) {
			return [
				"legacy"     => $api->check_permission() ? 1 : 0,
				"approvals"  => $api->check_approval_permission() ? 1 : 0,
				"history"    => $api->check_history_permission() ? 1 : 0,
				"tokens"     => $api->check_tokens_permission() ? 1 : 0,
				"operations" => $api->check_operations_permission() ? 1 : 0,
				"dashboard"  => $api->check_dashboard_permission() ? 1 : 0,
			];
		};
		$out = [ "open" => $probe() ];
		foreach ( [ "approval_center", "change_history", "token_capability_manager", "operations_explorer", "dashboard_overview" ] as $key ) {
			$f = function( $a, $feature ) use ( $key ) { return $feature === $key ? false : $a; };
			add_filter( "wpcc_feature_allowed", $f, 10, 2 );
			$out[ "off:" . $key ] = $probe();
			remove_filter( "wpcc_feature_allowed", $f, 10 );
		}
		$out["restored"] = $probe();
		echo wp_json_encode( $out );
	')"
	g() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"]["'"$2"'"] ?? "";' 2>/dev/null; }

	# All gates open -> every callback allows.
	for s in legacy approvals history tokens operations dashboard; do
		assert_eq "open: $s allows" "1" "$(g open "$s")"
	done

	# approval_center off -> ONLY approvals denies; legacy (capability-only) + others allow.
	assert_eq "approval_center off -> approvals denied"      "0" "$(g off:approval_center approvals)"
	assert_eq "approval_center off -> legacy still allowed"  "1" "$(g off:approval_center legacy)"
	assert_eq "approval_center off -> tokens unaffected"     "1" "$(g off:approval_center tokens)"

	# change_history off -> ONLY history denies.
	assert_eq "change_history off -> history denied"         "0" "$(g off:change_history history)"
	assert_eq "change_history off -> dashboard unaffected"   "1" "$(g off:change_history dashboard)"

	# token_capability_manager off -> ONLY tokens denies.
	assert_eq "tokens off -> tokens denied"                  "0" "$(g off:token_capability_manager tokens)"
	assert_eq "tokens off -> operations unaffected"          "1" "$(g off:token_capability_manager operations)"

	# operations_explorer off -> ONLY operations denies.
	assert_eq "operations off -> operations denied"          "0" "$(g off:operations_explorer operations)"
	assert_eq "operations off -> approvals unaffected"       "1" "$(g off:operations_explorer approvals)"

	# dashboard_overview off -> ONLY dashboard denies.
	assert_eq "dashboard off -> dashboard denied"            "0" "$(g off:dashboard_overview dashboard)"
	assert_eq "dashboard off -> history unaffected"          "1" "$(g off:dashboard_overview history)"

	# The legacy /admin/approvals* gate is capability-only: NO feature key disables it.
	assert_eq "legacy gate immune to approval_center off"    "1" "$(g off:approval_center legacy)"
	assert_eq "legacy gate immune to dashboard_overview off" "1" "$(g off:dashboard_overview legacy)"

	# Filters removed -> all restored.
	for s in legacy approvals history tokens operations dashboard; do
		assert_eq "restored: $s allows" "1" "$(g restored "$s")"
	done

	echo
	echo "== 7. Invariants unchanged (permission-layer refactor only) =="
	OPMAP="$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP );')"
	assert_eq "OPERATION_MAP stays 34" "34" "$OPMAP"
	CAPS="$(wpe 'echo count( \WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES );')"
	assert_eq "ALL_CAPABILITIES stays 23" "23" "$CAPS"
	CAT="$(wpe '$reg = new \WPCommandCenter\Operations\OperationRegistry(); echo count( $reg->get_operations() );')"
	assert_eq "operation catalogue stays 40" "40" "$CAT"
	DBV="$(wpe 'echo get_option("wpcc_db_version");')"
	assert_eq "DB_VERSION stays 2.4.0" "2.4.0" "$DBV"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
