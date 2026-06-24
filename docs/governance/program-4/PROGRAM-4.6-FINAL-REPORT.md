# PROGRAM-4.6 — WooCommerce Product Rollback Integrity · Final Report

> **Branch:** `program-4.6-woocommerce-products` (from `program-4b-integration-core-hardening` @ `8550a4b`). **No merge / push / deploy.**
> Companion: [Design](PROGRAM-4.6-DESIGN.md).

## 1. Outcome
`product_update` rollback converted from a full-object 16-field snapshot (the F-1 over-reach) to a **field-scoped, drift-aware delta** via the shared `RollbackDelta` core + a new `WooProductAccessor`. Mirrors P4.1–P4.5. Scope held to **products only**; all other Woo entity types/actions unchanged.

## 2. Changed files
- **New:** `includes/Rollback/WooProductAccessor.php` — `FieldAccessor` over `WC_Product`, WC public CRUD only (getters/setters + `save()`), never raw post meta. Per-field-type drift comparators (string / bool / nullable-numeric / id-set / normalized-attributes).
- **New:** `tests/test-woo-product-rollback-delta.sh` — 47 assertions (16 static + 31 functional S1–S9).
- **Modified:** `includes/Operations/WooCommerceRuntimeManager.php` — `product_update` now captures touched-only prior + after and stores a v2 delta record (`store_product_delta` via `OptionListRollbackStore('wpcc_woo_rollbacks',200)`); `rollback()` branches v2 product_update first (`rollback_product_delta` → `RollbackDelta::restore`/`result`, terminal only on `complete`); legacy `before_state` records + every other action/entity fall through unchanged. Dead `snapshot_product` removed; `restore_product` retained for legacy.

Diff stat: 1 file modified (+~118 / −27), 2 new files. No other code touched.

## 3. Tests
- **NEW** woo-product-rollback-delta **47/0** — S1 clearable-prior fidelity, S2 value-prior, S3 disjoint-layered (sibling survives), S4 same-field drift (conflict, sibling not clobbered), S5 out-of-order (no resurrection), S6 structured set (category_ids), S7 legacy `before_state` restore, S8 idempotency, S9 partial (history-honest).
- **Regression (all green):** woocommerce-runtime **117/0**, woocommerce-product-step93 **19/0**, rollback-delta-core **25/0** (core untouched), seo-rollback-delta **56/0**, seo-rollback-store **28/0**, content **30/0**, comment **27/0**, media **41/0**, settings **38/0**, user **28/0**, operations-registry **18/0**, capability-runtime **61/0**, mcp-error-surface **18/0**. Net-new attributable failures: **0**.

## 4. Invariants
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP **40** · DB_VERSION **2.5.0** — held (34/23/2.5.0 probed live; 40/40 via passing guard suites; no registry/schema touched).

## 5. Independent audit
**VERDICT: GO.** No defects, no scope violations. All five STOP conditions confirmed **not** triggered (no schema; WC-CRUD-only ⇒ no data-store corruption; no op/cap/MCP contract change; no security-model change; product_update is product-only). One informational CONCERN (WC may implicitly recalc `stock_status` when a `stock_quantity` save crosses the no-stock threshold) — inherent WC behavior, not introduced, strictly safer than the old full-object restore; documented inline in `product_touched_fields`.

## 6. Backward compatibility
v2 delta records and legacy `before_state` records coexist in `wpcc_woo_rollbacks`; the shared scan resolves by `id`, the new branch handles v2 product_update, everything else (legacy product_update + coupon/variation/order) restores exactly as before. Proven by S7.

## 7. GO / NO-GO
**GO** — F-1 closed for the Woo product runtime; field-scoped, drift-aware, idempotent, legacy-compatible, history-honest; invariants frozen; no forbidden/contract/schema drift; scope held to products (orders/variations/coupons/refunds/customers untouched). **Commit on `program-4.6-woocommerce-products` only — no merge / push / deploy.**

## 8. Deferred (unchanged by this phase)
Drift-awareness for the narrow single-field product actions (stock/price/publish/category/attribute — not full-object over-reach), and Woo **orders** (relational/custom tables — separate sub-design). Next program phases per PROGRAM-4-DESIGN: P4.7 ACF, P4.8 Bulk.
