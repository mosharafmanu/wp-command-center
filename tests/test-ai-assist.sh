#!/usr/bin/env bash
#
# ✨ AI Assist — consolidated AI row action (Slices 0–3).
#
# Asserts the scalable single-entry model: AiActionRegistry is the single source of
# truth; AiAssistRowActions emits ONE "AI Assist" anchor per object (no per-feature
# row links); the Governed Action Panel gained a chooser (data-wpcc-action="assist")
# that lists applicable actions and runs the existing generate→review→edit→apply→undo
# lifecycle; per-feature classes keep their no-JS admin-post fallbacks; build flag +
# FeatureGate gating is preserved; and no route/op/cap/MCP/schema is added.
#
# Requires wp-cli for the live gating-matrix + invariant section.

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

REG="$PLUGIN_DIR/includes/Admin/AiActionRegistry.php"
AAR="$PLUGIN_DIR/includes/Admin/AiAssistRowActions.php"
APA="$PLUGIN_DIR/includes/Admin/ActionPanelAssets.php"
JS="$PLUGIN_DIR/assets/js/wpcc-action-panel.js"
CSS="$PLUGIN_DIR/assets/css/wpcc-action-panel.css"

echo "✨ AI Assist — consolidated AI row action"

echo
echo "== 0. Files present + JS parses =="
[ -f "$REG" ] && pass "AiActionRegistry present" || fail "AiActionRegistry missing"
[ -f "$AAR" ] && pass "AiAssistRowActions present" || fail "AiAssistRowActions missing"
if command -v php >/dev/null 2>&1; then
	php -l "$REG" >/dev/null 2>&1 && pass "registry PHP parses" || fail "registry PHP syntax error"
	php -l "$AAR" >/dev/null 2>&1 && pass "row-action PHP parses" || fail "row-action PHP syntax error"
fi
if command -v node >/dev/null 2>&1; then
	node --check "$JS" >/dev/null 2>&1 && pass "panel JS parses" || fail "panel JS syntax error"
fi

echo
echo "== 1. Registry: single source of truth, required fields =="
has  "defines title action"        "'id'           => \$kind"        "$REG"
has  "defines seo action"          "'id'           => 'seo'"         "$REG"
has  "defines alt_text action"     "'id'           => 'alt_text'"    "$REG"
has  "field: label"                "'label'"                         "$REG"
has  "field: icon"                 "'icon'"                          "$REG"
has  "Title icon = edit/pencil"    "'dashicons-edit'"                "$REG"
has  "Excerpt icon distinct (text)" "'dashicons-text'"               "$REG"
has  "SEO icon = search"           "'dashicons-search'"              "$REG"
has  "field: object_types"         "'object_types'"                  "$REG"
has  "field: build_flag"           "'build_flag'"                    "$REG"
has  "field: filter"               "'filter'"                        "$REG"
has  "field: feature_key"          "'feature_key'"                   "$REG"
has  "field: generate endpoint"    "'generate'"                      "$REG"
has  "field: apply mapping"        "'apply'"                         "$REG"
has  "field: fallback_url"         "'fallback_url'"                  "$REG"
has  "content -> proposals route"  "'path' => '/admin/proposals'"    "$REG"
has  "SEO -> seo generate route"   "/admin/seo/generate"             "$REG"
has  "Alt -> alt generate route"   "/admin/alt-text/generate"        "$REG"
has  "content apply content_update" "'content_update'"               "$REG"
has  "SEO apply seo_update"        "'seo_update'"                    "$REG"
has  "alt apply media_update"      "'media_update'"                  "$REG"
has  "content status allow-list"   "ContentFieldGenerator::is_supported_status" "$REG"
has  "seo status allow-list"       "SeoMetaGenerator::is_supported_status" "$REG"
has  "alt image eligibility"       "wp_attachment_is_image"          "$REG"
lacks "registry adds no REST route" "register_rest_route"            "$REG"
lacks "registry adds no operation map edit" "OPERATION_MAP"          "$REG"

