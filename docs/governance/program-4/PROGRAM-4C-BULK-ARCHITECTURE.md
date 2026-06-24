# PROGRAM-4C — Bulk Operations Rollback Architecture

> **Type:** architecture design (no code). Report-only. **Target:** `includes/Operations/BulkRuntimeManager.php` (option `wpcc_bulk_rollbacks`), `includes/Admin/SelectionResolver.php`.
> **Status: this surface has an ACTIVE production correctness bug, not merely an F-1 gap.** It is the highest-severity rollback finding in the program.

---

## 1. Current state — verified facts

| Action | Capture (file:line) | What rollback actually does (`:97–103`) | Verdict |
|---|---|---|---|
| `bulk_content` | `{id→post_title}` (`:47–48`) | restores `post_title` from `before['before']` | **CORRECT** (single field; lossy if multi-field) |
| `bulk_publish` / `bulk_unpublish` | `{id→post_status}` (`:55–56`) | writes the captured **status string into `post_title`** (`:103`) | **CORRUPTS titles; never restores status** |
| `bulk_media` | none (`:63`) | nothing stored | **IRREVERSIBLE** |
| `bulk_woo` | none | nothing stored | **IRREVERSIBLE** |
| `bulk_acf` | none | nothing stored | **IRREVERSIBLE** |
| `batch_execute` | delegates per-op | per-op rollback if the inner op supports it | depends on inner op |

**Root cause of the corruption:** `rollback()` was written for `bulk_content` and hard-assumes `before['before']` is a title map; `bulk_status` reuses the same `{id→value}` shape but the value is a *status*, and the single restore line only ever writes `post_title`. So status rollbacks both (a) fail to restore status and (b) overwrite each title with `"draft"`/`"publish"`. Confirmed at `:56`, `:102–103`.

**Storage shape (`:91–93`):** one record per *operation*: `{id, entity_id:<action string>, action, before_state:{ids:[…], before:{id→value}}, rollback_applied, created_at, session, task}`; FIFO cap 200. `entity_id` is the action label, **not** a post id; lookup is a linear scan.

**Bounds:** `SelectionResolver::MAX_SELECTION = 100` (refuses over-cap, no silent truncation); `BulkRuntimeManager` caps items at 200. So a record is ≤200 items × (id+value) ≈ 5–8 KB; 200 records ≈ 1–1.6 MB. **The option does not have a scale problem.** The problem is *correctness and granularity*, not size.

**Retry/partial failure:** items missing at apply time are skipped but still listed in `ids`; a write that throws mid-loop is uncaught and the record is written *after* the loop, so a thrown error leaves a partial mutation with **no** record. No drift detection anywhere.

**Audit:** `bulk.<action>` with a count; **no ChangeRecorder/change_log integration** for bulk.

**Tests:** `test-bulk-runtime.sh` ~30 assertions, but the rollback test asserts only an HTTP status on an invalid id — it never applies a real bulk op and verifies the field reverted. The corruption bug is therefore **untested and undetected**.

---

## 2. Does the current storage scale? 
**Yes for size, no for correctness.** The single-blob-per-operation option is small and not autoloaded. But it cannot express:
- per-item field-scoped capture (needed to restore status *and* not touch title),
- drift detection (needed so a concurrent edit is not clobbered),
- per-item partial-result reporting (needed when 1 of N items drifts/fails),
- multi-field bulk edits (content + status + meta) without a lossy single-value map.

So the redesign is driven by **granularity**, and the storage choice should follow the granularity, not the size.

---

## 3. Storage options compared

| Option | Per-item delta? | Drift? | Scale | GC | Schema? | Verdict |
|---|---|---|---|---|---|---|
| **Current** (one option blob/op, value-map) | no | no | fine (size) | FIFO evict | none | **insufficient** (correctness) |
| **`OptionListRollbackStore`** (P4B) | no (one record holds N items) | possible if record carries per-item fields | fine | FIFO evict (whole op) | none | workable but couples N items to one evictable record |
| **Per-item `PostMetaRollbackStore`** (NEW, on existing `RollbackStore` interface) | **yes** — one v2 delta record in postmeta per item, all sharing a `batch_id` | **yes** (core drift) | excellent (rows, not one blob) | **natural** (post delete ⇒ record gone) | **none** | **RECOMMENDED** |
| **Dedicated bulk table** | yes | yes | excellent + indexable discovery | yes | **SCHEMA + DB_VERSION** | **NOT recommended** (trips Rule-7; postmeta suffices) |
| **Chunked records** (option blob split into N-sized chunks) | partial | partial | moderate | manual | none | unnecessary complexity vs postmeta |

### 3.1 Recommended: `PostMetaRollbackStore` (new, schema-free)
A new implementation of the **existing** `RollbackStore` interface (no interface change):
- `persist($post_id, $rollback_id, $record)` → `update_post_meta($post_id, "_wpcc_bulk_rb_{$rollback_id}", $record)` where `$record` is a standard v2 `RollbackDelta::build_record` for *that item's* touched fields, tagged with a shared `batch_id`.
- `resolve($rollback_id)` → resolve a **batch**: the runtime stores a lightweight **batch index** (option `wpcc_bulk_batches`, capped) mapping `batch_id → [post_ids]`, so rollback of a batch reads each item's postmeta record by id (no table scan). A single `rollback_id` (the batch id) reverses the whole batch item-by-item.
- `mark_applied` → per item.

This reuses `RollbackDelta::capture/build_record/restore/result` unchanged. Each item is independent → F-1 closed, drift per item, natural GC, and the 200-cap eviction risk disappears (records live with their posts).

---

## 4. Target architecture (the P4.8 Bulk redesign)

