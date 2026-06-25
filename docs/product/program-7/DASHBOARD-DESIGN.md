# PROGRAM-7 — Dashboard / Mission Control

## Implemented now (read-only, honest)
A **Mission Control** block on the AI Connections page, powered by the new read-only `Ai\Platform\AiActivity` (reads the existing AuditLog + approval queue — no writes, no AI calls, no schema/registry/runtime change):

- **Recent AI activity feed** — real recorded events, classified (Connection / AI generation / Change / Rollback / Agent / Operation / Security) with a color dot, human label, actor, and relative time.
- **Counters** — Recent events · **Pending approvals** (live count, links to Approval Center) · **Token usage & cost: "Not tracked yet"** (honest — never a fabricated figure).
- **Quick actions** — Review changes & undo · Approvals · Connect an AI agent.
- **Teaching empty state** — "When AI or an agent acts on this site, the governed history appears here — every action recorded, reversible where supported."

This unifies the workflow that already exists (agent/AI acts → recorded → approvable → reversible) into one glanceable surface, plus the 6S dashboard (readiness ring, KPIs, connection health, warnings).

## Why it's honest, not theatre
- Every row is a **real audit event** — no synthetic activity.
- Cost/usage is shown as **"Not tracked yet"** with a tooltip explaining it needs runtime metering, because fabricating an estimate would mislead. This is the opposite of vanity dashboards that invent numbers.

## Designed (next, gated)
The full Mission Control vision (per the brief — recent jobs, recent generations, feature usage) becomes *populated* once: (a) AI is enabled (owner decision — key + flags), and (b) the runtime records jobs/tokens (instrumentation — a STOP-boundary change to runtime contracts, out of this program). The activity feed is the forward-compatible precursor: when those events start flowing, they already render here, classified.

## Layout
Two-column: activity feed (1.2fr) + a counter rail (1fr), under the existing hero/KPI/warnings. Responsive: stacks on mobile (inherits the 6S grid + breakpoint). Accessible: feed is a labelled list, dots `aria-hidden`, counts in real text.
