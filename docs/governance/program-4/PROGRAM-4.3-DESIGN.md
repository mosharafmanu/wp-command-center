# PROGRAM-4.3 — Content Rollback Integrity · Design Report

> **Branch:** `program-4.3-content` (from P4.0 `2234dcc`; P4.1/P4.2 excluded — confirmed). **Goal:** field-scoped, drift-aware `RollbackDelta` for the Content `update` action. **No other runtime; no op/cap/MCP/REST/UI/schema; no merge/push/deploy.**

## 1. Audit (verified in source)
- `includes/Operations/ContentManager.php` (525 lines); dispatch via `ACTION_ROLLBACKS['content_manage' => 'content_rollback']` (action-based, no public `rollback()`).
- `update_content()` (`:213`): captures `$before = {title,status,content,excerpt}` (all four **post columns**) at `:218` **before** the write (`:235`) → no DEF-1; over-reaches (captures/restores all four even when one changed → F-1).
- `store_rollback()` (`:492`): record keyed by `rollback_id` in option `wpcc_content_rollbacks`; `{id, content_id, action, before_state, …}`.
- `rollback_content()` (`:63`): the shared restore — `wp_update_post` of title/status/content (+ excerpt if captured) from `before_state`; marks applied. Used for both `update` and `delete` records.
- `create_content` stores **no** record; `delete_content` stores `{title,status,type}` (action-based trash/untrash).
- Fields → backing keys: title→`post_title`, status→`post_status`, content→`post_content`, excerpt→`post_excerpt` (all columns; always exist).

## 2. Scope
**In:** the `update` action → field-scoped delta. **Out (unchanged):** `delete` (action-based), `create` (no record), publish/unpublish/schedule/taxonomy/featured. Mirrors the Media-metadata approach.

## 3. Design (reuse P4.0 core)
**New `includes/Rollback/ContentFieldAccessor.php` (`FieldAccessor`):** pure post-column accessor —
`backing_keys(f)=[KEY]`; `read_field/key_get = get_post_field(key,id,'raw')`; `key_set = wp_update_post([ID,key=>v])`; `key_exists = post exists ⇒ true`; `key_delete = wp_update_post([ID,key=>''])` (never reached — columns always exist); `equals = string`.

**`ContentManager` changes (update only):**
- `update_content()`: `$touched` = fields present in params among title/content/excerpt/status; `RollbackDelta::capture(new ContentFieldAccessor(), $id, $touched)` **before** the write; existing writes unchanged; read `$after`; persist a **v2 delta** record (same option, keyed by id, adds `version:2`+`fields`). `rollback_id` unchanged.
- `rollback_content()`: if record has `fields` (v2) → `RollbackDelta::restore(new ContentFieldAccessor(), …)`; mark applied **only on complete**; return success or `wpcc_rollback_conflict|partial` array (surfaced as a failed rollback by the dispatcher). Else (**legacy update + delete** records) → existing `wp_update_post(before)` path **unchanged**.

## 4. Affected files
**New:** `includes/Rollback/ContentFieldAccessor.php`, `tests/test-content-rollback-delta.sh`. **Modified:** `includes/Operations/ContentManager.php`. **Unchanged:** P4.0 core, all other runtimes, OperationExecutor, registries, Schema, REST, UI.

## 5. Risks
R1 delete/legacy records must keep working (branch on `fields`; test) · R2 column writes per field (extra `post_modified`; cold path) · R3 status restore hooks (same as before — one-field write) · R4 SEO/core regression (additive; run suites) · R5 content-runtime suite must stay green (98/0) · R6 invariants frozen.

## 6. Validation plan
New `tests/test-content-rollback-delta.sh`: S1 value-prior restore · S2 empty-but-existing (excerpt '') · S3 sibling preservation + drift (partial) · S4 same-field conflict · S5 out-of-order · S6 legacy `before_state` update record · S7 delete record still reverts · S8 repeated rollback guarded · S9 partial/conflict ≠ clean success · S10 untouched column not in record · static guards. **Regression:** `test-content-runtime` (98/0), core (25/0), seo-delta (56/0), change-history-rollback (standalone), registry/cap/MCP parity, invariants 34/23/40/40/2.5.0.
