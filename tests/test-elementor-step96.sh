#!/usr/bin/env bash
#
# STEP 96 — Elementor Runtime acceptance suite.
#
# Read and edit Elementor pages over REST + MCP by operating on the page's
# _elementor_data element tree: export the structure, list widgets, and update
# a widget's text, image, or button by widget id — with rollback, audit, cache
# clearing, and structured errors.
#
# Requires: curl, jq, wp, wpcc-env.sh, Elementor active.
# Usage: bash tests/test-elementor-step96.sh

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
el() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/elementor_manage/run"; }
elmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
elrb() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/elementor_manage/rollback"; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }
# Read a setting out of _elementor_data via a path expression e.g. '[0]["elements"][0]["elements"][0]["settings"]["title"]'
setting() { wpe '$d=json_decode(get_post_meta('"$PGID"',"_elementor_data",true),true); echo $d'"$1"';'; }

PGID=""
cleanup() { [ -n "$PGID" ] && wpe 'wp_delete_post('"$PGID"',true);'; }
trap cleanup EXIT

echo "== 0. Build an Elementor-shaped page (heading/text/image/button widgets) =="
PGID=$(wpe '
$pid = wp_insert_post(["post_title"=>"S96 Elementor Page","post_type"=>"page","post_status"=>"publish"]);
$data = [[ "id"=>"sec1","elType"=>"section","settings"=>new stdClass(),"elements"=>[
  [ "id"=>"col1","elType"=>"column","settings"=>new stdClass(),"elements"=>[
    ["id"=>"h1","elType"=>"widget","widgetType"=>"heading","settings"=>["title"=>"Original Heading"],"elements"=>[]],
    ["id"=>"t1","elType"=>"widget","widgetType"=>"text-editor","settings"=>["editor"=>"Original text"],"elements"=>[]],
    ["id"=>"i1","elType"=>"widget","widgetType"=>"image","settings"=>["image"=>["url"=>"http://example.com/old.jpg","id"=>0]],"elements"=>[]],
    ["id"=>"b1","elType"=>"widget","widgetType"=>"button","settings"=>["text"=>"Old Label","link"=>["url"=>"http://old.example/"]],"elements"=>[]]
  ]]
]]];
update_post_meta($pid,"_elementor_data", wp_slash(wp_json_encode($data)));
update_post_meta($pid,"_elementor_edit_mode","builder");
echo $pid;
')
assert_nonempty "elementor page created" "$PGID"

echo "== 1. elementor_get_page returns the element tree =="
G=$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_get_page",page_id:$p}')")
assert_eq "get_page title" "S96 Elementor Page" "$(echo "$G" | jq -r '.title')"
assert_eq "get_page top elType" "section" "$(echo "$G" | jq -r '.data[0].elType')"

echo "== 2. elementor_export_structure summarizes the tree =="
S=$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_export_structure",page_id:$p}')")
assert_eq "structure root section" "section" "$(echo "$S" | jq -r '.structure[0].elType')"
assert_eq "structure nested widgetType" "heading" "$(echo "$S" | jq -r '.structure[0].children[0].children[0].widgetType')"

echo "== 3. elementor_list_widgets flattens all widgets =="
L=$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_list_widgets",page_id:$p}')")
assert_eq "widget count" "4" "$(echo "$L" | jq -r '.total')"
assert_eq "heading text surfaced" "Original Heading" "$(echo "$L" | jq -r '.widgets[] | select(.id=="h1") | .text')"
assert_eq "button text surfaced" "Old Label" "$(echo "$L" | jq -r '.widgets[] | select(.id=="b1") | .text')"

echo "== 4. elementor_update_text changes the heading =="
UT=$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_update_text",page_id:$p,widget_id:"h1",text:"New Heading"}')")
assert_nonempty "update_text rollback_id" "$(echo "$UT" | jq -r '.rollback_id')"
assert_eq "heading title updated" "New Heading" "$(setting '[0]["elements"][0]["elements"][0]["settings"]["title"]')"

