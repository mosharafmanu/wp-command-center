#!/usr/bin/env bash
#
# F3.2 — Nested ACF read support.
#
# Report: acf_group_get / acf_field_get returned only top-level fields —
# repeater sub-fields and flexible-content layouts were absent even in verbose,
# so you could create nested structures but not read them back to verify/manage.
#
# Root cause (verified): the read serializer (summarize_field) emitted only
# key/label/name/type/required/parent. Sub-fields are persisted as separate
# field posts parented to the container, and acf_get_fields() only resolves
# children when passed the field ARRAY (it treats a key string as a group).
#
# Fix under test: a recursive detail_field() serializer wired into acf_group_get
# / acf_field_get / acf_field_list — repeater/group expose sub_fields,
# flexible_content exposes layouts each with sub_fields. The flat summarize_field
# is intentionally retained for rollback before-state.
#
# Requires: curl, jq, wp, wpcc-env.sh, ACF (Pro for repeater/flex).
# Usage: bash tests/test-acf-nested-read-f32.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"
WP_PATH="$(cd "$SCRIPT_DIR/../../../.." && pwd)"

PASS=0; FAIL=0
pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }
assert_eq() { local d="$1" e="$2" a="$3"; [ "$e" = "$a" ] && pass "$d" || fail "$d (expected '$e', got '$a')"; }
assert_nonempty() { local d="$1" a="$2"; { [ -n "$a" ] && [ "$a" != "null" ]; } && pass "$d" || fail "$d (empty/null)"; }
acf() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/acf_manage/run"; }
acfmcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" \
  -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"acf_manage\",\"arguments\":$1}}" \
  "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

GIDS=""
cleanup() {
  for g in $GIDS; do
    wpe '$x=acf_get_field_group("'"$g"'"); if($x && !empty($x["ID"])) wp_delete_post($x["ID"],true);'
  done
  true
}
trap cleanup EXIT

echo "== Setup: group + repeater(2 sub) + flexible_content(1 layout, 1 sub) =="
C=$(acf '{"action":"acf_group_create","title":"F32 Nested Read"}')
GID=$(echo "$C" | jq -r '.group_id'); GIDS="$GIDS $GID"
assert_nonempty "group created" "$GID"

R=$(acf "$(jq -n --arg g "$GID" '{action:"acf_field_create",parent:$g,type:"repeater",label:"Rep",name:"rep",sub_fields:[{type:"text",label:"S1",name:"s1"},{type:"number",label:"S2",name:"s2"}]}')")
REPKEY=$(echo "$R" | jq -r '.field_key')
assert_nonempty "repeater created" "$REPKEY"
assert_eq "create echoed 2 sub_field keys" "2" "$(echo "$R" | jq -r '.sub_fields | length')"

F=$(acf "$(jq -n --arg g "$GID" '{action:"acf_field_create",parent:$g,type:"flexible_content",label:"Flex",name:"flex"}')")
FLEXKEY=$(echo "$F" | jq -r '.field_key')
assert_nonempty "flexible_content created" "$FLEXKEY"
acf "$(jq -n --arg fk "$FLEXKEY" '{action:"acf_layout_create",field_key:$fk,name:"hero",label:"Hero",sub_fields:[{type:"text",label:"L1",name:"l1"}]}')" >/dev/null

echo "== 1. acf_group_get surfaces repeater sub_fields (the F3.2 gap) =="
G=$(acf "$(jq -n --arg g "$GID" '{action:"acf_group_get",group_id:$g}')")
assert_eq "repeater sub_fields count = 2" "2" "$(echo "$G" | jq -r '.fields[] | select(.type=="repeater") | .sub_fields | length')"
assert_eq "repeater sub_field names round-trip" "s1,s2" "$(echo "$G" | jq -r '[.fields[] | select(.type=="repeater") | .sub_fields[].name] | join(",")')"
assert_eq "nested sub_field type preserved" "number" "$(echo "$G" | jq -r '.fields[] | select(.type=="repeater") | .sub_fields[] | select(.name=="s2") | .type')"

echo "== 2. acf_group_get surfaces flexible_content layouts + their sub_fields =="
assert_eq "flex layouts count = 1" "1" "$(echo "$G" | jq -r '.fields[] | select(.type=="flexible_content") | .layouts | length')"
assert_eq "layout name = hero" "hero" "$(echo "$G" | jq -r '.fields[] | select(.type=="flexible_content") | .layouts[0].name')"
assert_eq "layout sub_fields count = 1" "1" "$(echo "$G" | jq -r '.fields[] | select(.type=="flexible_content") | .layouts[0].sub_fields | length')"
assert_eq "layout sub_field name = l1" "l1" "$(echo "$G" | jq -r '.fields[] | select(.type=="flexible_content") | .layouts[0].sub_fields[0].name')"

echo "== 3. acf_field_get on the repeater returns its sub_fields =="
FG=$(acf "$(jq -n --arg k "$REPKEY" '{action:"acf_field_get",field_key:$k}')")
assert_eq "field_get repeater sub_fields = 2" "2" "$(echo "$FG" | jq -r '.field.sub_fields | length')"

echo "== 4. acf_field_get on the flexible_content field returns layouts =="
FF=$(acf "$(jq -n --arg k "$FLEXKEY" '{action:"acf_field_get",field_key:$k}')")
assert_eq "field_get flex layout sub_fields = 1" "1" "$(echo "$FF" | jq -r '.field.layouts[0].sub_fields | length')"

echo "== 5. acf_field_list also serializes nested structure =="
FL=$(acf "$(jq -n --arg g "$GID" '{action:"acf_field_list",group_id:$g}')")
assert_eq "field_list repeater sub_fields = 2" "2" "$(echo "$FL" | jq -r '.fields[] | select(.type=="repeater") | .sub_fields | length')"

echo "== 6. Regression guard: a plain text field gains NO sub_fields/layouts keys =="
acf "$(jq -n --arg g "$GID" '{action:"acf_field_create",parent:$g,type:"text",label:"Plain",name:"plain"}')" >/dev/null
G2=$(acf "$(jq -n --arg g "$GID" '{action:"acf_group_get",group_id:$g}')")
assert_eq "text field has no sub_fields key" "false" "$(echo "$G2" | jq -r '[.fields[] | select(.type=="text") | has("sub_fields")] | any')"
assert_eq "text field has no layouts key" "false" "$(echo "$G2" | jq -r '[.fields[] | select(.type=="text") | has("layouts")] | any')"

echo "== 7. MCP parity: acf_group_get over MCP returns nested too =="
M=$(acfmcp "$(jq -n --arg g "$GID" '{action:"acf_group_get",group_id:$g}')")
assert_eq "MCP repeater sub_fields = 2" "2" "$(echo "$M" | jq -r '.fields[] | select(.type=="repeater") | .sub_fields | length')"
assert_eq "MCP flex layout sub_fields = 1" "1" "$(echo "$M" | jq -r '.fields[] | select(.type=="flexible_content") | .layouts[0].sub_fields | length')"

echo
echo "===================================="
echo "  F3.2 nested ACF read: $PASS passed, $FAIL failed"
echo "===================================="
[ "$FAIL" -eq 0 ]
