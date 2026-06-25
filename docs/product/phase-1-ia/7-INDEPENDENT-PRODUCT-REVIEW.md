# Phase 1 — Independent Product Review (adversarial)

> Hat: a skeptical product reviewer who did **not** write the code. Question: does this milestone genuinely meet the bar, and where is it weak?

## Does it meet the success criteria? — Yes, with caveats
**"A new user understands: I can use Built-in AI / connect AI Clients / connect Applications, without docs."** The top-level menu now literally says **Built-in AI** and **Connect** (→ *AI Clients*, *API & Integrations*), and the first-run Home asks the one question with three labelled doors. A first-timer can form the model from the nav alone. **Met.**

## Strengths
1. **The #1 confusion is gone.** "AI Setup" vs "Connect an AI Agent" sitting side-by-side is resolved: Door 1 (Built-in AI) and Door 2/3 (Connect) are now clearly different places.
2. **Backward compatibility is real, not claimed.** A live wp-cli test exercises the actual redirect; tab-aware resolution means even 5-C deep links land precisely.
3. **Honesty held.** The new API landing shows only real facts and adds no route/capability — the temptation to fake a "Door 3 console" was declined.
4. **Scope discipline.** No engine/REST/MCP/schema touched; invariants green; the flagged AI tools stay flag-gated and honest.

## Weaknesses / risks (called out honestly)
1. **Settings is heavy — 8 tabs.** It's the deliberate "advanced drawer," but Security & Approvals, Access, File Access, Diagnostics, Patches, Site Report, Capabilities, and Runtime is a lot for one tab bar. *Forward:* the blueprint groups these (Diagnostics should absorb Patches/Site Report; Access should absorb File Access as a scope). That grouping is view-merge work, intentionally deferred past Phase 1.
2. **File Access is still its own tab, not yet "a scope under Access."** The blueprint's end-state demotes it into Access; Phase 1 only relocated it into Settings (honest interim, no fake).
3. **API & Integrations routes out to create a token** rather than issuing in-context. That is the correct Phase-1 move (token issuance is the token manager's job; no new write path was invented), but the blueprint's "issue in context" ideal is only partially realized.
4. **Drafts (Dev) lives under Activity.** Defensible (it's a review queue), but the blueprint's end-state folds proposals into each tool's review step + Approvals. It remains a dev-flagged tab, off in production.
5. **The door fork shows whenever setup is incomplete**, reusing the existing `setup_incomplete()` signal — not a dedicated dismissible first-run state machine. Adequate and low-risk, but lighter than the blueprint's full onboarding flow.

## Did it weaken any guarantee? — No
Approval, rollback, audit, and capability scoping are untouched (no engine code changed). No provider was over-promised; no unrunnable capability was implied. The Four Guarantees and the "never fake" rule are intact.

## Verdict
**Ship-ready for the Phase-1 objective.** It delivers the narrative + IA transformation the blueprints mandate, preserves every URL, holds invariants, and stays honest. The weaknesses above are all **known forward items** (view-merges, scope demotion, deeper onboarding) that the blueprints themselves sequence beyond Phase 1 — none is a defect introduced here, and none requires runtime change to finish later.
