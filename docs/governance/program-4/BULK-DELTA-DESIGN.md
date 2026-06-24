# PROGRAM-4.8 — Bulk Delta Redesign Design (Phase B)

> **Type:** design (no code in this phase). Report-only. **Scope: `includes/Operations/BulkRuntimeManager.php` + minimal Bulk-scoped accessors only.**
> **Goal:** per-item, field-scoped, drift-aware Bulk rollback via `RollbackDelta` + `PostMetaRollbackStore`, closing the residual F-1 gaps (G1–G6) while preserving every P4C.0a hotfix behavior and staying legacy-compatible.

---

## 1. Building blocks (reused, unchanged)
- **`RollbackDelta`** (`capture`/`build_record`/`restore`/`result`) — the proven field-scoped, drift-aware core. Unchanged.
- **`PostMetaRollbackStore`** (P4.7) — per-item postmeta record store: O(1) by id, no FIFO, GC-with-post, schema-free. Unchanged.
- **`ContentFieldAccessor`** — maps `title→post_title`, `status→post_status`, `content→post_content`. Reused for the post-column bulk ops (content/status/media). Unchanged.

## 2. New, minimal Bulk-scoped accessors (only because none exist in this lineage)
- **`BulkWooAccessor`** — `FieldAccessor` over a WC product for fields `regular_price`, `status`, via WC public CRUD (getter / setter+save). Drift `equals`: `regular_price` normalized-decimal, `status` string. (No `WooProductAccessor` exists on this branch; this is Bulk-operation-scoped, not a Woo-runtime change.)
- **`BulkAcfAccessor`** — `FieldAccessor` over a single ACF field value (constructed with the `field_key`): field `value` via `get_field`/`update_field`; existence via `metadata_exists`; `key_delete` clears via `update_field(null)`. Scalar/normalized `equals`. Covers the single value the bulk_acf op writes (nested ACF structures remain P4.9).

All five bulk entities are **post-bound** (post / attachment / WC product / ACF-on-post), so every per-item record lives in `PostMetaRollbackStore`.

## 3. Record & envelope formats (schema-free; all postmeta)

### 3.1 Per-item record — `_wpcc_bulk_rb_{itemRid}` on the item post
A standard `RollbackDelta::build_record` v2 record with a Bulk head:
```
{ id: itemRid, post_id: P, action: <bulk_action>, accessor: 'content'|'woo'|'acf',
  batch_id: B, field_key?: <acf field>, version: 2,
  fields: { <field>: { after, keys:{ key:{ existed, prior } } } },
  rollback_applied: false, created_at, session_id, task_id }
```
Persisted via `PostMetaRollbackStore('_wpcc_bulk_rb_')->persist(P, itemRid, record)`.

### 3.2 Batch envelope — membership meta `_wpcc_bulk_b_{batchId}` on each item post
For each item, one membership row: `add_post_meta(P, '_wpcc_bulk_b_'.B, itemRid, false)`. The **batch_id is encoded in the meta_key**, so the whole batch is resolved by one indexed query:
```
SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key = '_wpcc_bulk_b_{B}'
→ [(post_id, itemRid), …]
```
**No option, no FIFO, no eviction, no autoload, GC-with-post, derivable** (the index *is* postmeta). The batch's type/accessor/field_key are read from any member record (each carries `action`/`accessor`/`field_key`), so no separate descriptor is stored.

