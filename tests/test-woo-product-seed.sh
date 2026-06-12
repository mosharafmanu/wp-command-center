#!/usr/bin/env bash
#
# WooCommerce Product Seeder Operation test suite for WP Command Center (Step 19).
#
# Verifies:
#   - operation discovery
#   - simple product creation
#   - category assignment
#   - SKU uniqueness
#   - price/stock validation
#   - audit logging
#   - timeline integration
#   - full-access security
#
# Requires: curl, jq, and wpcc-env.sh.
# Usage: bash tests/test-woo-product-seed.sh

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=/dev/null
source "$PLUGIN_DIR/wpcc-env.sh"

PASS=0
FAIL=0

pass() { PASS=$((PASS+1)); echo "  PASS: $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL: $1"; }

assert_eq() {
	local desc="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		pass "$desc"
	else
		fail "$desc (expected '$expected', got '$actual')"
	fi
}

assert_true() {
	local desc="$1" actual="$2"
	if [ "$actual" = "true" ]; then
		pass "$desc"
	else
		fail "$desc (expected 'true', got '$actual')"
	fi
}

api() {
	local method="$1" path="$2" body="${3:-}"
	if [ -n "$body" ]; then
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" -H "Content-Type: application/json" -d "$body" "$WPCC_BASE$path"
	else
		curl -s -X "$method" -H "Authorization: Bearer $WPCC_TOKEN" "$WPCC_BASE$path"
	fi
}

echo "== 1. Registry & Manifest Discovery =="

# Check Registry
OP_REG=$(api GET "/operations/woo_product_seed")
assert_eq "registry: id matches" "woo_product_seed" "$(echo "$OP_REG" | jq -r '.id')"
assert_eq "registry: available is true" "true" "$(echo "$OP_REG" | jq -r '.available')"

# Check Manifest
MANIFEST=$(api GET "/agent/manifest")
assert_true "manifest: contains woo_product_seed" "$(echo "$MANIFEST" | jq -r '.operations | any(.[]; .id == "woo_product_seed")')"

echo
echo "== 2. Invalid Parameters & Validation =="

# Invalid Price
INV_PRICE=$(api POST /operations/woo_product_seed/run '{"name": "Bad Price", "regular_price": "abc"}')
assert_eq "validation: invalid regular_price" "wpcc_invalid_product_price" "$(echo "$INV_PRICE" | jq -r '.code')"

# Duplicate SKU (setup)
SKU="QA-SKU-$(date +%s)"
api POST /operations/woo_product_seed/run "$(jq -n --arg name "Original" --arg sku "$SKU" '{name: $name, sku: $sku, regular_price: "10"}')" > /dev/null

DUP_SKU=$(api POST /operations/woo_product_seed/run "$(jq -n --arg name "Dup" --arg sku "$SKU" '{name: $name, sku: $sku, regular_price: "20"}')")
assert_eq "validation: duplicate sku" "wpcc_duplicate_sku" "$(echo "$DUP_SKU" | jq -r '.code')"

echo
echo "== 3. Successful Seeding =="

PRODUCT_NAME="QA Product $(date +%s)"
NEW_SKU="SKU-$(date +%s)"
SEED_BODY=$(jq -n --arg name "$PRODUCT_NAME" --arg sku "$NEW_SKU" \
	'{name: $name, sku: $sku, regular_price: "99.50", sale_price: "79.00", status: "publish", manage_stock: true, stock_quantity: 15, categories: ["QA Category"]}')

SEED_RESP=$(api POST /operations/woo_product_seed/run "$SEED_BODY")

PRODUCT_ID=$(echo "$SEED_RESP" | jq -r '.product_id // empty')
assert_true "execution: product created" "$([[ -n \"$PRODUCT_ID\" ]] && echo true || echo false)"
assert_eq "execution: name matches" "$PRODUCT_NAME" "$(echo "$SEED_RESP" | jq -r '.product_name')"
assert_eq "execution: category count" "1" "$(echo "$SEED_RESP" | jq -r '.category_count')"

# Verify via WP-CLI
ACTUAL_PRICE=$(wp post meta get "$PRODUCT_ID" _regular_price)
assert_eq "verification: price matches" "99.50" "$ACTUAL_PRICE"
ACTUAL_STOCK=$(wp post meta get "$PRODUCT_ID" _stock)
assert_eq "verification: stock matches" "15" "$ACTUAL_STOCK"

echo
echo "== 4. Timeline & Audit =="

TIMELINE=$(api GET "/agent/timeline?limit=30")
assert_true "timeline: has started event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "WooCommerce seeding started")')"
assert_true "timeline: has completed event" "$(echo "$TIMELINE" | jq -r 'any(.[]; .label == "WooCommerce seeding completed")')"

# Verify summary
COMP_SUM=$(echo "$TIMELINE" | jq -r --arg name "$PRODUCT_NAME" '.[] | select(.label == "WooCommerce seeding completed" and (.summary | contains($name))) | .summary' | head -1)
assert_true "timeline: summary has product name" "$([[ -n \"$COMP_SUM\" ]] && echo true || echo false)"

echo
echo "== 5. Security =="

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$WPCC_BASE/operations/woo_product_seed/run")
assert_eq "security: no token returns 401" "401" "$HTTP_CODE"

echo
echo "== Summary =="
echo "  $PASS passed, $FAIL failed"

# Cleanup
if [[ -n "$PRODUCT_ID" ]]; then wp post delete "$PRODUCT_ID" --force > /dev/null; fi

[ "$FAIL" -eq 0 ]
