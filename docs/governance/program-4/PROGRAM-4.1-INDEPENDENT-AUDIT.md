# PROGRAM-4.1 — Settings Runtime Rollback · Independent Diff Audit

> **Date:** 2026-06-23 · **Posture:** adversarial self-audit against the P4.1 scope contract and design goals.

## 1. Changed-files audit — only Settings + generic OptionAccessor
**Modified:** `includes/Operations/SettingsRuntimeManager.php` (+71/−3 vs P4.0 base).
**New:** `includes/Rollback/OptionAccessor.php`, `tests/test-settings-rollback-delta.sh`, `docs/governance/program-4/PROGRAM-4.1-*.md`.

**Forbidden surfaces — confirmed UNTOUCHED:** no `Woo`/`ACF`/`Content`/`User`/`Media`/`Bulk` runtime, no `Plugin`/`Theme` rollback, no `OperationRegistry`/`CapabilityRegistry`/`McpServerRuntime`/`Schema`/REST/UI/`*.css`/`*.js`. `OperationExecutor` unchanged (Settings rollback dispatches via its existing public `rollback()` signature). The P4.0 core (`RollbackDelta`, `FieldAccessor`, `PostMetaAccessor`, `SeoFieldAccessor`) and the SEO runtime are unchanged.
> The two `docs/product/*.md` shown in `git diff` were modified at session start (pre-existing) — not part of P4.1 and not staged.

## 2. Behaviour-drift audit
- **SEO/core:** `test-seo-rollback-delta.sh` 56/0 and `test-rollback-delta-core.sh` 25/0 — identical to P4.0 baselines ⇒ no regression from adding `OptionAccessor` or reusing the core.
- **Settings GET actions / non-mutating paths:** untouched (the capture block guards on `$is_mutation`).
- **Settings dispatch:** `rollback()` keeps its `(array $p, array $cx=[])` signature ⇒ `OperationExecutor::rollback` method-path dispatch unaffected; audit event `operation.rollback.dispatched` still emitted by the executor; new inner `settings.restored` audit added.
- **Record storage:** still the single `wpcc_settings_rollbacks` option (cap 200); legacy `before_state` records still resolve and restore (S8). No new option, no schema, DB_VERSION unchanged.

## 3. Design-goal coverage (audited against tests)
| Goal | Evidence | Verdict |
|---|---|---|
| 1 field-scoped only | `touched_options` + v2 `fields`; S3/S7 | ✅ |
| 2 drift-aware | S3/S4 drift skip+conflict | ✅ |
| 3 sibling preservation | S3 (intra-action), S7 (cross-action) | ✅ |
| 4 out-of-order safe | S5 no resurrection | ✅ |
| 5 existed-vs-empty fidelity | S1 (delete), S2 (restore empty) | ✅ |
| 6 partial/conflict ≠ clean success | S3 partial, S4 conflict; error envelopes | ✅ |
| 7 legacy records functional | S8 legacy path | ✅ |
| 8 core reused without SEO regression | core 25/0, SEO 56/0 | ✅ |

## 4. Defect-handling audit
- **DEF-1 (capture-after-write no-op):** the redesign moves capture **before** the write; S0 proves a real revert now occurs (old code would leave the post-write value). Correctly fixed, in scope.
- **DEF-2 (group over-reach):** capture limited to `touched_options`; S7 proves cross-action siblings are not touched. Fixed, in scope.
- **DEF-3 (`reading_update` null-key):** an **update-method** bug surfaced by S7's first draft; correctly **left unfixed** (outside rollback scope) and documented. The rollback path captures/restores whatever the method actually wrote, so it is correct independent of DEF-3.

## 5. Risk-mitigation audit (vs design §4)
R2 (touched-map divergence) — verified by per-action scenarios; R3 (option existence) — S1/S2 pass via sentinel; R4 (legacy) — S8; R5 (SEO/core) — baselines held; R7 (empty payload) — empty `fields` rolls back as a `complete` no-op. R6 (permalink no re-flush) accepted as a documented residual matching prior behaviour.

## 6. Audit verdict
**PASS.** Scope is exactly P4.1 (Settings + a generic, reusable OptionAccessor); no forbidden surface; no SEO/core/dispatch drift; all eight design goals proven; two in-scope defects fixed; one out-of-scope defect correctly documented not fixed. Clears for FINAL GO.
