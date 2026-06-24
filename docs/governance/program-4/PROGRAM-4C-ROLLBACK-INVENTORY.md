# PROGRAM-4C — Remaining Rollback Surface Inventory

> **Type:** audit / inventory (no code, no branches, no commits). Report-only.
> **Date:** 2026-06-24 · **Production HEAD:** `a41a9d7` (unchanged). All Program-4 work is branch-only.
> **Baseline completed (delta, Pattern B):** RollbackDelta core (P4.0) · Settings (P4.1) · Media metadata (P4.2) · Content (P4.3) · Comments (P4.4) · User (P4.5) · Woo Products (P4.6) · Core hardening (P4B).
> **Invariants (must not regress):** OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0.

---

## 0. Headline findings (new since PROGRAM-4-DESIGN)

1. **The original inventory undercounted.** PROGRAM-4-DESIGN catalogued 9 full-object runtimes + plugin/theme. Code reconnaissance found **6 additional rollback-capable runtimes never catalogued** — **CPT, Elementor, Menu, SiteBuilder, Widgets, MediaEnhancement** — each with its own `wpcc_*_rollbacks` option. The true unmigrated surface is **~12 runtimes**, not 9.
2. **Bulk has an active CORRUPTION bug, not just an F-1 gap.** On `bulk_publish`/`bulk_unpublish` rollback, the captured prior **status** map is written into **`post_title`** (`BulkRuntimeManager.php:56` stores `{id→post_status}` under `before`; `:103` writes those values via `'post_title'=>$old_title`). Result: every item's **title is overwritten with a status string** and **status is never restored**. This is live data corruption on a governed, approved operation — the single most severe rollback finding in the program.
3. **Two Woo write paths are silently irreversible (F-3).** `coupon_update` (`WooCommerceRuntimeManager.php` coupon_update — only `audit->record`, no `store_rollback`) and `variation_update` (only `audit->record`) capture **no** rollback record. Price/discount edits cannot be undone.
4. **Plugin/Theme UPDATE capture nothing yet are approved as reversible high-risk.** `plugin_update` (`PluginManager.php:243`) and `theme_update` (`ThemeManager.php:185`) contain **no `store_rollback` call**; the only plugin/theme rollback records come from activate/deactivate/delete. A contract mismatch: approval implies reversibility that does not exist.
5. **Elementor and ACF carry the F-1 full-blob pattern at high blast radius.** Elementor snapshots the entire `_elementor_data` tree per edit (`ElementorRuntimeManager.php:148`); ACF snapshots whole field/group definitions and serialized values (`ACFRuntimeManager.php` store_rollback). Both clobber siblings on layered edits.
6. **Shared-option FIFO eviction is a latent correctness weakness.** Woo products (post-P4.6) still write v2 delta records into the single `wpcc_woo_rollbacks` option capped at 200, shared with variations/coupons/orders. A busy store silently evicts older `rollback_id`s; a surfaced id can disappear before use. ACF/Elementor share the same single-option-cap risk.
7. **`OptionManager` rollback option has no cap** (`wpcc_option_rollbacks`) — unbounded growth on long-lived instances.

None of the remediations below requires a schema/DB_VERSION/capability/operation/MCP/security change; all fit postmeta/usermeta/commentmeta/option storage as P4.0–P4.6 proved. The only schema-bearing items (A2-1 reaper; an optional dedicated bulk table) are explicitly deferred or explicitly not recommended (see ROADMAP §Rule-7).

---

## 1. Master inventory

Legend — **Pattern:** A=full-object snapshot, B=field-scoped delta (DONE), C=byte snapshot+verify, D=inverse-action, E=none/irreversible. **F-1** = sibling clobber on layered edits. **Drift** = detects external edits. **Complexity** = migration effort 1 (trivial) – 5 (hard).

### 1a. Already migrated (Pattern B) — closed, listed for completeness
| Surface | Storage | Identity | Residual risk |
|---|---|---|---|
| SEO (reference) | postmeta `_wpcc_seo_rb_{id}` | post_id | none (reference impl) |
| Settings (P4.1) | option `wpcc_settings_rollbacks` v2 | action→touched options | **permalink `rewrite_rules` not captured** (LOW); direct-DB writes handled drift-safe (skip) |
| Media metadata (P4.2) | option `wpcc_media_rollbacks` v2 | attachment_id | none (bytes on Pattern C) |
| Content (P4.3) | option `wpcc_content_rollbacks` v2 | post_id | none |
| Comments (P4.4) | option `wpcc_comments_rollbacks` v2 | comment_id | none |
| User (P4.5) | option `wpcc_user_rollbacks` v2 | user_id | none |
| Woo product_update (P4.6) | option `wpcc_woo_rollbacks` v2 | product_id | **shared 200-cap FIFO eviction** (see §0.6); single-field Woo product actions (stock/price/publish/category/attribute) still legacy A (not full-object, lower risk) |

