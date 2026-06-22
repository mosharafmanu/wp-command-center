#!/usr/bin/env bash
#
# SEO Quick Panel — now running on the GENERALIZED Governed Action Panel.
#
# The SEO contextual workflow was migrated onto the reusable, config-driven
# Governed Action Panel (assets/js/wpcc-action-panel.js + the shared runtime
# assets/js/wpcc-admin-runtime.js), enqueued centrally by ActionPanelAssets. The SEO
# row-action anchor opts in via data-wpcc-action="seo"; the SEO config (routes,
# fields, apply shape, strings) lives in ActionPanelAssets::seo_config(). Behavior is
# preserved EXACTLY: progressive enhancement of the admin-post fallback; Generate →
# Review → Edit → Apply → Undo over ONLY the existing routes; persist-before-apply;
# mode-aware Apply; reversibility (Undo) only on a real applied + change_id; ONE
# execution path, ONE rollback path. No new route/operation/capability/MCP tool/schema.
#
# Requires: wp-cli for the functional/invariant section; static checks run regardless.

set -uo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
cd "$PLUGIN_DIR"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
has()  { grep -qF -- "$2" "$3" && pass "$1" || fail "$1 (missing '$2')"; }
lacks(){ grep -qF -- "$2" "$3" && fail "$1 (found '$2')" || pass "$1"; }
wpe() { wp --path="$WP_ROOT" eval "$1" 2>/dev/null; }

SRC="$PLUGIN_DIR/includes/Admin/SeoRowActions.php"
CFG="$PLUGIN_DIR/includes/Admin/ActionPanelAssets.php"
REG="$PLUGIN_DIR/includes/Admin/AiActionRegistry.php"
AAR="$PLUGIN_DIR/includes/Admin/AiAssistRowActions.php"
JS="$PLUGIN_DIR/assets/js/wpcc-action-panel.js"
RT="$PLUGIN_DIR/assets/js/wpcc-admin-runtime.js"
CSS="$PLUGIN_DIR/assets/css/wpcc-action-panel.css"

echo "SEO Quick Panel on the generalized Governed Action Panel"

echo
echo "== 0. Asset files exist =="
[ -f "$JS" ]  && pass "wpcc-action-panel.js present"  || fail "panel JS missing"
[ -f "$RT" ]  && pass "wpcc-admin-runtime.js present" || fail "runtime JS missing"
[ -f "$CSS" ] && pass "wpcc-action-panel.css present" || fail "panel CSS missing"
if command -v node >/dev/null 2>&1; then
	node --check "$JS" >/dev/null 2>&1 && pass "panel JS parses" || fail "panel JS syntax error"
	node --check "$RT" >/dev/null 2>&1 && pass "runtime JS parses" || fail "runtime JS syntax error"
else
	echo "  SKIP: node not available — JS syntax check skipped."
fi

echo
echo "== 1. Consolidated entry: SEO row link folded into ✨ AI Assist; fallback retained =="
# The SEO row link is now the single AI Assist anchor (AiAssistRowActions) bound to the
# panel chooser. SeoRowActions keeps only its nonce-checked no-JS admin-post fallback + bulk.
has  "AI Assist anchor opts into the panel chooser" "data-wpcc-action=\"assist\"" "$AAR"
has  "AI Assist carries the applicable action ids"  "data-actions=\""             "$AAR"
has  "AI Assist offers a no-JS fallback href"       "fallback_url"                "$AAR"
has  "WPCC-branded visible label"                "WPCC AI"                     "$AAR"
has  "SEO panel config (route) lives in the registry" "/admin/seo/generate"       "$REG"
has  "SEO status allow-list in the registry"        "SeoMetaGenerator::is_supported_status" "$REG"
has  "SeoRowActions retains nonce-checked fallback" "check_admin_referer"         "$SRC"
has  "SeoRowActions retains admin-post handler"     "admin_post_"                 "$SRC"
lacks "SeoRowActions no longer emits a per-kind row anchor" "data-wpcc-action="   "$SRC"

