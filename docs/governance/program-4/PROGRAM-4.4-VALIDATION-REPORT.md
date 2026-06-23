# PROGRAM-4.4 — Comments Rollback · Validation Report

> **Date:** 2026-06-23 · DEV, PHP 8.2.27, wp-cli. **Verdict: GO** — status reversibility added + proven; no regression; invariants held; zero attributable failures.

## Lint — clean
`php -l`: `includes/Rollback/CommentFieldAccessor.php`, `includes/Operations/CommentsRuntimeManager.php`.

## Comment delta acceptance — `tests/test-comment-rollback-delta.sh` → **24 / 0**
S1 approve→rollback restores hold · S2 unapprove→rollback restores approved · S3 spam→rollback restores prior approval · S4 same-field drift → **conflict** (later status change not clobbered) · S5 out-of-order (no resurrection) · S6 repeated rollback guarded · **S7 trash still untrashes (unchanged)** · **S8 delete creates no rollback record — irreversible by design, unchanged** · 8 static guards.

## Regression
| Suite | Result |
|---|---|
| `test-comments-runtime.sh` | **44 / 0** (approve/unapprove/spam/trash/delete) |
| `test-rollback-delta-core.sh` | **25 / 0** |
| `test-seo-rollback-delta.sh` | **56 / 0** |
| `test-operations-registry` / `capability-runtime` / `mcp-error-surface` | 18/0 · 61/0 · 18/0 |
| `test-change-history-rollback.sh` | standalone/alone — confirmatory |

## Invariants
**34 / 23 / 40 / 40 / 2.5.0** — held (v2 record reuses `wpcc_comments_rollbacks` option; **`CommentsRegistry` unchanged**; no schema).

## Failure classification
- During authoring, **S8** initially failed: it expected `wpcc_rollback_unsupported`, but `comment_delete` (verified) **stores no rollback record** (`supports_rollback('delete')=0`, irreversible by design) — a **test-expectation error, NON-ATTRIBUTABLE** (delete path untouched). Corrected to assert the actual unchanged behaviour; rollback code never at fault.
- No attributable product failures.

## Verdict
**GO.** Comments had **no F-1 over-reach** (status changes were not captured at all); P4.4 **closes that reversibility gap** by making approve/unapprove/spam field-scoped, drift-aware reversible via the P4.0 core, with trash/delete unchanged and no registry/schema change.