### 1b. Pattern C (byte snapshot) — CORRECT, no migration required
| Surface | Mechanism | Storage | Status |
|---|---|---|---|
| Patch/File | disk snapshot + hash + verify + auto-revert | `wpcc_snapshots` table + disk | reference-correct |
| Media bytes (replace/regen/webp/optimize/enhance) | `MediaSnapshot::capture/restore`, MD5 verify, created-file tracking, abort-on-fail | `/uploads/wpcc-snapshots/*.snapshot` + `wpcc_snapshots` + option `wpcc_media_enhance_rollbacks` (cap 100) | solid; gaps are only edge tests (disk-full, concurrent regen). Caps: 10MB/10s capture bound → oversize aborts (safe) |

### 1c. Unmigrated — the PROGRAM-4C work surface
| # | Surface | File | Pattern now | Storage | Identity | F-1 | Drift | Corruption risk | Complexity |
|---|---|---|---|---|---|---|---|---|---|
| 1 | **Bulk** | `BulkRuntimeManager.php` | A (title-only) + **active corruption bug** + 3 actions with **no** rollback | option `wpcc_bulk_rollbacks` (cap 200, one blob/op) | action string; post_ids in `before.ids` | **HIGH** | none | **VERY HIGH** — status rollback corrupts titles; media/woo/acf bulk irreversible | 4 |
| 2 | **ACF** | `ACFRuntimeManager.php` | A (whole-def + serialized value blobs) | option `wpcc_acf_rollbacks` (cap 200) | group_key / field_key / post_id+field | **HIGH** | none | **HIGH** — serialized repeater/flex clobber; `json_import` lossy & likely unrestorable; option-page values unsupported | 4 |
| 3 | **Elementor** | `ElementorRuntimeManager.php` | A (full `_elementor_data` blob) | option `wpcc_elementor_rollbacks` (cap 100) | post_id | **HIGH** | none | **MED-HIGH** — layered widget edits clobber whole tree | 3.5 |
| 4 | **Woo orders** | `WooCommerceRuntimeManager.php` | A (field-ish before_state) | option `wpcc_woo_rollbacks` (shared, 200) | order_id / refund_id | LOW | **HIGH** (relational, gateway hooks) | MED — status rollback re-emails customer; refund delete assumes refund exists | 3 (defer: relational) |
| 5 | **Woo variations** | same | create/delete OK; **variation_update has NO rollback (F-3)** | shared option | variation_id | n/a | MED (WC auto-sync) | MED — price/stock edits irreversible | 2.5 |
| 6 | **Woo coupons** | same | create/delete OK; **coupon_update has NO rollback (F-3)** | shared option | coupon_id | n/a | LOW | MED — amount/type edits irreversible | 2 |
| 7 | **Plugin update (G2)** | `PluginManager.php` | **E (no capture)**; approved as reversible high-risk | n/a (delete uses `wpcc_plugin_backups` ZIP, restore unwired) | slug | n/a | n/a | MED-HIGH — silent irreversibility + contract mismatch | 2 (visibility) / 3 (artifact) |
| 8 | **Theme update (G2)** | `ThemeManager.php` | **E (no capture)** | n/a (delete has no backup) | stylesheet | n/a | n/a | MED-HIGH — silent irreversibility; theme delete also unrecoverable | 1 (visibility) / 4 (snapshot) |
| 9 | **Forms** | `FormsRuntimeManager.php` | D (inverse) with empty before_state; **form_update = NO-OP** | option `wpcc_forms_rollbacks` (cap 200) | form_id | MED | MED | MED — form_update silently does nothing; delete republish loses meta drift | 3 |
| 10 | **OptionManager** | `OptionManager.php` | A (full per-option value), **no cap** | option `wpcc_option_rollbacks` (keyed, **unbounded**) | option_id | MED (serialized sub-field) | none (unconditional write) | LOW data / MED operational (unbounded growth) | 2 |
| 11 | **Menu** | `MenuRuntimeManager.php` | D + snapshot; mostly OK; **menu_item_reorder has NO rollback** | option `wpcc_menu_rollbacks` (cap 200) | menu_id / item_id | MED | LOW | LOW-MED — reorder irreversible; menu_delete+rollback recreates | 2.5 |
| 12 | **CPT** | `CPTRuntimeManager.php` | A (full config blob) | option `wpcc_cpt_rollbacks` (**no explicit cap**) | uuid; name in record | MINIMAL (hierarchical, no siblings) | LOW | LOW | 2 |
| 13 | **SiteBuilder** | `SiteBuilderRuntimeManager.php` | D + field-scoped post before_state | option `wpcc_sitebuilder_rollbacks` (cap 100) | post_id | LOW | LOW | LOW | 2 |
| 14 | **Widgets** | `WidgetsRuntimeManager.php` | D + partial settings snapshot | option `wpcc_widgets_rollbacks` (cap) | widget_id / sidebar_id | LOW | MED (WP admin race) | LOW-MED | 2 |

