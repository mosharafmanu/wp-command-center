#!/usr/bin/env bash
#
# STEP 95 — Site Builder Runtime acceptance suite.
#
# Construct a WordPress site over REST + MCP: pages, page templates, block
# patterns (reusable blocks), block-theme navigation, and menus (delegated to
# menu_manage), with rollback, audit, and structured errors.
#
# Workflow: create page → create menu → assign menu → publish → verify frontend.
#
# Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-site-builder-step95.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_PATH="$SCRIPT_DIR/../../../.."

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }
sb() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/site_builder_manage/run"; }
sbmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
sbrb() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/site_builder_manage/rollback"; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

RUN_ID="$(date +%s)-$$-$RANDOM"
PAGE_TITLE="S95 Acceptance Landing $RUN_ID"
PATTERN_TITLE="S95 Hero Pattern $RUN_ID"
NAV_TITLE="S95 Nav $RUN_ID"
MENU_TITLE="S95 Main Menu $RUN_ID"
NAV_LOCATIONS_BEFORE="$(wpe '$mods=get_option("theme_mods_".get_option("stylesheet"),[]);$exists=is_array($mods)&&array_key_exists("nav_menu_locations",$mods);echo base64_encode(wp_json_encode(["exists"=>$exists,"value"=>$exists?$mods["nav_menu_locations"]:null]));')"
PGID=""; MENU_ID=""; PAT_ID=""; NAV_ID=""
cleanup() {
  local test_status=$?
  local cleanup_failed=0
  [ -n "$PGID" ] && wpe 'wp_delete_post('"$PGID"',true);'
  [ -n "$PAT_ID" ] && wpe 'wp_delete_post('"$PAT_ID"',true);'
  [ -n "$NAV_ID" ] && wpe 'wp_delete_post('"$NAV_ID"',true);'
  [ -n "$MENU_ID" ] && wpe 'wp_delete_nav_menu('"$MENU_ID"');'
  local restore_result
  restore_result="$(env WPCC_NAV_LOCATIONS_BEFORE="$NAV_LOCATIONS_BEFORE" wp --path="$WP_PATH" eval '$raw=base64_decode((string)getenv("WPCC_NAV_LOCATIONS_BEFORE"),true);$s=is_string($raw)?json_decode($raw,true):null;if(!is_array($s)||!array_key_exists("exists",$s)){echo "WPCC_MENU_CLEANUP_FAILED";return;}if($s["exists"]){set_theme_mod("nav_menu_locations",$s["value"]??[]);}else{remove_theme_mod("nav_menu_locations");}$mods=get_option("theme_mods_".get_option("stylesheet"),[]);$exists=is_array($mods)&&array_key_exists("nav_menu_locations",$mods);$value=$exists?$mods["nav_menu_locations"]:null;echo ($exists===(bool)$s["exists"]&&maybe_serialize($value)===maybe_serialize($s["value"]??null))?"WPCC_MENU_CLEANUP_OK":"WPCC_MENU_CLEANUP_FAILED";' 2>/dev/null)" || restore_result="WPCC_MENU_CLEANUP_FAILED"
  [ "$restore_result" = "WPCC_MENU_CLEANUP_OK" ] || cleanup_failed=1
  if [ "$cleanup_failed" -ne 0 ]; then
    echo "  FAIL: menu fixture teardown/restoration failed" >&2
    exit 1
  fi
  exit "$test_status"
}
trap cleanup EXIT

echo "== 1. Create a page (draft) =="
R=$(sb "$(jq -n --arg t "$PAGE_TITLE" '{action:"page_create",title:$t,content:"<p>Welcome to the site.</p>",status:"draft"}')")
PGID=$(echo "$R" | jq -r '.page_id')
assert_nonempty "page created" "$PGID"
assert_nonempty "page rollback_id" "$(echo "$R" | jq -r '.rollback_id')"
assert_eq "page status draft" "draft" "$(wpe 'echo get_post_status('"$PGID"');')"

echo "== 2. Assign a template =="
T=$(sb "$(jq -n --argjson p "$PGID" '{action:"template_assign",page_id:$p,template:"default"}')")
assert_eq "template assigned" "default" "$(echo "$T" | jq -r '.template // .code')"

echo "== 3. Create a block pattern (reusable block) =="
PAT=$(sb "$(jq -n --arg t "$PATTERN_TITLE" '{action:"pattern_create",title:$t,content:"<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->"}')")
PAT_ID=$(echo "$PAT" | jq -r '.pattern_id')
assert_nonempty "pattern created" "$PAT_ID"
assert_eq "pattern is wp_block" "wp_block" "$(wpe 'echo get_post_type('"$PAT_ID"');')"

