# PROGRAM-8 — Runtime Telemetry Architecture

> **Branch:** `program-8-runtime-telemetry` (off `4bfc326`; main untouched `94a716c`). **Observe, don't change** — no UI/AI-feature/approval/rollback/security/MCP/capability/execution-behavior change.

## Goal
Provide the data foundation (not dashboards) so future surfaces — Live Operations Center, Job Center, Usage & Cost, enterprise reporting, provider comparison, runtime diagnostics — can be built **without redesigning the runtime again**. Real data only; unknowns stored as NULL, never invented.

## Components (all in `includes/Telemetry/`)
| Component | Role | Writes? |
|---|---|---|
| **TelemetryStore** | Self-provisioning table `{prefix}wpcc_telemetry`; insert/update/get/query/prune. **Decoupled from `Schema::DB_VERSION`** (2.5.0 invariant untouched). | yes (its own table) |
| **TelemetryRecorder** | The sanctioned **push API**: `start → complete/fail/cancel/retry`, plus one-shot `record()`. Derives duration + estimated cost. **Never throws into the runtime.** | via store |
| **CostModel** | Tokens → estimated cost from a per-model price table; **NULL when unpriced/untokened** (honest). Filterable (`wpcc_telemetry_prices`). | no |
| **TelemetryQuery** | The **dashboard contract** (read-only): `summary()`, `recent()`, `by_provider()`. Exposes measured-vs-unknown coverage. | no |
| **TelemetrySubscriber** | The **observation path**: listens to the behavior-neutral `wpcc_audit_recorded` hook and projects terminal lifecycle events into telemetry. Self-guarded. | via recorder |

## Two capture paths (by design)
1. **Observation (this program):** a single behavior-neutral `do_action('wpcc_audit_recorded', …)` fires AFTER the audit's durable write. The subscriber maps terminal events (operation completed/failed, connection test, change, rollback) into telemetry rows — capturing status, timestamps, provider/model, and **duration where the runtime already records it**; tokens/cost stay NULL (not yet measured). This **observes** the runtime without editing the executor.
2. **Push (sanctioned future insertion point):** `TelemetryRecorder` is the official API for future runtime instrumentation to call directly (e.g. capturing real tokens/cost from a provider response). Wiring it into the executor would edit execution code and is **intentionally out of this program's "observe, not change" boundary** — but the API + storage + cost model are ready, so that step needs **no telemetry redesign**.

## Why this satisfies the success criteria
- The full record (job/provider/model/tokens/cost/duration/queue/status/retry/cancel/error/rollback) exists and is queryable now.
- The two paths mean dashboards can light up with *observed* facts today and with *measured* tokens/cost the moment the runtime is instrumented — **no runtime redesign**.
- Provider-agnostic (free-string provider/model + filterable prices) → new providers are catalogue/price entries, not telemetry changes.
