# PROGRAM-10 — Operations Center Design

> **Branch:** `program-10-operations-center` (off `8f6527a`; main untouched `94a716c`). Consumes existing read-only data only; no runtime/schema/contract change.

## What it is
A new admin surface — **Operate → Operations Center** (first tab) — that answers, from real data:
*What needs attention? · What happened? · What can I review? · What can I undo?* It feels like GitHub Actions / Vercel Deployments / a Datadog incident timeline, for WordPress operations.

## Sections (all real data; honest empty states)
1. **Needs attention** — pending approvals (real count) + recent **failed** operations (from telemetry). "All clear" when nothing is waiting and nothing failed.
2. **Operations timeline** — newest-first jobs from **TelemetryQuery::recent()** (status badge, provider/model, duration *when known*); honest **audit-derived fallback** when telemetry has no rows yet (and it says duration isn't measured for those).
3. **Review & undo** — recent **reversible change sessions** (from `ChangeHistoryAdminQuery`, FeatureGate-guarded) with a per-session "Review & undo" deep link into Change History; plus an "All changes" link.
4. **System activity** — the telemetry **status roll-up** (completed/failed/running/cancelled, avg duration) over a window. `running` reflects only actually-recorded running rows.
5. **Data coverage (honesty)** — telemetry Active/No-data; token usage "Not tracked yet / Partly tracked"; **cost "Not tracked yet"** — never a fabricated figure.

## Architecture
```
OperationsCenterQuery (read-only aggregator)
  ├─ TelemetryQuery (P8)        → timeline, status roll-up, failures, coverage
  ├─ AiActivity (P7)            → pending approvals, audit-fallback timeline
  └─ ChangeHistoryAdminQuery    → reversible sessions (gated + guarded)
        ↓
  views/operations-center.php   → renders the 5 sections (no business logic in the view)
```
The view computes nothing; it renders the query's shapes (the P8 dashboard-contract principle).

## Surface placement
Added as the **first tab under Operate** (`wpcc-operate&wpcc_tab=center`), with a `wpcc-operations-center` legacy slug redirect. Inherits the section's `manage_options` gate (no new route, no new capability).

## Liveness without faking it
It "feels alive" by surfacing the freshest real events on page load — **no fake polling, no fake live refresh, no invented running states**. When the runtime later emits richer telemetry (push instrumentation), this same screen shows more, automatically.
