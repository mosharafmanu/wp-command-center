# Phase 3 — Adversarial Design Review

**Role:** Independent governance auditor attempting to break the Phase 3 design.
**Subject:** Field-scoped, drift-aware SEO delta rollback (see PHASE-3-ENGINEERING-DESIGN.md).
**Verdict:** Design is sound **after one revision** (terminal-marking policy, §B below).

---

## A. Scenario attacks

Each scenario states the attack, the design's behavior, and whether a guarantee breaks.

### A1. Change A=title, Change B=description, rollback A (disjoint)
A.fields = `{title}`. Restore reads live `title` (B never touched it) → equals
`after_A` → restore title's prior. `description` is **not in A's record** → never
written. **B's description survives.** ✅ Sibling preservation holds.

### A2. Change A=title, Change B=title (same field), rollback A
A.fields = `{title:{after:'A'}}`. Live title = `'B'` ≠ `after_A 'A'` → **drift** →
skip + conflict. status = `conflict`, nothing restored. **B is not destroyed.** ✅
The old full-snapshot path would have written `'A'`'s whole snapshot and clobbered B —
this is the F-1 fix proven.

### A3. Out-of-order rollback (A then B, both same field)
Setup: A `''→'A'` (prior absent), B `'A'→'B'` (prior 'A').
- Rollback A first → live 'B' ≠ after_A 'A' → drift → conflict, **no write**, A stays
  retryable. ✅ No resurrection of pre-A garbage.
- Rollback B → live 'B' == after_B 'B' → restore prior_B 'A'. title='A'. complete.
- (Optional) retry rollback A → live 'A' == after_A 'A' → restore prior_A (absent →
  delete). title removed. complete.
**Correct full revert reachable; no resurrection at any step.** ✅

### A4. Sibling-field preservation
Covered by A1. Only keys in `record['fields']` are ever touched; sibling meta is never
read or written during restore. ✅

### A5. Drift false positives
`after` is the literal post-write unified value (`$updated[$field]`), and the drift
compare normalizes identically to how `seo_update` produced it (`robots` sorted;
scalars `(string)`-cast). A field that is semantically unchanged compares equal → not
flagged. ✅
**Residual risk:** if the active SEO plugin re-sanitizes a meta value via a late
`save_post`/`updated_post_meta` hook *after* our write, `after` already captures the
sanitized value (we read post-write), so live and `after` still match on rollback —
no FP. Async/scheduled rewrites are not a meta pattern here. **Low risk, noted.**

### A6. Drift false negatives
Any subsequent write to a touched field changes its live value away from `after` →
trips the compare. ✅
**Known limitation (benign):** if a later change sets the field to the *identical*
value `after`, drift cannot distinguish it (no per-field change-id provenance is
stored). Rollback proceeds and restores prior. Since the live value equals `after`,
the only effect is provenance ambiguity, not data loss of a *different* value.
Documented as a remaining risk; out of Phase 3 scope (would require per-field change
stamping).

### A7. Robots array handling
- **Rank Math:** single array meta `rank_math_robots`. Capture `{existed, prior(raw
  array|'')}`; drift compares normalized arrays; restore writes the raw array or
  deletes if `existed=false`. ✅
- **Yoast:** robots expands to 3 backing keys (noindex / nofollow / adv). Each key's
  raw value + existence is captured and restored verbatim, so Yoast's `'0'`-for-off
  nofollow convention and the absent-vs-present distinction are preserved exactly.
  Drift compares the unified normalized robots read; if it diverged, the **whole
  robots field is skipped** (never half-restored). ✅

### A8. Rank Math provider
Live round-trip testable on DEV (active provider = Rank Math). Scalar + array meta
paths exercised by the validation suite. ✅

### A9. Yoast provider
Backing-key set is correct (3 keys). Restore is raw-key faithful. Covered structurally
(Yoast not active on DEV); the byte-faithful restore is provider-agnostic by
construction. ✅

### A10. Legacy rollback record
Records without `version`/`fields` (carry `before_state`) restore via the **unchanged
legacy full path**. ✅
**Residual risk (accepted):** legacy records retain the old over-reach behavior — a
legacy restore can still clobber siblings. This is bounded: the legacy set is a
draining population (pre-Phase-3), and the mission forbids destructive migration. New
records are all field-scoped. Documented.

### A11. Repeated rollback (idempotency)
- A `complete` restore marks `rollback_applied=true` → second call returns
  `already_applied` (guarded, no double-restore). ✅
