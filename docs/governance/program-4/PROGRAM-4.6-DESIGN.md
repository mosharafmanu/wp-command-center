# PROGRAM-4.6 — WooCommerce Product Rollback Integrity · Design Report

> **Branch:** `program-4.6-woocommerce-products` (from `program-4b-integration-core-hardening` @ `8550a4b`).
> **Type:** design report (no code). **No merge / push / deploy.**
> **Scope:** WooCommerce **products only**. Orders, refunds, customers, coupons, variations, webhooks, shipping, tax, payments, reports explicitly **out of scope**.
> **Goal:** close the F-1 full-object-snapshot over-reach for the Woo product runtime by converting `product_update` rollback to the field-scoped, drift-aware `RollbackDelta` core (Pattern B), reusing `build_record` + `restore` + `result` and a new `WooProductAccessor`. Mirrors P4.1–P4.5.

---

## 1. Base confirmation
- HEAD `8550a4b`; `2234dcc` (P4.0) `0788720` (P4.1) `8982e6c` (P4.2) `dbc7c47` (P4.3) `4ccf18b` (P4.4) `6b5d0ef` (P4.5) `6a8aad0` (octopus) all ancestors ✓.
- `main` = `a41a9d7` unchanged ✓. No uncommitted **code** (untracked docs only) ✓.
- Invariants live: OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0.

