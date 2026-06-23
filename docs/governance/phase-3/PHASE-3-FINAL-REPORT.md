# Phase 3 — Final Report (F-1 SEO Rollback Over-Reach)

**Program:** WP Command Center — Phase 3 Autonomous Governance Remediation
**Defect:** F-1 (HIGH) — rollback full-snapshot over-reach / layered rollback corruption
**Baseline:** production HEAD `a254a52`; invariants 34·23·40·40·2.5.0; security mode developer; AI flags OFF.
**Date:** 2026-06-23

---

## 1. GO / NO-GO for commit

**GO for commit — pending explicit owner authorization.**

F-1 is remediated in the SEO runtime with a field-scoped, drift-aware, existence-faithful,
legacy-compatible, history-honest delta rollback. Focused validation (52/52), targeted
regression, and an independent diff audit all pass; invariants are frozen; no schema,
capability, operation-map, MCP, or REST-contract change.

Per the program's commit/push/deploy rules, **I have not committed, pushed, or deployed.**
This report requests owner authorization to commit. Push, deploy, AI-flag enablement, key
setting, and security-mode changes remain explicitly out of scope and untouched.

---

## 2. Changed files

**Production (2):**
- `includes/Operations/SeoProvider.php` — +43 lines; two read-only helpers
  (`backing_keys`, `read_field`). `read()`/`write()` unchanged.
- `includes/Operations/SeoRuntimeManager.php` — delta store, field-scoped drift-aware
  restore, legacy branch retained, richer result + audit.

**Tests (3):**
- `tests/test-seo-rollback-delta.sh` — **new**, 52 assertions (all Phase 3 scenarios).
- `tests/test-seo-rollback-store.sh` — 2 assertions updated to the v2 record shape.
- `tests/test-seo-runtime-step91.sh` — section 9 rewritten provider-agnostic for
  field-scoped semantics.