### 3.3 Why not an option index
An option batch index would reintroduce FIFO eviction (violating requirement #16) or unbounded growth. Membership-in-the-meta-key is O(1), eviction-free, and self-GCing — the keystone philosophy.

## 4. Apply flow (per bulk op)
```
B = uuid()                                  // batch rollback_id (returned to caller)
store = PostMetaRollbackStore('_wpcc_bulk_rb_')
for each id in ids (≤ MAX_ITEMS):
   (accessor, touched, field_key?) = bind(action, id, payload)   // touched in unified names
   prior = RollbackDelta::capture(accessor, id, touched)         // BEFORE the write
   <existing forward mutation for this op>                       // unchanged
   after = { f: accessor.read_field(id, f) for f in touched }
   itemRid = uuid()
   record  = RollbackDelta::build_record(touched, prior, after, cx,
               head{ id:itemRid, post_id:id, action, accessor, batch_id:B, field_key? })
   store.persist(id, itemRid, record)                            // per-item, immediately
   add_post_meta(id, '_wpcc_bulk_b_'.B, itemRid, false)          // membership
   items++
return { action, updated, results, rollback_id: B }              // shape preserved + B
```
Capturing+persisting **per item inside the loop** means a mid-batch apply failure still leaves every already-mutated item individually reversible (closes G4 on the apply side).

**Field binding per action:**
| action | accessor | touched (unified) | notes |
|---|---|---|---|
| bulk_content | ContentFieldAccessor | title (if post_title), content (if post_content) | |
| bulk_publish / bulk_unpublish | ContentFieldAccessor | status | |
| bulk_media | ContentFieldAccessor | title | attachment post_title |
| bulk_woocommerce | BulkWooAccessor | regular_price (if set), status (if set) | |
| bulk_acf | BulkAcfAccessor(field_key) | value | field_key in head |

## 5. Rollback flow — `rollback(rollback_id = B)`
```
members = SELECT post_id, meta_value WHERE meta_key='_wpcc_bulk_b_'.B   // indexed
if members empty:
   return legacy_rollback(B)        // P4C.0a option path, unchanged (back-compat)
store = PostMetaRollbackStore('_wpcc_bulk_rb_')
restored=skipped=missing=already=errored=0 ; per_item=[]
for each (post_id, itemRid) in unique(members):
   res = store.resolve(itemRid)
   if res is null: missing++ ; per_item[]={post_id,itemRid,status:'missing'} ; continue
   rec = res.record
   if rec.rollback_applied: already++ ; per_item[]={…,status:'already'} ; continue
   accessor = build_accessor(rec.accessor, rec.field_key ?? '')
   try:
      o = RollbackDelta::restore(accessor, post_id, rec.fields)     // drift-aware
   catch Throwable:
      errored++ ; per_item[]={…,status:'error'} ; continue          // isolation
   if o.status == 'complete':
      rec.rollback_applied=true; rec.applied_at=time()
      store.mark_applied(post_id, itemRid, rec); restored++
   else:                                                            // partial/conflict (drift)
      skipped += count(o.skipped)
   per_item[] = { post_id, rid:itemRid, status:o.status, restored:o.restored, skipped:o.skipped }
status = aggregate(restored, skipped, missing, already, errored, total)
if total>0 and restored==0 and skipped==0 and missing==0 and errored==0 and already==total:
   return err('done', …)            // fully already-applied → preserves hotfix B8 idempotency
audit('bulk.rollback', { type, items, restored, skipped, missing, already, errored, status })
return { action:'bulk_rollback', rollback_id:B, type, items, restored, skipped, missing,
         already, status, per_item, fields, reversible:true }
```

### 5.1 Aggregate status semantics
- **complete** — every member restored (or already-applied) with no skips/missing/errors.
- **partial** — at least one restored AND at least one skipped/missing/errored.
- **conflict** — nothing restored, but members existed and all skipped (drift) — honest reversible:false on the skipped set, retryable.
- **already (`err('done')`)** — all members already applied (idempotent repeat).

### 5.2 Drift / sibling / out-of-order (the F-1 closure)
`RollbackDelta::restore` compares each field's live value to the recorded apply-time `after`; on drift it **skips + reports conflict** instead of clobbering. Because each item record captures **only the touched fields**, restore never touches siblings. Out-of-order is handled exactly as SEO S4/S5 (drift on the still-changed field; retry succeeds once it matches). Existed-vs-empty fidelity comes from the per-key `existed` flag.

## 6. Decisions (per the mission checklist)
- **Batch envelope format:** membership meta `_wpcc_bulk_b_{B}` (post_id→itemRid rows); type derived from member records. No option.
- **Per-item record format:** `RollbackDelta` v2 record + Bulk head, in `_wpcc_bulk_rb_{itemRid}` postmeta.
- **Partial failure semantics:** per-item try/catch; one item's failure → `status:'error'` for that item, batch continues, item **not** marked applied (retryable); batch status becomes `partial`.
- **Rollback result shape:** backward-compatible (`action, rollback_id, type, restored, fields, reversible`) **plus** `items, skipped, missing, already, status, per_item`.
- **Audit event:** `bulk.rollback` with the full per-batch aggregate (truthful).
- **Idempotency:** per-item `rollback_applied`; fully-applied batch → `err('done')`.
- **Maximum batch:** `MAX_ITEMS = 200` unchanged; per-item postmeta scales (2 rows/item; GC with posts).
- **GC behavior:** deleting an item post removes both its record and membership meta → that member resolves `missing` (honest), other members unaffected.

## 7. Legacy compatibility
- **P4C.0a option records** still resolve: if the new membership query returns no members for `B`, `rollback()` falls back to the **unchanged** legacy option path (scan `wpcc_bulk_rollbacks`, action-dispatched restore, legacy scalar normalization, `err('done')`, `unsupported`). New ops never write the option, so it stops growing.
- **Response conventions preserved** so the P4C.0a suite (`test-bulk-rollback-fix.sh`) stays green: normal complete → `restored>=1, reversible:true`; fully-applied → `err('done')`; unknown/unsupported → `wpcc_bulk_rollback_unsupported, reversible:false`; missing id → `err('missing')`; not found → `err('nf')`.

## 8. Scope / STOP
- Files: `BulkRuntimeManager.php` (rewrite of capture/restore internals) + new `BulkWooAccessor.php`, `BulkAcfAccessor.php` (Bulk-scoped). Reuses `ContentFieldAccessor`, `RollbackDelta`, `PostMetaRollbackStore`.
- **No** operation-registry / capability / MCP / REST-route / UI / schema / DB_VERSION / security change. Action set, routes, capability, MCP tool unchanged. Added response fields are additive. New meta keys do not bump DB_VERSION.
- **No STOP condition triggered.** Bulk semantics (per-item drift-aware reversal) follow directly from the program's stated goal — no product-owner decision required.

## 9. Validation plan (Phase D preview)
New `test-bulk-delta-rollback.sh` (manager-level, PHP-bootstrapped) covering the mission's 17 points: status-not-title, status restore, sibling preservation, media/woo/acf per-item rollback, one-item-failure isolation, partial truthfulness, idempotency, drift skip/report, legacy P4C.0a record, batch-index resolution, missing-item honesty, rollback_id surfaced, truthful audit, no-FIFO, invariants. Plus regression: existing bulk suites, rollback-delta-core, PostMetaRollbackStore suite, the other runtime rollback suites, change-history-rollback, and registry/capability/MCP guards.