echo
echo "== 2. Central enqueue + SEO config reuse existing routes only (ActionPanelAssets) =="
has  "enqueues the generalized panel JS"      "assets/js/wpcc-action-panel.js" "$CFG"
has  "panel depends on the shared runtime"    "wpcc-admin-runtime"            "$CFG"
has  "localizes REST base"                     "rest_url( 'wp-command-center/v1' )" "$CFG"
has  "localizes a wp_rest nonce"               "wp_create_nonce( 'wp_rest' )" "$CFG"
has  "localizes security mode for Apply label" "SecurityModeManager::current()" "$CFG"
has  "reads the AI action registry"            "AiActionRegistry"             "$CFG"
has  "SEO config (route) in the registry"      "/admin/seo/generate"          "$REG"
has  "SEO config gated by build flag (registry)" "WPCC_SEO_META_UI"           "$REG"
has  "SEO config gated by FeatureGate (registry)" "seo_meta_generator"        "$REG"
has  "SEO apply shape is seo_update (registry)" "'seo_update'"                "$REG"
has  "shared unsupportedStatus i18n"           "'unsupportedStatus'"          "$CFG"
has  "Approve & Apply label"                   "'applyDev'"                   "$CFG"
has  "Submit for approval label"               "'applyGate'"                  "$CFG"
has  "approval-required pre-signal"            "'approvalRequired'"           "$CFG"
has  "Undo label"                              "'undo'"                       "$CFG"
lacks "no new REST route in the enqueuer"      "register_rest_route"          "$CFG"

echo
echo "== 3. Governed action — panel JS reuses ONLY existing routes (config-driven) =="
has  "JS uses the config-driven generate path" "ST.cfg.generate.path"         "$JS"
has  "JS GETs existing proposal route"          "/admin/proposals/"           "$JS"
has  "JS PUTs final_payload (persist edit)"     "api( 'PUT', '/admin/proposals/" "$JS"
has  "JS POSTs existing apply route"            "/apply"                       "$JS"
has  "JS POSTs existing rollback route"         "/history/"                    "$JS"
has  "JS final_payload-first read"              "final_payload"               "$JS"
has  "JS keeps Open in Suggestions nav"         "suggestUrl"                   "$JS"
has  "JS handles unsupported_status skip"       "unsupported_status"           "$JS"
has  "runtime sends the wp_rest nonce header"   "X-WP-Nonce"                   "$RT"
lacks "JS never calls dismiss route"            "/dismiss"                     "$JS"
lacks "JS no admin-ajax usage"                  "admin-ajax"                   "$JS"

echo
echo "== 4. Apply is mode-aware, persist-before-apply, single governed path (JS) =="
has  "persist-before-apply (never apply stale)" "never apply stale data"      "$JS"
has  "mode-aware Apply label"                   "IS_DEV ? t( 'applyDev' )"     "$JS"
has  "reads security mode from config"          "ROOT.mode"                    "$JS"
has  "approval-required pre-signal (gated)"     "approvalRequired"             "$JS"
has  "outcome read from response status"        "st === 'applied'"             "$JS"
has  "gated outcome = pending_approval"         "pending_approval"             "$JS"
has  "Undo only with a change_id"               "applied && !! changeId"       "$JS"
has  "Undo reuses change_history rollback"      "/rollback"                    "$JS"
lacks "JS no direct seo_manage execution"       "seo_manage"                   "$JS"
lacks "JS no OperationExecutor reference"       "OperationExecutor"            "$JS"
lacks "JS no direct provider write"             "SeoProvider"                  "$JS"

echo
echo "== 5. Accessibility hooks present (JS + runtime) =="
has  "dialog role"                              "role: 'dialog'"               "$JS"
has  "aria-modal"                               "'aria-modal': 'true'"         "$JS"
has  "labelled by title"                        "aria-labelledby"             "$JS"
has  "status live region"                       "role: 'status'"              "$JS"
has  "focus trap on Tab (via runtime)"          "R.a11y.trapTab"               "$JS"
has  "runtime implements trapTab"               "trapTab:"                     "$RT"
has  "ESC closes"                               "'Escape'"                     "$JS"
has  "focus restore to trigger"                 "lastFocus.focus()"            "$JS"
has  "reduced-motion respected (CSS)"           "prefers-reduced-motion"       "$CSS"
has  "mobile bottom-sheet (CSS)"                "wpcc-qp-sheet-in"             "$CSS"

