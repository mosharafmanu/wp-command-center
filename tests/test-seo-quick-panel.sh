#!/usr/bin/env bash
#
# Contextual SEO Quick Panel (Option B → Initiative 2 Option B) — in-context governed
# action surface.
#
# Asserts the Quick Panel is a PROGRESSIVE ENHANCEMENT of the deployed row action:
# the <a> keeps its admin-post redirect (no-JS fallback) and gains the enhancement
# hooks (class + data-*). The modal now carries one item through its FULL governed
# lifecycle (Generate → Review → Edit → Apply → Undo) using ONLY the EXISTING routes:
#   POST /admin/seo/generate · GET /admin/proposals/{id} · PUT /admin/proposals/{id}
#   · POST /admin/proposals/{id}/apply · POST /admin/history/{change_id}/rollback.
# Apply PERSISTS the visible edited values (PUT final_payload) BEFORE applying
# (persist-before-apply), is MODE-AWARE (developer applies; client/enterprise submit
# for approval), and shows reversibility (Undo) only on a real applied + change_id.
# There is NO second execution path and NO second rollback path. The PHP side adds NO
# route / operation / capability / MCP tool / schema. Invariants hold 34/23/40/40/2.5.0.
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
JS="$PLUGIN_DIR/assets/js/seo-quick-panel.js"
CSS="$PLUGIN_DIR/assets/css/seo-quick-panel.css"

echo "Contextual SEO Quick Panel (Option B)"

echo
echo "== 0. Asset files exist =="
[ -f "$JS" ]  && pass "seo-quick-panel.js present"  || fail "seo-quick-panel.js missing"
[ -f "$CSS" ] && pass "seo-quick-panel.css present" || fail "seo-quick-panel.css missing"
if command -v node >/dev/null 2>&1; then
	node --check "$JS" >/dev/null 2>&1 && pass "JS parses (node --check)" || fail "JS syntax error"
else
	echo "  SKIP: node not available — JS syntax check skipped."
fi

echo
echo "== 1. Progressive enhancement: anchor keeps fallback + gains hooks =="
has  "row action still nonce-protected href"  "wp_nonce_url"                  "$SRC"
has  "row action still admin-post fallback"   "admin-post.php?action="        "$SRC"
has  "anchor carries quickgen class"          "wpcc-seo-quickgen"             "$SRC"
has  "anchor carries data-id"                 "data-id=\""                    "$SRC"
has  "anchor carries data-type"               "data-type=\""                  "$SRC"

echo
echo "== 2. Enqueue: scoped + gated, reuses existing routes only =="
has  "registers admin_enqueue_scripts"        "add_action( 'admin_enqueue_scripts', [ \$this, 'enqueue_assets' ] )" "$SRC"
has  "enqueue method present"                 "function enqueue_assets"       "$SRC"
has  "only on edit.php"                        "'edit.php' !== \$hook"        "$SRC"
has  "scoped to supported post type"          "supported_type( \$ptype )"     "$SRC"
has  "gated by allowed() (cap+flag+gate)"     "\$this->allowed()"             "$SRC"
has  "enqueues the JS asset"                   "assets/js/seo-quick-panel.js" "$SRC"
has  "enqueues the CSS asset"                  "assets/css/seo-quick-panel.css" "$SRC"
has  "localizes REST base"                     "rest_url( 'wp-command-center/v1' )" "$SRC"
has  "localizes a wp_rest nonce"               "wp_create_nonce( 'wp_rest' )" "$SRC"
has  "localizes unsupportedStatus i18n"        "'unsupportedStatus'"          "$SRC"
has  "localizes security mode for Apply label" "SecurityModeManager::current()" "$SRC"
has  "localizes Approve & Apply label"         "'applyDev'"                   "$SRC"
has  "localizes Submit for approval label"     "'applyGate'"                  "$SRC"
has  "localizes approval-required pre-signal"  "'approvalRequired'"           "$SRC"
has  "localizes Undo label"                    "'undo'"                       "$SRC"
# Status allow-list shared with the row action: the panel trigger (the quickgen
# anchor) renders for editable statuses, so the modal opens for draft/pending/etc.
has  "row action gates on shared status allow-list" "SeoMetaGenerator::is_supported_status" "$SRC"
lacks "no stale published-only gate"           "'publish' !== \$post->post_status" "$SRC"

echo
echo "== 3. Drafts only — no apply / rollback / new surface (PHP) =="
lacks "no ProposalApplyService"               "ProposalApplyService"          "$SRC"
lacks "no OperationExecutor"                   "OperationExecutor"            "$SRC"
lacks "no direct SEO write"                    "SeoProvider::write"           "$SRC"
lacks "no apply call"                          "->apply("                     "$SRC"
lacks "no rollback call"                       "->rollback("                  "$SRC"
lacks "no new REST route"                       "register_rest_route"         "$SRC"

echo
echo "== 4. Governed action — JS reuses ONLY the existing routes =="
has  "JS POSTs to existing generate route"     "/admin/seo/generate"          "$JS"
has  "JS GETs existing proposal route"          "/admin/proposals/"           "$JS"
has  "JS PUTs final_payload (persist edit)"     "api( 'PUT', '/admin/proposals/" "$JS"
has  "JS POSTs existing apply route"            "/apply"                       "$JS"
has  "JS POSTs existing rollback route"         "/history/"                    "$JS"
has  "JS sends the wp_rest nonce header"        "X-WP-Nonce"                   "$JS"
has  "JS final_payload-first read"              "final_payload"               "$JS"
has  "JS keeps Open in Suggestions nav"         "suggestUrl"                   "$JS"
has  "JS handles unsupported_status skip"       "unsupported_status"           "$JS"
lacks "JS no stale not_published reason"        "not_published"                "$JS"
lacks "JS never calls dismiss route"            "/dismiss"                     "$JS"
lacks "JS no admin-ajax usage"                  "admin-ajax"                   "$JS"