**Docs (new):** `docs/governance/phase-3/` — 7 reports (this is #7).

## 3. Diff stat

```
 includes/Operations/SeoProvider.php       |  43 +++++
 includes/Operations/SeoRuntimeManager.php | 220 +++++++++++++++++++++++++---
 tests/test-seo-rollback-store.sh          |  15 +-
 tests/test-seo-runtime-step91.sh          |  16 +-
 4 files changed, 264 insertions(+), 30 deletions(-)
```
(plus new `tests/test-seo-rollback-delta.sh` and `docs/governance/phase-3/*`.)

---

## 4. Exact architecture implemented

- **Drift detection is semantic, at the unified-field level** (compare live value to the
  recorded `after`); **restore is byte-faithful, at the backing-meta-key level** (restore
  exact prior raw value + existence). The two jobs the old code conflated are separated.
- `seo_update`: `capture_prior()` (touched fields' backing keys, `{existed, prior}`) →
  `SeoProvider::write()` → `store_rollback()` persists a v2 delta record with per-field
  `after` + backing-key prior. Audit `seo.updated` gains `rollback_format=delta`.
- `seo_restore`: resolve by `rollback_id`; `isset(fields)` → `restore_delta()`; else
  `restore_legacy_meta()` (post-meta `before_state`) / `seo_restore_legacy()` (option store).
- `restore_delta()`: per field, drift → skip + conflict (no write); no drift → restore each
  backing key by existence. `status` ∈ {complete, partial, conflict}; **only `complete` is
  terminal**.

## 5. Rollback record format (v2)

```php
[ 'id'=>uuid4, 'version'=>2, 'post_id'=>int, 'provider'=>str, 'created_at'=>ts,
  'session_id'=>?, 'task_id'=>?, 'rollback_applied'=>false,
  'fields'=>[ '<field>'=>[ 'after'=><applied value>,
                           'keys'=>[ '<meta_key>'=>['existed'=>bool,'prior'=>mixed] ] ] ] ]
```
Stored as the existing per-rollback protected post-meta row `_wpcc_seo_rb_{id}` (Slice 4c
storage unchanged; only the blob shape changed). The full SEO object is no longer stored.

## 6. Legacy compatibility

Records without `version`/`fields` (carry `before_state`) restore via the unchanged legacy
path — both pre-Phase-3 post-meta rows (`restore_legacy_meta`) and pre-4c option rows
(`seo_restore_legacy`). Forward-only; **no destructive migration**. Validated (S7).

## 7. Drift behavior

Default safe policy: on drift, **skip the field and report a conflict** — never clobber.
False positives avoided by normalizing the comparison exactly as `seo_update` produced
`after`; false negatives avoided because `after` is the literal post-write value. `complete`
restores are terminal (idempotency guard); `partial`/`conflict` stay retryable so multi-step
recovery (roll back newer, then older) is reachable, and never clobber.

## 8. Validation results

- `test-seo-rollback-delta.sh` (Phase 3 suite): **52 / 52 PASS** — all 11 required scenario
  groups (empty/value fidelity, disjoint layered, same-field drift, out-of-order, robots,
  legacy, repeated, partial result honesty, provider parity).
- `test-seo-rollback-store.sh`: **28 / 0** · `test-seo-undo.sh`: **33 / 0** ·
  `test-seo-apply.sh`: **76 / 0** · `test-workflow-rollback-f61.sh`: **16 / 0**.
- `tests/run.sh --tier T1 --changed` (11 suites): **470 / 4**, the 4 being pre-existing
  Yoast-vs-Rank-Math env mismatches in step91 — **proven 0 net-new** by a clean-room
  baseline run (original code + original test in this env = identical 20/4).
- `php -l` clean. Invariants live-verified: **34 · 23 · 40 · 40 · 2.5.0**.
- **T2 not run** (commit-gated); **required before deploy** — see Validation Report §5.

## 9. Audit results

Independent audit of the actual `git diff` (PHASE-3-INDEPENDENT-AUDIT.md): **PASS**, no
defects requiring a fix. Confirmed field-scoped restore, no over-reach for new records,
legacy intact, drift cannot silently clobber, siblings survive, out-of-order safe, truthful
audit/result, no scope/registry/schema/cap/MCP drift, `rollback_id` preserved.

## 10. Remaining risks

1. **Legacy records retain full-restore (over-reach) behavior** — bounded, draining set;
   no destructive migration permitted. New records are all field-scoped.
2. **Identical-value provenance ambiguity** — if a later change sets a field to the *same*
   value, drift cannot distinguish it; rollback proceeds (no data loss of a different value).
   Closing requires per-field change-id provenance (out of scope).
3. **Partial-retry conservative drift reports** — a retry of a `partial` flags
   already-restored fields as drift; safe, no clobber, surfaced honestly (benign).
4. **A2-2 residual** — the delta record is persisted *after* the write (it needs `after`),
   so a write throwing mid-sequence leaves no record. Same class as the acknowledged A2-2
   residual; not closed here.
5. **Deferred sibling runtimes** — ACF, Media-update, WooCommerce, Content, User-update,
   Settings, Elementor, Menu-location, Forms, Bulk, Comments, SiteBuilder share the same
   full-snapshot pattern (Architecture Audit §3/§4). Not fixed in Phase 3; the proven SEO
   delta pattern is the template for a follow-on program.

## 11. F-1 status

**F-1 is CLOSED for the SEO runtime** (the Phase 3 scope and the live AI-write surface):
layered SEO rollbacks no longer cause sibling loss, out-of-order resurrection, or
misleading "applied" history. **F-1 remains OPEN as a systemic pattern** in the deferred
runtimes (item 10.5), to be addressed by a follow-on program. Net: F-1 **partially closed
program-wide, fully closed for SEO.**

## 12. Phase 2.x reaper

The **A2-1 residual** (uncatchable fatal/OOM/timeout stranding an executing request via
`claimed_at` + stale-executing reaper) **remains a separate Phase 2.x item.** Phase 3 did
not touch it and does not depend on it. Unaffected.

## 13. Suggested commit message

```
fix(governance): field-scoped, drift-aware SEO rollback (F-1)

Replace SEO's full-object rollback snapshot with a field-scoped delta record
(v2: touched fields only, each with post-write `after` + backing-key prior
value/existence). Restore now restores only the fields the original update
touched, byte-faithfully per backing meta key, and detects drift: if a field's
live value diverged from the value the update applied (a later change touched
it), the field is skipped and a conflict is reported rather than clobbering the
newer/sibling change. Fixes layered-rollback corruption (sibling loss,
out-of-order resurrection, misleading applied history).

- Existence-faithful: prior-absent → delete on rollback; prior-present →
  restore exact value (even empty), via captured metadata_exists flag.
- History-honest: partial/conflict restores return an error envelope, so
  OperationExecutor/Change-History never stamp a non-complete rollback as a
  clean revert; seo.restored audit records status/restored/skipped/conflicts.
- Only a complete restore is terminal (idempotency guard); partial/conflict
  stay retryable so multi-step recovery is reachable and never clobbers.
- Legacy full-snapshot records still restore via the legacy path; no
  destructive migration. rollback_id contract unchanged.
- SeoProvider gains read-only backing_keys()/read_field() helpers.

Scope: SEO runtime only; structurally identical sibling runtimes deferred.
No schema/capability/operation-map/MCP/REST change.
Invariants: OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB 2.5.0.
Tests: test-seo-rollback-delta.sh 52/0; store 28/0; undo 33/0; apply 76/0;
workflow-rollback-f61 16/0; T1 --changed 0 net-new (step91 4 = pre-existing
Yoast/Rank-Math env mismatch).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

---

## 14. Stop condition

Reached stop condition (1): **Phase 3 final report complete with GO/NO-GO for commit.**
No schema change required (stop condition 2 not triggered). No unresolved product/governance
decision (3). No blocker (4). Awaiting owner authorization to commit.