echo
echo "== 6. Functional: central enqueue gating matrix (no asset leak) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$aa  = new \WPCommandCenter\Admin\ActionPanelAssets();
		$admin = get_users(["role"=>"administrator","number"=>1]); $aid = $admin?$admin[0]->ID:1;
		// Isolate the SEO workflow: force the OTHER UI flags off (priority 99 wins over
		// any dev mu-plugin enablers) so this matrix tests SEO gating alone regardless
		// of which Builders the dev env happens to enable.
		add_filter("wpcc_ai_content_ui","__return_false",99);
		add_filter("wpcc_alt_text_ui","__return_false",99);
		$enq = function() { return wp_script_is( "wpcc-action-panel", "enqueued" ); };
		$reset = function() {
			foreach (["wpcc-action-panel","wpcc-admin-runtime"] as $h) { wp_dequeue_script($h); wp_deregister_script($h); }
			foreach (["wpcc-action-panel","wpcc-tokens"] as $h) { wp_dequeue_style($h); wp_deregister_style($h); }
		};
		set_current_screen( "edit-post" );

		// (a) admin + SEO flag on + supported screen -> enqueued.
		add_filter("wpcc_seo_meta_ui","__return_true");
		wp_set_current_user($aid);
		$reset(); $aa->enqueue("edit.php");
		$out["enqueued_when_allowed"] = $enq() ? 1 : 0;

		// (b) wrong hook -> not enqueued.
		$reset(); $aa->enqueue("index.php");
		$out["wrong_hook_absent"] = $enq() ? 0 : 1;

		// (c) all build flags OFF -> not enqueued.
		remove_filter("wpcc_seo_meta_ui","__return_true");
		$reset(); $aa->enqueue("edit.php");
		$out["flag_off_absent"] = $enq() ? 0 : 1;
		add_filter("wpcc_seo_meta_ui","__return_true");

		// (d) FeatureGate OFF for seo (content flag default off) -> not enqueued.
		$gate_off = function($allowed,$feature){ return $feature==="seo_meta_generator" ? false : $allowed; };
		add_filter("wpcc_feature_allowed",$gate_off,10,2);
		$reset(); $aa->enqueue("edit.php");
		$out["featuregate_off_absent"] = $enq() ? 0 : 1;
		remove_filter("wpcc_feature_allowed",$gate_off,10);

		// (e) subscriber (no cap) -> not enqueued.
		$sub = wp_insert_user(["user_login"=>"qp_sub_".wp_generate_password(5,false),"user_pass"=>wp_generate_password(),"role"=>"subscriber"]);
		wp_set_current_user($sub);
		$reset(); $aa->enqueue("edit.php");
		$out["subscriber_absent"] = $enq() ? 0 : 1;
		wp_set_current_user($aid);

		// (f) unsupported screen type -> not enqueued.
		set_current_screen( "edit-wpcc_unsupported_type" );
		$reset(); $aa->enqueue("edit.php");
		$out["unsupported_type_absent"] = $enq() ? 0 : 1;

		$reset(); if (isset($sub)) { wp_delete_user($sub); }
		remove_filter("wpcc_seo_meta_ui","__return_true");
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "admin + flag-on + edit.php(post) -> enqueued" "1" "$(gj enqueued_when_allowed)"
	assert_eq "wrong hook -> not enqueued"                   "1" "$(gj wrong_hook_absent)"
	assert_eq "all build-flags OFF -> not enqueued"          "1" "$(gj flag_off_absent)"
	assert_eq "FeatureGate OFF -> not enqueued"              "1" "$(gj featuregate_off_absent)"
	assert_eq "subscriber (no cap) -> not enqueued"          "1" "$(gj subscriber_absent)"
	assert_eq "unsupported screen type -> not enqueued"      "1" "$(gj unsupported_type_absent)"
fi

echo
echo "== 7. Invariants unchanged (no new route/op/cap/tool/schema) =="
if command -v wp >/dev/null 2>&1; then
	assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
	assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
	assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
	assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"
else
	echo "  SKIP: wp-cli not available — invariant checks skipped."
fi

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
