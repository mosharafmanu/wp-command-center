# PROGRAM-4C.0a — Bulk Remediation Design (Phase C)

> **Type:** design (no code in this phase). Report-only. **Scope: strictly `includes/Operations/BulkRuntimeManager.php`.**
> **Nature:** the *smallest safe correctness hotfix* — legacy snapshot, corrected. **Not** the P4.8 delta redesign (no `RollbackDelta`, no per-item postmeta store, no drift-awareness — those are deferred).

---

## 1. Base & branch
- **Base:** `program-4b-integration-core-hardening` @ `8550a4b` (Bulk file blob `5f92d1e`, identical to production; no P4.6 spillover).
- **New branch (Phase G):** `program-4c.0a-bulk-rollback-fix`.

## 2. Goals / non-goals
**Goals:** eliminate the title-corruption; restore the correct field per action; make media/woo/acf reversible; capture `post_content` for content; surface `rollback_id`; make the rollback response honest; remain backward-compatible with any pre-existing record shape.
**Non-goals (explicitly out of scope):** `RollbackDelta`/`PostMetaRollbackStore`, drift detection, per-item delta records, batch-index, `batch_execute` reversal, any registry/schema/contract change, any non-Bulk file.

## 3. Root cause
`rollback()` was written for `bulk_content` (a `{id→title}` map) and hard-restores `post_title`. `bulk_status` reuses the same `{id→value}` envelope but the value is a *status*, so the single restore line writes a status into the title and never restores status. The fix is to make **capture self-describing** and **restore dispatch on the record's action**, instead of assuming every record is a title map.

## 4. Design

### 4.1 Self-describing capture (backward-compatible)
Each write path records a per-id **field map** instead of a bare scalar, plus the fields it touched:
```
before_state = {
  ids:    [<id>, ...],
  fields: [<field>, ...],                 // which fields this op wrote (drives restore)
  before: { <id>: { <field>: <priorValue>, ... }, ... }
}
```
- `bulk_content` → fields `['post_title']` (+ `'post_content'` when the payload set it); `before[id]` = `{post_title, post_content?}`.
- `bulk_status` (publish/draft) → fields `['post_status']`; `before[id]` = `{post_status}`.
- `bulk_media` → fields `['post_title']`; `before[id]` = `{post_title}` (only when a title is actually being changed).
- `bulk_woo` → fields among `['regular_price','status']` (only those the payload sets); `before[id]` = `{regular_price?, status?}`.
- `bulk_acf` → fields `['acf']`; record carries `field_key`; `before[id]` = `{acf:<priorValue>}` captured via `get_field($field,$id)` before the write.

**Legacy compatibility:** `restore_value()` accepts both shapes — if `before[id]` is a **scalar** (old format), it is treated as the value for the record action's *primary* field (content/media→`post_title`, publish/draft→`post_status`). This means even a pre-existing `bulk_publish`/`bulk_draft` legacy record (scalar status) now restores **status** correctly instead of clobbering the title. No migration, no new option, no shape break for readers.

### 4.2 Action-dispatched restore
`rollback()` resolves the record, then dispatches by `$rec['action']`:
| Record action | Restore primitive |
|---|---|
| `bulk_content` | `wp_update_post(['ID'=>id, 'post_title'=>…, 'post_content'?=>…])` |
| `bulk_publish` / `bulk_draft` | `wp_update_post(['ID'=>id, 'post_status'=>…])` |
| `bulk_media` | `wp_update_post(['ID'=>id, 'post_title'=>…])` |
| `bulk_woocommerce` | guard `class_exists('WooCommerce')`; `wc_get_product(id)`; `set_regular_price`/`set_status` for captured fields; `save()` |
| `bulk_acf` | guard `function_exists('update_field')`; `update_field($field_key, value, id)` |

Each id missing at restore time is skipped (counted). The dispatcher restores **only the fields the record captured** — so a status rollback touches status only, a content rollback touches title (+content) only: **no cross-field clobber.**

### 4.3 Surface the rollback_id + honest envelope
- `store_rollback()` returns the generated `$rid`; each write path returns it as `rollback_id` in its result (additive; matches Content/Woo/SEO convention).
- `bulk_media`/`bulk_woo`/`bulk_acf` call `store_rollback` (when at least one item was affected) and return `rollback_id`.
- `rollback()` returns an honest envelope: `{action:'bulk_rollback', rollback_id, type:<record action>, restored:<count>, fields:<restored fields>, reversible:true}`; when a required dependency is inactive (WC/ACF) or the record is unrecognized/empty, it returns a structured error (`reversible:false` / `wpcc_bulk_rollback_unsupported`) rather than a false success.
- A dedicated audit event `bulk.rollback` records `{action, restored, fields}` so the trail reflects what actually happened.

### 4.4 Idempotency & safety
- The existing `rollback_applied` guard (`:101`) is preserved — a second rollback returns `already applied`.
- Restore is wrapped so a single failing item cannot abort the whole pass (count + continue); the record is marked applied after the pass (mirrors current behavior; no partial-record retry semantics are introduced in the hotfix).
- **Drift:** the hotfix is **unconditional restore** (no drift detection) — this is the deliberate legacy behavior; drift-awareness is deferred to P4.8. The validation documents this honestly (a drifted field is overwritten by the prior value, but **only the captured field** — never a sibling).

## 5. Why this is the smallest safe fix
- One file, no new classes, no core/store dependency.
- Reuses the existing option, action set, routes, capability, MCP tool — zero contract/registry/schema movement.
- Backward-compatible record reader (handles old scalar records, and even *corrects* legacy status records).
- Additive response field only.
- Closes all six Phase-A findings (corruption, silent fail, incomplete records, lost ids, media/woo/acf gaps, dishonest history) without expanding architecture.

## 6. Invariant & STOP check
- **No** schema / DB_VERSION / capability / operation-registry / MCP / REST-contract / security-model change. Invariants 34 · 23 · 40 · 40 · 2.5.0 expected unchanged.
- Adding `rollback_id` and an `bulk.rollback` audit event are additive and within Bulk scope.
- **No STOP condition triggered.**

## 7. Validation plan (Phase E preview)
Dedicated PHP-bootstrapped suite `tests/test-bulk-rollback-fix.sh` exercising the manager directly:
- **B1 corruption reproduction (proof of bug → fixed):** publish then rollback → status back to prior **and title unchanged**.
- **B2 corruption prevention:** assert title is NOT a status word after a status rollback.
- **B3 content correctness:** title+content restored; sibling field untouched.
- **B4 media reversibility:** bulk_media rename → rollback restores title (record now exists + id surfaced).
- **B5 woo reversibility:** bulk_woo price → rollback restores regular_price (WC-guarded).
- **B6 acf reversibility:** bulk_acf value → rollback restores prior value (ACF-guarded).
- **B7 legacy compatibility:** a hand-crafted legacy `bulk_publish` scalar record restores **status** (proves the corrected legacy reader).
- **B8 idempotency:** second rollback → already-applied.
- **B9 history honesty:** rollback envelope reports `restored` count + fields; unsupported dependency → structured error, not false success.
- **B10 rollback_id surfaced:** each bulk write returns a non-empty `rollback_id`.
Plus the standing guards: rollback-delta-core, operations-registry, capability-runtime, mcp-error-surface, change-history-rollback, and the existing bulk-runtime suite.
