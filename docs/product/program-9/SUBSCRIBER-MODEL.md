# PROGRAM-9 — Subscriber Model

## How a future subscriber attaches (zero runtime modification)
```php
use WPCommandCenter\Events\EventBus;

// e.g. in a future Notifications module's init():
EventBus::subscribe( 'operation.failed', function ( $event ) {
    // $event is a RuntimeEvent — typed, no string parsing.
    notify_admin( $event->subject(), $event->severity(), $event->actor() );
}, 10 );

EventBus::subscribe( 'rollback.*', [ $webhookSender, 'send' ] );
EventBus::subscribe( '*',          [ $analytics, 'ingest' ], 50 ); // low priority, runs last
```
The subscriber needs **no change to the runtime, audit, telemetry, or executor** — it just registers on the bus (typically in its own `init()` called from `Plugin::boot`). The bridge already feeds the bus from the single runtime emission.

## Contract for subscribers
1. **Key off `name()` / `category()`**, never the raw `action()`.
2. **Be side-effect-isolated:** the bus guards every handler, but a subscriber must not assume ordering beyond its declared priority and must not throw expecting the bus to care.
3. **Read-only on the event:** RuntimeEvent is immutable.
4. **No secrets:** `context()` is already redacted; don't log raw context blindly to external sinks without re-checking.
5. **Idempotency where it matters:** webhooks/notifications should de-dupe on `correlation_id` if they must fire once.

## Priority & ordering
Lower `priority` runs first; ties break by registration order (deterministic). This lets, e.g., a "enrich" subscriber run before a "dispatch" subscriber.

## Existing consumers (unchanged)
- **Audit** — upstream source, not a bus subscriber.
- **Telemetry** — remains a *direct* `wpcc_audit_recorded` listener (deliberately not migrated, to honor "Telemetry unchanged"). It MAY migrate to `EventBus::subscribe('*')` later with no behavior change — the bus is ready.

## Planned subscribers (future programs — designs only)
| Subscriber | Subscribes to | Purpose |
|---|---|---|
| Notifications | `operation.failed`, `security.denied`, `connection.failed`, `approval.*` | alert the operator |
| Webhooks | `*` (filtered) | push events to external systems (Slack/Zapier/SIEM) |
| Live Dashboard | `*` | real-time Mission Control updates |
| Fleet | `*` | aggregate events across sites |
| Analytics | `*` | trend/usage analysis |

None require any runtime change — only an `EventBus::subscribe(...)` call.
