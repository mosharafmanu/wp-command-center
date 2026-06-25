# PROGRAM-9 — Runtime Event Bus Architecture

> **Branch:** `program-9-event-bus` (off `14752a1`; main untouched `94a716c`). Event-driven only; **no runtime behavior change**; Audit authoritative; Telemetry unchanged; backward compatible; zero duplicate recording.

## From point-to-point to a bus
```
Before (P8):  Runtime → Audit → (wpcc_audit_recorded) → Telemetry
After  (P9):  Runtime → Audit (authoritative, upstream)
                          │  wpcc_audit_recorded  (the single behavior-neutral emission, unchanged)
                          ├─→ Telemetry          (unchanged — still a direct listener)
                          └─→ EventBridge → EventBus ──┬─→ Notifications (future)
                                                       ├─→ Webhooks (future)
                                                       ├─→ Live Dashboard (future)
                                                       ├─→ Fleet (future)
                                                       └─→ Analytics (future)
```

## Why fed from the existing emission point (not a new runtime emission)
A new emission point would mean editing the executor → a runtime behavior change (forbidden). So the bus is fed from **P8's existing `wpcc_audit_recorded` hook**, which already fires AFTER the authoritative audit write. The bus therefore adds **zero** new runtime code and is provably behavior-neutral.

## Components (`includes/Events/`)
| Class | Role |
|---|---|
| **EventCatalog** | The published taxonomy: stable `category` + `verb` vocabulary, severity, terminal verbs. The contract subscribers depend on. |
| **RuntimeEvent** | Immutable event object: `name` (`category.verb`), category, verb, raw action, subject, context (redacted), timestamp, actor, severity, terminal, correlation_id. `matches(pattern)` for exact/wildcard/`*`. |
| **EventFactory** | The ONLY place raw audit actions are parsed → a normalized RuntimeEvent. Subscribers never parse strings. |
| **EventBus** | The pub/sub registry: `subscribe(pattern, handler, priority)`, `publish(event)`. Priority-ordered, **each handler `\Throwable`-guarded**, records nothing, no-op when empty. |
| **EventBridge** | Subscribes ONCE to `wpcc_audit_recorded`; builds a RuntimeEvent; publishes ONE event to the bus. The only audit→bus path → zero duplicates. |

## Design guarantees
- **No runtime change:** only `Plugin::boot` gains one additive registration line; no executor/approval/rollback/security/MCP/capability/Schema change.
- **Audit authoritative:** audit is upstream + the source of truth; the bus is strictly downstream fan-out.
- **Telemetry unchanged:** it remains a direct `wpcc_audit_recorded` listener; it was not moved to the bus.
- **Zero duplicate recording:** the bus records nothing; one bridge → one publish per record; subscribers are independent.
- **Future subscribers, zero runtime modification:** they call `EventBus::subscribe('category.*' | 'name' | '*', handler)` and immediately receive typed events.
- **Resilient:** a throwing subscriber can never break the bus, other subscribers, or the runtime.