> **Note on identity columns:** the unmigrated runtimes mostly key by a generated `rollback_id` (UUID) and carry their entity id inside the record; resolution is a linear scan of the option list/dict. This is the non-scaling pattern P4B encapsulated behind `RollbackStore`.

---

## 2. Per-surface audit detail

### 2.1 Bulk (`BulkRuntimeManager.php`) — **CRITICAL**
- **Mechanism:** one option-blob record per bulk *operation*; `bulk_content` captures `{id→post_title}` (`:47–48`), `bulk_status` captures `{id→post_status}` (`:55–56`). `rollback()` (`:97–103`) unconditionally restores **only `post_title`** from `before['before']`.
- **Bug:** for `bulk_publish`/`bulk_unpublish` the `before` map is statuses, so `:103` writes the **status string into post_title** → title corruption + status never restored. Verified `:56`, `:102–103`.
- **Coverage gaps:** `bulk_media` (`:63`), `bulk_woo`, `bulk_acf` perform writes but call **no `store_rollback`** → permanently irreversible. `bulk_content` captures title only (multi-field edits lossy).
- **Drift:** none — restore clobbers regardless of concurrent edits.
- **Storage/scale:** single 5–8 KB blob/op, cap 200; `SelectionResolver` bounds selections at **100** (`SelectionResolver.php` MAX_SELECTION), bulk runtime bounds at 200 — scale of the *option* is fine; the per-item delta requirement is the real driver.
- **Tests:** `test-bulk-runtime.sh` ~30 assertions; **rollback test only checks HTTP response**, never asserts a real status reversion → the corruption bug is untested. Missing: status-restore positive test, title-not-corrupted test, media/woo/acf rollback tests, large-N boundary, drift.
- **Complexity:** 4 (redesign to per-item delta + status capture + new per-item store).

### 2.2 ACF (`ACFRuntimeManager.php`) — **HIGH** (full deep design in PROGRAM-4C-ACF-ARCHITECTURE.md)
- **Mechanism:** `store_rollback` captures whole-object `before_state` (full group/field definition or value), restores via `acf_update_field_group`/`acf_update_field`/`update_field`. No drift, no field-scoping.
- **Sub-surfaces & risk:** value_update on scalar (clean delta candidate); value_update on serialized repeater/flexible/gallery/relationship (whole-blob clobber, HIGH); field/group **config** incl. nested sub_fields (post-parented child posts — not flat-mappable); location rules (clean candidate); layouts (sub-field orphaning); **`json_import`** in `ROLLBACKABLE` (`:642`) but stores lossy `summarize_group` (`:514`) and has no faithful restore → effectively a dead/garbage rollback record; **option-page fields unsupported** (requires post_id).
- **Storage:** `wpcc_acf_rollbacks` cap 200; large serialized definitions bloat the option.
- **Tests:** `test-acf-*` cover CRUD; **≈1 rollback assertion** (field_create delete); no drift, no F-1, no value/layout/group-delete rollback tests.
- **Complexity:** 2 (values/locations) → 4–5 (config/json_import).

### 2.3 Elementor (`ElementorRuntimeManager.php`) — **HIGH**
- **Mechanism:** each `update_text/image/button` snapshots the **entire** `_elementor_data` JSON (`:141`, stored `['data'=>$before_json]` `:148`); rollback writes the whole blob back (`:166–167`).
- **F-1:** layered edits to different widgets on the same page each snapshot the full tree → rolling back one edit resurrects the entire prior tree, clobbering the others. Page builders are edited incrementally → high likelihood.
- **Drift:** none — a native-Elementor-UI edit between apply and rollback is silently wiped.
- **Storage:** `wpcc_elementor_rollbacks` cap 100.
- **Tests:** `test-elementor-step96.sh` ~10; no layered/F-1 or drift test.
- **Complexity:** 3.5 (needs touched-widget-path delta or whole-blob+drift-guard).

