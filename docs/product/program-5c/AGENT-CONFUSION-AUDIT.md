# PROGRAM-5C — Phase C: Agent Confusion Audit

## The problem (confirmed the biggest *conceptual* blocker)
Before 5C, the "Connect an AI Agent" screen led with: *"Connect external AI tools to your WordPress site via the MCP protocol. All clients share the same platform, security model, and execution pipeline."* — every noun is jargon to the persona. Nothing explained **what an agent is**, **why you need one**, **what the token does**, or **what talks to what**.

## Can the user now answer the five questions?
| Question | Before | After 5C |
|---|---|---|
| What is an AI agent? | ❌ never explained | ✅ "An AI assistant — like Claude — running in a separate app on your computer… WP Command Center does not include the AI; it safely connects one to your site." |
| Why do I need one? | ❌ | ✅ "WPCC is the safe doorway between an AI assistant and your site. Without one connected, there's nothing to send work to." |
| What does the token do? | ❌ ("Generate token" with no why) | ✅ "A password just for the AI assistant… you can revoke it any time to instantly cut off access." |
| What talks to WordPress? | ❌ | ✅ flow line: "Your AI assistant → (access token) → WP Command Center → your approval → WordPress → recorded & undoable." |
| What talks to Claude? | ❌ | ✅ "You bring your own AI key for the assistant; this site never sends content anywhere except the AI provider you chose." |

## Changes implemented
1. **New `AgentExplainer` helper** (read-only): the four FAQ answers + a jargon-free one-line flow picture, all plain language, no MCP/dev assumptions.
2. **Connect screen rewritten:** H1 "AI Integrations" → **"Connect an AI Agent"**; jargon intro → plain language; an **open-by-default "New to AI assistants? Read this first (2 min)"** explainer panel rendering the FAQ + flow line + a numbered setup order with links to AI Setup and Tokens.
3. **Cross-surface coherence:** AI Setup now points to "Connect an AI Agent" as the next step (Phase D); Tokens copy (5B) already explains the token as an agent password.

## Audited surfaces
- **AI Setup** — explains key is BYO, optional, off until enabled; now adds "what next" (Phase D). ✅
- **Access Tokens** — 5B copy: "Create access tokens for your AI agents… token secret shown once… revoke instantly." ✅
- **Connect an AI Agent** — rewritten (above). ✅
- **Overview** — first-run + how-it-works frames the agent → approve → record → undo loop. ✅

## STOP-condition check
`AgentExplainer` is a read-only copy helper; the Connect screen change is copy + presentation. No schema/registry/MCP/REST/capability/rollback/security change. **Clear.**

## Validation
`test-first-value-5c.sh` §2 → all green (explainer answers all four questions, flow line, Connect renders it, plain-language H1, jargon intro removed).

**Phase C: GREEN.**
