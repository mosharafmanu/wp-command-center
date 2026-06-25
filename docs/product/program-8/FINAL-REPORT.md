# PROGRAM-8 — Final Report: Runtime Telemetry Foundation

> **Branch:** `program-8-runtime-telemetry` (off `4bfc326`; main untouched `94a716c`). **Not pushed, not merged, not deployed.** Observe-the-runtime, don't change it.

## What was built (the data foundation — not dashboards)
A complete, queryable runtime-telemetry foundation in `includes/Telemetry/`:
- **TelemetryStore** — self-provisioning `wpcc_telemetry` table (indexed, prunable), **decoupled from `Schema::DB_VERSION`** (2.5.0 preserved).
- **TelemetryRecorder** — the sanctioned push API (`start/complete/fail/cancel/retry` + one-shot `record`), deriving duration + estimated cost, **never throwing into the runtime**, backfilling lifecycle facts.
- **CostModel** — tokens→cost from a filterable per-model price table; **NULL when unpriced/untokened** (never invents).
- **TelemetryQuery** — the read-only **dashboard contract** (`summary/recent/by_provider`) exposing measured-vs-unknown coverage.
- **TelemetrySubscriber** — the observation path: projects terminal lifecycle events from the single behavior-neutral `wpcc_audit_recorded` hook into telemetry.

## How it observes without changing the runtime
One additive line in `AuditLog::record()` fires `do_action('wpcc_audit_recorded', …)` **after** the audit's durable write; one additive line in `Plugin::boot()` registers the subscriber. The subscriber records one row per **terminal** event (operation completed/failed, connection test, change, rollback), capturing status, timestamps, provider/model, and **duration where the runtime already records it** — tokens/cost stay NULL until measured. The executor, approvals, rollback, security, MCP, and capability systems are **untouched**.

## Answers it can now (honestly) support
What's running · completed · failed · how long (where measured) · which provider/model · tokens (when recorded) · estimated cost (when priced+tokened, else "—") · retry count · cancellation · queue wait — **real data only; NULL for unknown.**

## Telemetry vs. live tokens/cost (the honest boundary)
This program delivers **observation + the foundation**. **Measured tokens/cost** require wiring `TelemetryRecorder` into the executor to read each provider response's `usage` — that edits execution code and is **intentionally out of this program's "observe, not change" boundary**. Crucially, doing it later needs **zero telemetry redesign**: the columns, recorder API, cost model, and read contract already exist.

## Integrity
- **No STOP boundary crossed:** only an additive audit hook + plugin wiring + new `Telemetry/` classes. No UI/AI-feature/approval/rollback/security/MCP/capability/execution-behavior/registry/Schema/DB_VERSION change (`git diff` confirms; DB_VERSION 2.5.0 preserved).
- **Behavior-neutral (validated):** `ai-assist` 92/0 + all runtime/security/MCP/capability suites pass with telemetry active; recorder/subscriber `\Throwable`-guarded; hook fires post-write.
- **Honest:** unmeasured → NULL; cost NULL when unpriced/untokened; no fabricated metrics (asserted by tests).
- **Validation:** new `test-telemetry-8.sh` **21/0** (13 functional); every prior suite green; **net-new attributable = 0**; invariants **34/23/40/40/2.5.0** held.

## Performance / future-proofing
Low-frequency guarded writes (one per terminal event), per-request `ensure_table` guard, indexed bounded reads, prunable growth. Provider-agnostic (free-string provider/model + filterable prices) → **new providers need zero telemetry redesign**.

## Success criteria — met
WP Command Center now possesses the telemetry foundation to build a Live Operations Center, Job Center, Usage & Cost, enterprise reporting, performance analytics, provider comparison, and runtime diagnostics — **without redesigning the runtime again.** Dashboards consume `TelemetryQuery`; the runtime stays untouched.

## Merge GO / NO-GO: **GO (for review)**
Additive, behavior-neutral, invariant-preserving, honest, validated. Stack: …→6S→7→7.5→**8**.

## Deploy GO / NO-GO: **Code-safe; not from this program.**
No posture change; AI stays off; the telemetry table self-creates on first event and is harmless/empty until then. Deployment is a separate owner-authorized step.

## Deliverables (10) in `docs/product/program-8/`
TELEMETRY-ARCHITECTURE · EVENT-LIFECYCLE · STORAGE-DESIGN · DASHBOARD-CONTRACT · PROVIDER-COMPATIBILITY · PERFORMANCE-REVIEW · MIGRATION-STRATEGY · VALIDATION-REPORT · INDEPENDENT-AUDIT · FINAL-REPORT.

## Where I stopped
The telemetry architecture is complete, validated, and future-proof. The only remaining HIGH-value step — capturing **measured** tokens/cost — requires editing the executor (push instrumentation), which crosses this program's "observe, not change" boundary and belongs to a separate, owner-authorized runtime program. The foundation is ready for it with no redesign.
