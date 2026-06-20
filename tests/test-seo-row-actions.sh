#!/usr/bin/env bash
#
# Contextual SEO entry points — Posts/Pages/Products row actions (Sprint A).
#
# Asserts the "Generate SEO Suggestion" row action creates a governed DRAFT only,
# via the EXISTING SeoMetaGenerator, and redirects to SEO Meta → Suggestions. It
# NEVER applies, NEVER writes SEO meta, NEVER bypasses approval/rollback/audit/
# capability scoping, and adds NO route/operation/capability/MCP tool/schema. Gated
# by capability + FeatureGate + the SEO Meta UI build flag; nonce-protected handler.
#
# Requires: wp-cli (functional section); static checks run regardless.

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
BOOT="$PLUGIN_DIR/includes/Core/Plugin.php"
VIEW="$PLUGIN_DIR/includes/Admin/views/seo-meta.php"

echo "Contextual SEO entry points — row actions"

echo
echo "== 1. SeoRowActions: thin admin wiring, propose-only =="
has  "registers post row action"            "add_filter( 'post_row_actions'"  "$SRC"
has  "registers page row action"            "add_filter( 'page_row_actions'"  "$SRC"
has  "admin_post handler"                    "admin_post_' . self::ACTION"    "$SRC"
has  "row action label"                      "Generate SEO Suggestion"        "$SRC"
has  "nonce on the URL"                      "wp_nonce_url"                   "$SRC"
has  "nonce verified in handler"             "check_admin_referer"            "$SRC"
has  "capability gate"                       "current_user_can( 'manage_options' )" "$SRC"
has  "FeatureGate gate"                      "FeatureGate::allows( self::FEATURE )" "$SRC"
has  "build-flag gate (const)"               "WPCC_SEO_META_UI"               "$SRC"
has  "build-flag gate (filter)"              "wpcc_seo_meta_ui"               "$SRC"
has  "Products only when Woo active"         "class_exists( 'WooCommerce' )"  "$SRC"
has  "calls existing generator"              "SeoMetaGenerator() )->generate" "$SRC"
has  "redirects to SEO Meta menu"            "'page' => self::MENU"           "$SRC"
has  "lands on Suggestions tab"              "'tab' => 'suggestions'"         "$SRC"
has  "passes result code"                    "'wpcc_seo_gen'"                 "$SRC"
# Propose != Apply: NO apply / rollback / executor / direct write / new route.
lacks "no ProposalApplyService"              "ProposalApplyService"           "$SRC"
lacks "no OperationExecutor"                 "OperationExecutor"              "$SRC"
lacks "no direct SEO write"                  "SeoProvider::write"             "$SRC"
lacks "no apply call"                        "->apply("                       "$SRC"
lacks "no rollback call"                     "->rollback("                    "$SRC"
lacks "no REST route"                        "register_rest_route"            "$SRC"

echo
echo "== 2. Bootstrap + view wiring =="
has  "wired in admin bootstrap"              "new \\WPCommandCenter\\Admin\\SeoRowActions() )->init()" "$BOOT"
has  "view shows entry notice"               "wpcc-seo-entry-notice"          "$VIEW"
has  "view reads result code"                "wpcc_seo_gen"                   "$VIEW"
has  "view lands on Suggestions"             "switchTab( 'suggestions' )"     "$VIEW"

