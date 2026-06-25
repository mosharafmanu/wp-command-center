#!/usr/bin/env bash
#
# Phase 2B — Runtime migration cutover (acceptance suite).
#
# Verifies Runtime no longer exists as a customer page, Settings is grouped to five
# tabs, every old Runtime/Settings URL redirects safely (no loops), and the migrated
# tools still work — WITHOUT engine/REST/MCP/capability/schema change.
#
# Requires: php, rg; wp-cli optional (functional/redirect/invariant checks).
# Usage: bash tests/test-phase-2b.sh

set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_ROOT="$(cd "$ROOT/../../.." && pwd)"
[ -f "$ROOT/wpcc-env.sh" ] && source "$ROOT/wpcc-env.sh"

SHELL_PHP="$ROOT/includes/Admin/AppShell.php"
MENU="$ROOT/includes/Admin/AdminMenu.php"
DIAG="$ROOT/includes/Admin/views/settings-diagnostics.php"
ADV="$ROOT/includes/Admin/views/settings-advanced.php"
ADMIN_DIR="$ROOT/includes/Admin"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq(){ [ "$2" = "$3" ] && pass "$1" || fail "$1 (expected '$2', got '$3')"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint(){ if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe(){ wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. Runtime removed from the customer UI =="
[ -f "$ROOT/includes/Admin/views/dashboard.php" ] && fail "dashboard.php deleted" || pass "dashboard.php deleted"
lacks "no 'dashboard' view mapped in the shell"        "'view' => 'dashboard'" "$SHELL_PHP"
lacks "no 'Runtime' tab label in the shell"            "__\( 'Runtime'"        "$SHELL_PHP"
if rg -q -e "Agent Runtime Dashboard" "$ADMIN_DIR"; then fail "no 'Agent Runtime Dashboard' anywhere in admin"; else pass "no 'Agent Runtime Dashboard' anywhere in admin"; fi

echo
echo "== 2. Settings grouped to five tabs =="
lint "Diagnostics hub lints" "$DIAG"
lint "Advanced hub lints"    "$ADV"
has "Diagnostics hub registered" "'view' => 'settings-diagnostics'" "$SHELL_PHP"
has "Advanced hub registered"    "'view' => 'settings-advanced'"    "$SHELL_PHP"
has "Tools tab kept"             "'view' => 'tools-search-replace'" "$SHELL_PHP"
# Retired flat tabs no longer registered in the shell.
lacks "no flat recommendations tab" "'recommendations' => \[ 'label'" "$SHELL_PHP"
lacks "no flat patches tab"         "'patches'      => \[ 'label'"    "$SHELL_PHP"
lacks "no flat intelligence tab"    "'intelligence' => \[ 'label'"    "$SHELL_PHP"
lacks "no flat capabilities tab"    "'capabilities' => \[ 'label'"    "$SHELL_PHP"
# Hubs host the existing sub-views.
has "Diagnostics hosts Health"          "'view' => 'diagnostics'"        "$DIAG"
has "Diagnostics hosts Recommendations" "'view' => 'recommendations'"    "$DIAG"
has "Diagnostics hosts Site Report"     "'view' => 'site-intelligence'"  "$DIAG"
has "Diagnostics hosts Patches"         "'view' => 'patches'"            "$DIAG"
has "Advanced hosts Capabilities"       "'view' => 'operations-explorer'" "$ADV"
has "Advanced hosts File Access"        "'view' => 'file-access'"        "$ADV"
has "Diagnostics sub-nav uses namespaced ?dpane=" "dpane" "$DIAG"
has "Advanced sub-nav uses namespaced ?apane="    "apane" "$ADV"
has "Engine Inspector deferral documented"        "Engine Inspector" "$ADV"

echo
echo "== 3. Redirects re-home retired Settings sub-tabs + legacy slugs =="
has "runtime → diagnostics"        "'runtime'         => \[ self::SETTINGS_SLUG, 'diagnostics' \]" "$SHELL_PHP"
has "patches → diagnostics/patches" "'patches'         => \[ self::SETTINGS_SLUG, 'diagnostics', \[ 'dpane' => 'patches' \] \]" "$SHELL_PHP"
has "capabilities → advanced/caps"  "'capabilities'    => \[ self::SETTINGS_SLUG, 'advanced', \[ 'apane' => 'capabilities' \] \]" "$SHELL_PHP"
has "redirect_to carries hub-pane args" "extra_args" "$MENU"

echo
echo "== 4. Live: render + redirect + functional + invariants =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli unavailable."
else
	NAV="$(wpe '
		use WPCommandCenter\Admin\AppShell;
		set_current_screen("toplevel_page_wp-command-center");
		$u=get_users(["role"=>"administrator","number"=>1]); if($u) wp_set_current_user($u[0]->ID);
		$shell=new AppShell; $bad=0;
		// every Settings tab + every hub pane renders
		foreach(["security","access","tools","diagnostics","advanced"] as $t){ $_GET=["page"=>"wpcc-settings","wpcc_tab"=>$t]; ob_start(); try{$shell->render("wpcc-settings");}catch(\Throwable $e){$bad++;} $h=ob_get_clean(); if(strlen($h)<200||strpos($h,"wpcc-shell__brand")===false)$bad++; }
		foreach([["diagnostics","dpane","health"],["diagnostics","dpane","recommendations"],["diagnostics","dpane","sitereport"],["diagnostics","dpane","patches"],["advanced","apane","capabilities"],["advanced","apane","files"]] as $p){ $_GET=["page"=>"wpcc-settings","wpcc_tab"=>$p[0],$p[1]=>$p[2]]; ob_start(); try{$shell->render("wpcc-settings");}catch(\Throwable $e){$bad++;} $h=ob_get_clean(); if(strlen($h)<200)$bad++; }
		// Settings = exactly 5 tabs, Runtime absent
		$tabs=array_keys(AppShell::sections()["wpcc-settings"]["tabs"]);
		if(count($tabs)!==5)$bad++; if(in_array("runtime",$tabs,true))$bad++;
		// no redirect loops; live tabs never redirect
		$follow=function($p,$t) use(&$follow){ $seen=[];$h=0; while(true){$k="$p|$t";if(isset($seen[$k]))return"LOOP";$seen[$k]=1;$r=AppShell::resolve_legacy($p,$t);if($r===null)return"OK";$p=$r[0];$t=$r[1];if(++$h>10)return"RUN";} };
		foreach(AppShell::sections() as $slug=>$sec){ if(AppShell::resolve_legacy($slug,"")!==null)$bad++; foreach(array_keys($sec["tabs"]) as $tk){ if(AppShell::resolve_legacy($slug,$tk)!==null)$bad++; } }
		$cases=[]; foreach(array_keys(AppShell::legacy_map()) as $s)$cases[]=[$s,""]; foreach(AppShell::legacy_tab_map() as $s=>$tt)foreach(array_keys($tt) as $t)$cases[]=[$s,$t==="*"?"":$t];
		foreach($cases as $c){ if($follow($c[0],$c[1])!=="OK")$bad++; }
		echo $bad===0?"CLEAN":("BAD:".$bad);
	')"
	assert_eq "5 tabs · all render · all URLs terminate · no loops" "CLEAN" "$NAV"

	SR="$(wpe '
		$om=new WPCommandCenter\Operations\OperationManager(); $oq=new WPCommandCenter\Operations\OperationQueue(); global $wpdb;
		$req=$om->create_request("safe_search_replace",["search"=>"__wpcc_probe_nomatch__","replace"=>"x","tables"=>[$wpdb->prefix."posts"],"dry_run"=>true,"case_sensitive"=>false],["actor"=>"t2b"]);
		if(is_wp_error($req)){echo "ERR";} else { $om->approve_request($req["request_id"]); $qi=$wpdb->get_row($wpdb->prepare("SELECT queue_id FROM {$wpdb->prefix}wpcc_operation_queue WHERE request_id=%s",$req["request_id"])); $r=$oq->run_item($qi->queue_id,["actor"=>["type"=>"admin","user_id"=>1,"user_login"=>"t2b"]]); echo is_wp_error($r)?"ERR":"OK"; }
	')"
	assert_eq "Tools S&R governed dry-run still works" "OK" "$SR"

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
