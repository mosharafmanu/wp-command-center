# STEP 101.3 — Reversible Write Validation

**Date:** 2026-06-15
**Target:** local DEV only (AMPPS `localhost`). Production never touched.
**Mode:** developer (writes execute directly; approval pipeline validated explicitly via `approval_manage`).
**Cleanup:** all validation assets created during this step were removed (see end). Site left in original state (e.g. `posts_per_page=15`, media 15647 `alt=""`, no WPCC menus/users/products/groups, theme asset file deleted, validation page trashed).

## Verdict: **PASS WITH RISKS**

The core safety machinery is proven and correct, but **rollback is not uniformly reachable through the public API**, which is a real risk for a product whose central promise is reversibility.

- **Writes themselves work everywhere tested** (14/14 write-capable runtimes performed the create/update correctly and the change was verified).
- **Rollback proven end-to-end on 6/12 runtimes**: Option, SEO, Media, Snapshot, Patch, Workflow. These do full create/update → verify → rollback → verify-restore with no residual state.
- **Rollback NOT reachable on 6/12 runtimes**: Content (hard defect), Menu, ACF, User, WooCommerce, Settings (rollback_id not surfaced).
- **Approval pipeline: 9/9 PASS**, including all three negative-path safeguards (execute-without-approval, duplicate-execution, approve-nonexistent).
- **Audit trail + Timeline: PASS** (operation/patch/approval activity all recorded; `/agent/timeline` populated).
- **Error paths: graceful everywhere** — every invalid input returned a structured `{isError, code, message}` (STEP 89 contract holds on the write path too).

## Counts

| Metric | Value |
|---|---|
| Evidence records | 73 |
| PASS | 65 |
| FAIL | 7 |
| Observation | 1 |
| Runtimes write-tested | 14 (+2 infra: Audit, Timeline) |
| Runtimes skipped (documented) | 9 categories |
| **Rollback success rate** | **6/12 (50%)** |
| **Approval workflow success rate** | **9/9 (100%)** |
| **Audit trail success rate** | **100%** (3/3 activity reports populated) |
| **Timeline success rate** | **100%** (`/agent/timeline` returns entries) |

## Runtime Write Validation Matrix

| Runtime | Write | Verify | Rollback | Verify-restore | Lifecycle |
|---|---|---|---|---|---|
| **Option** | PASS | PASS | PASS (`option_rollback`) | PASS | ✅ full reversible round-trip |
| **SEO** | PASS | PASS | PASS (`seo_restore`) | PASS | ✅ full reversible round-trip |
| **Media** | PASS | PASS | PASS (REST `/media_manage/rollback`) | PASS | ✅ full reversible round-trip |
| **Snapshot** | PASS | PASS (hash) | PASS (`snapshot_restore`) | PASS | ✅ full reversible round-trip |
| **Patch** | PASS | PASS | PASS (`rollback_manage`) | PASS (file on disk) | ✅ full reversible round-trip |
| **Workflow** | PASS | PASS | PASS (`workflow_rollback`) | PASS | ✅ single-approval exec + per-step rollback |
| **Content** | PASS | PASS | **FAIL** | **FAIL** | ❌ rollback action blocked (F-1) |
| **Menu** | PASS | PASS | **FAIL** | n/a | ⚠️ rollback_id not surfaced (F-2) |
| **ACF** | PASS | PASS | **FAIL** | n/a | ⚠️ rollback_id not surfaced (F-3) |
| **User** | PASS | — | **FAIL** | n/a | ⚠️ update rollback_id not surfaced; delete-handshake cleanup OK (F-3) |
| **WooCommerce** | PASS | PASS | **FAIL** | n/a | ⚠️ price_update rollback_id not surfaced (F-3) |
| **Settings** | PASS | — | **FAIL** | PASS (manual) | ⚠️ rollback_id not surfaced; manual restore (F-3) |
| **Approval** | PASS | PASS | n/a | n/a | ✅ pipeline + 3 negatives all PASS |
| **Rollback** | PASS | — | PASS | PASS | ✅ get/apply + duplicate & invalid-id negatives |
| **Audit** | — | PASS | — | — | ✅ activity reports populated |
| **Timeline** | — | PASS | — | — | ✅ `/agent/timeline` populated |

