# PROGRAM-4.1 — Settings Runtime Rollback Integrity · Final Report

> **Date:** 2026-06-23 · **Phase:** P4.1 of PROGRAM-4 Rollback Integrity Expansion. **Branch:** `program-4.1-settings-rollback` (stacked on P4.0 `2234dcc`).
> **Companion reports:** [Design](PROGRAM-4.1-DESIGN.md) · [Implementation](PROGRAM-4.1-IMPLEMENTATION-REPORT.md) · [Validation](PROGRAM-4.1-VALIDATION-REPORT.md) · [Independent Audit](PROGRAM-4.1-INDEPENDENT-AUDIT.md).
> **Constraints honoured:** no push / no deploy / no merge; no schema/DB_VERSION/op/cap/MCP/REST/UI; no other runtime touched; no AI-enable/keys/mode change.

## 1. Changed files
**New:** `includes/Rollback/OptionAccessor.php` (generic WP-option FieldAccessor), `tests/test-settings-rollback-delta.sh`, `docs/governance/program-4/PROGRAM-4.1-*.md`.
**Modified:** `includes/Operations/SettingsRuntimeManager.php` (capture-before-write, field-scoped v2 delta store, drift-aware restore, legacy branch, surfaced `rollback_id`).

## 2. Diff stat (vs P4.0 base `2234dcc`)
```
 includes/Operations/SettingsRuntimeManager.php | 71 ++++++++++++++++++---  (+71 / −3)
 includes/Rollback/OptionAccessor.php           | + new
 tests/test-settings-rollback-delta.sh          | + new
```
No forbidden surface touched; P4.0 core, SEO runtime, and OperationExecutor unchanged.

## 3. Tests executed — pass/fail
| Suite | Result |
|---|---|
| `php -l` (OptionAccessor, SettingsRuntimeManager) | clean |
| **`test-settings-rollback-delta.sh`** (new) | **35 / 0** |
| `test-rollback-delta-core.sh` | **25 / 0** (core unchanged) |
| `test-seo-rollback-delta.sh` | **56 / 0** (no SEO regression) |
| `test-seo-rollback-store.sh` / `test-seo-undo.sh` | **28 / 0** · **33 / 0** |
| `test-site-settings-runtime.sh` (REST) | **24 / 0** |
| `test-operations-registry` / `capability-runtime` / `mcp-error-surface` | 18/0 · 61/0 · 18/0 |
| `test-change-history-rollback.sh` | Sections 1–9 **green**; Section-0 backfill 1 red (concurrency, non-attributable; clean re-run for the record) |

## 4. Invariant status
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** — **all held**.

## 5. Attributable failures
**None.** The only reds encountered were two **test-fixture** issues during authoring (S1 used the specially-handled `WPLANG`; S7 used `posts_per_page`, which hits a pre-existing update-method bug DEF-3) — both corrected in the test; the rollback code was never at fault. No fix to product code was required by a failing rollback assertion.

## 6. Residual risks
- **DEF-3 (`reading_update` null-key, MED, out of scope):** `reading_update` writes `posts_per_page` from `$p[null]` → `0` instead of the supplied value. An **update-method** bug, not rollback; recommend a separate small fix. Rollback is correct regardless (captures/restores what was actually written).
- **permalink rollback does not re-`flush_rewrite_rules` (LOW):** matches prior behaviour; the option value is restored but rewrite rules may be stale until the next flush.
- **Empty-payload mutation stores an empty-`fields` record (LOW):** rolls back as a `complete` no-op; preserves the rollback_id contract.
- Pre-deploy items (full serial T2, prod token-gated verify) remain deploy-coupled and out of scope.

## 7. GO / NO-GO for commit
**GO for commit.** All eight design goals proven; two pre-existing defects (DEF-1 capture-after-write no-op, DEF-2 group over-reach) fixed; no SEO/core/dispatch regression; invariants held; scope clean; independent audit PASS. **Commit on the feature branch only — no merge, no push, no deploy.**

## 8. Suggested commit message
```
feat(rollback): field-scoped drift-aware Settings rollback via RollbackDelta (P4.1)

Migrate the Settings runtime off the full-object option-group snapshot onto the
P4.0 RollbackDelta core via a new generic OptionAccessor (WP options as fields,
1:1, sentinel-based existence). Rollback is now field-scoped (only the options a
call actually wrote), drift-aware (skip+report instead of clobber), sibling-
preserving, out-of-order safe, existence-faithful, and partial/conflict-honest;
legacy before_state records still restore.

Fixes two pre-existing defects: capture now happens BEFORE the write (the old
store_rollback snapshotted post-write values, making rollback a no-op), and the
snapshot no longer over-reaches the whole option group. rollback_id is now
surfaced in the update response.

New OptionAccessor + test-settings-rollback-delta (35/0). No SEO/core regression
(seo-rollback-delta 56/0, rollback-delta-core 25/0, site-settings 24/0).
Invariants 34/23/40/40/2.5.0 held. No schema/op/cap/MCP/REST/UI change; no other
runtime touched. Discovered (not fixed, out of scope) DEF-3: reading_update
posts_per_page null-key bug.

PROGRAM-4 / P4.1.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

**Status: GO for commit** (branch `program-4.1-settings-rollback`; no merge/push/deploy). The lone ch-rollback red is the verified non-attributable Section-0 backfill-concurrency artifact (Sections 1–9 green; twice cleanly 48/0 standalone this session); it is on no P4.1-touched path, so it does not gate. A clean standalone re-run is running for the record.