echo
echo "== 2. AiAssistRowActions: ONE anchor, chooser-bound, fallback retained =="
has  "registers post row actions"   "post_row_actions"               "$AAR"
has  "registers page row actions"   "page_row_actions"               "$AAR"
has  "registers media row actions"  "media_row_actions"              "$AAR"
has  "single AI Assist key"         "wpcc_ai_assist"                 "$AAR"
has  "opts into the panel chooser"  "data-wpcc-action=\"assist\""    "$AAR"
has  "carries applicable action ids" "data-actions=\""               "$AAR"
has  "no-JS fallback href"          "fallback_url"                   "$AAR"
has  "WPCC-branded visible label"     "esc_html__( 'WPCC AI'"          "$AAR"
has  "a11y: has-popup (menu for multi)" "aria-haspopup=\"' . \$popup"   "$AAR"
has  "a11y: menu trigger expandable"  "aria-expanded=\"false\""        "$AAR"
has  "decorative sparkle hidden"    "aria-hidden=\"true\""           "$AAR"
lacks "no register_rest_route"      "register_rest_route"            "$AAR"
lacks "no executor"                 "OperationExecutor"              "$AAR"

echo
echo "== 3. Row dropdown menu (JS) + back-compat dispatch =="
has  "assist key handled"            "key === 'assist'"               "$JS"
has  "openDropdown implemented"      "function openDropdown"          "$JS"
has  "selection opens the panel"     "function chooseFromMenu"        "$JS"
has  "resolves ids from data-actions" "data-actions"                  "$JS"
has  "startAction runs the lifecycle" "function startAction"          "$JS"
has  "single applicable auto-advances (no menu)" "ids.length === 1"   "$JS"
has  "menu uses action labels"       "cfg.label"                      "$JS"
has  "back-compat: specific action key still supported" "ACTIONS[ key ]" "$JS"
has  "menu role (a11y)"              "role: 'menu'"                   "$JS"
has  "menuitem role (a11y)"          "role: 'menuitem'"               "$JS"
has  "Esc closes the menu"           "'Escape'"                       "$JS"
has  "Arrow-key navigation"          "'ArrowDown'"                    "$JS"
has  "outside click closes"          "function onDocClick"            "$JS"
has  "opens on hover"                "function onMouseOver"           "$JS"
has  "hover open helper"             "function hoverOpen"             "$JS"
has  "closes on pointer-leave (debounced)" "function scheduleClose"   "$JS"
has  "no flicker: menu cancels close on enter" "menu.addEventListener( 'mouseenter', cancelClose )" "$JS"
has  "opens on keyboard focus"       "function onFocusIn"             "$JS"
has  "Space/ArrowDown open via keyboard" "function onTriggerKeydown"  "$JS"
has  "focus-out closes"              "function onFocusOut"            "$JS"
has  "keeps native row strip visible (JS)" "wpcc-ai-keep-open"        "$JS"
has  "finds the row action strip"    "closest( '.row-actions' )"      "$JS"
has  "menu aria-label is branded"    "chooserTitle"                   "$JS"
lacks "old modal chooser removed"    "function renderChooser"         "$JS"
has  "lifecycle unchanged: config-driven generate" "ST.cfg.generate.path" "$JS"
has  "lifecycle unchanged: apply route" "/apply"                     "$JS"
has  "lifecycle unchanged: rollback route" "/history/"               "$JS"

echo
echo "== 4. Dropdown menu CSS + centered modal =="
has  "dropdown menu style"          "wpcc-ai-menu"                   "$CSS"
has  "dropdown menu item"           "wpcc-ai-menu__item"             "$CSS"
has  "modal centered in viewport"   "Center the modal in the viewport" "$CSS"
has  "modal capped to viewport height" "max-height: calc( 100vh - 32px )" "$CSS"
has  "mobile bottom-sheet preserved" "align-items: flex-end"         "$CSS"
has  "row strip stays visible while menu open (CSS)" "row-actions.wpcc-ai-keep-open" "$CSS"
has  "keep-open overrides WP position-hide (real mechanism)" "position: static !important" "$CSS"
has  "keep-open resets off-screen left" "left: auto !important"   "$CSS"

echo
echo "== 5. Shared i18n carries chooser strings =="
has  "chooserTitle string"          "'chooserTitle'"                 "$APA"
has  "chooserIntro string"          "'chooserIntro'"                 "$APA"

echo
echo "== 6. Live: build-flag + FeatureGate gating matrix =="
if ! command -v wp >/dev/null 2>&1; then
	echo "  SKIP: wp-cli not available — static checks only."
