# Phase 3 — External Independent Audit

**Auditor stance:** adversarial and independent. Author conclusions ignored; findings
derived from the **actual `git diff`**, the surrounding governance code, and **live
re-execution** on DEV (active SEO provider = Rank Math).
**Scope audited:** Phase 3 only (F-1 SEO rollback). **No code modified.**
**Date:** 2026-06-23 · **Base:** `a254a52` · working tree: 2 prod files + 2 tests modified, 1 test new.

---

## Verdict: **PASS** — GO for commit (on a branch). No blockers.

All 13 focus questions verified true against the code and/or live behavior. Five
non-blocking findings (all test-coverage / process, none affecting correctness) and four
residual risks (all previously disclosed, bounded, or out of scope).

---

## Evidence base (independently reproduced)

- `git diff --stat`: only `includes/Operations/SeoProvider.php` (+43) and
  `includes/Operations/SeoRuntimeManager.php` (+220/-30) in production; tests
  `test-seo-rollback-delta.sh` (new), `test-seo-rollback-store.sh`, `test-seo-runtime-step91.sh`.
  **`ChangeHistoryRuntimeManager`, `OperationExecutor`, registries, schema, and UI are NOT in the diff.**
- `SeoProvider` diff hunk is a single insertion block at L113; `read()`/`write()` are
  outside any changed region — **unchanged**.
- Live: `test-seo-rollback-delta.sh` → **52/52**. Invariants → `OPERATION_MAP=34`,
  `capabilities=23`, `DB_VERSION=2.5.0`. Catalogue/MCP =40 unchanged (no registry edit).
- Live probe of the untested empty-but-existing branch (below).

---

## Focus questions

**1. Field-scoped delta records? — YES.** `store_rollback()` builds `fields` only from
`$touched = array_keys($fields)`; record is `version=2` with per-field `after` +
`keys[meta_key]={existed,prior}`. No `before_state` for new records. `capture_prior()`
iterates only `SeoProvider::backing_keys()` of touched fields.

**2. Untouched siblings never written on rollback? — YES.** `restore_delta()` iterates
`$record['fields']` only and writes only each field's `$spec['keys']`. No read or write
touches any meta key outside the recorded set. Live S3: rollback A restores `title` while
sibling `description=B_D` survives.

**3. Drift prevents same-field clobber? — YES.** `values_equal($current,$after,$field)`
mismatch ⇒ field pushed to `skipped`/`conflicts` and `continue` (no write). Live S4:
rollback of the older title change returns `conflict`; newer `B_T` is preserved.

**4. Existed-vs-empty fidelity? — YES (and verified on the branch the suite misses).**
Restore branches on the captured `existed` flag, not value emptiness: `existed=false`⇒
`delete_post_meta`; `existed=true`⇒`update_post_meta(prior)` even when `prior===''`. Live
probe: a pre-existing empty meta (`existed=yes, prior=''`) is captured as
`existed=yes/prior=''` and after restore the row **still exists with `''`** (not deleted).
Correct. (Coverage gap — see NB-1.)

**5. Legacy `before_state` records still work? — YES.** `seo_restore` branches
`isset($record['fields'])` → delta; else `restore_legacy_meta()` (full `before_state`
write, behavior identical to pre-Phase-3); option-store records still route to
`seo_restore_legacy()`. Live S7: legacy record restores via `path=legacy`. No destructive
migration (forward-only).

**6. Can partial/conflict be marked clean success? — NO (verified at all three consumers).**
`restore_delta()` returns `error:true` with `wpcc_rollback_partial`/`wpcc_rollback_conflict`
for any non-complete outcome. Consumers:
- `OperationExecutor::rollback` sets `success = empty($res['error'])` (L428/L450) ⇒ false.
- `ChangeHistoryRuntimeManager::rollback_target` L289 `if (empty($res['success'])) return err()`
  ⇒ the change is **not** stamped `rolled_back` and no reversal is recorded; only verified
  success reaches L305/L309.
- `WorkflowRuntimeManager` L166 `$dispatched=($r['success']??false)===true` ⇒ a drifted SEO
  step is reported as a **failed** rollback, not silent success.
- Admin Undo uses the same `/admin/history/{id}/rollback` → `rollback_target` path.
History honesty holds on every path.

**7. Can repeated rollback corrupt state? — NO.** `complete` marks `rollback_applied=true`
⇒ second call returns `wpcc_rollback_already_applied` (live S8). `partial`/`conflict` do not
mark applied and are retryable; a retry only writes `prior` to currently-non-drifted fields
and skips drifted ones (no clobber). Re-restoring an already-restored field is either a
no-op or now reads as drift and is skipped — never destructive (NB-4).

**8. Can out-of-order rollback resurrect old values? — NO.** The drift gate blocks an older
rollback while a newer change shadows the field. Live S5: rollback A (older, shadowed) →
conflict (no write); rollback B → restores to post-A value; retry A → restores to ORIG_T.
No pre-A value is ever forced over a newer one.