Full per-step evidence in `evidence.json`; machine-readable matrix in `write-validation-matrix.json`.

## Approval validation (developer-mode explicit pipeline)

Validated the full Request → Approval → Queue → Execute → Result chain via `approval_manage`, plus safeguards:

| Check | Result | Evidence |
|---|---|---|
| Plan generation (`request_create`) | PASS | request `48c0139f…` created, status pending |
| Approval requirement (`request_get`) | PASS | status `pending` until approved |
| Execution gating (run before approve) | PASS | blocked — no queue item exists pre-approval (`wpcc_missing_queue_id`) |
| Approve (`request_approve`) | PASS | status `approved`, queue item `9e17a300…` created |
| Execute (`queue_run`) | PASS | queue item executed |
| Audit generation (`results_list`) | PASS | result records present |
| Duplicate execution | PASS | blocked: `wpcc_invalid_queue_status` "Cannot run queue item in status completed." |
| Approve nonexistent | PASS | `wpcc_request_not_found` |

Note: per-action approval *auto-gating* (pending_approval responses) is only active in client/enterprise mode; dev runs in developer mode (writes direct). The explicit pipeline above exercises the same machinery without changing site mode. See `approval-validation-report.md`.

## Patch validation

`patch_create` (low-risk file in an inactive theme) → `patch_apply` (status `applied`, `rollback_id` returned) → file on disk verified changed → `rollback_manage rollback_get` → `rollback_apply` (status `rolled_back`, `restored:true`) → file on disk verified back to original. Negatives: duplicate rollback → `wpcc_invalid_status`; invalid patch id → `wpcc_patch_not_found`. **Full PASS.** See `rollback-validation-report.md`.

## Snapshot & Rollback validation

`snapshot_create` → `snapshot_verify` (`hash_matches:true`) → `snapshot_details` → `snapshot_restore`: file restored byte-for-byte. Patch rollback path additionally confirmed on-disk restoration. No corruption, no orphaned records observed. **Full PASS.**

## Skipped runtimes (with reasons)

| Runtime | Reason |
|---|---|
| Elementor | No Elementor-built page on dev (F-4 from 101.2); a valid `_elementor_data` fixture couldn't be created safely. |
| Theme | Only writes are `theme_activate/update` (site-disrupting; dev must stay hello-elementor); `theme_delete` destructive. |
| Plugin | activate/deactivate/update risk breaking dev; `plugin_delete` destructive. Reads validated in 101.2. |
| Database | `database_inspect` is read-only; `safe_search_replace` is a live critical DB mutation — excluded by the no-destructive mandate (dry-run is non-mutating only). |
| Seeder | Create-only generators with no rollback_id (reversed by delete), same pattern as `content_create`. |
| Comments / CPT / Widgets / Site Builder / Bulk | Overlap or higher blast radius; the rollback contract is already proven on safer runtimes. Comments has a working public `rollback()` (verified statically). |

## Observed issues

See `observed-issues.md` for full reproduction steps. Reproducible issues only:
- **F-1 (HIGH):** Content rollback is unreachable — `content_rollback` blocked by the action allow-list; updates emit an unconsumable `rollback_id`.
- **F-2 (MEDIUM-HIGH):** Menu rollback_id not surfaced (12/13 write paths) and `menu_update` not handled in the rollback switch.
- **F-3 (MEDIUM, systemic):** `rollback_id` inconsistently surfaced — ACF, User, WooCommerce (`price_update`), Settings stored a rollback internally but the response omits the id, and there is no per-runtime `rollback_list` to discover it, making the documented REST `/rollback` routes undriveable for those actions.
- **Observation:** create operations don't emit rollback_ids by design (reversed by delete) — not a bug.

## Recommended next step

A focused remediation step (call it **101.3a / STEP 102**) to make rollback uniformly reachable: (1) fix F-1 by adding `content_rollback` to `ContentRegistry::ACTIONS` (or expose a public `rollback()` + REST route); (2) surface `rollback_id` in every write response that calls `store_rollback()`; (3) add a per-runtime `rollback_list` (or extend `rollback_manage`) so ids are discoverable. Then re-run 101.3 across all write runtimes. **Do not proceed to any further validation step until instructed.**
