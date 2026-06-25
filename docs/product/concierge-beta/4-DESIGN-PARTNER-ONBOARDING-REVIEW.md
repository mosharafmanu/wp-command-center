# Concierge Beta — Phase 4: Design Partner Onboarding Review

Onboarding the first agency, reviewed from the code. Findings are what would actually confuse or block a real agency.

## Would-confuse / would-block (evidence-based)

| # | Finding | Severity | Evidence | Concierge mitigation |
|---|---|---|---|---|
| O1 | **AI features can only be enabled by editing code** — there is **no admin toggle**; you must `define('WPCC_ALT_TEXT_UI', true)` (or add a `wpcc_alt_text_ui` filter). | **High** | `AppShell::flag()` reads a constant/filter (default false); no settings UI writes it. | Founder enables the flag during onboarding. **Self-serve is impossible** — accept this for the concierge model; do not market self-serve. |
| O2 | **Existing-site installs are NOT client-safe by default** — the seed is unset-only, so a site that previously ran the plugin (mode already `developer`) stays self-approving. | **High** | `get_option('wpcc_security_mode')='developer'` on the existing env; `Activator` uses `add_option` (no overwrite). | Onboarding step: **explicitly set Client mode** and verify, on every site (not just truly-fresh ones). |
| O3 | **Two "AI" mental models** — "AI Setup / Connections" (provider keys) vs "Connect an AI Agent" (MCP tokens). | Medium | Two surfaces; disambiguated in 5C/7.5 but still two concepts. | Walk the partner through which is which; most beta partners need only AI Setup. |
| O4 | **No partner-facing documentation** — only internal handoffs/program reports exist. | Medium | No `getting-started`/end-user guide in the repo. | Founder-led walkthrough substitutes; write a 1-page quickstart before partner #2. |
| O5 | **Operations Center / Mission Control are empty on day one** — honest, but an underwhelming first impression. | Medium | Read-only surfaces show "no data yet" until activity. | Set expectation; run the first AI workflow live during onboarding so the partner sees real rows immediately. |
| O6 | **Single admin permission** — everything is gated by `manage_options`; no scoped role for a junior. | Low–Medium | All admin surfaces gate on `manage_options`. | Fine for an agency owner; note that delegation to limited-permission staff isn't supported yet. |
| O7 | **Internal operation names** leak in a few labels (e.g. `report_manage`, `system_info`). | Low | Op ids surfaced in some catalog views. | Cosmetic; friendly labels exist for primary flows. |

## What is GOOD for onboarding (evidence)
- **Fresh installs are governed by default** (Client mode) — the safety promise is ON for new partner sites.
- **AI is off until explicitly enabled** — no surprise spend, no surprise writes.
- **First-value path exists** (5C): plain-language home, no-AI quick win, agent-confusion fixes.
- **Honest data everywhere** — "not tracked yet" instead of fake numbers builds trust fast.
- **Rollback/audit are production-proven** — the partner can undo anything; this is the trust anchor.

## Onboarding time (realistic)
- **Concierge (founder-led):** ~30 min/site — install, set Client mode, add key, enable one flag, run one live workflow, tour Operations Center. (Matches the RC-2 checklist.)
- **Self-serve:** **not feasible** (O1 — requires code edits). Do not attempt.

## Net
The product is **onboardable by a founder in ~30 minutes per site** and safe once Client mode is confirmed. It is **not** self-serve onboardable (O1) — which is fine for a concierge beta but must be understood. O2 (existing-site mode) is the one finding that could create a *safety* surprise and must be an explicit onboarding step.