echo
echo "== 4b. Apply is mode-aware, persist-before-apply, single governed path (JS) =="
# persist-before-apply: PUT final_payload happens, then /apply — never apply stale data.
has  "persist-before-apply (PUT then apply)"    "do NOT apply stale data"      "$JS"
has  "mode-aware Apply label"                   "IS_DEV ? t( 'applyDev' )"     "$JS"
has  "reads security mode from CFG"             "CFG.mode"                     "$JS"
has  "approval-required pre-signal (gated)"     "approvalRequired"             "$JS"
has  "outcome read from response status"        "=== 'applied'"               "$JS"
has  "gated outcome = pending_approval"         "pending_approval"             "$JS"
has  "Undo only with a change_id"               "applied && !! changeId"       "$JS"
has  "Undo reuses change_history rollback"      "/rollback"                    "$JS"
# Single execution + single rollback path: the modal must NOT bypass the engine.
lacks "JS no direct seo_manage execution"       "seo_manage"                   "$JS"
lacks "JS no OperationExecutor reference"       "OperationExecutor"            "$JS"
lacks "JS no direct SEO provider write"         "SeoProvider"                  "$JS"
assert_eq "exactly one rollback route in JS"    "1" "$(grep -c "/history/' + encodeURIComponent" "$JS")"
assert_eq "exactly one apply route in JS"       "1" "$(grep -c "/apply', null" "$JS")"

echo
echo "== 5. Accessibility hooks present (JS) =="
has  "dialog role"                              "role: 'dialog'"               "$JS"
has  "aria-modal"                               "'aria-modal': 'true'"         "$JS"
has  "labelled by title"                        "aria-labelledby"             "$JS"
has  "status live region"                       "role: 'status'"              "$JS"
has  "focus trap on Tab"                         "trapTab"                     "$JS"
has  "ESC closes"                                "'Escape'"                    "$JS"
has  "focus restore to trigger"                  "lastFocus.focus()"          "$JS"
has  "reduced-motion respected (CSS)"            "prefers-reduced-motion"     "$CSS"

echo
echo "== 6. Functional: enqueue gating matrix (no asset leak) =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$out = [];
		$ra  = new \WPCommandCenter\Admin\SeoRowActions();
		$admin = get_users(["role"=>"administrator","number"=>1]); $aid = $admin?$admin[0]->ID:1;

		$enqueued = function() { return wp_script_is( "wpcc-seo-quick-panel", "enqueued" ); };
		$reset = function() { wp_dequeue_script("wpcc-seo-quick-panel"); wp_dequeue_style("wpcc-seo-quick-panel"); wp_deregister_script("wpcc-seo-quick-panel"); wp_deregister_style("wpcc-seo-quick-panel"); };

		// Force an edit.php screen for a supported type (post).
		set_current_screen( "edit-post" );

		// (a) admin + flag on + supported screen -> enqueued.
		add_filter("wpcc_seo_meta_ui","__return_true");
		wp_set_current_user($aid);
		$reset(); $ra->enqueue_assets("edit.php");
		$out["enqueued_when_allowed"] = $enqueued() ? 1 : 0;

		// (b) wrong hook -> not enqueued.
		$reset(); $ra->enqueue_assets("index.php");
		$out["wrong_hook_absent"] = $enqueued() ? 0 : 1;

		// (c) build-flag OFF -> not enqueued.
		remove_filter("wpcc_seo_meta_ui","__return_true");
		$reset(); $ra->enqueue_assets("edit.php");
		$out["flag_off_absent"] = $enqueued() ? 0 : 1;
		add_filter("wpcc_seo_meta_ui","__return_true");

		// (d) FeatureGate OFF -> not enqueued.
		$gate_off = function($allowed,$feature){ return $feature==="seo_meta_generator" ? false : $allowed; };
		add_filter("wpcc_feature_allowed",$gate_off,10,2);
		$reset(); $ra->enqueue_assets("edit.php");
		$out["featuregate_off_absent"] = $enqueued() ? 0 : 1;
		remove_filter("wpcc_feature_allowed",$gate_off,10);

		// (e) subscriber (no cap) -> not enqueued.
		$sub = wp_insert_user(["user_login"=>"qp_sub_".wp_generate_password(5,false),"user_pass"=>wp_generate_password(),"role"=>"subscriber"]);
		wp_set_current_user($sub);
		$reset(); $ra->enqueue_assets("edit.php");
		$out["subscriber_absent"] = $enqueued() ? 0 : 1;
		wp_set_current_user($aid);

		// (f) unsupported screen type -> not enqueued.
		set_current_screen( "edit-wpcc_unsupported_type" );
		$reset(); $ra->enqueue_assets("edit.php");
		$out["unsupported_type_absent"] = $enqueued() ? 0 : 1;

		$reset(); if (isset($sub)) { wp_delete_user($sub); }
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "admin + flag-on + edit.php(post) -> enqueued" "1" "$(gj enqueued_when_allowed)"
	assert_eq "wrong hook -> not enqueued"                   "1" "$(gj wrong_hook_absent)"
	assert_eq "build-flag OFF -> not enqueued"               "1" "$(gj flag_off_absent)"
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