## 2. Current Woo product rollback (the defect)
`WooCommerceRuntimeManager::product_update` (`:121`) captures a **full 16-field snapshot** (`snapshot_product` `:712`) and `restore_product` (`:733`) writes **every** snapshotted field back unconditionally. This is the F-1 over-reach: layered field-wise edits to the same product clobber siblings on rollback (edit A=price, edit B=name → rollback A restores A's *name* too, clobbering B), with no drift detection (out-of-order resurrection) and no existence/empty fidelity reporting.

The other product write actions are **not** full-object over-reach and are **out of this phase's change set**:
- `stock_update`, `price_update`, `sale_price_update`, `product_publish/unpublish`, `category_assign/remove`, `attribute_assign/remove` each capture only their own touched field(s) — narrow, no sibling clobber. (They lack drift-awareness, a *lesser* concern; deferred.)
- `product_create`, `product_delete`, `product_duplicate` are inverse-action (delete/republish) — drift-safe by construction.

**Decision:** P4.6 converts **`product_update` only** to Pattern B. This is the single full-object instance and the identified Woo F-1 driver. All other actions and entity types keep their existing legacy path verbatim — the shared `store_rollback`/`rollback` remain backward-compatible (dual-read). This exactly mirrors P4.3 Content ("delete/legacy unchanged").

## 3. Reused core (no new core code)
- `RollbackDelta::capture / build_record / restore / result` — unchanged.
- `FieldAccessor` interface — unchanged.
- Storage: the existing `wpcc_woo_rollbacks` option (FIFO cap 200), now holding **v2 delta records** for `product_update` alongside legacy records. No schema, no new option, no DB_VERSION change.

## 4. New code (one file + edits to one runtime)
### 4.1 `includes/Rollback/WooProductAccessor.php` (new)
A `FieldAccessor` over a `WC_Product`, driving **WC public CRUD only** (getters/setters + `save()`) — **never raw postmeta**. This is the WooCommerce-sanctioned write path and preserves WC-derived state (price/stock lookup tables, term relationships). It is therefore **corruption-safe** — the "Woo data-store uncertainty" STOP does not trigger.

Field model — each unified product field is its own single backing key (key == field name):
| Unified field | getter / setter | drift `equals` |
|---|---|---|
| name, description, short_description, sku, regular_price, sale_price, status, stock_status | `get_*` / `set_*` | string |
| manage_stock | `get_manage_stock`/`set_manage_stock` | bool |
| stock_quantity | `get_stock_quantity`/`set_stock_quantity` | nullable-numeric |
| image_id | `get_image_id`/`set_image_id` | int |
| category_ids, tag_ids, gallery_image_ids | `get_*_ids`/`set_*_ids` | order-insensitive int-set |
| attributes | `get_attributes`/`set_attributes` | normalized (name→options/flags) compare |

- `key_exists` → always `true` (a WC product property is always present) ⇒ restore always writes the prior value (like Content/Media post-columns); `key_delete` is unreachable.
- `key_get`/`read_field` → the WC getter (scalar or array/objects).
- `key_set` → `wc_get_product($id)`; `set_*($value)`; `save()` (per-field load+set+save; mirrors `MediaFieldAccessor`'s per-column `wp_update_post`; rollback is a cold path). Per-field independent saves are safe — each property maps to an independent meta/term write; stock_quantity does **not** require manage_stock at the data-store level.

### 4.2 `WooCommerceRuntimeManager` edits (product_update path only)
- `product_update`: replace `snapshot_product` full snapshot + `store_rollback('product_update', $before)` with: compute **touched** fields from payload (only fields this call writes), `RollbackDelta::capture` (prior), `apply_product_fields` + `save`, read post-write `after`, `store_product_delta` (v2 record into `wpcc_woo_rollbacks`). Returns `rollback_id` as before.
- `rollback()`: **branch first** for v2 product_update records (`version===2 && isset(fields)`) → `RollbackDelta::restore(new WooProductAccessor, $id, $fields)`, mark applied **only on `complete`**, audit, return `RollbackDelta::result([...], $o)`. Legacy `product_update` records (carrying `before_state`) and **all other actions/entity types** fall through to the existing switch **unchanged**.
- New `store_product_delta()` helper builds the v2 record via `RollbackDelta::build_record(..., head=['id','entity_id','entity_type'=>'product','action'=>'product_update'])` and appends to `wpcc_woo_rollbacks` with the same 200 cap (reuse `OptionListRollbackStore('wpcc_woo_rollbacks',200)`).
- `snapshot_product`/`restore_product` are **retained** (legacy records still restore through them).

Touched-field derivation matches `apply_product_fields` exactly so capture/after/restore cover precisely the fields written (name, description, short_description, sku, regular_price, sale_price, status, manage_stock, stock_quantity, stock_status, category_ids←categories, tag_ids←tags, image_id, gallery_image_ids, attributes).

## 5. Contract / invariant impact — NONE
- No change to `WooCommerceRegistry::ACTIONS`, risk/approval, or `supports_rollback` (`product_update` already supports rollback).
- No operation-map / capability / MCP-tool / REST-route / UI change. Dispatch path (`OperationExecutor::rollback` → public `rollback()`; `result['error']` ⇒ success=false) unchanged.
- No schema / DB_VERSION change (reuse `wpcc_woo_rollbacks` option; v2 record shape, like Content/Settings).
- Invariants 34 · 23 · 40 · 40 · 2.5.0 frozen.

## 6. Backward compatibility
- v2 and legacy records coexist in `wpcc_woo_rollbacks`; the shared scan finds by `id`; v2 product_update branches to delta, everything else to the legacy switch.
- Pre-existing legacy `product_update` `before_state` records still restore via `restore_product`.
- Record format for non-product actions is byte-identical.

## 7. Validation plan
New `tests/test-woo-product-rollback-delta.sh` (clone of `test-seo-rollback-delta.sh`), PHP-bootstrapped over a real WC product:
- **S1** empty/absent-equivalent prior fidelity (clearable field e.g. sale_price '').
- **S2** value-prior fidelity (exact restore).
- **S3** disjoint layered (A=regular_price, B=name; rollback A → name survives).
- **S4** same-field drift (A,B both price; rollback A → conflict, B not clobbered, reported).
- **S5** out-of-order (rollback B then retry A → complete, no resurrection).
- **S6** structured fidelity (category_ids set restore; attributes restore).
- **S7** legacy `before_state` record still restores.
- **S8** idempotency (second rollback guarded).
- **S9** history honesty (partial → restored/skipped lists; drifted sibling preserved).
- Static: v2 record shape, accessor `equals`, legacy branch retained, WC-CRUD-only (no raw `update_post_meta` in accessor).
Plus regression: `test-woocommerce-runtime.sh`, `test-woocommerce-product-step93.sh` stay green at current tally; SEO `56/0`, core `25/0` unchanged; invariant guard suites green.

## 8. STOP conditions — none triggered
- Schema change: none (reuse option). ✗
- Woo data-store corruption risk: none — WC public CRUD only. ✗
- Operation/capability/MCP contract change: none. ✗
- Security model change: none. ✗
- `product_update` broader than product-only: no — operates on `wc_get_product` + product fields. ✗

## 9. Files
- **New:** `includes/Rollback/WooProductAccessor.php`; `tests/test-woo-product-rollback-delta.sh`.
- **Modified:** `includes/Operations/WooCommerceRuntimeManager.php` (product_update + rollback branch + store_product_delta).
- **Untouched:** registry, capability/MCP/REST, UI, schema, all non-product Woo code, `snapshot_product`/`restore_product` (retained for legacy).
