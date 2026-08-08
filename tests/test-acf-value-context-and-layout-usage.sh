#!/usr/bin/env bash
#
# ISSUE 14 / 15 — acf_value_get context_mode shaping + acf_layout_usage.
#
# ISSUE 14: acf_value_get ignored context_mode entirely — compact returned the
# same full formatted tree as verbose (a payload bomb for flexible content
# with image fields: ~40-key array per image, repeated per row). This suite
# proves compact/standard/verbose now genuinely differ, plus format=raw,
# layouts_only, and depth.
#
# ISSUE 15: there was no way to answer "which posts use layout X" without
# either a group-level acf_inventory (no per-post data) or a per-post
# acf_value_get payload bomb. This suite proves acf_layout_usage finds every
# post using a layout via one scoped query, with occurrence counts.
#
# Self-contained: creates a disposable field group + flexible-content field +
# layout (text + image sub-fields) via the acf_manage API itself, a disposable
# post, seeds its value via acf_value_set, and cleans up both on exit.
#
# Requires ACF active. Requires: curl, jq, wp-cli, wpcc-env.sh.
# Usage: bash tests/test-acf-value-context-and-layout-usage.sh

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
assert_true() { local d="$1" a="$2"; [ "$a" = "true" ] && pass "$d" || fail "$d (got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }

acfm() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/acf_manage/run"; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

if [ "$(wpe 'echo function_exists("acf")?"yes":"no";')" != "yes" ]; then
  echo "  SKIP: ACF not active"; echo "  ACF value context_mode + layout_usage: 0 passed, 0 failed"; exit 0
fi

GROUP=""; POST_ID=""
cleanup() {
  [ -n "$GROUP" ] && acfm "$(jq -n --arg g "$GROUP" '{action:"acf_group_delete",group_id:$g}')" >/dev/null 2>&1
  [ -n "$POST_ID" ] && wp post delete "$POST_ID" --force --path="$WP_PATH" >/dev/null 2>&1
}
trap cleanup EXIT

echo "== Setup: disposable field group + flexible-content field + layout =="
GROUP=$(acfm '{"action":"acf_group_create","title":"WPCC Issue14-15 Fixture","location":[[{"param":"post_type","operator":"==","value":"post"}]]}' | jq -r '.group_id')
assert_nonempty "group created" "$GROUP"

FLEX=$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_field_create",group_id:$g,type:"flexible_content",label:"Sections",name:"i1415_sections"}')" | jq -r '.field_key')
assert_nonempty "flexible field created" "$FLEX"

LAY=$(acfm "$(jq -n --arg f "$FLEX" '{action:"acf_layout_create",field_key:$f,name:"sample_layout",label:"Sample Layout",sub_fields:[{type:"text",label:"Heading",name:"heading"},{type:"image",label:"Photo",name:"photo",config:{return_format:"array"}}]}')")
assert_nonempty "layout created" "$(echo "$LAY" | jq -r '.layout_key')"

ATTACH_ID=$(wpe 'echo get_posts(["post_type"=>"attachment","numberposts"=>1])[0]->ID ?? "";')
assert_nonempty "have an attachment ID for the image sub-field" "$ATTACH_ID"

POST_ID=$(wp post create --post_title="WPCC Issue14-15 Post" --post_status=publish --porcelain --path="$WP_PATH")
assert_nonempty "test post created" "$POST_ID"

