# PROGRAM-8 — Migration Strategy

## No core-schema migration; no DB_VERSION bump
The telemetry table is **self-provisioned** (`CREATE TABLE IF NOT EXISTS`) and **decoupled from `Schema::DB_VERSION`** (held at **2.5.0**). This is deliberate:
- The long-guarded DB_VERSION invariant stays intact — no prior test/assertion changes, no upgrade-path risk to existing tables.
- Telemetry can evolve its own table independently and can never conflict with the core schema versioning.

## Provisioning
- The table is created the first time anything writes telemetry (a terminal event fires the subscriber, or `TelemetryRecorder`/`ensure_table()` is called). Per-request guarded so it runs at most once.
- On a fresh install with no events, the table simply doesn't exist yet; all reads (`TelemetryQuery`, `summary/recent/by_provider`) return empty/zero safely (existence-guarded). **No fatal.**

## Backward/forward compatibility
- **Existing installs:** unaffected — nothing reads/writes telemetry until an event occurs; no data migration; no behavior change.
- **Future column additions:** add the column in `ensure_table()` (additive `CREATE TABLE IF NOT EXISTS` won't alter an existing table, so a future additive column would ship with a small `ALTER TABLE … ADD COLUMN IF NOT EXISTS` guarded migration in the store — still decoupled from DB_VERSION). Documented as the evolution path; not needed now.
- **Uninstall:** the table can be dropped in `uninstall.php` in a future pass (not added here to avoid touching uninstall logic without need); it is additive and harmless if left.

## Future push-instrumentation migration (separate, owner-authorized)
Wiring `TelemetryRecorder` into the executor (to capture real tokens/cost) is the only step that edits execution code — **out of this program's "observe, not change" boundary**. When undertaken:
- It requires **no telemetry schema redesign** (the columns + recorder + cost model already exist).
- It would call `start()` at execution begin and `complete()/fail()` at end with the provider response's `usage` — purely additive to the executor, behavior-neutral if guarded.

## Rollback of this program
Fully reversible: revert the two additive lines (audit hook + plugin wiring) and remove the `includes/Telemetry/` classes; optionally `DROP TABLE wpcc_telemetry`. No data loss elsewhere, no schema entanglement.
