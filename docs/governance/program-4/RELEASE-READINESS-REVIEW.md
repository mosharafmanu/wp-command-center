# PROGRAM-4 — Release Readiness Review (Phase D)

> **Type:** adversarial — attempt to prove "the RC should NOT deploy." Audit-only.

---

## Attempts to block the deploy

### Attack 1 — "The acceptance gate was never run." → **STRONGEST, becomes a CONDITION (not a disqualifier)**
The serial T2 + prod token-gated functional verify (BLK-2) has not been executed on the deploy host for tip `efeee24`. The project's own history flags this exact omission: the SEO Phase-3 fix was deployed *without* closing this gate and that was recorded as a residual mistake (`SESSION-HANDOFF-PHASE-3`). Repeating it would be wrong.
**But:** the prod functional verify is *deploy-coupled* (it tests live rollbacks), so it cannot be a reason to never deploy — it is a reason to **gate** the deploy: run serial T2 first, deploy, then immediately run the prod verify (Phase C). → **CONDITION, not NO-GO.**

### Attack 2 — "A certified surface can silently lose a rollback (FIFO eviction)." → **NOT a deploy regression**
Settings/Media/Comments/Users (+ shared Woo/ACF-def) persist to FIFO-capped options; a `rollback_id` can be evicted on a busy store. **However:** those surfaces used capped option storage **before** Program-4 too — the RC adds drift-awareness on top; it does **not** introduce or worsen eviction. So deploying does not regress this. It is a HIGH *residual reliability* item for a follow-up, **not** a deploy blocker.

### Attack 3 — "Prod plugin versions differ from dev." → **exactly what the prod verify covers**
ACF/Elementor/Woo accessors use public APIs; prod versions are unverified here. This is a real production-only unknown — but it is precisely the Phase-C per-surface functional verify's job, and the runtimes no-op gracefully if a plugin is inactive. → **CONDITION (run prod verify), not NO-GO.**

### Attack 4 — "You can't prove prod is at a41a9d7." → **process check, not a code defect**
HTTP can't reveal a commit hash. Resolved by checking `~/wpcc-deploy.log`/SSH at merge time. → pre-flight item.

### Attack 5 — "No formal single serial-T2 artifact for efeee24." → **CONDITION**
All rollback suites + guards were run green and net-new-attributable = 0 was established per phase; affected suites re-validated after D2. But a single `tests/run.sh --tier T2 -j 1` pass pinned to `efeee24` has not been produced. → run it before merge (deploy-host or local). **CONDITION.**

### Attack 6 — "Whole-platform reversibility is still partial." → **scoped claim, no regression**
~10 surfaces remain legacy/non-drift-aware (Woo orders/variation/coupon, Forms, Menu, CPT, Widgets, SiteBuilder, OptionManager) and plugin/theme update are honestly `reversible:false`. Deploying changes **nothing** for those (they behave as on prod today); it improves the 10 certified surfaces. The certification claim is explicitly **scoped**, not platform-wide. → no blocker; honesty already in place.

### Attack 7 — "Docs ship to prod." → **harmless**
68 `docs/` files deploy too; they are not executed by WP. Cosmetic; no risk.

### Attack 8 — "D2 was just fixed; is it stable?" → **no**
One-line, audited GO, re-validated (content-rollback 30/0, content-runtime 98/0, warning eliminated). Not a blocker.

---

## Challenge outcomes
- **Certification:** holds. 10 surfaces independently re-verified drift-aware + honest; no incorrectly-certified surface; no corruption path; consolidation intact. **PASS.**
- **Merge readiness:** **PASS** — clean fast-forward, 18 additive commits, zero forbidden-surface changes, invariant parity.
- **Deploy readiness:** **PASS *conditioned*** on the acceptance gate (Attacks 1/3/5).
- **Rollback readiness (of the deploy):** **PASS** — revert-and-push restores prod in ~1 min; migration-free so revert is clean; per-feature data is additive + dual-read.

## Verdict of the adversarial review
The attempt to prove **"RC should NOT deploy" FAILS** — no disqualifying defect exists (no corruption, no fatal, no forbidden change, clean merge, migration-free, audited GO). What remains are **execution gates**, not blockers of principle:
1. Serial T2 pinned to `efeee24` (net-new attributable = 0).
2. Production token-gated functional verify per Phase-C plan (post-deploy).
3. Merge-time pre-flight (confirm prod baseline + posture; re-confirm fast-forward).

→ The correct disposition is **DEPLOY-CONDITIONAL-GO** (the conditions are the standard, deploy-coupled acceptance gate — declining to call it unconditional GO specifically to avoid repeating the documented "deployed-but-not-gated" precedent).
