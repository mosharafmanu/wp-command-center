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
AAR="$PLUGIN_DIR/includes/Admin/AiAssistRowActions.php"
BOOT="$PLUGIN_DIR/includes/Core/Plugin.php"
VIEW="$PLUGIN_DIR/includes/Admin/views/seo-meta.php"

echo "Contextual SEO entry points — row actions"

echo
echo "== 1. SeoRowActions: retains no-JS fallback + bulk (row link consolidated) =="
# The contextual SEO row link is now the consolidated ✨ AI Assist anchor
# (AiAssistRowActions); SeoRowActions keeps only its nonce-checked admin-post fallback.
has  "AI Assist anchor present"              "wpcc_ai_assist"                 "$AAR"
has  "AI Assist offers SEO via the registry" "fallback_url"                   "$AAR"
has  "admin_post fallback handler retained"  "admin_post_' . self::ACTION"    "$SRC"
has  "nonce verified in handler"             "check_admin_referer"            "$SRC"
lacks "row link consolidated (no per-kind anchor)" "data-wpcc-action"         "$SRC"
has  "capability gate"                       "current_user_can( 'manage_options' )" "$SRC"
has  "FeatureGate gate"                      "FeatureGate::allows( self::FEATURE )" "$SRC"
has  "build-flag gate (const)"               "WPCC_SEO_META_UI"               "$SRC"
has  "build-flag gate (filter)"              "wpcc_seo_meta_ui"               "$SRC"
has  "Products only when Woo active"         "class_exists( 'WooCommerce' )"  "$SRC"
has  "calls existing generator"              "make_generator()->generate"     "$SRC"
has  "generator is SeoMetaGenerator"         "new SeoMetaGenerator()"          "$SRC"
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
		// The SEO contextual entry is now the consolidated AI Assist anchor. "Has the
		// SEO action" = the single anchor exists AND lists "seo" in data-actions.
		$ra = new \WPCommandCenter\Admin\AiAssistRowActions();
		$out = [];
		$has_action = function( $actions ) {
			if ( ! isset( $actions["wpcc_ai_assist"] ) ) { return false; }
			if ( ! preg_match( "/data-actions=\"([^\"]*)\"/", $actions["wpcc_ai_assist"], $m ) ) { return false; }
			return in_array( "seo", explode( ",", $m[1] ), true );
		};

		$admin = get_users(["role"=>"administrator","number"=>1]); $aid = $admin?$admin[0]->ID:1;
		$pub = wp_insert_post(["post_title"=>"RA pub","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]);
		$pubpost = get_post($pub);

		// Build-flag ON for the allowed cases.
		add_filter("wpcc_seo_meta_ui","__return_true");

		// (a) admin + flag-on + published -> action present.
		wp_set_current_user($aid);
		$out["admin_published"] = $has_action( $ra->add( [], $pubpost ) ) ? 1 : 0;

		// (b) status allow-list (clone + override status — deterministic, no WP coercion).
		// Allowed: publish/draft/pending/future/private -> present.
		$allowed_ok = 1;
		foreach (["publish","draft","pending","future","private"] as $st) {
			$p = clone $pubpost; $p->post_status = $st;
			if ( ! $has_action( $ra->add( [], $p ) ) ) { $allowed_ok = 0; }
		}
		$out["allowed_statuses_present"] = $allowed_ok;
		// Disallowed: trash/auto-draft/inherit (revisions, attachments) -> absent.
		$blocked_ok = 1;
		foreach (["trash","auto-draft","inherit"] as $st) {
			$p = clone $pubpost; $p->post_status = $st;
			if ( $has_action( $ra->add( [], $p ) ) ) { $blocked_ok = 0; }
		}
		$out["blocked_statuses_absent"] = $blocked_ok;

		// (c) unsupported post type -> absent.
		$unsupp = clone $pubpost; $unsupp->post_type = "wpcc_unsupported_type";
		$out["unsupported_type_absent"] = $has_action( $ra->add( [], $unsupp ) ) ? 0 : 1;

		// (d) FeatureGate OFF -> absent.
		$gate_off = function($allowed,$feature){ return $feature==="seo_meta_generator" ? false : $allowed; };
		add_filter("wpcc_feature_allowed",$gate_off,10,2);
		$out["featuregate_off_absent"] = $has_action( $ra->add( [], $pubpost ) ) ? 0 : 1;
		remove_filter("wpcc_feature_allowed",$gate_off,10);

		// (e) non-admin (subscriber) -> absent.
		$sub = wp_insert_user(["user_login"=>"ra_sub_".wp_generate_password(5,false),"user_pass"=>wp_generate_password(),"role"=>"subscriber"]);
		wp_set_current_user($sub);
		$out["subscriber_absent"] = $has_action( $ra->add( [], $pubpost ) ) ? 0 : 1;
		wp_set_current_user($aid);

		// (f) build-flag OFF -> absent.
		remove_filter("wpcc_seo_meta_ui","__return_true");
		$out["flag_off_absent"] = $has_action( $ra->add( [], $pubpost ) ) ? 0 : 1;
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
		wp_delete_post($pub, true); if (isset($sub)) { wp_delete_user($sub); }
		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "admin + flag-on + published -> action present" "1" "$(gj admin_published)"
	assert_eq "allowed statuses (publish/draft/pending/future/private) -> present" "1" "$(gj allowed_statuses_present)"
	assert_eq "disallowed statuses (trash/auto-draft/inherit) -> absent"          "1" "$(gj blocked_statuses_absent)"
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
echo "== 4. Bulk actions (Sprint B): registration, gating, MAX_BATCH, propose-only =="
has  "registers bulk_actions filter"        "bulk_actions-edit-{\$type}"      "$SRC"
has  "registers handle_bulk_actions filter" "handle_bulk_actions-edit-{\$type}" "$SRC"
has  "add_bulk_action method"               "function add_bulk_action"        "$SRC"
has  "handle_bulk method"                    "function handle_bulk"           "$SRC"
has  "bulk action label"                     "Generate SEO Suggestions"       "$SRC"
has  "enforces MAX_BATCH cap"                "count( \$ids ) > SeoMetaGenerator::MAX_BATCH" "$SRC"
has  "bulk capability gate"                  "current_user_can( 'manage_options' )" "$SRC"
has  "bulk FeatureGate gate"                 "FeatureGate::allows( self::FEATURE )" "$SRC"
has  "bulk redirects to Suggestions"         "'wpcc_seo_bulk' => 1"           "$SRC"
has  "bulk reuses the generator"             "make_generator()->generate( \$ids" "$SRC"
lacks "bulk: no apply"                       "->apply("                       "$SRC"
lacks "bulk: no rollback"                    "->rollback("                    "$SRC"
has  "view shows bulk summary notice"        "showBulkNotice"                 "$VIEW"
has  "view reads wpcc_seo_bulk"              "wpcc_seo_bulk"                  "$VIEW"

if command -v wp >/dev/null 2>&1; then
	BRES="$(wpe '
		$out = [];
		$admin = get_users(["role"=>"administrator","number"=>1]); $aid = $admin?$admin[0]->ID:1;
		add_filter("wpcc_seo_meta_ui","__return_true"); wp_set_current_user($aid);

		// Test subclass injects a stub-provider generator (deterministic; no network).
		$ra = new class extends \WPCommandCenter\Admin\SeoRowActions {
			public $genFactory;
			protected function make_generator(): \WPCommandCenter\Seo\SeoMetaGenerator { return ($this->genFactory)(); }
		};
		$okStub = new class implements \WPCommandCenter\Seo\SeoMetaProvider {
			public function id(): string { return "stub"; }
			public function is_configured(): bool { return true; }
			public function suggest_meta(array $c, array $x=[]): \WPCommandCenter\Seo\SeoMetaResult { return \WPCommandCenter\Seo\SeoMetaResult::ok("Bulk Title","Bulk description long enough to be a realistic meta description for this generated test proposal.","stub","stub-model"); }
		};
		$resolver = new class($okStub) extends \WPCommandCenter\Seo\SeoMetaProviderResolver { private $p; public function __construct($p){ $this->p=$p; } public function active(): ?\WPCommandCenter\Seo\SeoMetaProvider { return $this->p; } };
		$store = new \WPCommandCenter\Proposals\ProposalStore();
		$ra->genFactory = function() use ($store,$resolver){ return new \WPCommandCenter\Seo\SeoMetaGenerator($store,$resolver); };

		$seoActive = ( \WPCommandCenter\Operations\SeoProvider::NONE !== \WPCommandCenter\Operations\SeoProvider::detect() );
		$out["seo_active"] = $seoActive ? 1 : 0;

		// Dropdown registration (admin + flag) -> action present with label.
		$acts = $ra->add_bulk_action( [] );
		$out["bulk_action_present"] = ( isset($acts["wpcc_seo_generate"]) && $acts["wpcc_seo_generate"]==="Generate SEO Suggestions" ) ? 1 : 0;

		// Wrong action -> redirect unchanged.
		$out["wrong_action_passthrough"] = ( $ra->handle_bulk("HOME","not_mine",[1,2]) === "HOME" ) ? 1 : 0;

		if ( $seoActive ) {
			// MAX_BATCH: 27 published posts -> generator caps to 25; 25 drafts created.
			$pids = [];
			for ($i=0;$i<27;$i++){ $pids[] = wp_insert_post(["post_title"=>"Bulk RA ".$i,"post_status"=>"publish","post_type"=>"post","post_content"=>"x"]); }
			$url = $ra->handle_bulk("HOME","wpcc_seo_generate",$pids);
			parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
			$out["redirect_to_suggestions"] = ( ($q["page"]??"")==="wpcc-seo" && ($q["tab"]??"")==="suggestions" && ($q["wpcc_seo_bulk"]??"")=="1" ) ? 1 : 0;
			$out["cap_25_created"]   = ( (int)($q["c"]??0) === 25 ) ? 1 : 0;   // 27 selected, only 25 processed
			$out["overflow_dropped"] = ( (int)($q["c"]??0) + (int)($q["s"]??0) + (int)($q["f"]??0) <= 25 ) ? 1 : 0;
			// Verify drafts actually created in the store + no SEO meta written to a post.
			$drafts = $store->count(["operation_id"=>"seo_manage","status"=>"draft"]);
			$out["drafts_exist"] = ( $drafts >= 25 ) ? 1 : 0;
			$meta = \WPCommandCenter\Operations\SeoProvider::read($pids[0], \WPCommandCenter\Operations\SeoProvider::detect());
			$out["no_meta_written"] = ( ($meta["title"]??"") === "" ) ? 1 : 0; // generator never writes meta

			// Duplicate prevention on a second bulk over the same ids.
			$url2 = $ra->handle_bulk("HOME","wpcc_seo_generate",$pids);
			parse_str((string)parse_url($url2, PHP_URL_QUERY), $q2);
			$out["dup_all_skipped"] = ( (int)($q2["c"]??-1)===0 && ($q2["r"]??"")==="has_open_proposal" ) ? 1 : 0;

			// Mixed-status bulk: 1 draft (allowed -> created) + 1 trashed (disallowed
			// -> skipped). Aggregate counts must reflect created=1, skipped>=1.
			$mixDraft = wp_insert_post(["post_title"=>"Bulk mix draft","post_status"=>"draft","post_type"=>"post","post_content"=>"x"]);
			$mixTrash = wp_insert_post(["post_title"=>"Bulk mix trash","post_status"=>"publish","post_type"=>"post","post_content"=>"x"]); wp_trash_post($mixTrash);
			$urlm = $ra->handle_bulk("HOME","wpcc_seo_generate",[$mixDraft,$mixTrash]);
			parse_str((string)parse_url($urlm, PHP_URL_QUERY), $qm);
			$out["mixed_draft_created"] = ( (int)($qm["c"]??0) === 1 ) ? 1 : 0;
			$out["mixed_trash_skipped"] = ( (int)($qm["s"]??0) >= 1 ) ? 1 : 0;

			// cleanup: drafts for these posts + the posts.
			global $wpdb; $t=$wpdb->prefix."wpcc_proposals";
			foreach (array_merge($pids,[$mixDraft,$mixTrash]) as $pid){ $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE target_id=%s AND operation_id=%s",(string)$pid,"seo_manage")); wp_delete_post($pid,true); }
		}

		// Not-allowed (subscriber) -> redirect unchanged, no generation.
		$sub = wp_insert_user(["user_login"=>"bra_".wp_generate_password(5,false),"user_pass"=>wp_generate_password(),"role"=>"subscriber"]);
		wp_set_current_user($sub);
		$out["subscriber_passthrough"] = ( $ra->handle_bulk("HOME","wpcc_seo_generate",[1]) === "HOME" ) ? 1 : 0;
		$out["subscriber_no_dropdown"] = ( ! isset($ra->add_bulk_action([])["wpcc_seo_generate"]) ) ? 1 : 0;
		wp_set_current_user($aid); wp_delete_user($sub);

		echo wp_json_encode($out);
	')"
	bj() { printf '%s' "$BRES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "bulk dropdown action present + label" "1" "$(bj bulk_action_present)"
	assert_eq "wrong action -> redirect passthrough"  "1" "$(bj wrong_action_passthrough)"
	assert_eq "subscriber -> redirect passthrough"    "1" "$(bj subscriber_passthrough)"
	assert_eq "subscriber -> no bulk dropdown action" "1" "$(bj subscriber_no_dropdown)"
	if [ "$(bj seo_active)" = "1" ]; then
		assert_eq "bulk redirects to Suggestions tab"  "1" "$(bj redirect_to_suggestions)"
		assert_eq "MAX_BATCH enforced (27 -> 25 created)" "1" "$(bj cap_25_created)"
		assert_eq "overflow beyond 25 dropped"          "1" "$(bj overflow_dropped)"
		assert_eq "draft proposals created via bulk"    "1" "$(bj drafts_exist)"
		assert_eq "NO SEO meta written (propose-only)"  "1" "$(bj no_meta_written)"
		assert_eq "duplicate bulk -> all has_open_proposal" "1" "$(bj dup_all_skipped)"
		assert_eq "mixed bulk: draft created"           "1" "$(bj mixed_draft_created)"
		assert_eq "mixed bulk: trashed skipped"         "1" "$(bj mixed_trash_skipped)"
	else
		echo "  NOTE: no SEO plugin active — bulk propose-only round-trip skipped."
	fi
else
	echo "  SKIP: wp-cli not available — bulk functional checks skipped."
fi

echo
echo "== 5. Invariants unchanged =="
assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
