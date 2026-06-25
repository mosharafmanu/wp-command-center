# Concierge Beta — Phase 6: Remaining Issues

Issues found during deployment validation. Severity is for a **founder-led concierge beta**.

| # | Issue | Severity | Fix owner | Notes |
|---|---|---|---|---|
| I1 | **Existing-site deploy is not client-safe** — RC client-safe seed is unset-only; a site already on `developer` stays self-approving after deploy. | **High (must-do at deploy)** | Owner (1 line) | Set Client mode on production/each existing site at deploy. Not a code bug; a deploy procedure step. Optionally a future migration could offer to flip, but that's out of scope here. |
| I2 | **AI activation requires a code edit** (no admin toggle). | High (onboarding) | Founder | Accept for concierge; do not market self-serve. A UI toggle is a future feature (out of scope). |
| I3 | **Live AI generation unproven** (no key available in this environment). | Medium | Owner | Confirm on first keyed site (Phase 3 / Checklist). Governed pipeline already proven. |
| I4 | **No partner-facing quickstart doc.** | Medium | Owner | Write a 1-pager before partner #2. |
| I5 | **Operations Center empty on day one.** | Low–Medium | — | Honest by design; run a live workflow during onboarding to populate. |
| I6 | **`regression-baseline.tsv` stale** (over-reports net-new). | Low | Owner/QA | Refresh post-deploy; attribution already done vs `main`. |
| I7 | **Plaintext keys at rest / file token manifest.** | Low (concierge) | Future | Documented in RC-2 Security Review; revisit before GA. |

## Genuine deployment blockers found?
- **No code blocker.** 0 lint errors, clean boot, net-new attributable 0, all surfaces smoke-clean.
- **One procedural must-do (I1):** set Client mode on any existing target site at deploy, or it deploys self-approving.

## What I did NOT change
Per the rules (no feature dev, no architecture, no refactor unless a deploy blocker): I changed **no code** in this program. No genuine deployment blocker required a fix; I1 is a deploy-procedure step, not a code change.