echo "== 5. elementor_update_image changes the image url/id =="
el "$(jq -n --argjson p "$PGID" '{action:"elementor_update_image",page_id:$p,widget_id:"i1",image_url:"http://example.com/new.png",image_id:42}')" >/dev/null
assert_eq "image url updated" "http://example.com/new.png" "$(setting '[0]["elements"][0]["elements"][2]["settings"]["image"]["url"]')"
assert_eq "image id updated" "42" "$(setting '[0]["elements"][0]["elements"][2]["settings"]["image"]["id"]')"

echo "== 6. elementor_update_button changes label + link, then rollback =="
UB=$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_update_button",page_id:$p,widget_id:"b1",text:"Buy Now",url:"http://new.example/checkout"}')")
assert_eq "button text updated" "Buy Now" "$(setting '[0]["elements"][0]["elements"][3]["settings"]["text"]')"
assert_eq "button link updated" "http://new.example/checkout" "$(setting '[0]["elements"][0]["elements"][3]["settings"]["link"]["url"]')"
elrb "$(jq -n --arg r "$(echo "$UB" | jq -r '.rollback_id')" '{rollback_id:$r}')" >/dev/null
assert_eq "button text rolled back" "Old Label" "$(setting '[0]["elements"][0]["elements"][3]["settings"]["text"]')"
assert_eq "button link rolled back" "http://old.example/" "$(setting '[0]["elements"][0]["elements"][3]["settings"]["link"]["url"]')"

echo "== 7. Edit clears Elementor CSS cache =="
wpe 'update_post_meta('"$PGID"',"_elementor_css","STALE");' >/dev/null
el "$(jq -n --argjson p "$PGID" '{action:"elementor_update_text",page_id:$p,widget_id:"h1",text:"Cache Bust"}')" >/dev/null
assert_eq "_elementor_css cleared after edit" "" "$(wpe 'echo get_post_meta('"$PGID"',"_elementor_css",true);')"

echo "== 8. MCP parity (list_widgets) =="
assert_eq "MCP widget count" "4" "$(elmcp "$(jq -n --argjson p "$PGID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"elementor_manage",arguments:{action:"elementor_list_widgets",page_id:$p}}}')" | jq -r '.total')"

echo "== 9. Structured errors =="
assert_eq "widget not found" "wpcc_widget_not_found" "$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_update_text",page_id:$p,widget_id:"zzz",text:"x"}')" | jq -r '.code // "none"')"
assert_eq "page not found" "wpcc_page_not_found" "$(el '{"action":"elementor_get_page","page_id":99999999}' | jq -r '.code // "none"')"
assert_eq "missing widget_id" "wpcc_missing_widget_id" "$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_update_text",page_id:$p,text:"x"}')" | jq -r '.code // "none"')"
assert_eq "missing image" "wpcc_missing_image" "$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_update_image",page_id:$p,widget_id:"i1"}')" | jq -r '.code // "none"')"
assert_eq "missing button fields" "wpcc_missing_button_fields" "$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_update_button",page_id:$p,widget_id:"b1"}')" | jq -r '.code // "none"')"
assert_eq "invalid action" "wpcc_invalid_elementor_action" "$(el "$(jq -n --argjson p "$PGID" '{action:"elementor_bogus",page_id:$p}')" | jq -r '.code // "none"')"
NONEL=$(wpe '$id=wp_insert_post(["post_title"=>"plain","post_type"=>"page","post_status"=>"draft"]); echo $id;')
assert_eq "not an elementor page" "wpcc_not_elementor_page" "$(el "$(jq -n --argjson p "$NONEL" '{action:"elementor_get_page",page_id:$p}')" | jq -r '.code // "none"')"
wpe 'wp_delete_post('"$NONEL"',true);' >/dev/null

echo "== 10. MCP error surface (isError) for bad widget =="
ERR=$(curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$(jq -n --argjson p "$PGID" '{jsonrpc:"2.0",id:2,method:"tools/call",params:{name:"elementor_manage",arguments:{action:"elementor_update_text",page_id:$p,widget_id:"zzz",text:"x"}}}')" "$WPCC_BASE/mcp")
assert_eq "MCP isError on widget-not-found" "true" "$(echo "$ERR" | jq -r '.result.isError')"

echo
echo "================================================"
echo "  Elementor (STEP 96): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
