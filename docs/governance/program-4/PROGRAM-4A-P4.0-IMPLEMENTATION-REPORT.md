# PROGRAM-4A / P4.0 — Implementation Report

> **Date:** 2026-06-23 · **Scope:** P4.0 only — extract SEO's field-scoped, drift-aware delta into a runtime-agnostic `RollbackDelta` core; refactor SEO to consume it; behaviour frozen. **No commit / push / deploy.**
> **Plan:** [`PROGRAM-4A-P4.0-IMPLEMENTATION-PLAN.md`](PROGRAM-4A-P4.0-IMPLEMENTATION-PLAN.md) · **Design:** [`PROGRAM-4A-ROLLBACKDELTA-CORE-DESIGN.md`](PROGRAM-4A-ROLLBACKDELTA-CORE-DESIGN.md).

## What was built

**New core (`includes/Rollback/`, ns `WPCommandCenter\Rollback`, autoloaded by convention):**
- **`FieldAccessor.php`** — interface: `backing_keys`, `read_field`, `key_exists`, `key_get`, `key_set`, `key_delete`, `equals`. The only surface the core uses; it never calls WordPress directly.
- **`PostMetaAccessor.php`** — abstract base implementing the raw key primitives over a post's meta (`metadata_exists('post',…)/get_post_meta/update_post_meta/delete_post_meta`) + default string `equals`. Leaves `backing_keys`/`read_field` abstract.
- **`SeoFieldAccessor.php`** — `extends PostMetaAccessor`; `backing_keys`/`read_field` delegate to `SeoProvider($provider)`; `equals('robots',…)` = order-insensitive set compare (lifted verbatim from the old `values_equal`). The single SEO-aware class.
- **`RollbackDelta.php`** — pure logic: `capture()` (prior existence+value per touched backing key) and `restore()` (drift-skip + existence-faithful key write/delete + `complete|partial|conflict` status). Returns a structured outcome; does **not** persist "applied" or audit — the caller owns terminality and provenance.

**Refactored (`includes/Operations/SeoRuntimeManager.php`):**
- `capture_prior()` → one-line delegation to `RollbackDelta::capture(new SeoFieldAccessor($provider), …)`.
- `restore_delta()` → delegates the loop to `RollbackDelta::restore(…)`; **keeps** in the runtime: `complete`-only mark-applied (idempotency), `seo.restored` audit, success envelope, and the `wpcc_rollback_conflict|partial` envelope.
- `values_equal()` → **removed** (moved to `SeoFieldAccessor::equals`).
- `store_rollback()` → **unchanged** → the persisted v2 record shape is byte-identical (compat reqs #1/#2). `seo_update()`/`seo_restore()` dispatch and both legacy restore paths → **unchanged** (compat req #3).

**Tests:**
- **`tests/test-rollback-delta-core.sh`** (new) — pure-`php` harness with an in-memory **FakeAccessor** (no WordPress): 25 assertions across S1–S7 proving capture/restore F-1 behaviours and the status machine. Demonstrates the core is correct *and* storage-decoupled.
- **`tests/test-seo-rollback-delta.sh`** (modified) — **34 functional wp-cli round-trip assertions byte-unchanged** (the behaviour oracle); **5 static structural guards re-pointed** to the code's new home + **4 new** delegation/primitive guards added (52 → 56 assertions). Every re-point is documented in the independent audit.

## Decision recorded: `PostMetaRollbackStore` deferred
The design listed an optional record-store abstraction. **Not built in P4.0:** record resolution (the indexed postmeta `SELECT` + legacy-option fallback) stays in `seo_restore` **unchanged** — the most behaviour-preserving choice, and not needed until a non-postmeta runtime (Settings, P4.1) requires an option-store. Revisit at P4.1.

## Diff stat
```
 includes/Operations/SeoRuntimeManager.php   | 75 +++++------------------- (net −26)
 tests/test-seo-rollback-delta.sh            | 21 ++++++--
 includes/Rollback/FieldAccessor.php         | new
 includes/Rollback/PostMetaAccessor.php      | new
 includes/Rollback/SeoFieldAccessor.php      | new
 includes/Rollback/RollbackDelta.php         | new
 tests/test-rollback-delta-core.sh           | new
```
No other source touched. `SeoProvider.php`, OperationRegistry, CapabilityRegistry, McpServerRuntime, Schema, REST, UI — **all unchanged**.
