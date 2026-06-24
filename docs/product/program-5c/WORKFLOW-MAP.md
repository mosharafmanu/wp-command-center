# PROGRAM-5C — Phase B: Workflow Mapping

> Persona: agency owner, no MCP/Claude/agent knowledge. "✅ now clear" reflects 5A+5B+5C fixes.

## Journey 1 — Generate SEO metadata
**Path:** Overview → (AI Setup: add key) → enable AI screens → (Connect an AI Agent) → agent generates → Operate→Approvals → approve → Audit→Changes.
- **Clicks/screens:** many; spans 3–4 surfaces + an external agent app.
- **Jargon:** "SEO meta", "agent", "token".
- **Failure points:** AI SEO screen is **flag-OFF** (invisible) → user stuck; or value only via external agent. **STRUCTURAL BLOCKER (F1).**
- **Unnecessary steps:** none removable in scope — the blocker is the flag-off + agent requirement, not extra clicks.

## Journey 2 — Generate image alt text
Same shape as Journey 1 (alt-text screen also flag-OFF). Same structural blocker. Lowest-risk workflow *if* enabled.

## Journey 3 — Review changes
**Path:** Overview "Needs attention" → Operate→Approvals (or admin-bar "AI Requests" badge). ✅ now clear (5B section desc + 5C how-it-works link).

## Journey 4 — Approve changes
**Path:** Operate→Approvals → approve/reject. Requires a human approver in Client/Enterprise mode (correct). ✅ discoverable; the admin-bar badge surfaces pending count.

## Journey 5 — Undo changes
**Path:** Audit→Changes → Restore button on a reversible row. ✅ **now discoverable** (5B fixed the stale "arrives later" copy; 5C added the "Changes →" link in the how-it-works strip). Previously hidden.

## Journey 6 — Connect an AI assistant
**Path:** Connect→AI Setup (key+model+test) → Connect→Connect an AI Agent (token + paste config into the assistant). 
- **Jargon:** was heavy ("MCP protocol", "execution pipeline"). ✅ **5C added a plain-language "New to AI assistants?" explainer** (what/why/token/what-talks-to-what + a jargon-free flow line + setup order).
- **Failure points:** still requires the user to operate an external assistant app (Claude desktop) — **inherent**, mitigated by the explainer + concierge onboarding.

## Unnecessary steps identified
- None safely removable: the multi-surface path reflects the real governance model (key → token → connect → approve → undo). The *real* friction is conceptual (what is an agent) + structural (flag-off AI), both addressed by copy (in scope) and flagged as product work (out of scope), not by deleting steps.

## Summary
Journeys 3/4/5/6 are now **clear and discoverable**. Journeys 1/2 (the actual AI value) are gated by **F1 (flag-off AI + external-agent requirement)** — the dominant blocker, out of UX scope.