echo "== 4. Create block-theme navigation =="
NAV=$(sb "$(jq -n --arg t "$NAV_TITLE" '{action:"navigation_manage",op:"create",title:$t}')")
NAV_ID=$(echo "$NAV" | jq -r '.navigation_id')
assert_nonempty "navigation created" "$NAV_ID"
assert_eq "navigation is wp_navigation" "wp_navigation" "$(wpe 'echo get_post_type('"$NAV_ID"');')"

echo "== 5. Create a menu (delegated to menu_manage) =="
M=$(sb "$(jq -n --arg n "$MENU_TITLE" '{action:"menu_create",name:$n}')")
assert_eq "menu_create delegated" "site_builder_manage" "$(echo "$M" | jq -r '.delegated_from // "none"')"
MENU_ID=$(echo "$M" | jq -r '.menu_id // .id // empty')
assert_nonempty "menu id returned" "$MENU_ID"

echo "== 6. Assign the menu to a theme location (delegated) =="
LOC=$(wpe '$l=get_registered_nav_menus(); echo $l?array_key_first($l):"";')
if [ -n "$LOC" ]; then
  A=$(sb "$(jq -n --argjson m "$MENU_ID" --arg l "$LOC" '{action:"menu_assign",menu_id:$m,location:$l}')")
  assert_eq "menu assignment preserves registered location key" "$LOC" "$(echo "$A" | jq -r '.location // .code')"
  assert_eq "menu assigned to location" "$MENU_ID" "$(wpe '$locs=get_theme_mod("nav_menu_locations")?:[]; echo (int)($locs["'"$LOC"'"]??0);')"
else
  pass "menu assign: theme registers no nav locations, skipped gracefully"
fi

echo "== 7. Publish the page + verify frontend =="
sb "$(jq -n --argjson p "$PGID" '{action:"page_update",page_id:$p,status:"publish"}')" >/dev/null
assert_eq "page published" "publish" "$(wpe 'echo get_post_status('"$PGID"');')"
PERMALINK=$(wpe 'echo get_permalink('"$PGID"');')
assert_eq "page frontend HTTP 200" "200" "$(curl -s -o /dev/null -w "%{http_code}" "$PERMALINK")"

echo "== 8. page_get + page_list reflect the page =="
assert_eq "page_get title" "$PAGE_TITLE" "$(sb "$(jq -n --argjson p "$PGID" '{action:"page_get",page_id:$p}')" | jq -r '.page.title')"
assert_eq "page_list includes our page" "true" "$(sb '{"action":"page_list","per_page":100}' | jq -r '[.pages[] | select(.id == '"$PGID"')] | length > 0')"

echo "== 9. MCP parity =="
assert_eq "MCP page_get title" "$PAGE_TITLE" "$(sbmcp "$(jq -n --argjson p "$PGID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"site_builder_manage",arguments:{action:"page_get",page_id:$p}}}')" | jq -r '.page.title')"

echo "== 10. Structured errors =="
assert_eq "missing page title" "wpcc_missing_title" "$(sb '{"action":"page_create"}' | jq -r '.code // "none"')"
assert_eq "page not found" "wpcc_page_not_found" "$(sb '{"action":"page_get","page_id":99999999}' | jq -r '.code // "none"')"
assert_eq "invalid template" "wpcc_invalid_template" "$(sb "$(jq -n --argjson p "$PGID" '{action:"template_assign",page_id:$p,template:"nonexistent-tpl"}')" | jq -r '.code // "none"')"

echo "== 11. Update a page + rollback =="
CHANGED_TITLE="S95 Changed Title $RUN_ID"
UP=$(sb "$(jq -n --argjson p "$PGID" --arg t "$CHANGED_TITLE" '{action:"page_update",page_id:$p,title:$t}')")
assert_eq "title changed" "$CHANGED_TITLE" "$(wpe 'echo get_the_title('"$PGID"');')"
sbrb "$(jq -n --arg r "$(echo "$UP" | jq -r '.rollback_id')" '{rollback_id:$r}')" >/dev/null
assert_eq "title rolled back" "$PAGE_TITLE" "$(wpe 'echo get_the_title('"$PGID"');')"

echo
echo "================================================"
echo "  Site Builder (STEP 95): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
