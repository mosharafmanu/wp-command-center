# PROGRAM-4 — Consolidation Inventory Reconciliation (Phase A)

> **Type:** audit-only inventory (no code changes). Report-only.
> **Verification basis:** source + tests read **directly at HEAD `c23fc19`** (`program-4.10-elementor-rollback-integrity`); prior reports were NOT trusted. Production `a41a9d7` (no Program-4 work deployed).

---

## 0. Headline reconciliation finding (HIGH)
**No single branch contains all completed Program-4 work.** The current tip (P4.10) lineage is:
```
main a41a9d7 → P4B 8550a4b (octopus of P4.0–P4.5) → P4C.0a 5a57db4 → P4.7 97e9ccd → P4.8 81afaab → P4.9 6fff16c → P4.10 c23fc19
```
**P4.6 (Woo Products, `c8fb602`) is NOT an ancestor of HEAD** (verified: `git merge-base --is-ancestor` = false). P4C.0a forked from **P4B** in parallel with P4.6 and never incorporated it. Consequences verified at HEAD:
- `includes/Rollback/WooProductAccessor.php` — **ABSENT**.
- `tests/test-woo-product-rollback-delta.sh` — **ABSENT**.
- `WooCommerceRuntimeManager::product_update` still uses `snapshot_product` (`:124`, `:712`) — the **pre-P4.6 F-1 full-object snapshot**.

So at the consolidation tip, **Woo product rollback is still F-1-vulnerable**, even though P4.6 fixed it on its own (validated) branch. This is an **integration/consolidation defect, not a code defect in P4.6**.

---

## 1. Migrated runtimes (RollbackDelta core) — verified at HEAD

All 9 call `RollbackDelta::restore` (drift-aware) and pass their suites at HEAD.

| Runtime | Rollback model | Storage | Drift-aware | Sibling-protected | Out-of-order safe | Partial/conflict honest | Legacy-compat | Tests @HEAD | Cert status |
|---|---|---|---|---|---|---|---|---|---|
| **SEO** | field-scoped delta (reference) | postmeta `_wpcc_seo_rb_{id}` | ✅ | ✅ | ✅ | ✅ | ✅ (option fallback) | 56/0 | **certifiable** |
| **Settings** | field-scoped delta | option `wpcc_settings_rollbacks` v2 (cap 200) | ✅ | ✅ | ✅ | ✅ | ✅ | 38/0 | **certifiable** (residual: permalink rewrite_rules not captured — LOW) |
| **Media metadata** | field-scoped delta | option `wpcc_media_rollbacks` v2 (cap 100) | ✅ | ✅ | ✅ | ✅ | ✅ | 41/0 | **certifiable** (bytes via Pattern C) |
| **Content** | field-scoped delta | option `wpcc_content_rollbacks` (keyed) | ✅ | ✅ | ✅ | ✅ | ✅ | 30/0 | **certifiable** |
| **Comments** | field-scoped delta | option `wpcc_comments_rollbacks` v2 (cap 100) | ✅ | ✅ | ✅ | ✅ | ✅ | 27/0 | **certifiable** |
| **Users** | field-scoped delta | option `wpcc_user_rollbacks` v2 (cap 100) | ✅ | ✅ | ✅ | ✅ | ✅ | 28/0 | **certifiable** |
| **Bulk** | per-item field delta + batch index | **postmeta** `_wpcc_bulk_rb_{id}` + `_wpcc_bulk_b_{batch}` | ✅ | ✅ (per item) | ✅ | ✅ (per-item + aggregate) | ✅ (legacy option) | 53/0 (+fix 35/0) | **certifiable** |
| **ACF** | atomic whole-field value delta + def fingerprint guard | **postmeta** `_wpcc_acf_rb_{id}` (values); option (legacy + defs) | ✅ | ✅ | ✅ | ✅ | ✅ | 47/0 | **certifiable** (values + def-update guard; json_import honest-irreversible) |
| **Elementor** | atomic whole-document delta + drift guard | **postmeta** `_wpcc_elementor_rb_{id}` | ✅ | ✅ (refuse-on-drift) | ✅ | ✅ | ✅ | 34/0 | **certifiable** |
| **RollbackDelta core / PostMetaRollbackStore** | — | — | — | — | — | — | — | 25/0 / 30/0 | reference |

