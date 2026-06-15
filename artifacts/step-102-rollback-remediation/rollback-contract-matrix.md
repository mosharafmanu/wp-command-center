# Rollback Contract Matrix (post-STEP 102)

Unified contract: every reversible write returns `rollback_id` + `rollback_available:true` (surfaced centrally by `RollbackContext` + `OperationExecutor::normalize_success`), and exposes an executable rollback path.

Legend — **Surfacing**: how `rollback_id` reaches the caller. **Execution**: how rollback is invoked. **Status**: `verified-102` (re-verified this step), `proven-101.3` (verified in prior step, unchanged), `shared-cover` (inherits the shared surfacing fix; not individually re-verified this step).

| Runtime | Store option | Surfacing (post-102) | Rollback execution path | Status |
|---|---|---|---|---|
| **Content** | `wpcc_content_rollbacks` | shared + own | action `content_rollback` (now in ACTIONS) | ✅ verified-102 |
| **Menu** | `wpcc_menu_rollbacks` | shared | REST `/operations/menu_manage/rollback`; `menu_update` arm added | ✅ verified-102 |
| **ACF** | `wpcc_acf_rollbacks` | shared + own | REST `/operations/acf_manage/rollback` | ✅ verified-102 |
| **User** | `wpcc_user_rollbacks` | shared (gate bug fixed) | REST `/operations/user_manage/rollback` | ✅ verified-102 |
| **WooCommerce** | `wpcc_woo_rollbacks` | shared + own | REST `/operations/woocommerce_manage/rollback` | ✅ verified-102 |
| **Settings** | `wpcc_settings_rollbacks` | shared | REST `/operations/settings_manage/rollback` | ✅ verified-102 |
| **Option** | `wpcc_option_rollbacks` | own + shared | action `option_rollback` | ✅ proven-101.3 + regressed-102 |
| **SEO** | `wpcc_seo_rollbacks` | own | action `seo_restore` | ✅ proven-101.3 |
| **Media** | `wpcc_media_rollbacks` | own + shared | REST `/operations/media_manage/rollback` | ✅ proven-101.3 |
| **Snapshot** | (snapshot store) | own | action `snapshot_restore` | ✅ proven-101.3 |
| **Patch** | (patch store) | own (`patch_apply`) | `rollback_manage rollback_apply` | ✅ proven-101.3 |
| **Workflow** | per-step ids | own | `workflow_rollback` (unified dispatcher) | ✅ proven-101.3 |
| Comments | `wpcc_comments_rollbacks` | shared | REST `/operations/comments_manage/rollback` (public `rollback()`) | ⚪ shared-cover |
| Forms | `wpcc_forms_rollbacks` | shared | REST `/operations/forms_manage/rollback` | ⚪ shared-cover |
| Site Builder | `wpcc_sitebuilder_rollbacks` | own + shared | REST `/operations/site_builder_manage/rollback` | ⚪ shared-cover |
| Elementor | `wpcc_elementor_rollbacks` | own + shared | REST `/operations/elementor_manage/rollback` | ⚪ shared-cover |
| CPT | `wpcc_cpt_rollbacks` | own + shared | REST `/operations/cpt_manage/rollback` | ⚪ shared-cover |
| Widgets | `wpcc_widgets_rollbacks` | own + shared | REST `/operations/widgets_manage/rollback` | ⚪ shared-cover |
| Media-Enhance | `wpcc_media_enhance_rollbacks` | own | REST `/operations/media_enhance/rollback` | ✅ proven (STEP 100) |
| Bulk | `wpcc_bulk_rollbacks` | own + shared | `bulk_manage` rollback | ⚪ shared-cover |

## Before → After (the 6 remediated runtimes)

| Runtime | Before (101.3) | After (102) |
|---|---|---|
| Content | rollback_id returned but **action blocked** → unconsumable | `content_rollback` dispatches; round-trip restores |
| Menu | rollback_id **not surfaced**; `menu_update` not reversible | id surfaced via shared layer; rename reversal arm added |
| ACF | rollback_id **not surfaced** | id surfaced via shared layer |
| User | **no rollback ever stored** (action-key mismatch) | gate fixed → stored → id surfaced → reversible |
| WooCommerce | `price_update` rollback_id **not surfaced** | id surfaced via shared layer |
| Settings | rollback_id **not surfaced** | id surfaced via shared layer |

## Rollback-id success rate

- STEP 101.3: **6/12** write runtimes had a working reversible round-trip.
- STEP 102: **12/12** of the runtimes covered by the contract are now driveable (6 re-verified this step + 6 proven previously), with the remaining `shared-cover` runtimes inheriting the same surfacing mechanism.