```
bulk_<action>(ids, fields):
  batch_id = uuid
  touched  = fields_for_action(action)            // e.g. content→[title,content]; publish→[status]; woo→[price,status]; acf→[value]
  for each id in ids (bounded by SelectionResolver):
     accessor = accessor_for(action, id)           // ContentFieldAccessor | WooProductAccessor | ACFValueAccessor | MediaFieldAccessor
     prior    = RollbackDelta::capture(accessor, id, touched)
     apply the write
     after    = read touched via accessor
     PostMetaRollbackStore::persist(id, batch_id, build_record(touched, prior, after, ctx, head{batch_id, action}))
  record batch index: wpcc_bulk_batches[batch_id] = ids (capped)
  return { batch_id as rollback_id, per_item: [...] }

rollback(batch_id):
  ids = batch index[batch_id]
  results = []
  for each id:
     rec = PostMetaRollbackStore::resolve item (id, batch_id)
     o   = RollbackDelta::restore(accessor_for(action,id), id, rec.fields)   // drift-aware, field-scoped
     mark item applied only if o.status == complete
     results[] = { id, status: o.status, restored: o.restored, skipped: o.skipped }
  return aggregate(results)   // complete | partial | conflict at the BATCH level + per-item detail
```

**Key properties this delivers:**
- **Status rollback actually restores status** (touched=[status]) and never touches title → kills the corruption bug.
- **media/woo/acf bulk become reversible** by reusing the *same per-item accessors the singular runtimes already have* (ContentFieldAccessor, WooProductAccessor, MediaFieldAccessor, ACFValueAccessor). Bulk becomes a thin fan-out over the proven single-entity delta — minimal new correctness.
- **F-1 closed per item**; **drift-aware per item**; **partial batch handled** (per-item status, idempotent, retryable on the items that drifted).
- **Multi-field bulk** is free (touched is a list, not a single value).

---

## 5. Performance implications
- **Apply:** N capture+write+persist cycles. For N≤100 (SelectionResolver bound) this is N postmeta writes — trivial; bulk is an approved, low-frequency admin op.
- **Rollback:** N restore cycles, each a field-scoped meta/CRUD write. Bounded by N≤100.
- **Storage:** N postmeta rows per batch instead of one 5–8 KB option blob. 100 items × many batches = thousands of small rows — well within WP norms; GC on post delete. No autoloaded growth.
- **Batch index option** (`wpcc_bulk_batches`) is tiny (`batch_id → [ids]`), capped; the only shared option, and it holds ids only.

---

## 6. Drift, retry, audit requirements
- **Drift:** inherited from the core — a concurrently-edited item is **skipped + reported**, never clobbered. This is the central fix vs today's unconditional clobber.
- **Retry:** per-item idempotency (mark applied only on `complete`); a partial batch can be re-run and will reverse only the still-reversible items. Wrap each item's write in try/catch so one failure does not abort the batch or strand the record (closes the current uncaught-throw gap).
- **Audit:** emit a per-batch `bulk.rollback` event with aggregate + per-item status; recommended to also write change_log rows so bulk reversals are discoverable like single-entity ones (no schema — uses existing `wpcc_change_log`). 

---

## 7. Phasing & the hotfix-vs-redesign split
Because there is an **active corruption bug**, split into two clearly-separated efforts:

- **P4C.0a — Bulk correctness hotfix (small, do FIRST, before any delta work):**
  - Fix `rollback()` so `bulk_status` restores **status** (and never writes title).
  - Add `store_rollback` to `bulk_media`/`bulk_woo`/`bulk_acf` capturing their touched fields (legacy snapshot acceptable here) so they stop being silently irreversible.
  - Add the **missing regression tests** that actually apply a bulk op and assert the field reverted (the test that would have caught this).
  - This is a behavior-correctness fix; it stabilizes the surface but is still legacy snapshot (no F-1 closure yet).

- **P4.8 — Bulk delta redesign (the architecture in §4):**
  - `PostMetaRollbackStore` + per-item field-scoped delta over the existing single-entity accessors; batch index; aggregate result.
  - Closes F-1, adds drift, fixes multi-field and partial-batch.

Keeping the hotfix separate means the live corruption is removed quickly and the larger redesign is reviewed on its own merits.

---

## 8. Recommendation
- **Storage:** new `PostMetaRollbackStore` (per-item postmeta) on the existing `RollbackStore` interface — **schema-free, scalable, self-GCing**. Reject the dedicated-table option (trips Rule-7; postmeta is sufficient at N≤100).
- **Mechanism:** fan-out over the proven single-entity accessors via `RollbackDelta`; aggregate per-item results into a batch envelope.
- **Sequence:** hotfix the corruption + coverage gaps first (P4C.0a), then the delta redesign (P4.8).
- **No schema / DB_VERSION / capability / operation / MCP / security change** is required by any part of this design. The `PostMetaRollbackStore` is a new class behind an existing interface; `wpcc_bulk_batches` is a new option key (option keys do not bump DB_VERSION).

---

## 9. Validation must-haves
- **Corruption regression (the bug):** apply `bulk_publish`, rollback, assert **status restored to draft AND titles unchanged**. (Today this fails.)
- **Coverage:** media/woo/acf bulk apply→rollback restores the touched field.
- **F-1 per item:** two items edited on disjoint fields; rollback the batch; the untouched field on each survives.
- **Drift per item:** externally edit item #k's field after the batch; rollback skips #k (reports), restores the rest.
- **Partial batch:** one item deleted before rollback → batch returns partial, others reversed, idempotent on retry.
- **Large-N bound:** N at 99/100/101 honored by SelectionResolver (no silent truncation).
