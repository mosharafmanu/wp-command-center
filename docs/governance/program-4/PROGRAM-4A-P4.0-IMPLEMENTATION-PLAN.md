# PROGRAM-4A / P4.0 — Implementation Plan (pre-coding)

> **Date:** 2026-06-23 · Produced before any code change (workflow step 2). Scope: **P4.0 only** — extract SEO delta into a runtime-agnostic core, refactor SEO to consume it, behavior frozen.

## Extraction boundary (decided)
**Core (reusable, pure):** the two correctness-critical loops.
- `RollbackDelta::capture(FieldAccessor, entity_id, touched)` — prior {existed,prior} per backing key.
- `RollbackDelta::restore(FieldAccessor, entity_id, fields)` — drift-skip + existence-faithful key write/delete + status (`complete|partial|conflict`). Returns `{status,restored,skipped,conflicts}`. **No WP calls** (only via accessor).

**Stays in `SeoRuntimeManager` (SEO-specific, behavior-frozen):**
- `store_rollback()` — **unchanged**; keeps the exact v2 record literal (`version=>2`, `fields=>$fields`, `post_id`, `provider`, …) → **on-disk shape guaranteed identical** (compat req #1/#2).
- `seo_restore()` dispatch (indexed postmeta SELECT → v2 vs legacy-meta vs legacy-option) — **unchanged** (compat req #3).
- `restore_delta()` becomes a thin wrapper: call core → on `complete` mark-applied (`update_post_meta` record) + audit + success envelope; else audit + `wpcc_rollback_conflict|partial` envelope. Keeps those strings + `if ( 'complete' === $status )` + idempotency in the runtime.
- `restore_legacy_meta()` / `seo_restore_legacy()` — **unchanged**.

**New accessor classes (`includes/Rollback/`, ns `WPCommandCenter\Rollback`, autoloaded by convention):**
- `FieldAccessor` (interface): backing_keys, read_field, key_exists, key_get, key_set, key_delete, equals.
- `PostMetaAccessor` (abstract base): key_* via `metadata_exists('post',…)/get_post_meta/update_post_meta/delete_post_meta`; default `equals` = string compare.
- `SeoFieldAccessor extends PostMetaAccessor`: backing_keys/read_field delegate to `SeoProvider($provider)`; `equals('robots',…)` = order-insensitive set compare (verbatim from `values_equal`).
- `RollbackDelta`: the two loops above.

**Removed from `SeoRuntimeManager`:** `values_equal()` (moved to `SeoFieldAccessor::equals`); the inline capture/restore loop bodies (delegated).

## Behavior-preservation guarantees
- `capture()`/`restore()` are **1:1 lifts** (same comparisons, same `existed?set:delete` existence fidelity, same status formula). Verified against compat reqs #4–#9.
- `store_rollback` untouched ⇒ record shape frozen; legacy paths untouched ⇒ legacy restore intact.
- `RollbackDelta::restore` never marks applied (runtime does, only on `complete`) ⇒ partial/conflict stay retryable (#6/#7).

## Test strategy
- **NEW** `tests/test-rollback-delta-core.sh` — pure-`php` harness (define `ABSPATH` shim, require `FieldAccessor` + `RollbackDelta`, supply an in-memory **FakeAccessor**). Proves capture/restore F-1 behaviors with **zero WordPress** — the decoupling proof.
- **`tests/test-seo-rollback-delta.sh`** — all **34 functional** wp-cli round-trip assertions **byte-identical** (the behavior oracle). **5 static assertions re-pointed** to the code's new home (`metadata_exists` → PostMetaAccessor; `function values_equal` → SeoFieldAccessor `equals` + robots sort; `'reason'=>'drift'`, existence-faithful set/delete → RollbackDelta), plus new assertions proving SEO now delegates (`RollbackDelta::`, `SeoFieldAccessor`). Every re-point documented old→new in the audit.

## Files to change
NEW: `includes/Rollback/FieldAccessor.php`, `PostMetaAccessor.php`, `SeoFieldAccessor.php`, `RollbackDelta.php`; `tests/test-rollback-delta-core.sh`.
MOD: `includes/Operations/SeoRuntimeManager.php`; `tests/test-seo-rollback-delta.sh`.
UNCHANGED: `SeoProvider.php`, OperationRegistry, CapabilityRegistry, McpServerRuntime, Schema, REST, UI.

## Decision: defer PostMetaRollbackStore
The design listed an optional `RollbackStore`. **Not added in P4.0:** record resolution (the indexed postmeta SELECT + legacy-option fallback) stays in `seo_restore` **unchanged** — the most behavior-preserving choice and zero benefit until a non-postmeta runtime (Settings, P4.1) needs an option-store. Documented; revisit at P4.1.

## Invariants to re-verify: 34 · 23 · 40 · 40 · 2.5.0 (no op/cap/tool/schema touched).
