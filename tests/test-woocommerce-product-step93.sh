#!/usr/bin/env bash
#
# STEP 93 — WooCommerce Product Runtime acceptance suite.
#
# Complete product management over REST + MCP: create (incl. variable), full
# product data (title, descriptions, images, categories, tags, attributes,
# variations, inventory, pricing, SKU), publish/unpublish, duplicate, delete,
# with rollback, audit, structured errors, and frontend verification.
#
# Workflow: create product → add image → add attributes → create variation →
# publish → update inventory → verify frontend.
#
# Requires WooCommerce active. Requires: curl, jq, wp, wpcc-env.sh.
# Usage: bash tests/test-woocommerce-product-step93.sh

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
  echo "  SKIP: WooCommerce not active"; echo "  WooCommerce Product (STEP 93): 0 passed, 0 failed"; exit 0
fi

# Fixture image attachment.
IMG=$(wpe '$id=wp_insert_attachment(["post_title"=>"S93 Img","post_mime_type"=>"image/png","post_status"=>"inherit"],false,0);echo $id;')
PROD=""
cleanup() { [ -n "$PROD" ] && wpe 'wp_delete_post('"$PROD"',true);'; wpe 'wp_delete_post('"$IMG"',true);' >/dev/null 2>&1; }
trap cleanup EXIT

echo "== 1. Create variable product with full data =="
R=$(woo '{"action":"product_create","name":"S93 Acceptance Tee","type":"variable","status":"draft","description":"Full description here.","short_description":"Soft cotton tee","sku":"S93-TEE","regular_price":"25.00","categories":["S93 Apparel"],"tags":["s93new"],"attributes":[{"name":"Color","options":["Red","Blue"],"visible":true,"variation":true}]}')
PROD=$(echo "$R" | jq -r '.product_id')
assert_nonempty "product created" "$PROD"
assert_eq "product type variable" "variable" "$(echo "$R" | jq -r '.type')"
assert_nonempty "product rollback_id" "$(echo "$R" | jq -r '.rollback_id')"

echo "== 2. Verify full product data persisted =="
assert_eq "sku" "S93-TEE" "$(wpe 'echo wc_get_product('"$PROD"')->get_sku();')"
assert_eq "short_description" "Soft cotton tee" "$(wpe 'echo wc_get_product('"$PROD"')->get_short_description();')"
assert_eq "category" "S93 Apparel" "$(wpe 'echo implode(",",wp_get_post_terms('"$PROD"',"product_cat",["fields"=>"names"]));')"
assert_eq "tag" "s93new" "$(wpe 'echo implode(",",wp_get_post_terms('"$PROD"',"product_tag",["fields"=>"names"]));')"
assert_eq "attribute present" "color" "$(wpe '$a=wc_get_product('"$PROD"')->get_attributes();echo implode(",",array_keys($a));')"

echo "== 3. Add featured image (product_update image_id) =="
woo "$(jq -n --argjson pid "$PROD" --argjson img "$IMG" '{action:"product_update",product_id:$pid,image_id:$img}')" >/dev/null
assert_eq "image set" "$IMG" "$(wpe 'echo (int) wc_get_product('"$PROD"')->get_image_id();')"

echo "== 4. Create a variation =="
VAR=$(woo "$(jq -n --argjson pid "$PROD" '{action:"variation_create",product_id:$pid,regular_price:"25.00",attributes:{"color":"red"}}')")
assert_eq "variation created" "variation_create" "$(echo "$VAR" | jq -r '.action // .code')"

echo "== 5. Publish =="
woo "$(jq -n --argjson pid "$PROD" '{action:"product_publish",product_id:$pid}')" >/dev/null
assert_eq "status publish" "publish" "$(wpe 'echo wc_get_product('"$PROD"')->get_status();')"

echo "== 6. Update inventory =="
woo "$(jq -n --argjson pid "$PROD" '{action:"product_update",product_id:$pid,manage_stock:true,stock_quantity:37}')" >/dev/null
assert_eq "stock quantity" "37" "$(wpe 'echo (int) wc_get_product('"$PROD"')->get_stock_quantity();')"

echo "== 7. Verify frontend =="
PERMALINK=$(wpe 'echo get_permalink('"$PROD"');')
assert_eq "product page HTTP 200" "200" "$(curl -s -o /dev/null -w "%{http_code}" "$PERMALINK")"
assert_eq "product retrievable via product_get" "$PROD" "$(woo "$(jq -n --argjson pid "$PROD" '{action:"product_get",product_id:$pid}')" | jq -r '.product.id // .product_id // empty')"

echo "== 8. MCP parity =="
assert_eq "MCP product_get id" "$PROD" "$(woomcp "$(jq -n --argjson pid "$PROD" '{jsonrpc:"2.0",id:1,method:"tools/call",params:{name:"woocommerce_manage",arguments:{action:"product_get",product_id:$pid}}}')" | jq -r '.product.id // .product_id // empty')"

echo "== 9. Structured errors =="
assert_eq "missing name → wpcc_missing_name" "wpcc_missing_name" "$(woo '{"action":"product_create","type":"simple"}' | jq -r '.code // "none"')"
assert_eq "product not found" "wpcc_product_not_found" "$(woo '{"action":"product_update","product_id":99999999,"name":"X"}' | jq -r '.code // "none"')"

echo "== 10. Rollback an update (short_description; variable products have no product-level price) =="
UP=$(woo "$(jq -n --argjson pid "$PROD" '{action:"product_update",product_id:$pid,short_description:"CHANGED short desc"}')")
URID=$(echo "$UP" | jq -r '.rollback_id')
assert_eq "short_description changed" "CHANGED short desc" "$(wpe 'echo wc_get_product('"$PROD"')->get_short_description();')"
woorb "$(jq -n --arg r "$URID" '{rollback_id:$r}')" >/dev/null
assert_eq "short_description restored after rollback" "Soft cotton tee" "$(wpe 'echo wc_get_product('"$PROD"')->get_short_description();')"

echo
echo "================================================"
echo "  WooCommerce Product (STEP 93): $PASS passed, $FAIL failed"
echo "================================================"
[ "$FAIL" -eq 0 ]
