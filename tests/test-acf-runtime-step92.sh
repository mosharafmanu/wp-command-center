#!/usr/bin/env bash
#
# STEP 92 — ACF Runtime acceptance suite.
#
# Build/manage ACF structures over REST + MCP: field groups, fields of every
# required type, repeaters (sub-fields), flexible content + layouts, attach to a
# CPT, with rollback, audit, and structured errors.
#
# Workflow: create field group → add fields → add repeater → add flexible
# content (+ layout with sub-fields) → attach to CPT → verify.
#
# Requires ACF active. Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-acf-runtime-step92.sh

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
acfm() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/acf_manage/run"; }
acfrb() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/acf_manage/rollback"; }
acfmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }
acf_subs() { wpe 'echo count(acf_get_field("'"$1"'")["sub_fields"]??[]);'; }

if [ "$(wpe 'echo function_exists("acf")?"yes":"no";')" != "yes" ]; then
  echo "  SKIP: ACF not active"; echo "  ACF Runtime (STEP 92): 0 passed, 0 failed"; exit 0
fi

GROUP=""
cleanup() { [ -n "$GROUP" ] && acfm "$(jq -n --arg g "$GROUP" '{action:"acf_group_delete",group_id:$g}')" >/dev/null 2>&1; }
trap cleanup EXIT

echo "== 1. Create field group attached to a CPT (post_type) =="
GROUP=$(acfm '{"action":"acf_group_create","title":"WPCC S92 Acceptance","location":[[{"param":"post_type","operator":"==","value":"post"}]]}' | jq -r '.group_id')
assert_nonempty "group created" "$GROUP"

echo "== 2. Add fields of multiple types =="
TXT=$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_field_create",group_id:$g,type:"text",label:"Subtitle",name:"subtitle",required:true}')")
assert_eq "text field type" "text" "$(echo "$TXT" | jq -r '.type')"
assert_nonempty "text field rollback_id" "$(echo "$TXT" | jq -r '.rollback_id')"
SEL=$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_field_create",group_id:$g,type:"select",label:"Width",config:{choices:{wide:"Wide",narrow:"Narrow"},default_value:"wide"}}')")
SELK=$(echo "$SEL" | jq -r '.field_key')
assert_eq "select field type" "select" "$(echo "$SEL" | jq -r '.type')"
assert_eq "select choices persisted" "Wide" "$(wpe 'echo acf_get_field("'"$SELK"'")["choices"]["wide"]??"";')"
IMG=$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_field_create",group_id:$g,type:"image",label:"Cover",config:{return_format:"id"}}')")
IMGK=$(echo "$IMG" | jq -r '.field_key')
assert_eq "image return_format config" "id" "$(wpe 'echo acf_get_field("'"$IMGK"'")["return_format"]??"";')"

echo "== 3. Add a repeater with sub-fields =="
REP=$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_field_create",group_id:$g,type:"repeater",label:"Items",config:{button_label:"Add Item"},sub_fields:[{type:"text",label:"Item Title"},{type:"image",label:"Item Image",config:{return_format:"id"}}]}')")
REPK=$(echo "$REP" | jq -r '.field_key')
assert_eq "repeater type" "repeater" "$(echo "$REP" | jq -r '.type')"
assert_eq "repeater sub_fields in response" "2" "$(echo "$REP" | jq -r '.sub_fields | length')"
assert_eq "repeater sub_fields persisted (ACF)" "2" "$(acf_subs "$REPK")"

echo "== 4. Add flexible content + a layout with sub-fields =="
FLEX=$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_field_create",group_id:$g,type:"flexible_content",label:"Sections"}')" | jq -r '.field_key')
assert_nonempty "flexible field created" "$FLEX"
LAY=$(acfm "$(jq -n --arg f "$FLEX" '{action:"acf_layout_create",field_key:$f,name:"hero",label:"Hero",sub_fields:[{type:"text",label:"Heading"},{type:"textarea",label:"Body"}]}')")
LAYK=$(echo "$LAY" | jq -r '.layout_key')
assert_nonempty "layout created" "$LAYK"
assert_eq "layout sub_fields in response" "2" "$(echo "$LAY" | jq -r '.sub_fields | length')"
assert_eq "layout sub_fields persisted (ACF)" "2" "$(wpe '$f=acf_get_field("'"$FLEX"'");$l=array_values($f["layouts"]??[]);echo count($l[0]["sub_fields"]??[]);')"

echo "== 5. Update a layout =="
acfm "$(jq -n --arg f "$FLEX" --arg l "$LAYK" '{action:"acf_layout_update",field_key:$f,layout_key:$l,label:"Hero Banner",display:"row"}')" >/dev/null
assert_eq "layout label updated" "Hero Banner" "$(wpe '$f=acf_get_field("'"$FLEX"'");$l=array_values($f["layouts"]??[]);echo $l[0]["label"];')"

echo "== 6. Attach the group to another CPT (location_assign) + verify =="
acfm "$(jq -n --arg g "$GROUP" '{action:"acf_location_assign",group_id:$g,rules:{param:"post_type",operator:"==",value:"page"}}')" >/dev/null
assert_eq "group now applies to a page" "true" "$(wpe '$gs=acf_get_field_groups(["post_type"=>"page"]); $hit=false; foreach($gs as $g){ if($g["key"]==="'"$GROUP"'") $hit=true; } echo $hit?"true":"false";')"

echo "== 7. Verify structure visible (admin UI data) via acf_group_get =="
GG=$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_group_get",group_id:$g}')")
assert_eq "group_get returns >=5 top-level fields" "true" "$(echo "$GG" | jq -r '(.fields | length) >= 5')"

echo "== 8. Structured errors =="
assert_eq "unsupported type" "wpcc_acf_unsupported_field_type" "$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_field_create",group_id:$g,type:"bogus",label:"X"}')" | jq -r '.code // "none"')"
assert_eq "layout on non-flexible field" "wpcc_acf_not_flexible" "$(acfm "$(jq -n --arg f "$REPK" '{action:"acf_layout_create",field_key:$f,name:"x"}')" | jq -r '.code // "none"')"
assert_eq "field_create missing parent" "wpcc_acf_missing_parent" "$(acfm '{"action":"acf_field_create","type":"text","label":"X"}' | jq -r '.code // "none"')"
assert_eq "field_create bad parent" "wpcc_acf_parent_not_found" "$(acfm '{"action":"acf_field_create","parent":"field_nope","type":"text","label":"X"}' | jq -r '.code // "none"')"

echo "== 9. MCP parity =="
assert_eq "MCP acf_group_get fields" "true" "$(acfmcp "$(jq -n --arg g "$GROUP" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"acf_manage",arguments:{action:"acf_group_get",group_id:$g}}}')" | jq -r '(.fields | length) >= 5')"

echo "== 10. Rollback a field_create removes the field =="
TMP=$(acfm "$(jq -n --arg g "$GROUP" '{action:"acf_field_create",group_id:$g,type:"text",label:"Temp"}')")
TMPK=$(echo "$TMP" | jq -r '.field_key'); TMPR=$(echo "$TMP" | jq -r '.rollback_id')
assert_eq "temp field exists" "yes" "$(wpe 'echo acf_get_field("'"$TMPK"'")?"yes":"no";')"
acfrb "$(jq -n --arg r "$TMPR" '{rollback_id:$r}')" >/dev/null
assert_eq "temp field removed after rollback" "no" "$(wpe 'echo acf_get_field("'"$TMPK"'")?"yes":"no";')"

echo
echo "================================================"
echo "  ACF Runtime (STEP 92): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
