# STEP 93 — WooCommerce Product Runtime

## Goal

Complete WooCommerce product management over REST and MCP, covering the full
product data model with rollback.

## Audit

`WooCommerceRuntimeManager` already had `product_list/get/search`,
`product_create/update/delete/duplicate/publish/unpublish`, category/attribute
assignment, and `variation_create/update/delete/get/list`. STEP 93 filled the
product-data gaps and completed update rollback.

## Added

`product_create` and `product_update` now apply the **full product data model**
via a shared `apply_product_fields()`:

- title (name), description, **short_description**, sku
- pricing (regular_price, sale_price), status
- inventory (manage_stock, stock_quantity, stock_status)
- **categories** and **tags** (by name — created if missing — or ID)
- **images** (image_id featured, gallery_image_ids)
- **attributes** (`[{ name, options, visible, variation }]` → WC_Product_Attribute)

`product_create` also accepts **`type`** (simple | variable | grouped |
external), so variable products with variations can be built end to end.

## Fixed

`product_update` had **no rollback restore path** (the switch lacked a
`product_update` case) and its snapshot (`format_product`) captured only a subset
of fields. Added `snapshot_product()` (full editable state incl. short
description, tags, images, attributes) and `restore_product()`, wired into the
rollback switch. `store_rollback()` now returns the id, and create/update return
`rollback_id`.

## Operations (REST `/operations/woocommerce_manage/run` + `/rollback`, MCP)

`product_create` / `product_update` (enhanced), plus the existing
publish/unpublish/duplicate/delete and variation/category/attribute actions —
all `medium` risk, audited (`product.created/updated`), rollback-capable.
Structured errors: `wpcc_missing_name`, `wpcc_product_not_found`, `wpcc_not_variable`.

## Acceptance tests — `tests/test-woocommerce-product-step93.sh` (19/19)

Workflow: create a **variable** product with full data (descriptions, sku,
categories, tags, attributes) → add a featured image → create a variation →
publish → update inventory → **verify frontend** (product page HTTP 200 +
`product_get`). Plus full-data persistence checks, MCP parity, structured errors,
and a complete update rollback. Existing `test-woocommerce-runtime` (117) stays
green.

## Files changed

- `includes/Operations/WooCommerceRuntimeManager.php` — `apply_product_fields`,
  `resolve_terms`, `build_attributes`, `snapshot_product`, `restore_product`;
  enhanced `product_create`/`product_update`; `product_update` rollback case;
  `store_rollback` returns id.
- `includes/Operations/OperationRegistry.php` — `woocommerce_manage` description.

## Test-environment note

WooCommerce 10.8.1 active on the dev site; the workflow was exercised against it,
including a real product front-end page (HTTP 200).

## Preserved guarantees

Backward compatible (additive fields; existing actions unchanged). Security modes
(writes gated medium), approval, rollback, audit, REST/MCP parity intact.
