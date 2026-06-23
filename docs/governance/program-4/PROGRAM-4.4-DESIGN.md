# PROGRAM-4.4 — Comments Rollback Integrity · Design Report

> **Branch:** `program-4.4-comments` (from P4.0 `2234dcc`; P4.1/P4.2/P4.3 excluded — confirmed). **No other runtime; no op/cap/MCP/REST/UI/schema; no merge/push/deploy.**

## 1. Audit (verified in source) — Comments differs from the F-1 pattern
`includes/Operations/CommentsRuntimeManager.php` (314 lines); public `rollback()` (dispatched via `OperationExecutor::rollback` method-path). Actions: list/get/approve/unapprove/spam/trash/delete/reply.
- **Only `trash` and `delete` store rollback records** (`trash_comment`/`delete_comment` → `store_rollback`). `rollback()` reverses `trash` (untrash) and rejects `delete` (irreversible — honest).
- **`approve`/`unapprove`/`spam` capture NOTHING and are NOT reversible** — they call `wp_set_comment_status` and return; no rollback record. This is a **reversibility coverage gap**, not a full-object over-reach.
- There is **no comment-content edit/update action** (reply creates a new comment). So the only runtime-mutable comment field is the **status** (`comment_approved`).

**Conclusion:** Comments exhibits **no F-1 full-object over-reach to replace.** The integrity defect is the *missing* reversibility of status changes. P4.4 closes it with the field-scoped, drift-aware delta — the same core, applied constructively.

## 2. Scope
**In:** add field-scoped, drift-aware **status** rollback (`comment_approved`) for `approve`/`unapprove`/`spam`, via `RollbackDelta` + a new `CommentFieldAccessor`. **Out (unchanged):** `trash` (untrash), `delete` (irreversible), `reply`, list/get.

## 3. Design (reuse P4.0 core)
**New `includes/Rollback/CommentFieldAccessor.php` (`FieldAccessor`):** single field `status`→`comment_approved` (comment column).
`backing_keys('status')=['comment_approved']`; `read_field/key_get = (string) get_comment(id)->comment_approved`; `key_set = wp_update_comment(['comment_ID'=>id,'comment_approved'=>v], true)`; `key_exists = comment exists ⇒ true`; `equals = string`.

**`CommentsRuntimeManager` changes:**
- `approve_comment`/`unapprove_comment`/`spam_comment`: capture `status` via `RollbackDelta::capture(new CommentFieldAccessor(), $id, ['status'])` **before** the status change; perform the existing change; read post-change `after`; persist a **v2 delta** record (action `'status'`, `version:2`, `fields`) via a new `store_status_delta()` (same `wpcc_comments_rollbacks` option, dedicated path — **no `CommentsRegistry` change**); add `rollback_id` to the response.
- `rollback()`: add an early branch for v2 records (`isset($record['fields'])`) → `RollbackDelta::restore(new CommentFieldAccessor(), …)`, mark applied **only on complete**, truthful `status/restored/skipped` audit, success or `wpcc_rollback_conflict|partial` envelope. The existing `trash`/`delete` switch is **unchanged**.

## 4. Affected files
**New:** `includes/Rollback/CommentFieldAccessor.php`, `tests/test-comment-rollback-delta.sh`. **Modified:** `includes/Operations/CommentsRuntimeManager.php`. **Unchanged:** P4.0 core, `CommentsRegistry`, all other runtimes, OperationExecutor, registries, Schema, REST, UI.

## 5. Risks
R1 status restore via `wp_update_comment(comment_approved)` across spam/hold/approve transitions — **must verify** (if spam/trash restore proves unsafe, scope down to approve/unapprove and document) · R2 single field ⇒ status is `complete` or `conflict` (no `partial`) — fine · R3 SEO/core regression (additive) · R4 comments suite must stay green · R5 invariants frozen · R6 adding reversibility is additive (no new op/cap/MCP; `rollback_id` in response is additive like Settings/Media).

## 6. Validation plan
New `tests/test-comment-rollback-delta.sh`: S1 approve→rollback restores prior status (drift-aware) · S2 unapprove→rollback · S3 spam→rollback restores prior approval · S4 same-field drift → conflict (status changed again) · S5 out-of-order · S6 repeated rollback guarded · S7 trash record still untrashes (unchanged) · S8 delete still unsupported (unchanged) · static guards. **Regression:** `test-comments-runtime`, core (25/0), seo-delta (56/0), change-history-rollback (standalone), registry/cap/MCP parity, invariants 34/23/40/40/2.5.0. **Stop if** wp_update_comment status restore yields an attributable failure that can't be safely resolved in scope.