## 2. Woo Products — solved on a branch, NOT consolidated

| Runtime | At HEAD (P4.10 lineage) | On P4.6 branch (`c8fb602`, validated) |
|---|---|---|
| **Woo product_update** | **F-1 full-object snapshot** (`snapshot_product`/`restore_product`), no drift | field-scoped drift-aware delta via `WooProductAccessor` (woo suite 47/0 on its branch) |

**Status: CONDITIONAL — requires integration of P4.6 into the consolidated branch + re-validation.** P4.6 itself is not flawed; it is stranded.

## 3. Remaining rollback-capable surfaces — unaddressed (verified present at HEAD)

| Surface | Storage (option) | Current model | Drift | Cert status | Risk |
|---|---|---|---|---|---|
| **Woo orders** (order_update/status/note/refund) | `wpcc_woo_rollbacks` (shared, cap 200) | full-object/field-ish, unconditional | ❌ | not certifiable | MED (relational; status-rollback re-emails) |
| **Woo variations** (`variation_update`) | — | **NO `store_rollback` (0 calls verified)** → irreversible (F-3) | ❌ | not certifiable | MED-HIGH |
| **Woo coupons** (`coupon_update`) | — | **NO `store_rollback` (0 calls verified)** → irreversible (F-3) | ❌ | not certifiable | MED |
| **Forms** | `wpcc_forms_rollbacks` (cap 200) | inverse-action; **form_update = no-op** | ❌ | not certifiable | LOW-MED (gap) |
| **Menu** | `wpcc_menu_rollbacks` (cap 200) | inverse + snapshot; **item_reorder no rollback** | ❌ | not certifiable | LOW-MED |
| **CPT** | `wpcc_cpt_rollbacks` (**no cap**) | full-config blob, unconditional | ❌ | not certifiable | LOW |
| **Widgets** | `wpcc_widgets_rollbacks` (cap) | inverse + partial snapshot | ❌ | not certifiable | LOW |
| **SiteBuilder** | `wpcc_sitebuilder_rollbacks` (cap 100) | inverse + field-ish | ❌ | not certifiable | LOW |
| **Media Enhancement** | `wpcc_media_enhance_rollbacks` (cap 100) | Pattern C byte snapshot (correct) | n/a | **OK (Pattern C)** | LOW |
| **OptionManager** | `wpcc_option_rollbacks` (**no cap**) | full-value per option, unconditional | ❌ | not certifiable | MED (unbounded growth) |
| **Plugin update** | `wpcc_plugin_rollbacks` | **E — update captures nothing**; delete backup exists but restore unwired | ❌ | not certifiable | MED-HIGH (silent irreversibility + false contract) |
| **Theme update** | `wpcc_theme_rollbacks` | **E — captures nothing**; delete no backup | ❌ | not certifiable | MED-HIGH |
| **Patch/File, Media bytes** | disk snapshot + `wpcc_snapshots` | Pattern C (verify) | n/a | **OK** | LOW |

## 4. Tally
- **Certifiable now (pending deploy + gate): 9 surfaces** (SEO, Settings, Media-meta, Content, Comments, Users, Bulk, ACF, Elementor) + Pattern-C (Patch, Media-bytes, Media-Enhancement).
- **Conditional (needs consolidation): 1** (Woo Products — fixed on P4.6, not in lineage).
- **Not addressed: ~10** (Woo orders/variations/coupons, Forms, Menu, CPT, Widgets, SiteBuilder, OptionManager, Plugin update, Theme update).

## 5. Invariants @HEAD (verified)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP **40** · DB_VERSION **2.5.0** — held. No schema/registry/MCP/REST/capability/security drift across all phases.