SET=$(acfm "$(jq -n --arg f "$FLEX" --arg pid "$POST_ID" --arg img "$ATTACH_ID" '
  {action:"acf_value_set",object_type:"post",object_id:($pid|tonumber),fields:{($f):[
    {acf_fc_layout:"sample_layout",heading:"First",photo:($img|tonumber)},
    {acf_fc_layout:"sample_layout",heading:"Second",photo:($img|tonumber)}
  ]}}')")
assert_nonempty "value_set applied (rollback_id present)" "$(echo "$SET" | jq -r '.rollback_id // empty')"

echo "== ISSUE 14: acf_value_get context_mode shaping =="

COMPACT=$(acfm "$(jq -n --arg f "$FLEX" --arg pid "$POST_ID" '{action:"acf_value_get",field_key:$f,post_id:($pid|tonumber),context_mode:"compact"}')")
assert_eq "compact: 2 rows" "2" "$(echo "$COMPACT" | jq -r '.value | length')"
assert_eq "compact: row has index/layout/fields shape" "sample_layout" "$(echo "$COMPACT" | jq -r '.value[0].layout')"
assert_eq "compact: scalar sub-field kept" "First" "$(echo "$COMPACT" | jq -r '.value[0].fields.heading')"
assert_true "compact: image sub-field dropped (not in fields)" "$(echo "$COMPACT" | jq -r '.value[0].fields | has("photo") | not')"
assert_true "compact: payload much smaller than verbose" "$([ "$(echo "$COMPACT" | wc -c)" -lt 800 ] && echo true || echo false)"

STANDARD=$(acfm "$(jq -n --arg f "$FLEX" --arg pid "$POST_ID" '{action:"acf_value_get",field_key:$f,post_id:($pid|tonumber),context_mode:"standard"}')")
assert_eq "standard: 2 rows, full structure kept" "2" "$(echo "$STANDARD" | jq -r '.value | length')"
assert_eq "standard: image reduced to ID/url/alt/width/height" "5" "$(echo "$STANDARD" | jq -r '.value[0].photo | keys | length')"
assert_true "standard: image has ID and url" "$(echo "$STANDARD" | jq -r '(.value[0].photo | has("ID")) and (.value[0].photo | has("url"))')"
assert_true "standard: image does NOT have sizes (that would be verbose)" "$(echo "$STANDARD" | jq -r '.value[0].photo | has("sizes") | not')"

VERBOSE=$(acfm "$(jq -n --arg f "$FLEX" --arg pid "$POST_ID" '{action:"acf_value_get",field_key:$f,post_id:($pid|tonumber),context_mode:"verbose"}')")
assert_true "verbose: full image array (many keys incl. sizes)" "$(echo "$VERBOSE" | jq -r '(.value[0].photo | keys | length) > 10')"
assert_true "verbose: has sizes key" "$(echo "$VERBOSE" | jq -r '.value[0].photo | has("sizes")')"

# Unformatted ACF flexible-content rows key sub-fields by their internal
# field_key hash, not name — so assert structurally: every non-layout value
# in the row is a bare scalar (no formatted image array survives raw mode).
RAW=$(acfm "$(jq -n --arg f "$FLEX" --arg pid "$POST_ID" '{action:"acf_value_get",field_key:$f,post_id:($pid|tonumber),format:"raw"}')")
assert_true "format=raw: every row sub-field is a bare scalar (no formatted arrays)" "$(echo "$RAW" | jq -r '[.value[0] | to_entries[] | select(.key != "acf_fc_layout") | (.value | type)] | all(. == "string" or . == "number")')"

LAYOUTS_ONLY=$(acfm "$(jq -n --arg f "$FLEX" --arg pid "$POST_ID" '{action:"acf_value_get",field_key:$f,post_id:($pid|tonumber),layouts_only:true}')")
assert_eq "layouts_only: ordered layout names, no sub-field payload" '["sample_layout","sample_layout"]' "$(echo "$LAYOUTS_ONLY" | jq -c '.layouts')"
assert_true "layouts_only: no value key present" "$(echo "$LAYOUTS_ONLY" | jq -r 'has("value") | not')"

DEPTH0=$(acfm "$(jq -n --arg f "$FLEX" --arg pid "$POST_ID" '{action:"acf_value_get",field_key:$f,post_id:($pid|tonumber),context_mode:"standard",depth:0}')")
assert_true "depth=0: nested photo array collapsed to _depth_truncated" "$(echo "$DEPTH0" | jq -r '.value[0].photo._depth_truncated // false')"
assert_eq "depth=0: scalar heading untouched by depth cap" "First" "$(echo "$DEPTH0" | jq -r '.value[0].heading')"

echo "== ISSUE 15: acf_layout_usage =="

USAGE=$(acfm "$(jq -n --arg field "i1415_sections" '{action:"acf_layout_usage",layout:"sample_layout",field:$field,post_type:["post"],post_status:["publish"]}')")
assert_eq "usage: finds exactly 1 post" "1" "$(echo "$USAGE" | jq -r '.total')"
assert_eq "usage: post_id matches" "$POST_ID" "$(echo "$USAGE" | jq -r '.posts[0].post_id')"
assert_eq "usage: occurrences = 2 (both rows use the layout)" "2" "$(echo "$USAGE" | jq -r '.posts[0].occurrences')"
assert_nonempty "usage: permalink present" "$(echo "$USAGE" | jq -r '.posts[0].permalink')"

USAGE_NOFIELD=$(acfm "$(jq -n '{action:"acf_layout_usage",layout:"sample_layout",post_type:["post"],post_status:["publish"]}')")
assert_eq "usage (field omitted, auto-discover): still finds the post" "1" "$(echo "$USAGE_NOFIELD" | jq -r '.total')"

USAGE_NONE=$(acfm '{"action":"acf_layout_usage","layout":"no_such_layout_xyz_1415"}')
assert_eq "usage: unknown layout returns 0" "0" "$(echo "$USAGE_NONE" | jq -r '.total')"

USAGE_ERR=$(acfm '{"action":"acf_layout_usage"}')
assert_eq "usage: missing layout param is a structured error" "wpcc_missing_layout" "$(echo "$USAGE_ERR" | jq -r '.code // "none"')"

echo
echo "================================================"
echo "  ACF value context_mode + layout_usage: $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