### 2.4 Woo non-product (`WooCommerceRuntimeManager.php`)
- **Orders:** `order_update` captures `{customer_note, billing{…}}`; `order_status_change` captures `{status}`; `order_note_add` captures `{note_id}`; `refund_create` captures `{order_id}`. Restores are field-ish and mostly correct, but **drift is HIGH** (orders mutate via gateways/fulfillment) and `order_status_change` rollback **re-triggers a customer email**. Relational/custom-table state ⇒ **defer to a sub-design**.
- **Variations:** create/delete have rollback; **`variation_update` has none (F-3)** — price/stock edits irreversible.
- **Coupons:** create/delete have rollback; **`coupon_update` has none (F-3)** — amount/type edits irreversible.
- **Tests:** no dedicated order/coupon/variation rollback suite.

### 2.5 Plugin/Theme update (G2) — full design in PROGRAM-4C-G2-REVERSIBILITY.md
- **Plugin/theme update capture nothing** (`PluginManager.php:243`, `ThemeManager.php:185` — no `store_rollback`); responses omit `rollback_id`; no restore case for `update`. Yet `OperationRegistry` marks both `high` risk (approval implies reversibility).
- **Plugin delete** writes a pre-delete ZIP to `wpcc-plugin-backups/` (`create_plugin_backup`) and surfaces `backup_id`, but the **restore path is unimplemented** (rollback of delete returns an error). **Theme delete** has **no backup** and no restore.
- **DestructiveGuard** already gates delete with confirm+phrase+reason+target (plugin `backup_capable=true`, theme `false` — honest).

### 2.6 Forms / OptionManager / Menu / CPT / SiteBuilder / Widgets
- **Forms:** inverse-action create/delete with empty before_state; **`form_update`/`notification_update` rollback is a no-op** (definition never captured). CF7 stores form in postmeta `_form`/`_mail`/`_messages` → capturable as fields if migrated.
- **OptionManager:** per-option full-value rollback, type-preserved, drift-unaware (unconditional restore), **no cap** on `wpcc_option_rollbacks` (operational growth).
- **Menu:** mostly correct inverse/snapshot (menu_update restore fixed in STEP 102); **`menu_item_reorder` writes no rollback** (irreversible reorder).
- **CPT:** full-config snapshot; low risk (hierarchical config, no sibling fields); `wpcc_cpt_rollbacks` has no explicit cap.
- **SiteBuilder/Widgets:** post/option-isolated inverse + partial snapshots; low risk; missing layered/F-1 tests; Widgets instance-numbering uses `count()+1` (minor collision risk).

---

## 3. Coverage-gap vs corruption families (carried from PROGRAM-4-DESIGN, updated)

- **Active corruption (fix first):** Bulk status-rollback (title clobber + no status restore).
- **F-1 corruption (delta migration):** ACF (values/config), Elementor (tree), Bulk (per-item), Woo product single-field actions (lower).
- **Coverage gaps (add capture):** Bulk media/woo/acf (no rollback), Woo variation_update/coupon_update (F-3), Forms update (no-op), Menu reorder (no rollback), ACF option-page values (unsupported), plugin/theme update (E).
- **Drift gaps:** OptionManager (unconditional), Woo orders (relational), Widgets (admin race).
- **Operational:** shared-option FIFO eviction (Woo/ACF/Elementor), uncapped options (OptionManager, CPT).

---

## 4. Storage-fit summary (schema-free target confirmed)
Every remediation maps to existing storage primitives:
- **Post-bound entities** (ACF values, Elementor pages, Bulk items, Woo variations) → **postmeta per-entity** records (mirrors SEO) — fixes both F-1 and FIFO eviction; **no schema**.
- **Global/keyed** (OptionManager, Settings, CPT) → option records in v2/keyed shape; add caps where missing; **no schema**.
- **Whole-definition blobs that cannot decompose** (ACF field/group config, Elementor fallback) → whole-def record + a **drift fingerprint (hash)** stored in the record; **no schema**.
- **Binary/file** (G2 plugin update) → reuse the existing `wpcc-plugin-backups` ZIP infra + a **new option key** (adding option keys does **not** bump DB_VERSION).

**Conclusion:** the full remaining program is achievable schema-free and invariant-frozen. The only schema-bearing candidates are the A2-1 reaper (`claimed_at` column) and an *optional* dedicated bulk table — both deferred / not recommended (see ROADMAP §Rule-7). No STOP condition is triggered by this plan.
