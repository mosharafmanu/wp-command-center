# Phase 4 — Validation Report

## Lint
Clean (`php -l`) on all changed/new PHP: `BuiltinAiSettings.php`, `DesignPartnerReadiness.php`, `AppShell.php`, `views/partials/builtin-ai-tools.php`, `views/ai-setup.php`, `views/command-home.php`.

## New suite — `test-phase-4-readiness.sh` → **58 / 0**
Covers (static + live wp-cli):
- **Enablement model:** 3 tools; config-control detection; all five status states; `set()` refuses when config-controlled; **nonce-checked + capability-checked + audited** POST handler; no key read/render; no REST route.
- **AppShell:** `flag()` consults `enabled_by_option`; defined constant wins (on or off); filter opt-in honored.
- **Enablement UI:** CDS card; nonce field; honest Anthropic note; config-controlled shown "Locked"; escaped; no key rendered; included on Providers.
- **Readiness:** ≥8 items; the key items present; honest generation note; `can_run_first_workflow()`; `next_action()`; performs no writes; Home uses real state + one next action + progressive disclosure; Home fabricates no demo content.
- **Live functional:**
  - *Option path (fresh-install sim, filters removed):* tool is option-governed → enable turns it on → disable turns it off.
  - *Config-control:* a truthy filter makes the tool config-controlled → status `enabled_by_config` → `set()` is a no-op.
  - *Readiness:* ≥8 items, every status ∈ {pass, warning, blocked}.
  - *Invariants:* `34 / 23 / 40 / 40 / 2.5.0` (no schema/DB_VERSION change — enablement is a WP option).

## Behavioral verification (manual trace via wp eval)
- Default (no constant/filter/option): tool **off**, tab absent.
- Enable via option: tool **on**, tab appears; status `enabled` (or `requires_provider` if no key).
- Constant/filter present: status `enabled_by_config`/`disabled_by_config`; toggle **locked**; option ignored.
- Existing installs: unchanged (option defaults off; constants/filters keep prior behavior).

## Affected existing suites (all green)
`test-ia-phase1` 89/0 · `test-experience-layer` 113/0 · `test-alt-text-ui` 76/0 · `test-ai-content-builder` 81/0 · `test-proposal-admin` 25/0 · `test-adoption-readiness` 44/0 · `test-usability-5b` 36/0 · `test-first-value-5c` 24/0 · `test-approval-center` 127/0 · `test-phase-2a` 45/0 · `test-phase-2b` 33/0.

## Pre-existing (not attributable to Phase 4)
`test-seo-audit` — 2 SEO **classify** assertions (`classify weak`, `classify ok`). Proven pre-existing across prior phases (fail with all changes reverted); functional/environmental, unrelated to enablement.

## Drift checks
- **No REST route / engine dispatch** added in the new model/partial.
- **No capability / MCP / catalogue / DB_VERSION change** (invariants asserted green).
- **No secrets** rendered or logged; the toggle handler is nonce + capability gated and audited.

## Net
**Net-new attributable failures: 0.** The in-admin first-governed-AI-change path works end-to-end without code editing, governed and honest. Safety defaults (approval/rollback/audit/scoping/Client-safe) preserved.
