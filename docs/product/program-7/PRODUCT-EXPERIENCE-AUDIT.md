# PROGRAM-7 — Product Experience Audit

> **Branch:** `program-7-ai-workflows` (off the checkpoint `7f157e2`; main untouched `94a716c`). **Experience only** — STOP-list (Program-4/rollback/security/DB_VERSION/MCP/REST/registries/runtime) untouched.
> Written wearing every hat (VP Product, UX, AI architect, agency owner, freelancer, first-timer, power user). Brutally honest.

## The one truth that governs this audit
The platform's *experience* is now strong (6R connection foundation + 6S premium UX). But the **product still cannot perform a live AI workflow end-to-end for a normal user**, because AI is dormant by design: key unset, feature flags OFF, runtime Anthropic-only and untouchable here. Therefore **the honest job of an "experience" program is to make the governed pieces that DO exist feel like one platform — not to fake jobs, cost, or generations.** Program-7 builds that unifying surface (Mission Control) honestly and designs the rest.

## Phase A — brand-new customer (walkthrough)
| Screen | Verdict |
|---|---|
| Overview (5A–5C first-run) | Good: checklist, how-it-works, no-AI quick win, honest copy. |
| AI Connections (6S) | Strong: dashboard hero, readiness, wizard, health cards. |
| **Gap (HIGH):** "what has the AI *done*?" | No single activity surface tying generate→approve→apply→undo together. → **Fixed:** Mission Control activity feed + counters + links. |
| **Gap (honest):** "let me run SEO on my site now" | Not possible unaided (flags off + external agent). **Documented, not faked** (PMF/5C finding stands). |

## Phase B — agency owner, 50 sites
- **Expectation:** one pane to see AI health/activity/approvals across sites. **Reality:** single-site. Fleet is a future layer (6X roadmap). Within one site, Mission Control now answers "what's healthy / what's pending / what just happened."
- **Scary → calmed:** approvals + undo are now visible from the AI page (links + pending count), not buried.
- **Missing (honest):** per-site rollup, usage/cost — designed (USAGE-AND-COST, fleet roadmap), not faked.

## Phase C — freelancer ("generate SEO for my whole site")
- **Effortless?** Not yet, unaided — the killer workflow (SEO/alt-text at scale) is the right wedge (PMF) but needs AI enabled + an agent or the governed-drafts UI (flag-OFF). The **workflow shape** (generate→review→approve→apply→rollback) is designed (AI-WORKFLOW-DESIGN) and its governed substrate (proposals→approval→change/rollback) already exists; what's missing is *enablement*, an owner/config decision, not UX.

## Prioritized findings
| # | Finding | Severity | Disposition |
|---|---|---|---|
| 1 | No unified "what AI did" surface | **HIGH** | **FIXED** — Mission Control activity (this program). |
| 2 | Approvals/undo discoverability from the AI page | **HIGH** | **FIXED** — counters + links. |
| 3 | No job/usage/cost data | **HIGH (but gated)** | **DESIGNED + honestly labelled "not tracked yet"** — needs runtime instrumentation (STOP boundary). |
| 4 | Live AI workflows not runnable unaided | **HIGH (gated)** | **DESIGNED** — needs AI enablement (owner decision). Not faked. |
| 5 | Fleet / multi-site | Medium | Roadmap (6X); out of scope. |

## Stance
Program-7 ships the honest, high-value unification (Mission Control) and refuses to fabricate jobs/cost/generations. The disappointing truths (AI dormant, no cost metering) are surfaced plainly, not hidden behind a pretty empty dashboard.
