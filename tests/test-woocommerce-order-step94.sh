#!/usr/bin/env bash
#
# STEP 94 — WooCommerce Order Runtime acceptance suite.
#
# Order + customer management over REST + MCP: order_update, order_note_add,
# order_status_change, refund_create, customer_get, customer_search — with
# rollback, audit, and structured errors.
#
# Workflow: create order → read order → add note → change status → verify.
# Plus update, refund, customer lookups, rollback, errors, MCP parity.
#
# Requires WooCommerce active. Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-woocommerce-order-step94.sh

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
woo() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/woocommerce_manage/run"; }
woomcp() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/mcp" | jq -r '.result.content[0].text // empty'; }
woorb() { curl -s -X POST -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$1" "$WPCC_BASE/operations/woocommerce_manage/rollback"; }
wpe() { wp eval "$1" --path="$WP_PATH" 2>/dev/null; }

if [ "$(wpe 'echo class_exists("WooCommerce")?"yes":"no";')" != "yes" ]; then
  echo "  SKIP: WooCommerce not active"; echo "  WooCommerce Order (STEP 94): 0 passed, 0 failed"; exit 0
fi

# Fixtures: customer, product, and an order with a line item (total > 0).
read OID CID PRODID < <(wpe '
$u = wp_insert_user(["user_login"=>"s94_cust","user_email"=>"s94order@example.com","first_name"=>"Sam","last_name"=>"Buyer","role"=>"customer","user_pass"=>wp_generate_password()]);
$p = new WC_Product_Simple(); $p->set_name("S94 Order Product"); $p->set_regular_price("20.00"); $p->set_status("publish"); $pid=$p->save();
$o = wc_create_order(); $o->add_product(wc_get_product($pid),2); $o->set_customer_id($u);
$o->set_billing_email("s94order@example.com"); $o->set_billing_first_name("Sam"); $o->set_billing_last_name("Buyer");
$o->calculate_totals(); $o->set_status("processing"); $o->save();
echo $o->get_id()." ".$u." ".$pid;
')
cleanup() { wpe 'wp_delete_post('"${OID:-0}"',true); wp_delete_post('"${PRODID:-0}"',true); wp_delete_user('"${CID:-0}"');' >/dev/null 2>&1; }
trap cleanup EXIT
assert_nonempty "fixture: order created" "$OID"
assert_nonempty "fixture: customer created" "$CID"

echo "== 1. Read order =="
R=$(woo "$(jq -n --argjson o "$OID" '{action:"order_get",order_id:$o}')")
assert_eq "order_get id" "$OID" "$(echo "$R" | jq -r '.order.id')"
assert_eq "order_get status" "processing" "$(echo "$R" | jq -r '.order.status')"

echo "== 2. Add an order note =="
NOTE=$(woo "$(jq -n --argjson o "$OID" '{action:"order_note_add",order_id:$o,note:"Packed and shipped",customer_note:true}')")
assert_eq "note added (note_id>0)" "true" "$(echo "$NOTE" | jq -r '.note_id > 0')"
assert_nonempty "note rollback_id" "$(echo "$NOTE" | jq -r '.rollback_id')"

echo "== 3. Change status + rollback =="
SC=$(woo "$(jq -n --argjson o "$OID" '{action:"order_status_change",order_id:$o,status:"completed",note:"done"}')")
assert_eq "status changed to completed" "completed" "$(echo "$SC" | jq -r '.status')"
assert_eq "previous_status recorded" "processing" "$(echo "$SC" | jq -r '.previous_status')"
woorb "$(jq -n --arg r "$(echo "$SC" | jq -r '.rollback_id')" '{rollback_id:$r}')" >/dev/null
assert_eq "status rolled back to processing" "processing" "$(wpe 'echo wc_get_order('"$OID"')->get_status();')"

echo "== 4. Update order (customer_note + billing) + rollback =="
UP=$(woo "$(jq -n --argjson o "$OID" '{action:"order_update",order_id:$o,customer_note:"Leave at door",billing:{phone:"555-9999"}}')")
assert_eq "customer_note set" "Leave at door" "$(wpe 'echo wc_get_order('"$OID"')->get_customer_note();')"
assert_eq "billing phone set" "555-9999" "$(wpe 'echo wc_get_order('"$OID"')->get_billing_phone();')"
woorb "$(jq -n --arg r "$(echo "$UP" | jq -r '.rollback_id')" '{rollback_id:$r}')" >/dev/null
assert_eq "customer_note rolled back (empty)" "" "$(wpe 'echo wc_get_order('"$OID"')->get_customer_note();')"

echo "== 5. Create a refund + rollback =="
REF=$(woo "$(jq -n --argjson o "$OID" '{action:"refund_create",order_id:$o,amount:"10.00",reason:"goodwill"}')")
assert_eq "refund created (refund_id>0)" "true" "$(echo "$REF" | jq -r '.refund_id > 0')"
assert_eq "order has 1 refund" "1" "$(wpe 'echo count(wc_get_order('"$OID"')->get_refunds());')"
woorb "$(jq -n --arg r "$(echo "$REF" | jq -r '.rollback_id')" '{rollback_id:$r}')" >/dev/null
assert_eq "refund removed after rollback" "0" "$(wpe 'echo count(wc_get_order('"$OID"')->get_refunds());')"

echo "== 6. Customer lookups =="
assert_eq "customer_get by id" "$CID" "$(woo "$(jq -n --argjson c "$CID" '{action:"customer_get",customer_id:$c}')" | jq -r '.customer.id')"
assert_eq "customer_get by email" "$CID" "$(woo '{"action":"customer_get","email":"s94order@example.com"}' | jq -r '.customer.id')"
assert_eq "customer_search finds customer" "true" "$(woo '{"action":"customer_search","search":"s94order"}' | jq -r '(.customers | length) > 0')"

echo "== 7. MCP parity =="
assert_eq "MCP order_get id" "$OID" "$(woomcp "$(jq -n --argjson o "$OID" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"woocommerce_manage",arguments:{action:"order_get",order_id:$o}}}')" | jq -r '.order.id')"

echo "== 8. Structured errors =="
assert_eq "order not found" "wpcc_order_not_found" "$(woo '{"action":"order_status_change","order_id":99999999,"status":"completed"}' | jq -r '.code // "none"')"
assert_eq "invalid status" "wpcc_invalid_order_status" "$(woo "$(jq -n --argjson o "$OID" '{action:"order_status_change",order_id:$o,status:"bogus"}')" | jq -r '.code // "none"')"
assert_eq "empty note" "wpcc_empty_note" "$(woo "$(jq -n --argjson o "$OID" '{action:"order_note_add",order_id:$o,note:""}')" | jq -r '.code // "none"')"
assert_eq "customer not found" "wpcc_customer_not_found" "$(woo '{"action":"customer_get","customer_id":99999999}' | jq -r '.code // "none"')"

echo
echo "================================================"
echo "  WooCommerce Order (STEP 94): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