echo
echo "== 3. Functional: visibility matrix + propose-only round-trip =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$ra = new \WPCommandCenter\Admin\SeoRowActions();
		$out = [];
		$has_action = function( $actions ) { return isset( $actions["wpcc_seo_generate"] ) && strpos( $actions["wpcc_seo_generate"], "Generate SEO Suggestion" ) !== false; };

		$admin = get_users(["role"=>"administrator","number"=>1]); $aid = $admin?$admin[0]->ID:1;
		$pub = wp_insert_post(["post_title"=>"RA pub","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		$pubpost = get_post($pub);
		$draftpost = get_post( wp_insert_post(["post_title"=>"RA draft","post_status"=>"draft","post_type"=>"post","post_content"=>"x"]) );

		// Build-flag ON for the allowed cases.
		add_filter("wpcc_seo_meta_ui","__return_true");

		// (a) admin + flag-on + published -> action present.
		wp_set_current_user($aid);
		$out["admin_published"] = $has_action( $ra->add_row_action( [], $pubpost ) ) ? 1 : 0;

		// (b) non-published -> absent.
		$out["non_published_absent"] = $has_action( $ra->add_row_action( [], $draftpost ) ) ? 0 : 1;

		// (c) unsupported post type -> absent.
		$unsupp = clone $pubpost; $unsupp->post_type = "wpcc_unsupported_type";
		$out["unsupported_type_absent"] = $has_action( $ra->add_row_action( [], $unsupp ) ) ? 0 : 1;

		// (d) FeatureGate OFF -> absent.
		$gate_off = function($allowed,$feature){ return $feature==="seo_meta_generator" ? false : $allowed; };
		add_filter("wpcc_feature_allowed",$gate_off,10,2);
		$out["featuregate_off_absent"] = $has_action( $ra->add_row_action( [], $pubpost ) ) ? 0 : 1;
		remove_filter("wpcc_feature_allowed",$gate_off,10);

		// (e) non-admin (subscriber) -> absent.
		$sub = wp_insert_user(["user_login"=>"ra_sub_".wp_generate_password(5,false),"user_pass"=>wp_generate_password(),"role"=>"subscriber"]);
		wp_set_current_user($sub);
		$out["subscriber_absent"] = $has_action( $ra->add_row_action( [], $pubpost ) ) ? 0 : 1;
		wp_set_current_user($aid);

		// (f) build-flag OFF -> absent.
		remove_filter("wpcc_seo_meta_ui","__return_true");
		$out["flag_off_absent"] = $has_action( $ra->add_row_action( [], $pubpost ) ) ? 0 : 1;
		add_filter("wpcc_seo_meta_ui","__return_true");

		// --- Propose-only round-trip via the EXISTING generator (stub provider, no network) ---
		// Mirrors test-seo-generate.sh: prove the handler core creates a DRAFT and writes NO meta.
		if ( \WPCommandCenter\Operations\SeoProvider::NONE !== \WPCommandCenter\Operations\SeoProvider::detect() ) {
			$prov = \WPCommandCenter\Operations\SeoProvider::detect();
			$store = new \WPCommandCenter\Proposals\ProposalStore();
			$okStub = new class implements \WPCommandCenter\Seo\SeoMetaProvider {
				public function id(): string { return "stub"; }
				public function is_configured(): bool { return true; }
				public function suggest_meta(array $c, array $x=[]): \WPCommandCenter\Seo\SeoMetaResult { return \WPCommandCenter\Seo\SeoMetaResult::ok("Row Action Title","Row action description long enough to be a realistic meta description for this test case.","stub","stub-model"); }
			};
			$resolver = new class($okStub) extends \WPCommandCenter\Seo\SeoMetaProviderResolver { private $p; public function __construct($p){ $this->p=$p; } public function active(): ?\WPCommandCenter\Seo\SeoMetaProvider { return $this->p; } };

			$before = \WPCommandCenter\Operations\SeoProvider::read($pub, $prov);
			$gen = new \WPCommandCenter\Seo\SeoMetaGenerator($store, $resolver);
			$env1 = $gen->generate([$pub], ["actor"=>["wp_user_id"=>$aid,"source"=>"admin_ui"]]);
			$out["draft_created"] = ( !empty($env1["created"]) ) ? 1 : 0;
			$pid_created = $env1["created"][0] ?? "";
			$row = $pid_created ? $store->get($pid_created) : null;
			$out["status_draft"] = ( $row && ($row["status"]??"")==="draft" && ($row["operation_id"]??"")==="seo_manage" ) ? 1 : 0;
			$after = \WPCommandCenter\Operations\SeoProvider::read($pub, $prov);
			$out["no_meta_written"] = ( ($before["title"]??"") === ($after["title"]??"") && ($before["description"]??"") === ($after["description"]??"") ) ? 1 : 0;
			// Duplicate prevention: second generate -> has_open_proposal skip, no new draft.
			$env2 = $gen->generate([$pub], ["actor"=>[]]);
			$out["dup_skipped"] = ( empty($env2["created"]) && (($env2["skipped"][0]["reason"]??"")==="has_open_proposal") ) ? 1 : 0;
			// cleanup the draft row
			global $wpdb; if ($pid_created) { $wpdb->query($wpdb->prepare("DELETE FROM ".$wpdb->prefix."wpcc_proposals WHERE proposal_id=%s",$pid_created)); }
			$out["seo_active"] = 1;
		} else { $out["seo_active"] = 0; }

		// cleanup
		wp_delete_post($pub, true); wp_delete_post($draftpost->ID, true); if (isset($sub)) { wp_delete_user($sub); }
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "admin + flag-on + published -> action present" "1" "$(gj admin_published)"
	assert_eq "non-published -> action absent"                "1" "$(gj non_published_absent)"
	assert_eq "unsupported post type -> action absent"        "1" "$(gj unsupported_type_absent)"
	assert_eq "FeatureGate OFF -> action absent"              "1" "$(gj featuregate_off_absent)"
	assert_eq "subscriber (no cap) -> action absent"          "1" "$(gj subscriber_absent)"
	assert_eq "build-flag OFF -> action absent"               "1" "$(gj flag_off_absent)"
	if [ "$(gj seo_active)" = "1" ]; then
		assert_eq "row-action generate creates a DRAFT"       "1" "$(gj draft_created)"
		assert_eq "draft is seo_manage/status=draft"          "1" "$(gj status_draft)"
		assert_eq "NO SEO meta written (propose-only)"        "1" "$(gj no_meta_written)"
		assert_eq "duplicate prevented (has_open_proposal)"   "1" "$(gj dup_skipped)"
	else
		echo "  NOTE: no SEO plugin active — propose-only round-trip skipped."
	fi
fi

echo
echo "== 4. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
