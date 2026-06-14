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

PGID=""; MENU_ID=""; PAT_ID=""; NAV_ID=""
cleanup() {
  [ -n "$PGID" ] && wpe 'wp_delete_post('"$PGID"',true);'
  [ -n "$PAT_ID" ] && wpe 'wp_delete_post('"$PAT_ID"',true);'
  [ -n "$NAV_ID" ] && wpe 'wp_delete_post('"$NAV_ID"',true);'
  [ -n "$MENU_ID" ] && wpe 'wp_delete_nav_menu('"$MENU_ID"');'
  wpe 'wp_delete_post(0,true);' >/dev/null 2>&1
}
trap cleanup EXIT

echo "== 1. Create a page (draft) =="
R=$(sb '{"action":"page_create","title":"S95 Acceptance Landing","content":"<p>Welcome to the site.</p>","status":"draft"}')
PGID=$(echo "$R" | jq -r '.page_id')
assert_nonempty "page created" "$PGID"
assert_nonempty "page rollback_id" "$(echo "$R" | jq -r '.rollback_id')"
assert_eq "page status draft" "draft" "$(wpe 'echo get_post_status('"$PGID"');')"

echo "== 2. Assign a template =="
T=$(sb "$(jq -n --argjson p "$PGID" '{action:"template_assign",page_id:$p,template:"default"}')")
assert_eq "template assigned" "default" "$(echo "$T" | jq -r '.template // .code')"

echo "== 3. Create a block pattern (reusable block) =="
PAT=$(sb '{"action":"pattern_create","title":"S95 Hero Pattern","content":"<!-- wp:paragraph --><p>Hero</p><!-- /wp:paragraph -->"}')
PAT_ID=$(echo "$PAT" | jq -r '.pattern_id')
assert_nonempty "pattern created" "$PAT_ID"
assert_eq "pattern is wp_block" "wp_block" "$(wpe 'echo get_post_type('"$PAT_ID"');')"

echo "== 4. Create block-theme navigation =="
NAV=$(sb '{"action":"navigation_manage","op":"create","title":"S95 Nav"}')
NAV_ID=$(echo "$NAV" | jq -r '.navigation_id')
assert_nonempty "navigation created" "$NAV_ID"
assert_eq "navigation is wp_navigation" "wp_navigation" "$(wpe 'echo get_post_type('"$NAV_ID"');')"

echo "== 5. Create a menu (delegated to menu_manage) =="
M=$(sb '{"action":"menu_create","name":"S95 Main Menu"}')
assert_eq "menu_create delegated" "site_builder_manage" "$(echo "$M" | jq -r '.delegated_from // "none"')"
MENU_ID=$(echo "$M" | jq -r '.menu_id // .id // empty')
assert_nonempty "menu id returned" "$MENU_ID"

echo "== 6. Assign the menu to a theme location (delegated) =="
LOC=$(wpe '$l=get_registered_nav_menus(); echo $l?array_key_first($l):"";')
if [ -n "$LOC" ]; then
  A=$(sb "$(jq -n --argjson m "$MENU_ID" --arg l "$LOC" '{action:"menu_assign",menu_id:$m,location:$l}')")
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
assert_eq "page_get title" "S95 Acceptance Landing" "$(sb "$(jq -n --argjson p "$PGID" '{action:"page_get",page_id:$p}')" | jq -r '.page.title')"
assert_eq "page_list includes our page" "true" "$(sb '{"action":"page_list","per_page":100}' | jq -r '[.pages[] | select(.id == '"$PGID"')] | length > 0')"

echo "== 9. MCP parity =="
assert_eq "MCP page_get title" "S95 Acceptance Landing" "$(sbmcp "$(jq -n --argjson p "$PGID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"site_builder_manage",arguments:{action:"page_get",page_id:$p}}}')" | jq -r '.page.title')"

echo "== 10. Structured errors =="
assert_eq "missing page title" "wpcc_missing_title" "$(sb '{"action":"page_create"}' | jq -r '.code // "none"')"
assert_eq "page not found" "wpcc_page_not_found" "$(sb '{"action":"page_get","page_id":99999999}' | jq -r '.code // "none"')"
assert_eq "invalid template" "wpcc_invalid_template" "$(sb "$(jq -n --argjson p "$PGID" '{action:"template_assign",page_id:$p,template:"nonexistent-tpl"}')" | jq -r '.code // "none"')"

echo "== 11. Update a page + rollback =="
UP=$(sb "$(jq -n --argjson p "$PGID" '{action:"page_update",page_id:$p,title:"S95 Changed Title"}')")
assert_eq "title changed" "S95 Changed Title" "$(wpe 'echo get_the_title('"$PGID"');')"
sbrb "$(jq -n --arg r "$(echo "$UP" | jq -r '.rollback_id')" '{rollback_id:$r}')" >/dev/null
assert_eq "title rolled back" "S95 Acceptance Landing" "$(wpe 'echo get_the_title('"$PGID"');')"

echo
echo "================================================"
echo "  Site Builder (STEP 95): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
