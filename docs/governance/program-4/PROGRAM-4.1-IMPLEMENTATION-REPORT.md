# PROGRAM-4.1 — Settings Runtime Rollback · Implementation Report

> **Date:** 2026-06-23 · **Branch:** `program-4.1-settings-rollback` (stacked on P4.0 `2234dcc`). **No commit yet / no push / no deploy.**
> **Design:** [`PROGRAM-4.1-DESIGN.md`](PROGRAM-4.1-DESIGN.md).

## What was built
**New (`includes/Rollback/OptionAccessor.php`, ns `WPCommandCenter\Rollback`):** a generic WP-option `FieldAccessor` for the P4.0 core — `backing_keys(field)=[field]`, `read_field`=`get_option`, key primitives via `get_option/update_option/delete_option`, existence via a sentinel default (`get_option(key, ABSENT) !== ABSENT`), scalar-string `equals`. Reusable by any option-backed runtime.

**Refactored (`includes/Operations/SettingsRuntimeManager.php`):**
- `run()` mutation path now **captures prior BEFORE the write** (`RollbackDelta::capture(new OptionAccessor(), 0, $touched)`), then runs the update method, then stores a v2 delta with post-write `after` values, and **surfaces `rollback_id`** in the result (previously discarded).
- New `option_field_map($action)` + `touched_options($action,$payload)` — the single source of truth for which options each action writes (mirrors each `*_update` method's `isset` write set), so capture is **field-scoped** (only touched options).
- `store_rollback()` rewritten to persist a **v2 `fields` delta** (touched options only, each with `after` + prior `existed`/value) in the existing `wpcc_settings_rollbacks` option (cap 200) — **no new option, no schema**.
- `rollback()` rewritten: resolve by id, guard `rollback_applied`, branch **v2 → `RollbackDelta::restore(new OptionAccessor(), 0, $fields)`** (mark applied only on `complete`; audit `settings.restored` with path/status/restored/skipped; conflict/partial error envelopes), else **legacy `before_state` → unchanged whole-record restore**.

## Defects discovered (mission permits fixing those in scope)
- **DEF-1 (FIXED) — capture-after-write no-op:** the old `store_rollback` ran *after* the update method and snapshotted `get_option()`, recording post-write values → the prior Settings rollback **reverted to the just-written state (a no-op)**. The new capture-before-write flow fixes this; proven by test S0.
- **DEF-2 (FIXED) — group over-reach:** the old snapshot captured the action's whole option group regardless of what was written; the new field-scoped capture records only touched options (proven by S3/S7 sibling preservation).
- **DEF-3 (DISCOVERED, NOT fixed — out of rollback scope):** `reading_update` writes `update_option('posts_per_page',(int)$p[$key])` where `$key` is `null` for `posts_per_page` (line 48), so it reads `$p[null]` → `0` instead of the supplied value. This is an **update-method** bug, not a rollback bug; fixing it is outside "rollback integrity" and would warrant its own change. Documented as a residual recommendation; the rollback path is correct regardless (it captures/restores whatever the method actually wrote).

## Diff stat (vs P4.0 base)
```
 includes/Operations/SettingsRuntimeManager.php | 71 ++++++++++++++++++---
 includes/Rollback/OptionAccessor.php           | new
 tests/test-settings-rollback-delta.sh          | new
 docs/governance/program-4/PROGRAM-4.1-*.md      | new (reports)
```
Untouched: `RollbackDelta.php`, `FieldAccessor.php`, `PostMetaAccessor.php`, `SeoFieldAccessor.php`, SEO runtime, OperationExecutor, SettingsRegistry, OperationRegistry, CapabilityRegistry, McpServerRuntime, Schema, REST, UI.
