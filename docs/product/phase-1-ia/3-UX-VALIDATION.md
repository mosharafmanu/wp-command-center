# Phase 1 — UX Validation

> Validates the implementation against the FINAL-UX-MASTER-BLUEPRINT north stars and the milestone's success criteria.

## Success criterion: "30-second understanding"
> *A new agency owner should immediately understand: "I can use Built-in AI," "I can connect AI Clients," "I can connect Applications" — without reading docs.*

| Signal | Before (5-C) | After (Phase 1) |
|---|---|---|
| "I can use Built-in AI" | Hidden as flag-gated tabs under *Operate* | **Top-level "Built-in AI" section** + first-run door #1 "Use WPCC's built-in AI" |
| "I can connect AI Clients" | "Connect an AI Agent" (jargon, sat next to "AI Setup") | **Connect › AI Clients**, H1 *"AI Clients"*, plain definition naming Claude/Cursor/Codex; first-run door #2 |
| "I can connect Applications" | Implicit, buried in Tokens | **Connect › API & Integrations** landing + first-run door #3 "Connect an app or service" |

The first screen a new user meets (Home, when setup is incomplete) now leads with the blueprint's one question — **"How do you want to use AI here?"** — and three self-contained doors. That is the 30-second model, delivered on the landing.

## North-star checks (UX blueprint §1.1 / §15)
- ✅ **One promise, everywhere** — "every change waits for your approval and can be undone" appears on the door fork and the Home control-flow panel.
- ✅ **One screen, one question** — each section answers a single question (Home: what needs me; Built-in AI: connected & working?; Connect: let an external actor in; Activity: what's happening/waiting; History: what changed/undo; Settings: rules/advanced).
- ✅ **Value before configuration** — Built-in AI promoted; the no-setup "Run a site report" quick win retained.
- ✅ **The door you chose is the only door you see** — the three journeys never share a screen; the fork orders, never hides.
- ✅ **Every empty state instructs** — see below.
- ✅ **Honest over impressive** — the API landing states the real Base URL + a real read call and is explicit that writes are governed; it invents nothing.
- ✅ **Progressive disclosure preserved** — the Engineer/Developer disclosure (`data-wpcc-mode`, `wpcc-engineer-only`) and the advanced surfaces (Capabilities, Runtime) remain collapsed into Settings, off the daily path.

## Terminology (UX blueprint §7) — applied
`AI Setup → Built-in AI › Providers` · `Connect an AI Agent → AI Clients` · `Operate → Activity` · `Operations Center → Activity › Live` · `Operations (catalogue) → Capabilities` · `Audit/Change History → History` · `Approval Center → Approvals` · `Tokens → Settings › Access` · `Runtime (advanced) → Settings › Runtime`. No internal noun (registry, dialect, telemetry, event bus) appears in a customer-facing label.

## Empty states (UX blueprint §6)
- **API & Integrations, no token** → headline *"Create an access token to connect an app."* + one sentence + primary action *"Create a token in Settings → Access."*
- **A whole section gated off** (licensing seam) → `AppShell::render_empty_section()`: names the section, explains it's plan-gated, offers "Back to Home" — never a blank page.
- Existing instructive empties (Home activity/AI/approvals, operations-center, change-history) are unchanged and intact.

## Information density for three users (UX blueprint §9)
The same screens serve first-timer (door fork + Built-in AI), agency power-user (Activity/Approvals/History daily loop, all doors available), and enterprise admin (Settings › Advanced: Capabilities, Access, Diagnostics) via the unchanged progressive-disclosure mechanism — no separate UIs introduced.

## Documented contradictions (UX blueprint §14) — posture unchanged, none faked
- **C1** (AI enable needs a PHP flag) — Built-in AI tools still appear only when their flag + FeatureGate allow; the section honestly shows just *Providers* until a tool is enabled. No fake toggle was added. *(Forward item, not this milestone.)*
- **C2** (provider-agnostic UX vs Anthropic-only execution) — untouched; no unrunnable provider is implied as ready. *(Generation Adapters are a later phase.)*
- **C3/C4/C5/C6/C7** — addressed at the IA/labeling level where Phase-1-appropriate (C4 token placement, C6 naming clash); runtime-bearing parts (C5 scoped tokens, C2 execution) remain forward items.
