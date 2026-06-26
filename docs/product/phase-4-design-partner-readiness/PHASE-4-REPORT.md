# Phase 4 — Design Partner Readiness (Report)

> **Type:** implementation documentation. **Date:** 2026-06-26.
> **Goal:** remove the friction between *fresh install → connect provider → enable a built-in AI tool → generate → review → approve → apply → undo*, so the product is ready for a hand-held design-partner pilot. **No** enterprise-scale features (SSO/SCIM/fleet/pricing/licensing/RBAC/export/SIEM), no new provider execution, no IA/routing/REST/MCP/capability change, **no schema/DB_VERSION change** (enablement is a WordPress option, not a table). Four Guarantees intact.

## What was implemented

### 1. In-admin Built-in AI enablement (the #1 blocker)
Built-in AI tools (SEO · Alt Text · Content) can now be turned on/off **from the UI** — no more wp-config edit. New `BuiltinAiSettings` resolves each tool in strict precedence, never silently:
1. A **defined constant** is site configuration and **wins** (on or off) → status `enabled_by_config` / `disabled_by_config`, toggle **locked**.
2. A truthy `wpcc_*_ui` **filter** is a programmatic opt-in → `enabled_by_config`.
3. Otherwise the **per-tool option governs** (default **off**) → `enabled` / `disabled`.

A tool enabled with **no provider** is honestly reported as `requires_provider` (its own screen guides connecting one). The toggle is **nonce-protected, capability-checked (`manage_options`), and audited** (`builtin_ai.tool_enabled|disabled`). `AppShell::flag()` now consults the option while still honoring constants and filters. **Existing installs are not silently changed** — the option defaults off and constants/filters keep working exactly as before. Surfaced as a CDS card on **Built-in AI › Providers**.

### 2. Readiness checklist
New `DesignPartnerReadiness` answers *"Can I run the first governed AI change now?"* from **real state** (no fabrication): Approvals-on (Client-safe) · provider connected · provider tested · generation supported (honest: Anthropic today) · a tool is on · test content available · approvals ready · history/undo ready. Each item is `pass` / `warning` / `blocked` with a next action. `can_run_first_workflow()` is true when nothing is blocked.

### 3. Home first-value panel
The Home first-run now leads with **one next action** ("Get to your first governed AI change · Next: …") or a green "You're ready" state, with the full 8-item checklist behind a **progressive-disclosure `<details>`** — not a wall. Uses the readiness model; no fake demo data.

### 4. Provider honesty
The enablement card and the readiness "generation supported" item both state plainly: **"Generation runs on Anthropic today. Other providers can be connected and tested, but can't generate yet."** No unsupported provider is shown as ready; healthy-but-ineligible providers are not hidden.

### 5. Demo / sandbox support (checklist-based, by design)
The readiness "test content available" item **detects** whether a post and an image exist and links to add them — it does **not** silently create content. (Per the brief's safety rules, auto-creating content was judged unnecessary risk for the demo; a guided "Add a post or image" link is provided instead.)

### 6. Safety defaults — verified preserved
Client-safe seed for fresh installs, approval gates, rollback, audit, and capability scoping are all unchanged. The readiness checklist **surfaces a warning** if the site is still in Developer (self-approving) mode, with a one-click link to Client-safe mode.

## Files
- **New:** `includes/Admin/BuiltinAiSettings.php`, `includes/Admin/DesignPartnerReadiness.php`, `includes/Admin/views/partials/builtin-ai-tools.php`, `tests/test-phase-4-readiness.sh`, and the four phase-4 docs.
- **Modified:** `includes/Admin/AppShell.php` (`flag()` consults the option), `includes/Admin/views/ai-setup.php` (includes the enablement card), `includes/Admin/views/command-home.php` (first-value panel).

## Validation (summary; full detail in VALIDATION-REPORT.md)
`test-phase-4-readiness.sh` **58/0** (incl. live option-path, config-control locking, audited toggle, no key leakage, readiness shape, invariants). Affected suites green: ia-phase1 89/0 · experience-layer 113/0 · alt-text-ui 76/0 · ai-content-builder 81/0 · proposal-admin 25/0 · adoption-readiness 44/0 · usability-5b 36/0 · first-value-5c 24/0 · approval-center 127/0 · phase-2a 45/0 · phase-2b 33/0. Invariants `34/23/40/40/2.5.0`. **Net-new attributable failures: 0** (the 2 `test-seo-audit` classify fails are pre-existing).

## Is the first governed AI demo now ready?
**Yes — the in-admin path works end-to-end without code editing.** On a fresh install a design partner (or the founder) can: connect a provider → **turn on a tool from the UI** → the tool's tab appears → generate → review → approve → apply → undo in History. The readiness panel guides each step from real state.

## What still requires founder/manual setup
- A **real Anthropic key** (BYO — must not be committed; founder pastes it).
- **Client-safe mode** on an *existing* install (fresh installs seed it; existing installs show a readiness warning to flip it).
- A **post + image** to demo on (readiness detects + links; founder adds if absent).

## Remaining design-partner blockers (honest)
None that block the hand-held demo. Out-of-scope items that remain (documented, not built): multi-provider generation, key encryption-at-rest, self-serve onboarding polish, daily-loop screen polish. These are pilot-framing or post-pilot, not demo blockers.

## Next recommended action
Recruit 3–5 partners and run the demo (see FIRST-WORKFLOW.md). Staged on `main`, **not pushed**; production untouched (Program-4).