**9. Rank Math and Yoast both safe? — YES by construction; Rank Math proven live, Yoast
structural only.** Restore operates on raw backing meta keys captured verbatim, so provider
quirks (Yoast's 3-key robots split incl. `nofollow='0'`, Rank Math's array meta) round-trip
exactly; drift compares the normalized unified value. `backing_keys('robots','yoast')`
returns the 3 keys (live S10). **No live Yoast round-trip exists** (DEV runs Rank Math) — see
NB-2.

**10. Schema/capability/MCP/REST/UI drift? — NONE.** Diff touches only `SeoProvider` (2
read-only helpers) and `SeoRuntimeManager`. No registry/route/capability/MCP/schema/UI file
changed. `seo_restore` complete-result is a **superset** of the prior shape; grep confirms no
consumer (`seo-meta.php`, Change History) reads a removed field.

**11. Invariants 34/23/40/40/2.5.0? — YES.** Live: OPERATION_MAP 34, capabilities 23,
DB_VERSION 2.5.0; catalogue/MCP 40 unchanged (no registration edits).

**12. Tests sufficient? — Adequate for GO, with gaps.** 52/52 focused + regression (store
28/0, undo 33/0, apply 76/0, workflow-rollback-f61 16/0). Gaps: NB-1 (empty-but-existing not
asserted), NB-2 (no live Yoast), NB-3 (no integration test that a partial rollback via the
change_history route leaves the change un-reverted — verified only by code reading).

**13. Hidden regression risk in ChangeHistoryRuntimeManager / OperationExecutor::rollback /
SeoProvider? — LOW/NONE.** First two are unmodified; their behavior with the new error
envelopes is the intended path (verified at L289 / L166). `SeoProvider::read`/`write`
unmodified; new methods are pure read-only accessors over existing maps. Residual surface is
confined to `SeoRuntimeManager`'s rollback internals.

---

## Blocker findings

**None.**

---

## Non-blocking findings

- **NB-1 — Empty-but-existing prior not covered by an automated test.** The branch is
  correct (verified live this audit), but `SeoProvider::write` itself never creates an
  empty-existing meta, so only an external writer triggers it. Add a delta-suite assertion
  to lock the behavior.
- **NB-2 — No live Yoast validation.** Yoast paths are structural only (DEV = Rank Math).
  Provider-faithful by construction, but run `test-seo-rollback-delta.sh` /
  `test-seo-runtime-step91.sh` under an active Yoast before marketing Yoast reversibility.
- **NB-3 — No end-to-end "partial rollback is not marked reverted" test.** History honesty is
  proven by code-reading (`ChangeHistory` L289, `Workflow` L166) but not by an integration
  assertion through `/admin/history/{id}/rollback`. Add one.
- **NB-4 — Partial-retry emits conservative drift reports on already-restored fields.** Safe
  (no write, no clobber), surfaced honestly; documented in the Adversarial Review. Cosmetic.
- **NB-5 — `regression-baseline.tsv` recorded under Yoast.** The runner flags 4 step91
  failures as "net-new" purely from the Rank-Math env; clean-room baseline (original code) in
  this env reproduces the identical 4 (proven). Refresh the baseline or run step91 under Yoast
  so the runner signal is not misread as a real regression.

---

## Residual risks (carried, not introduced by Phase 3)

- **R1 — Legacy `before_state` records retain full-restore over-reach.** Bounded, draining
  set; no destructive migration permitted. New records are all field-scoped.
- **R2 — Identical-value provenance ambiguity.** If a later change sets a field to the same
  value, drift cannot distinguish it; rollback proceeds. No loss of a *different* value;
  closing needs per-field change-id (out of scope).
- **R3 — A2-2: delta record persisted after the write** (it needs `after`). A write throwing
  mid-sequence leaves no record. Same class as the acknowledged A2-2 residual.
- **R4 — F-1 remains systemic.** ACF, Media-update, Woo, Content, User, Settings, Elementor,
  Menu-location, Forms, Bulk, Comments, SiteBuilder share the full-snapshot pattern and are
  **deferred**. F-1 is closed for SEO only.

---

## Decisions

- **GO / NO-GO for commit: GO.** F-1 is correctly remediated for SEO; guarantees preserved;
  invariants frozen; no scope/registry/schema drift; validation independently reproduced.
- **Commit on `main` or a branch? → BRANCH (required).** The deploy model is pull-based:
  `git push origin main` goes live in ~1 minute (handoff §1). T2 has not been run. Commit on a
  feature branch so an accidental push cannot auto-deploy and so T2 gates the merge. The repo
  is currently on `main`.
- **Is T2 required before deploy? → YES, before deploy (not before commit).** These are
  governance-critical (Rollback guarantee) paths and the runner's full serial suite is the
  standing pre-deploy gate. Run `tests/run.sh --tier T2` (serial) and clear NB-5 (Yoast
  baseline) before merge/push. The focused + T1 evidence is sufficient for the **commit**
  decision only.

**Bottom line:** the implementation does what the reports claim. Commit it on a branch; run
T2 and add NB-1/NB-2/NB-3 coverage before it is allowed to reach production.
