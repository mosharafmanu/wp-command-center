# PROGRAM-10 — Validation Report

## PHP lint
`OperationsCenterQuery`, `views/operations-center.php`, `AppShell` → clean.

## Tests
| Suite | Result |
|---|---|
| **test-operations-center-10.sh** (new) | **28 / 0** — incl. **9 functional**: section shapes don't fatal when empty; honest source flag (telemetry/audit); a seeded **failed** job surfaces in Needs attention; a **completed** job shows **real duration**; status roll-up counts the failure; cost `false`; reversible guarded |
| test-admin-permissions.sh | **51 / 0** (access gating intact) |
| test-usability-5b.sh | **36 / 0** (5-C nav + AI Setup tab intact after adding the Operations Center tab) |
| test-ai-platform-ux-6s.sh | 44 / 0 |
| test-event-bus-9.sh / test-telemetry-8.sh | 17 / 0 · 21 / 0 |
| test-ai-assist.sh | **92 / 0** (runtime unbroken) |
| test-security-modes / -operations-registry | 28 / 0 · 18 / 0 |
| test-admin-ux.sh | 22 / 1 — the 1 failure ("queue status badge") is **pre-existing on main**, not attributable |

**Net-new attributable failures = 0.**

## Requirement verification (per the brief)
- uses existing data only ✓ (TelemetryQuery / AiActivity / ChangeHistoryAdminQuery; query writes nothing — asserted)
- no fake tokens/cost ✓ (cost `false`; no `$` figure; coverage panel honest)
- no fake running states ✓ (`running` from real telemetry rows only)
- failed/pending/completed sections render correctly ✓ (functional seed test)
- reversible sessions link correctly ✓ (session deep link into Change History; FeatureGate-guarded)
- empty-state behavior is honest ✓ ("All clear", "No operations recorded yet", "No reversible changes recorded yet")
- access control preserved ✓ (inherits the Operate page `manage_options` gate; no new route/capability)
- XSS escaping preserved ✓ (`esc_html`/`esc_url`/`esc_attr`; session id `rawurlencode`)
- performance bounded ✓ (handful of indexed/aggregate reads; fixed row caps)

## Invariants
OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0 — held. No Schema/registry/MCP/EventBus/telemetry-model change (`git diff` = AppShell + new query/view only).
