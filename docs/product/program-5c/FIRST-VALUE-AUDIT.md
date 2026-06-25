# PROGRAM-5C — Phase A: First-Value Audit

> **Branch:** `program-5c-first-value-workflow`, stacked on Program-5B `e8e54cd` (main untouched at `94a716c`).
> **Persona:** owns a WordPress agency, knows WordPress, does **not** know MCP / Claude / Codex / AI agents / developer concepts.
> **Task:** install the plugin and try to get value. **Brutally honest.**

## 1. Where do they start?
Install → "Command Center" menu → **Overview**. The 5A/5B first-run checklist greets them (good). They read "Set up WP Command Center" with 4 steps. So far so good — they have orientation.

## 2. The wall they hit (the #1 finding)
**There is no in-admin button that does AI work.** To get the headline value (AI generates SEO meta / alt text), the user must:
1. **Enable a build flag** (`WPCC_ALT_TEXT_UI` / `WPCC_SEO_META_UI`) — OFF by default, set via PHP constant/filter. **A non-technical user cannot do this.** The AI generator tabs are invisible until then.
2. **Wire an external AI agent** (Claude Desktop + MCP) OR use the dev-flagged Governed Drafts UI — both require concepts the persona lacks.

**Conclusion:** today, a non-technical agency owner **cannot reach AI first-value unaided.** This is a *product/config* gate (flags + the agent requirement), **not** a copy bug — and it is the single biggest adoption blocker. 5C can make the path *understandable and honest*; it cannot, within scope (no flag-flipping, no new runtime), make AI value one-click for a flag-off, agent-less user.

## 3. What is confusing (friction log)
| # | Friction | Where | Severity | In 5C scope? |
|---|---|---|---|---|
| F1 | **No in-admin "do the work" action**; AI surfaces flag-OFF | whole product | **BLOCKER (structural)** | **No** — product/config decision; document honestly |
| F2 | **"AI agent" never explained** — what it is, why needed, what the token does, what talks to what | Connect / AI Setup / Tokens | **BLOCKER (copy)** | **Yes — fix** |
| F3 | **"AI Integrations" screen leads with MCP jargon** ("via the MCP protocol… execution pipeline") | ai-integrations | **HIGH** | **Yes — fix** |
| F4 | **No zero-setup first win** — everything seems to need AI + an agent before anything happens | Overview | **HIGH** | **Yes — add a no-AI quick win** |
| F5 | **Two "AI" screens** (AI Setup vs Connect an AI Agent) — which first? | Connect | MEDIUM | **Yes — sequence + cross-link** |
| F6 | **Approval/undo discoverability** — user may not realize the safety net exists until they look | Overview/Audit | MEDIUM | **Yes — surface (5B started; strengthen)** |
| F7 | **"Token" feels like dev-speak** — why am I making a token? | Tokens | MEDIUM | **Yes — plain-language why** |
| F8 | **AI key added, then… nothing visibly happens** | AI Setup | MEDIUM | **Yes — honest "what next"** |

## 4. Terminology that fails the persona
"MCP protocol", "execution pipeline", "AI client", "agent", "token/bearer", "operation", "capability", "runtime", "manifest", "snapshot". None are explained in plain language at the point of use.

## 5. Hidden assumptions
- That the user runs (or will run) an AI agent like Claude Desktop. **Most agencies don't.**
- That the user can edit `wp-config.php` to enable AI flags. **A non-technical owner can't.**
- That "connect AI" = "configure an MCP server in a desktop app." **Unknown to the persona.**

## 6. What would cause abandonment
1. **F1/F2:** "I added a key and made a token but nothing does anything, and I don't know what an 'agent' is." → **abandon.**
2. **F3:** the Connect screen reads like developer docs. → "this isn't for me." → **abandon.**
3. **F4:** no quick win to build confidence before the hard agent step. → **abandon.**

## 7. 5C plan (in-scope = UX/copy only; no schema/registry/MCP/REST/capability/rollback/security change)
- **Phase C:** plain-language **"What is an AI agent? Why do I need one? What does the token do? What talks to what?"** explainer (new `AgentExplainer` helper, rendered on the Connect screen + cross-linked).
- **Phase D:** a **no-setup first win** ("Run a site report — no AI needed") on Overview so value is reachable in ~2 minutes; clearer ordered path; honest "what next after a key."
- **Phase E:** strengthen approval/undo **discoverability** (links in the how-it-works strip; persistent pointer).
- **Phase B/F/G:** journey maps + persona walkthroughs + zero-doc test → drive the above fixes.
- **Documented out-of-scope (honest):** F1 (flag-off AI + agent requirement + no human-do-the-work UI) is a *product/validation* decision for a later program (enable a vertical slice / build a human action path), not a 5C copy fix.

## 8. STOP-condition pre-clearance
All planned fixes are admin **copy + read-only helper + presentation**. None touches schema, DB_VERSION, MCP/REST contracts, capabilities, the operation registry, rollback, or security architecture. **No STOP triggered.** (F1's real fix *would* require enabling AI/new runtime — explicitly NOT done here; documented instead.)
