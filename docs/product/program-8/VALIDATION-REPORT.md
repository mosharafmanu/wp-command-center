# PROGRAM-8 — Validation Report

## PHP lint
`TelemetryStore`, `TelemetryRecorder`, `CostModel`, `TelemetryQuery`, `TelemetrySubscriber`, `AuditLog`, `Plugin` → all clean.

## Tests
| Suite | Result |
|---|---|
| **test-telemetry-8.sh** (new) | **21 / 0** — incl. **13 functional** checks: table self-provision, cost honesty (priced→value, unpriced→NULL, no-tokens→NULL), recorder lifecycle (duration + cost derived, model backfilled), unknown-not-faked, subscriber projection of a terminal event with real duration, query summary coverage + recent |
| test-ai-assist.sh | **92 / 0** — **AI runtime unbroken with the subscriber active** |
| test-security-modes / -validation | 28 / 0 · 27 / 0 |
| test-mcp-error-surface | 18 / 0 |
| test-operations-registry / -capability-runtime | 18 / 0 · 61 / 0 |
| test-change-history-admin | 119 / 0 |
| test-admin-permissions | 51 / 0 |
| test-ai-platform-6r / -ux-6s / -activity-7 / -polish-7-5 | 38 / 44 / 15 / 29 — 0 |
| test-adoption-readiness / -5b / -5c | 44 / 36 / 23 — 0 |

**Net-new attributable failures = 0.** No prior test re-pointing needed.

## Required verification coverage (per the brief)
duration ✓ · status ✓ · provider ✓ · timestamps ✓ · token tracking ✓ (recorded when present; NULL when not) · cost tracking ✓ (derived when priced+tokened; NULL otherwise) · queue timing ✓ (column + recorder field) · error recording ✓ · retry recording ✓ · cancellation ✓.

## Behavior-neutrality (the core STOP guard)
The observation hook fires **after** the audit's durable write; recorder + subscriber are `\Throwable`-guarded. `ai-assist` 92/0 and every runtime/security/MCP/capability suite pass with telemetry active → **execution behavior unchanged**.

## Invariants
OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · **DB_VERSION 2.5.0 (preserved — telemetry table decoupled)**.

## Honesty (asserted)
Cost NULL for unpriced/untokened; unmeasured fields NULL not 0; subscriber leaves tokens/cost unknown. No fabricated metrics.
