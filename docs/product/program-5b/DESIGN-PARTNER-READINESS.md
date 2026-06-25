# PROGRAM-5B — Phases G + H: Human Workflow Audit + Design-Partner Readiness

## Phase G — task-based walkthrough (persona: agency owner, zero MCP/AI knowledge)
| Task | Before 5A/5B | After 5A+5B | Status |
|---|---|---|---|
| **Understand what WPCC is** | No first-run; raw chrome | First-run intro + "does/doesn't" + how-it-works strip | ✅ Pass |
| **Set a safe mode** | Technical radios, soft Developer copy | RECOMMENDED Client, red "NOT FOR CLIENT SITES" Developer, confirm guard | ✅ Pass |
| **Connect AI (add provider key)** | Impossible from UI (constant/option only) | Connect → AI Setup: masked key, model, test | ✅ Pass |
| **Pick a model** | None | Dropdown + "why this model / what changes if I switch" | ✅ Pass |
| **Create an access token** | Worked, but copy said "arrives later" (hidden) | Tokens copy corrected: "Create access tokens…"; first-run links to it | ✅ Pass |
| **Connect the agent (MCP)** | "AI Integrations" ambiguous vs Setup | Renamed "Connect an AI Agent"; AI Setup distinct | ✅ Pass |
| **Review & approve work** | Approvals under "Operate" (vague) | Operate desc: "Review and approve the work AI wants to do"; admin-bar badge for pending | ✅ Pass |
| **Roll back / undo** | Changes said restore "arrives later" (hidden) | Corrected: "Reversible changes show a Restore button…"; how-it-works step 4 | ✅ Pass |
| **Understand audit history** | "Audit" abstract | Audit desc: "See every change, and undo the ones that can be reversed" | ✅ Pass |

**Result:** every core task is now discoverable and explained in plain language. The two biggest blockers (hidden Restore, hidden token-create) were stale-copy bugs — fixed.

## Phase H — "Could I hand this to 5 design partners next week?"
**Yes, for concierge onboarding** (the model the Design-Partner program already prescribes), with eyes open about the remaining limits.

### Blockers fixed in 5A+5B (were real)
- No provider-key UI → **fixed** (AI Setup).
- No safe-mode guidance / insecure-by-default confusion → **fixed** (recommendation + red flag + confirm).
- Hidden Restore and hidden token-create (stale copy) → **fixed**.
- No first-run orientation / heavy jargon → **fixed** (first-run + plain language + section descriptions).
- Ambiguous "AI Setup" vs "AI Integrations" → **fixed** (rename).

### Blockers acknowledged, OUT of UX scope (not fixable by this program)
- **AI value unproven in production** (key unset, flags OFF) — a *validation* task, not a UX task (PRODUCT-MARKET-FIT-DISCOVERY §0).
- **No self-serve onboarding for non-technical, agent-less users** — the human must still wire an agent; concierge bridges this for design partners.
- **Plaintext-option key at rest** — accepted limitation (masked UI); encrypted storage is schema-bearing (STOP).
- **No multisite/fleet, no notifications, no licensing** — out of an adoption-readiness UX program.

### Verdict
The plugin is **ready to put in 5 design partners' hands next week via concierge onboarding**: a partner can be walked from install → Client mode → their key → model → token → connect agent → review/approve → undo, every step now discoverable and honestly described. Unguided self-serve and the demand-validation question remain — by design — the next program's job.

**Phases G + H: GREEN (all in-scope blockers fixed).**
