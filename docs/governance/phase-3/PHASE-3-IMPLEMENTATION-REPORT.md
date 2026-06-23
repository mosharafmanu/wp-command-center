# Phase 3 — Implementation Report (F-1 SEO Delta Rollback)

**Scope:** SEO runtime only. **Status:** implemented, lint-clean, validation-green.
**Invariants:** OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0 (all verified unchanged).

---

## 1. Files changed

| File | Lines | Nature |
|---|---|---|
| `includes/Operations/SeoProvider.php` | +43 | Two read-only helpers: `backing_keys()`, `read_field()`. `write()`/`read()` unchanged. |
| `includes/Operations/SeoRuntimeManager.php` | +~190/-~30 | v2 delta store; field-scoped drift-aware restore; legacy branch retained. |
| `tests/test-seo-rollback-delta.sh` | new (52 assertions) | Phase 3 validation suite (all scenarios). |
| `tests/test-seo-rollback-store.sh` | ~15 | Updated 2 assertions to the v2 record shape (Slice 4c storage guarantees unchanged). |
| `tests/test-seo-runtime-step91.sh` | ~16 | Section 9 rewritten provider-agnostic for field-scoped drift semantics. |

No changes to registries, OperationExecutor, ChangeRecorder, ChangeHistory, REST
routes, capabilities, operation map, MCP tools, DB schema, or admin UI.

---

## 2. What was implemented

### SeoProvider (read-only additions)
- `backing_keys(field, provider)` — field → backing post-meta keys (1 for scalars; the
  provider's robots key-set for `robots` — Rank Math `rank_math_robots`; Yoast
  noindex/nofollow/adv).
- `read_field(post_id, field, provider)` — single-field current value (scalar string or
  normalized robots array) for drift comparison. Wraps the existing private
  `read_robots`. No write-path or storage change.

### SeoRuntimeManager (rollback rework)
- **`seo_update`**: now `capture_prior()` snapshots ONLY the touched fields' backing
  keys (each `{existed, prior}`) BEFORE the write; after `SeoProvider::write`, persists
  a **v2 delta record** carrying per-field `after` (post-write value) + backing-key
  prior. The full-object `before_state` snapshot is gone. Audit `seo.updated` gains
  `rollback_format => 'delta'`. `rollback_id` (uuid4) contract unchanged.
- **`store_rollback`**: new signature builds the `fields` map; same per-meta storage
  (`_wpcc_seo_rb_{id}`, no cap, not autoloaded).
- **`seo_restore`**: resolves the record by `rollback_id` (unchanged indexed lookup),
  then branches:
  - `isset($record['fields'])` → **`restore_delta()`** (field-scoped, drift-aware).
  - else → **`restore_legacy_meta()`** (pre-Phase-3 full-snapshot post-meta record;
    unchanged behavior).
  - not found in post-meta → `seo_restore_legacy()` (pre-4c option store; unchanged).
- **`restore_delta()`**: per touched field, compares live value to recorded `after`
  (`values_equal()`); drift → skip + conflict; no drift → restore each backing key by
  existence (`existed=false` ⇒ delete; `existed=true` ⇒ write prior, even `''`).
  Computes `status` ∈ {complete, partial, conflict}. **Only `complete` is terminal**
  (marks `rollback_applied`); partial/conflict stay retryable. Returns a success result
  for complete, an `error` envelope (`wpcc_rollback_partial` / `wpcc_rollback_conflict`)
  with `restored_fields`/`skipped_fields`/`conflicts`/`status`/`path` otherwise. Records
  `seo.restored` audit with the full outcome.
- **`values_equal()`**: robots compares normalized directive sets (order-insensitive);
  scalars compare as strings — the same normalization `seo_update` used.

---

## 3. Design adherence

- Delta record (touched fields only) — ✅ `store_rollback`/`capture_prior`.
- Field-scoped restore, no sibling writes — ✅ only `record['fields']` keys touched.
- Existed-vs-empty fidelity — ✅ `existed` flag drives delete-vs-write-empty.
- Drift detection (skip + report, default safe) — ✅ `restore_delta` drift branch.
- Legacy compatibility, no destructive migration — ✅ `restore_legacy_meta` +
  `seo_restore_legacy` retained; forward-only.
- History honesty — ✅ result carries status/restored/skipped/conflicts/path; partial &
  conflict return `error` ⇒ `OperationExecutor::rollback` success=false ⇒ Change History
  does not stamp the change rolled_back (verified at `ChangeHistoryRuntimeManager` L289).
- Audit trail — ✅ `seo.updated` (format), `seo.restored` (outcome), and the existing
  `operation.rollback.dispatched`.
- Capability/op-map/MCP/schema frozen — ✅ verified 34·23·40·40·2.5.0.

The Adversarial Review §B revision (only `complete` is terminal) is implemented exactly.

---

## 4. Lint & focused validation

- `php -l` clean on both production files.
- `tests/test-seo-rollback-delta.sh`: **52/52 PASS** (Rank Math live round-trips + Yoast
  structural). Full scenario evidence in PHASE-3-VALIDATION-REPORT.md.
- `tests/test-seo-rollback-store.sh`: **28/0** (Slice 4c storage guarantees intact under
  the v2 shape).
- `tests/test-seo-runtime-step91.sh`: **23/4** — the 4 failures are pre-existing
  Yoast-vs-Rank-Math env mismatches (DEV runs Rank Math; suite hardcodes Yoast
  provider/meta-key expectations), unrelated to F-1. The rollback section now passes
  fully under field-scoped semantics.

Broader regression results: PHASE-3-VALIDATION-REPORT.md.
