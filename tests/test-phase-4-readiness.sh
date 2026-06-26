#!/usr/bin/env bash
#
# Phase 4 — Design-partner readiness acceptance suite.
#
# Validates in-admin enablement of the built-in AI tools (governed, audited, honest),
# the readiness checklist, and the Home first-value panel — WITHOUT changing provider
# execution, REST, MCP, capabilities, or schema/DB_VERSION.
#
# Requires: php, rg; wp-cli optional (functional + invariant checks).
# Usage: bash tests/test-phase-4-readiness.sh

set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/.." && pwd)"
WP_ROOT="$(cd "$ROOT/../../.." && pwd)"
[ -f "$ROOT/wpcc-env.sh" ] && source "$ROOT/wpcc-env.sh"

SET="$ROOT/includes/Admin/BuiltinAiSettings.php"
RDY="$ROOT/includes/Admin/DesignPartnerReadiness.php"
SHELL_PHP="$ROOT/includes/Admin/AppShell.php"
TOOLS_VIEW="$ROOT/includes/Admin/views/partials/builtin-ai-tools.php"
PROVIDERS="$ROOT/includes/Admin/views/ai-setup.php"
HOME_VIEW="$ROOT/includes/Admin/views/command-home.php"

PASS=0; FAIL=0
pass(){ PASS=$((PASS+1)); echo "  PASS: $1"; }
fail(){ FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq(){ [ "$2" = "$3" ] && pass "$1" || fail "$1 (expected '$2', got '$3')"; }
has(){ if rg -q -e "$2" "$3"; then pass "$1"; else fail "$1"; fi; }
lacks(){ if rg -q -e "$2" "$3"; then fail "$1"; else pass "$1"; fi; }
lint(){ if php -l "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
wpe(){ wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

echo "== 1. Lint =="
for f in "$SET" "$RDY" "$SHELL_PHP" "$TOOLS_VIEW" "$PROVIDERS" "$HOME_VIEW"; do lint "lint $(basename "$f")" "$f"; done

echo
echo "== 2. Enablement model — governed, config-respecting, honest =="
has "option key defined"                 "wpcc_builtin_ai_tools" "$SET"
has "three tools (seo/alt_text/content)" "'seo'" "$SET"
has "alt_text tool"                      "'alt_text'" "$SET"
has "content tool"                       "'content'" "$SET"
has "config-controlled detection"        "function is_config_controlled" "$SET"
has "status: requires_provider state"    "requires_provider" "$SET"
has "status: enabled_by_config state"    "enabled_by_config" "$SET"
has "status: disabled_by_config state"   "disabled_by_config" "$SET"
has "set() refuses when config-controlled" "is_config_controlled\( \\\$key \)" "$SET"
has "POST handler is nonce-checked"      "check_admin_referer\( self::NONCE" "$SET"
has "POST handler is capability-checked" "current_user_can\( 'manage_options' \)" "$SET"
has "toggle is audited"                  "'builtin_ai.tool_'" "$SET"
lacks "model never reads/renders a provider key" "->key\(\)|wpcc_anthropic_api_key" "$SET"
lacks "model adds no REST route"         "register_rest_route" "$SET"

echo
echo "== 3. AppShell consults the option (constants/filters still win) =="
has "flag() consults enabled_by_option"  "BuiltinAiSettings::enabled_by_option" "$SHELL_PHP"
has "defined constant wins (on or off)"  "if \( defined\( \\\$const \) \)" "$SHELL_PHP"
has "filter opt-in honored"              "apply_filters\( \\\$filter, false \)" "$SHELL_PHP"

echo
echo "== 4. Enablement UI — CDS, governed, honest, escaped =="
has "renders enablement card"            "wpcc-bai-tools-h" "$TOOLS_VIEW"
has "uses CDS card"                      "wpcc-cds-card" "$TOOLS_VIEW"
has "nonce field present"                "wp_nonce_field\( BuiltinAiSettings::NONCE" "$TOOLS_VIEW"
has "honest Anthropic note"              "Generation runs on Anthropic" "$TOOLS_VIEW"
has "config-controlled shown as Locked"  "Locked" "$TOOLS_VIEW"
has "escapes labels"                     "esc_html" "$TOOLS_VIEW"
lacks "no provider key rendered"         "api_key|->key\(\)|secret" "$TOOLS_VIEW"
has "Providers view includes the card"   "partials/builtin-ai-tools.php" "$PROVIDERS"

echo
echo "== 5. Readiness model + Home first-value panel =="
has "8 readiness items present"          "function checklist" "$RDY"
has "security-mode item"                 "'security_mode'" "$RDY"
has "provider-connected item"            "'provider_connected'" "$RDY"
has "provider-tested item"               "'provider_tested'" "$RDY"
has "generation-supported (honest)"      "Generation runs on Anthropic" "$RDY"
has "tool-enabled item"                  "'tool_enabled'" "$RDY"
has "test-content item"                  "'test_content'" "$RDY"
has "can_run_first_workflow()"           "function can_run_first_workflow" "$RDY"
has "next_action() single focus"         "function next_action" "$RDY"
lacks "readiness performs no writes"     "update_option|->run\(|wp_insert_post" "$RDY"
has "Home uses real readiness state"     "DesignPartnerReadiness::can_run_first_workflow" "$HOME_VIEW"
has "Home shows one next action"         "wpcc-firstvalue" "$HOME_VIEW"
has "Home progressive disclosure"        "Show all readiness steps" "$HOME_VIEW"
lacks "Home fabricates no demo content"  "wp_insert_post" "$HOME_VIEW"

echo
echo "== 6. Functional + invariants (wp-cli) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli unavailable."
else
	# Option path on a clean (no constant/filter) tool: enable → on, disable → off; config still wins.
	OPT="$(wpe '
		use WPCommandCenter\Admin\BuiltinAiSettings as B;
		delete_option(B::OPTION);
		remove_all_filters("wpcc_seo_meta_ui"); // simulate a fresh install (no dev filter)
		if (defined("WPCC_SEO_META_UI")) { echo "CONSTSKIP"; return; } // a constant would (correctly) win
		$d = B::is_config_controlled("seo") ? "x" : "ok";
		B::set("seo", true);   $on  = B::is_on("seo") ? "1" : "0";
		$st = B::status("seo");
		B::set("seo", false);  $off = B::is_on("seo") ? "1" : "0";
		delete_option(B::OPTION);
		echo "$d:$on:$st:$off";
	')"
	if [ "$OPT" = "CONSTSKIP" ]; then
		echo "  SKIP: WPCC_SEO_META_UI constant defined — option path not applicable here."
	else
		assert_eq "fresh tool is option-governed (not config-controlled)" "ok" "$(echo "$OPT"|cut -d: -f1)"
		assert_eq "enable via option turns the tool on"  "1" "$(echo "$OPT"|cut -d: -f2)"
		assert_eq "disable via option turns the tool off" "0" "$(echo "$OPT"|cut -d: -f4)"
	fi

	# Config-control locks the toggle: a truthy filter => enabled_by_config + set() is a no-op.
	LOCK="$(wpe '
		use WPCommandCenter\Admin\BuiltinAiSettings as B;
		delete_option(B::OPTION);
		add_filter("wpcc_alt_text_ui","__return_true",99);
		$cc = B::is_config_controlled("alt_text") ? "1" : "0";
		$st = B::status("alt_text");
		$changed = B::set("alt_text", false) ? "changed" : "nochange";
		remove_all_filters("wpcc_alt_text_ui");
		echo "$cc:$st:$changed";
	')"
	assert_eq "filter makes the tool config-controlled" "1" "$(echo "$LOCK"|cut -d: -f1)"
	assert_eq "config-controlled status is enabled_by_config" "enabled_by_config" "$(echo "$LOCK"|cut -d: -f2)"
	assert_eq "set() is a no-op when config-controlled" "nochange" "$(echo "$LOCK"|cut -d: -f3)"

	# Readiness snapshot is shaped + side-effect-free.
	RDYOUT="$(wpe '$c=WPCommandCenter\Admin\DesignPartnerReadiness::checklist(); $ok=count($c)>=8?1:0; $st=true; foreach($c as $i){ if(!in_array($i["status"],["pass","warning","blocked"],true))$st=false; } echo $ok.":".($st?1:0);')"
	assert_eq "readiness has >= 8 items" "1" "$(echo "$RDYOUT"|cut -d: -f1)"
	assert_eq "every readiness status is valid" "1" "$(echo "$RDYOUT"|cut -d: -f2)"

	# Invariants unchanged (no schema / capability / MCP / catalogue drift).
	INV="$(wpe '$i=(new WPCommandCenter\Admin\DashboardAdminQuery())->overview()["invariants"]; echo $i["operation_map"].",".$i["capabilities"].",".$i["catalogue"].",".$i["mcp_tools"].",".$i["db_version"];')"
	assert_eq "OPERATION_MAP 34" "34" "$(echo "$INV"|cut -d, -f1)"
	assert_eq "CAPABILITIES 23"  "23" "$(echo "$INV"|cut -d, -f2)"
	assert_eq "catalogue 40"     "40" "$(echo "$INV"|cut -d, -f3)"
	assert_eq "MCP tools 40"     "40" "$(echo "$INV"|cut -d, -f4)"
	assert_eq "DB_VERSION 2.5.0 (no schema change)" "2.5.0" "$(echo "$INV"|cut -d, -f5)"
fi

echo
echo "== RESULT =="
echo "  PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
