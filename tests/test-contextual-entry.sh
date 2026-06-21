#!/usr/bin/env bash
#
# Contextual AI entry points — Title/Excerpt (ContentRowActions) + Alt Text
# (MediaRowActions) row/bulk actions, and the central Governed Action Panel enqueuer.
#
# Asserts (static): both classes are propose-only (create governed DRAFTS via the
# existing generators; never apply / never write / no new route/op/cap), the anchors
# opt into the generalized panel via data-wpcc-action, gating is cap + build flag +
# FeatureGate, and the panel is enqueued centrally for the right screens with the
# right per-workflow config. Asserts (live): the gating matrix on ActionPanelAssets
# (Title/Excerpt on edit.php; Alt Text on upload.php), and frozen invariants.
#
# Requires: wp-cli for the functional/invariant section.

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

CRA="$PLUGIN_DIR/includes/Admin/ContentRowActions.php"
MRA="$PLUGIN_DIR/includes/Admin/MediaRowActions.php"
APA="$PLUGIN_DIR/includes/Admin/ActionPanelAssets.php"

echo "Contextual AI entry points (Title / Excerpt / Alt Text) + central panel enqueuer"

echo
echo "== 1. ContentRowActions: propose-only Title/Excerpt entry =="
has  "registers post/page row actions"        "post_row_actions"             "$CRA"
has  "registers WP bulk actions"              "bulk_actions-edit-"           "$CRA"
has  "creates drafts via the content generator" "ContentFieldGenerator"      "$CRA"
has  "title anchor opts into the panel"       "data-wpcc-action=\"' . esc_attr( \$kind )" "$CRA"
has  "no-JS admin-post fallback"              "admin-post.php?action="       "$CRA"
has  "gated by build flag"                    "WPCC_AI_CONTENT_UI"           "$CRA"
has  "gated by per-kind FeatureGate (title)"  "title_generator"              "$CRA"
has  "gated by per-kind FeatureGate (excerpt)" "excerpt_generator"           "$CRA"
has  "status allow-list shared with generator" "ContentFieldGenerator::is_supported_status" "$CRA"
lacks "never applies"                          "ProposalApplyService"        "$CRA"
lacks "never calls the executor"               "OperationExecutor"           "$CRA"
lacks "no new REST route"                      "register_rest_route"         "$CRA"

echo
echo "== 2. MediaRowActions: propose-only Alt Text entry (Media Library) =="
has  "registers media row actions"            "media_row_actions"            "$MRA"
has  "registers upload bulk actions"          "bulk_actions-upload"          "$MRA"
has  "creates drafts via the alt generator"   "AltTextGenerator"             "$MRA"
has  "anchor opts into the panel (alt_text)"  "data-wpcc-action=\"alt_text\"" "$MRA"
has  "only image attachments"                 "wp_attachment_is_image"       "$MRA"
has  "no-JS admin-post fallback"              "admin-post.php?action="       "$MRA"
has  "gated by build flag"                    "WPCC_ALT_TEXT_UI"             "$MRA"
has  "gated by FeatureGate"                    "ai_alt_text"                 "$MRA"
lacks "never applies"                          "ProposalApplyService"        "$MRA"
lacks "no new REST route"                      "register_rest_route"         "$MRA"

echo
echo "== 3. ActionPanelAssets: one panel, per-workflow config, existing routes only =="
has  "SEO config -> existing generate route"  "/admin/seo/generate"          "$APA"
has  "Title/Excerpt -> existing proposals route" "'path' => '/admin/proposals'" "$APA"
has  "content generate via the generic branch" "'generate_kind' => \$kind"    "$APA"
has  "Alt Text -> existing alt generate route" "/admin/alt-text/generate"     "$APA"
has  "content apply -> content_update"        "'content_update'"             "$APA"
has  "alt apply -> media_update"              "'media_update'"               "$APA"
has  "alt on the Media Library screen"        "'upload.php'"                 "$APA"
lacks "no new REST route in the enqueuer"     "register_rest_route"          "$APA"
lacks "no executor in the enqueuer"           "OperationExecutor"            "$APA"

echo
echo "== 4. Functional: panel enqueue gating per screen =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$aa  = new \WPCommandCenter\Admin\ActionPanelAssets();
		$admin = get_users(["role"=>"administrator","number"=>1]); $aid = $admin?$admin[0]->ID:1; wp_set_current_user($aid);
		$enq = function() { return wp_script_is( "wpcc-action-panel", "enqueued" ); };
		$reset = function() {
			foreach (["wpcc-action-panel","wpcc-admin-runtime"] as $h) { wp_dequeue_script($h); wp_deregister_script($h); }
			foreach (["wpcc-action-panel","wpcc-tokens"] as $h) { wp_dequeue_style($h); wp_deregister_style($h); }
		};

		// Neutralize any ambient workflow (the dev env enables the SEO Builder flag)
		// so each sub-check isolates the workflow under test.
		$only = function($keys){ return function($a,$f) use ($keys){ return in_array($f,$keys,true) ? $a : false; }; };

		// Title/Excerpt enqueue on edit.php when the AI Content flag is on (SEO neutralized).
		set_current_screen("edit-post");
		$f1 = $only(["title_generator","excerpt_generator"]); add_filter("wpcc_feature_allowed",$f1,10,2);
		add_filter("wpcc_ai_content_ui","__return_true");
		$reset(); $aa->enqueue("edit.php");
		$out["content_on_edit"] = $enq() ? 1 : 0;
		remove_filter("wpcc_ai_content_ui","__return_true");
		$reset(); $aa->enqueue("edit.php");
		$out["content_off_absent"] = $enq() ? 0 : 1;
		remove_filter("wpcc_feature_allowed",$f1,10);

		// Alt Text enqueue on upload.php when the Alt Text flag is on (only alt allowed).
		$f2 = $only(["ai_alt_text"]); add_filter("wpcc_feature_allowed",$f2,10,2);
		add_filter("wpcc_alt_text_ui","__return_true");
		set_current_screen("upload");
		$reset(); $aa->enqueue("upload.php");
		$out["alt_on_upload"] = $enq() ? 1 : 0;
		// Alt Text workflow does NOT appear on edit.php (it is upload.php-only).
		set_current_screen("edit-post");
		$reset(); $aa->enqueue("edit.php");
		$out["alt_not_on_edit"] = $enq() ? 0 : 1;
		remove_filter("wpcc_alt_text_ui","__return_true");
		remove_filter("wpcc_feature_allowed",$f2,10);

		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "Title/Excerpt panel enqueued on edit.php" "1" "$(gj content_on_edit)"
	assert_eq "AI Content flag off -> not enqueued"      "1" "$(gj content_off_absent)"
	assert_eq "Alt Text panel enqueued on upload.php"    "1" "$(gj alt_on_upload)"
	assert_eq "Alt Text not enqueued on edit.php"        "1" "$(gj alt_not_on_edit)"

	echo
	echo "== 5. Invariants unchanged =="
	assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
	assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
	assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
	assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"
fi

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
