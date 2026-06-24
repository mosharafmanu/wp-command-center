# PROGRAM-4.8 — Bulk Delta Forensic Report (Phase A)

> **Type:** source-verified forensic audit (no code changes in this phase). Report-only.
> **Method:** re-audited `includes/Operations/BulkRuntimeManager.php` directly at the P4.7 base (`97e9ccd`, which carries the P4C.0a hotfix `5a57db4`). Trusted no prior report.
> **State audited:** post-hotfix Bulk. The corruption is already fixed; this audit isolates the **residual F-1 gaps** P4.8 must close.

---

## 1. Per-operation forensics

For each: **(1)** fields mutated · **(2)** captured · **(3)** rollback_id · **(4)** record stored · **(5)** restore primitive · **(6)** one-item-fails · **(7)** repeated rollback · **(8)** drift · **(9)** audit/change-history.

### 1.1 `bulk_publish` / `bulk_unpublish` → `bulk_status()` (`:73–79`, restore `:149–156`)
1. **Mutates:** `post_status` (publish or draft).
2. **Captures:** per-id `['post_status'=>$prior]` into `before_state.before` (`:76`). Self-describing (hotfix). **Per-operation, not per-item-record.**
3. **rollback_id:** **one per operation** (`store_rollback` `:127` → `wp_generate_uuid4`), surfaced (`:78`).
4. **Record:** one entry appended to option `wpcc_bulk_rollbacks`, **FIFO cap 200** (`:128–130`), autoloaded, O(n) scan to resolve (`:137`).
5. **Restore:** `wp_update_post(['ID'=>id,'post_status'=>prior])` — **unconditional** (`:155`).
6. **One item fails:** restore skips ids whose post is gone (`:152`); an uncaught `wp_update_post` failure does not throw, but there is **no per-item status** — all-or-nothing count.
7. **Repeated rollback:** guarded at the **batch** level by `rollback_applied` (`:138`) → second call `err('done')`.
8. **Drift:** **NOT handled.** A field changed by a later op/edit is silently overwritten with the captured prior (no compare). ← **residual F-1.**
9. **Audit:** `bulk.bulk_publish` apply (`:53`) + `bulk.rollback` (`:174`). **No change_log/ChangeRecorder** (confirmed by grep).

### 1.2 `bulk_content()` (`:57–71`)
1. **Mutates:** `post_title` and/or `post_content`.
2. **Captures:** per-id `{post_title?, post_content?}` (only touched fields) (`:64–65`).
3–4. one batch rollback_id; one option record (FIFO 200).
5. **Restore:** `wp_update_post` of the captured fields (`:154–155`) — unconditional.
6–7. same as 1.1 (no per-item status; batch-level applied guard).
8. **Drift:** NOT handled.
9. Audit only.

### 1.3 `bulk_media()` (`:81–91`)
1. **Mutates:** attachment `post_title` (only when a title is supplied).
2. **Captures:** per-id `{post_title}` (`:86`).
3–9. as above; restore via `wp_update_post` post_title (`:154–155`), unconditional, batch record, no drift.

### 1.4 `bulk_woo()` (`:93–107`, restore `:157–164`)
1. **Mutates:** `regular_price` and/or `status` on each WC product.
2. **Captures:** per-id `{regular_price?, status?}` via WC getters (`:101–102`).
3–4. one batch rollback_id; one option record.
5. **Restore:** WC `set_regular_price`/`set_status` + `save()` (`:161–163`) — unconditional.
6. **One item fails:** `wc_get_product` false → skip (`:160`); a `save()` throw would abort the loop (uncaught) → partial restore, batch **not** marked applied (retryable), but no per-item report.
7. Batch-level guard.
8. **Drift:** NOT handled.
9. Audit only. **Dependency gate:** WC inactive at rollback → `unsupported` (reversible:false) **without** marking applied (`:158`) — correct/retryable.

### 1.5 `bulk_acf()` (`:109–117`, restore `:165–168`)
1. **Mutates:** one ACF field value per post.
2. **Captures:** per-id `{acf: prior get_field}` + top-level `field_key` (`:114–115`).
3–4. one batch rollback_id; one option record.
5. **Restore:** `update_field($field_key, prior, id)` (`:168`) — unconditional.
6–7. as above; batch guard.
8. **Drift:** NOT handled.
9. Audit only. Dependency gate: ACF inactive / missing field_key → `unsupported` (`:166–167`).

---

## 2. Cross-cutting residual gaps (post-hotfix)

| # | Gap | Evidence | F-1 family |
|---|---|---|---|
| G1 | **No drift detection** — restore unconditionally writes the captured prior | `:155,:161–163,:168` | corruption (clobbers a concurrently/later-changed field) |
| G2 | **One option-blob record per operation**, FIFO cap 200 | `:128–130` | eviction (a surfaced batch rollback_id silently lost on a busy store) |
| G3 | **Autoloaded + O(n)** option | `update_option` default; `:137` linear scan | scalability |
| G4 | **No per-item isolation / per-item status** | single before_map loop, one applied flag | partial-failure opacity |
| G5 | **No partial/conflict truthfulness** | rollback always returns `reversible:true, restored:N` | dishonest on drift |
| G6 | **No out-of-order safety** | follows from G1 (no drift compare) | resurrection / sibling clobber |

What the hotfix **already** delivers (and P4.8 must preserve): field-scoped self-describing capture (no status→title corruption), action-dispatched restore, dependency-gated reversible:false, batch-level idempotency, legacy scalar normalization.

---

## 3. Answers to the Phase-A questions (summary)
1. **Fields mutated:** content→title/content; status→post_status; media→post_title; woo→regular_price/status; acf→one field value.
2. **Captured:** per-id field map of touched fields (hotfix) — but bundled in one per-operation record.
3. **rollback_id:** one per operation (batch-level), surfaced.
4. **Record:** single option-list entry in `wpcc_bulk_rollbacks`, FIFO-capped 200, autoloaded.
5. **Restore primitive:** `wp_update_post` / WC setters+save / `update_field` — **unconditional** (no drift compare).
6. **One item fails:** missing post skipped; an uncaught throw aborts the restore loop; no per-item status.
7. **Repeated rollback:** batch-level `rollback_applied` guard → `err('done')`.
8. **Drift:** **not detected** — silently clobbers.
9. **Audit/change-history:** `bulk.<action>` + `bulk.rollback` audit events; **no change_log**.

---

## 4. What P4.8 must change (scope)
Convert each item to a **per-item, field-scoped, drift-aware** delta record via `RollbackDelta`, stored in `PostMetaRollbackStore` (`_wpcc_bulk_rb_{itemRid}`), addressed by a **batch envelope** (membership meta `_wpcc_bulk_b_{batchId}`), with per-item partial/conflict truthfulness — closing G1/G2/G3/G4/G5/G6 while preserving every hotfix behavior and remaining **legacy-compatible** with the P4C.0a option records. No registry/schema/contract change.

All five bulk entities are **post-bound** (posts, attachments, WC products = posts, ACF values on posts), so `PostMetaRollbackStore` fits all of them. The post-column ops (content/status/media) reuse the existing `ContentFieldAccessor` (title/status/content); woo/acf need **minimal Bulk-scoped accessors** (no `WooProductAccessor` exists in this lineage). Proceeding to Phase B.