else
	RES="$(wpe '
		$a=get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($a?$a[0]->ID:1);
		$reg = new \WPCommandCenter\Admin\AiActionRegistry();
		// Priority-99 overrides win over the ambient dev mu-plugin enablers (priority 10).
		$on  = function($f){ add_filter($f,"__return_true",99); };
		$off = function($f){ add_filter($f,"__return_false",99); };
		$clr = function($f){ remove_filter($f,"__return_true",99); remove_filter($f,"__return_false",99); };

		// content only
		$on("wpcc_ai_content_ui"); $off("wpcc_seo_meta_ui"); $off("wpcc_alt_text_ui");
		$out["content_only"] = implode(",", $reg->enabled_for_type("post"));
		$clr("wpcc_ai_content_ui"); $clr("wpcc_seo_meta_ui"); $clr("wpcc_alt_text_ui");

		// content + seo
		$on("wpcc_ai_content_ui"); $on("wpcc_seo_meta_ui"); $off("wpcc_alt_text_ui");
		$out["content_seo"] = implode(",", $reg->enabled_for_type("post"));
		$clr("wpcc_ai_content_ui"); $clr("wpcc_seo_meta_ui"); $clr("wpcc_alt_text_ui");

		// all off
		$off("wpcc_ai_content_ui"); $off("wpcc_seo_meta_ui"); $off("wpcc_alt_text_ui");
		$out["none"] = implode(",", $reg->enabled_for_type("post"));
		$clr("wpcc_ai_content_ui"); $clr("wpcc_seo_meta_ui"); $clr("wpcc_alt_text_ui");

		// FeatureGate denies title even with the flag on
		$on("wpcc_ai_content_ui"); $on("wpcc_seo_meta_ui"); $off("wpcc_alt_text_ui");
		$deny = function($a,$f){ return $f === "title_generator" ? false : $a; };
		add_filter("wpcc_feature_allowed",$deny,10,2);
		$out["title_denied"] = implode(",", $reg->enabled_for_type("post"));
		remove_filter("wpcc_feature_allowed",$deny,10);
		$clr("wpcc_ai_content_ui"); $clr("wpcc_seo_meta_ui"); $clr("wpcc_alt_text_ui");

		// media type
		$on("wpcc_alt_text_ui");
		$out["media"] = implode(",", $reg->enabled_for_type("attachment"));
		$clr("wpcc_alt_text_ui");

		// per-object status: draft supported, trash not (content supports closure)
		$on("wpcc_ai_content_ui"); $off("wpcc_seo_meta_ui");
		$d = wp_insert_post(["post_type"=>"post","post_status"=>"draft","post_title"=>"AAQA draft","post_content"=>"b"]);
		$out["draft_applicable"] = implode(",", $reg->applicable_for_post(get_post($d)));
		$t = wp_insert_post(["post_type"=>"post","post_status"=>"trash","post_title"=>"AAQA trash","post_content"=>"b"]);
		$out["trash_applicable"] = implode(",", $reg->applicable_for_post(get_post($t)));
		wp_delete_post($d,true); wp_delete_post($t,true);
		$clr("wpcc_ai_content_ui"); $clr("wpcc_seo_meta_ui");

		echo wp_json_encode($out);
	')"
	gj() { printf '%s' "$RES" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["'"$1"'"] ?? "";' 2>/dev/null; }
	assert_eq "content flag only -> Title/Excerpt"        "title,excerpt"     "$(gj content_only)"
	assert_eq "content + SEO -> Title/Excerpt/SEO"        "title,excerpt,seo" "$(gj content_seo)"
	assert_eq "no flags -> no actions (no AI Assist)"     ""                  "$(gj none)"
	assert_eq "FeatureGate denies Title -> Excerpt/SEO"   "excerpt,seo"       "$(gj title_denied)"
	assert_eq "attachment type -> Alt Text"               "alt_text"          "$(gj media)"
	assert_eq "draft post -> content actions applicable"  "title,excerpt"     "$(gj draft_applicable)"
	assert_eq "trashed post -> none applicable"           ""                  "$(gj trash_applicable)"

	echo
	echo "== 7. Invariants unchanged =="
	assert_eq "OPERATION_MAP == 34" "34" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::OPERATION_MAP);')"
	assert_eq "capabilities == 23"  "23" "$(wpe 'echo count(\WPCommandCenter\Operations\CapabilityRegistry::ALL_CAPABILITIES);')"
	assert_eq "catalogue == 40"     "40" "$(wpe 'echo count((new \WPCommandCenter\Operations\OperationRegistry())->get_operations());')"
	assert_eq "DB_VERSION 2.5.0"    "2.5.0" "$(wpe 'echo \WPCommandCenter\Core\Schema::DB_VERSION;')"
fi

echo ""
echo "RESULT: ${PASS} passed, ${FAIL} failed"
[ "$FAIL" -eq 0 ]