- A drift-blocked attempt (`partial`/`conflict`) is **retryable** and re-evaluates
  drift each time; it only restores fields that are currently non-drifted and never
  clobbers. Repeated calls produce the same conservative result — no cumulative
  damage. ✅ (See §B for why partial/conflict are deliberately *not* terminal.)

### A12. Partial rollback
A touches title+desc; B touches desc; rollback A → title restored, desc drift-skipped
→ status `partial`. Returns `error: wpcc_rollback_partial` with `restored_fields`,
`skipped_fields`, `conflicts`. `OperationExecutor::rollback` → `success=false`. ✅

### A13. Failed rollback
`update_post_meta` returning `false` (value unchanged) is not a failure — state is
already correct. `delete_post_meta` is guarded by `metadata_exists`. No partial-write
hazard inside restore (it is a sequence of independent idempotent meta ops). ✅

### A14. Audit consistency
Every attempt records `seo.restored` with `status`, `restored`, `skipped`,
`conflicts`, `path`. Creation records `seo.updated` with `rollback_format=delta`. ✅

### A15. Change-history consistency — **the decisive check**
`ChangeHistoryRuntimeManager::rollback_target` (L284-295): for `runtime_option` kind it
calls `OperationExecutor::rollback(...)` and **only stamps the change `rolled_back` /
records the reversal when `$res['success']` is truthy** (L289). Therefore:
- `complete` → success=true → change stamped reverted, reversal recorded. ✅
- `partial`/`conflict` → success=false → ChangeHistory returns the error and **does NOT
  mark the change rolled_back**. History never claims a clean revert when drift caused
  a non-complete rollback. ✅ This is exactly the history-honesty requirement, enforced
  by existing governance code with no change needed.

---

## B. Design defect found → revision

**Defect (terminal-marking over-reach).** The initial design table marked `partial`
as terminal (`rollback_applied=true`). Adversarial tracing shows this **strands a
field at its applied value** and creates a confusing asymmetry: the SEO record reads
`applied` while the change-log row remains `reversible` (because partial returned
success=false and was not stamped). It also blocks the legitimate multi-step recovery
where, after rolling back the newer shadowing change, a retry of the partial would
restore the remaining field.

**Revision (adopted).** Only a **`complete`** restore is terminal
(`rollback_applied=true`). **`partial` and `conflict` are retryable**
(`rollback_applied=false`). This:
- never clobbers (drift always skips),
- enables eventual full revert via the correct order (roll back the newer change, then
  retry the older — see A3),
- keeps the SEO record and the change-log row consistent (both remain "not fully
  reverted" until a clean `complete` restore),
- satisfies idempotency: a successful rollback is guarded; a drift-blocked attempt is
  safely repeatable and self-converging.

The cost — a retry of a previously-partial restore may report conservative "drift" on
the fields it already restored (their live value now equals `prior`, not `after`) — is
**benign**: it triggers no write and surfaces honestly. Accepted.

The Engineering Design §5 table is updated to reflect this revision.

---

## C. Guarantee audit (Four Guarantees)

| Guarantee | Status under design | Note |
|---|---|---|
| **Approval** | Preserved | Restore still routes through `OperationExecutor::rollback`/`change_history`; no new bypass; capability `content_manage` unchanged. |
| **Rollback** | Strengthened | Field-scoped, drift-aware, existence-faithful; no sibling clobber; legacy still restorable. |
| **Audit** | Strengthened | `seo.updated` (format), `seo.restored` (status/restored/skipped/conflicts/path), `operation.rollback.dispatched` (success) all emitted. |
| **Capability scoping** | Unchanged | No registry/op-map/cap/MCP/schema change. 34·23·40·40·2.5.0 frozen. |

---

## D. Remaining risks carried forward

1. **Legacy over-reach** (A10) — bounded, draining, no destructive migration permitted.
2. **Identical-value provenance ambiguity** (A6) — benign; needs per-field change-id to
   close; out of scope.
3. **Plugin late re-sanitization** (A5) — low risk; not observed for these metas.
4. **A2-2 residual** — record is persisted after the write (needs `after`), so a
   write that throws mid-sequence leaves no delta record. Same class as the already-
   acknowledged A2-2 residual; prior values are still captured pre-write and could be
   logged, but full closure is out of Phase 3 scope.

**Conclusion:** proceed to implementation with the §B revision applied.
