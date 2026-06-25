# RC-2 — Final Report (Release Candidate Execution)

> **Objective:** move WP Command Center from RC-1's **NOT READY** to **READY WITH MINOR RISKS (Concierge Beta)** by clearing the four RC-1 blockers. No new features.
> **RC branch:** `rc-2-release-candidate` (tip `4bb1f86`); `main` unchanged (`94a716c`, Program-4). **Not pushed, not deployed.**

## The four RC-1 blockers — status

| # | RC-1 Blocker | Status | Evidence |
|---|---|---|---|
| 1 | **Not merged / no release build** (W3) | **CLEARED** | `rc-2-release-candidate` integrates the full linear 5A→10 stack via a `--no-ff` merge (`a977ed0`), history preserved, **0 conflicts**. (RELEASE-INTEGRATION-REPORT) |
| 2 | **No full-stack acceptance gate** (W4) | **CLEARED** | Full T2: **5874 passed / 38 failed**; every failure baselined, environmental, or **proven identical on `main`**; SEO/AltText/Content runtimes **byte-identical to main** → **net-new attributable = 0**. (ACCEPTANCE-TEST-REPORT) |
| 3 | **Insecure-by-default** (W2) | **CLEARED** | Fresh installs now seed **Client mode** (governed by default); `current()`/`DEFAULT_MODE` unchanged; existing installs untouched. (PRODUCTION-DEFAULTS-REVIEW) |
| 4 | **End-to-end AI workflow** | **CLEARED for the governed pipeline; live AI inference is a BYO-key concierge step** | Connect/Review/Approve/Apply/Verify/Rollback demonstrated green within T2 (certified rollback suites pass live); live AI generation requires a real key (unset by design, must not be committed) → confirmed at concierge onboarding. (END-TO-END-WORKFLOW-EVIDENCE) |

## What changed in RC-2 (no new features)
- Integration merge of 5A→10 (`a977ed0`).
- `Activator`: fresh-install security-mode seed `developer` → **`client`** (one-time, unset-only).
- Version `0.1.0` → **`0.2.0-rc.2`**.
- Eight RC-2 review documents. **No runtime, architecture, telemetry, fleet, notifications, webhooks, or UI-redesign work** (all out of scope, honored).

## Acceptance evidence (the headline)
The full 159-suite T2 run on the merged RC, plus direct comparison against `main` and a `git diff` proving the SEO/AltText/Content runtimes are untouched, establishes that **the 5A→10 stack introduces zero attributable regressions** across runtime, approvals, rollback, MCP, AI configuration, telemetry, event bus, operations center, security, and admin UX. The runner's "net-new: 14" is a stale-`regression-baseline.tsv` artifact, not real regressions (documented, with the fix deferred as QA hygiene).

## Remaining (minor) risks
Live-generation-on-keyed-site not yet confirmed (concierge step); telemetry/ops sparse until activity; single-site; plaintext keys at rest; stale baseline.tsv; external security/a11y/perf passes pending for GA; N=1 demand. All documented + mitigated; none blocks a hand-onboarded concierge beta. (REMAINING-RISKS)

## Deliverables (8)
RELEASE-INTEGRATION-REPORT · ACCEPTANCE-TEST-REPORT · PRODUCTION-DEFAULTS-REVIEW · END-TO-END-WORKFLOW-EVIDENCE · SECURITY-REVIEW · REMAINING-RISKS · CONCIERGE-BETA-CHECKLIST · RC-2-FINAL-REPORT (this).

---

# FINAL DECISION

## READY WITH MINOR RISKS

**Evidence:**
- **Integrated release build exists** (`rc-2-release-candidate`, full history, clean merge).
- **Full acceptance gate passed:** T2 5874 passed; **net-new attributable to the stack = 0** (proven by identical-on-`main` failures + byte-identical SEO/AltText/Content runtimes).
- **Client-safe by default:** fresh installs are governed (Client mode); the developer self-approve convenience is no longer a production default; resolution logic + tests unchanged.
- **Governed safety pipeline proven end-to-end:** Connect→Review→Approve→Apply→Verify→Rollback green live within T2 (certified rollback surfaces restore correctly).
- **Honesty intact:** no faked metrics; cost/tokens "not tracked yet"; AI off until enabled.

**The "minor risks":** live AI *generation* has not been exercised in CI (BYO-key boundary — must not commit a key); it is the first, low-risk concierge-onboarding action on a keyed partner site. Telemetry/ops data is sparse until activity, the product is single-site, and keys are plaintext-at-rest (masked) — all documented and appropriate to set as expectations for a hand-onboarded beta.

This is exactly the RC-2 target state. It is **not** an unconditional "READY FOR CONCIERGE BETA": that requires the owner to (a) deploy the RC build and (b) complete the per-partner concierge checklist — including confirming the live generate→apply→undo on the first partner's keyed site. Once those operational steps are done, the residuals are genuinely minor.

*Evidence-driven. RC-2 execution complete.*
