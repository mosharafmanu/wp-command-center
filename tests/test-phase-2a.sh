#!/usr/bin/env bash
#
# Phase 2A — Runtime migration, additive homes (acceptance suite).
#
# Verifies the NEW destinations exist and work, the originals are untouched, real data
# drives the signals, and nothing drifted — WITHOUT deleting Runtime or changing engine
# behavior, REST, MCP, capabilities, or schema.
#
#   - Settings › Tools hosts Safe Search & Replace, governed flow preserved
#   - Settings › Recommendations surfaces findings + governed actions, honest empty state
#   - Home shows a recommendations signal only on real open findings
#   - Activity › Approvals points to Recommendations only on real pending plans
#   - Runtime (dashboard.php) still present and rendering (additive guarantee)
#   - No redirect loops; invariants 34/23/40/40/2.5.0; no new route/capability in new views
#
# Requires: php, rg; wp-cli optional (functional + invariant checks).
# Usage: bash tests/test-phase-2a.sh

set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_ROOT="$(cd "$ROOT/../../.." && pwd)"
[ -f "$ROOT/wpcc-env.sh" ] && source "$ROOT/wpcc-env.sh"

SHELL_PHP="$ROOT/includes/Admin/AppShell.php"
TOOLS="$ROOT/includes/Admin/views/tools-search-replace.php"
RECS="$ROOT/includes/Admin/views/recommendations.php"
HOME_V="$ROOT/includes/Admin/views/command-home.php"
APPROV="$ROOT/includes/Admin/views/approval-center.php"
RUNTIME="$ROOT/includes/Admin/views/dashboard.php"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq(){ [ "$2" = "$3" ] && pass "$1" || fail "$1 (expected '$2', got '$3')"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint(){ if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe(){ wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. Lint =="
for f in "$SHELL_PHP" "$TOOLS" "$RECS" "$HOME_V" "$APPROV"; do lint "lint $(basename "$f")" "$f"; done
[ -f "$TOOLS" ] && pass "tools-search-replace.php exists" || fail "tools view missing"
[ -f "$RECS" ] && pass "recommendations.php exists" || fail "recommendations view missing"

echo
echo "== 2. New Settings tabs registered (additive) =="
has "Tools tab → tools-search-replace"           "'tools'\s*=> \[ 'label' => __\( 'Tools'" "$SHELL_PHP"
has "Recommendations tab → recommendations view" "'recommendations'\s*=> \[ 'label' => __\( 'Recommendations'" "$SHELL_PHP"
has "Runtime tab STILL present (not removed)"     "'runtime'\s*=> \[ 'label' => __\( 'Runtime'" "$SHELL_PHP"

echo
echo "== 3. Tools — governed Search & Replace preserved =="
has "S&R nonce/action"            "wpcc_sr_action" "$TOOLS"
has "creates governed request"    "create_request\( 'safe_search_replace'" "$TOOLS"
has "dry-run preserved"           "dry_run" "$TOOLS"
has "confirmation modal preserved" "wpcc-sr-confirm-overlay" "$TOOLS"
has "risk model preserved"        "Computed Risk Level" "$TOOLS"
has "uses OperationManager"       "OperationManager" "$TOOLS"
has "uses OperationQueue"         "OperationQueue" "$TOOLS"
has "points to Activity › Approvals (new IA)" "Activity . Approvals" "$TOOLS"
lacks "no old Approval-Center-by-Operate copy" "Operate . Approvals" "$TOOLS"
lacks "Tools adds NO REST route"  "register_rest_route" "$TOOLS"

echo
echo "== 4. Recommendations — surfaces real data + governed actions + honest empty =="
has "lists via RecommendationEngine"   "RecommendationEngine" "$RECS"
has "engine list() used"               "->list\(" "$RECS"
has "dismiss/resolve via transition()" "->transition\(" "$RECS"
has "plan approve/reject preserved"    "sync_plan_status" "$RECS"
has "scan action (non-destructive)"    "->scan\(" "$RECS"
has "honest empty state"               "No recommendations yet" "$RECS"
has "nonce wpcc_recommendations"       "wpcc_recommendations" "$RECS"
lacks "does not invent data"           "fake|dummy|sample_recommendation" "$RECS"
lacks "Recommendations adds NO REST route" "register_rest_route" "$RECS"

echo
echo "== 5. Home signal — real data only, conditional, calm =="
has "reads real open-recommendation count" "wpcc_recommendations WHERE status" "$HOME_V"
has "signal is conditional (>0 only)"      "wpcc_rec_open > 0" "$HOME_V"
has "links to Recommendations tab"         "wpcc_tab=recommendations" "$HOME_V"

echo
echo "== 6. Approvals pointer — real pending plans only =="
has "reads real pending-plan count"   "wpcc_agent_plans WHERE status" "$APPROV"
has "pointer conditional (>0 only)"   "wpcc_pending_plan_cnt > 0" "$APPROV"
has "links to Recommendations"        "wpcc_tab=recommendations" "$APPROV"
lacks "no duplicated recommendations table" "RecommendationEngine" "$APPROV"

echo
echo "== 7. Runtime untouched (additive guarantee) =="
[ -f "$RUNTIME" ] && pass "dashboard.php (Runtime) still exists" || fail "dashboard.php was removed (must not be in 2A)"
has "Runtime still hosts its own S&R copy" "wpcc_sr_action" "$RUNTIME"

echo
echo "== 8. Functional + invariants + nav (wp-cli) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli unavailable."
else
	# Governed dry-run path the Tools view uses still works end-to-end.
	SR="$(wpe '
		$om=new WPCommandCenter\Operations\OperationManager(); $oq=new WPCommandCenter\Operations\OperationQueue(); global $wpdb;
		$req=$om->create_request("safe_search_replace",["search"=>"__wpcc_probe_nomatch__","replace"=>"x","tables"=>[$wpdb->prefix."posts"],"dry_run"=>true,"case_sensitive"=>false],["actor"=>"t2a"]);
		if(is_wp_error($req)){echo "ERR";} else { $om->approve_request($req["request_id"]); $qi=$wpdb->get_row($wpdb->prepare("SELECT queue_id FROM {$wpdb->prefix}wpcc_operation_queue WHERE request_id=%s",$req["request_id"])); $r=$oq->run_item($qi->queue_id,["actor"=>["type"=>"admin","user_id"=>1,"user_login"=>"t2a"]]); echo is_wp_error($r)?"ERR":"OK"; }
	')"
	assert_eq "S&R governed dry-run path works (request→approve→run)" "OK" "$SR"

	# New + existing tabs render without fatal; no redirect loops.
	NAV="$(wpe '
		use WPCommandCenter\Admin\AppShell;
		set_current_screen("toplevel_page_wp-command-center");
		$u=get_users(["role"=>"administrator","number"=>1]); if($u) wp_set_current_user($u[0]->ID);
		$shell=new AppShell; $bad=0;
		foreach(["tools","recommendations","runtime"] as $t){ $_GET=["page"=>"wpcc-settings","wpcc_tab"=>$t]; ob_start(); try{$shell->render("wpcc-settings");}catch(\Throwable $e){$bad++;} $h=ob_get_clean(); if(strlen($h)<200||strpos($h,"wpcc-shell__brand")===false)$bad++; }
		foreach(AppShell::sections() as $slug=>$sec){ if(AppShell::resolve_legacy($slug,"")!==null)$bad++; foreach(array_keys($sec["tabs"]) as $tk){ if(AppShell::resolve_legacy($slug,$tk)!==null)$bad++; } }
		echo $bad===0?"CLEAN":("BAD:".$bad);
	')"
	assert_eq "new+existing tabs render; no redirect loops" "CLEAN" "$NAV"

	INV="$(wpe '$i=(new WPCommandCenter\Admin\DashboardAdminQuery())->overview()["invariants"]; echo $i["operation_map"].",".$i["capabilities"].",".$i["catalogue"].",".$i["mcp_tools"].",".$i["db_version"];')"
	assert_eq "OPERATION_MAP 34" "34" "$(echo "$INV"|cut -d, -f1)"
	assert_eq "CAPABILITIES 23"  "23" "$(echo "$INV"|cut -d, -f2)"
	assert_eq "catalogue 40"     "40" "$(echo "$INV"|cut -d, -f3)"
	assert_eq "MCP tools 40"     "40" "$(echo "$INV"|cut -d, -f4)"
	assert_eq "DB_VERSION 2.5.0" "2.5.0" "$(echo "$INV"|cut -d, -f5)"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
