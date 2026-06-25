# PROGRAM-9 — Event Contracts

Subscribers depend on these, not on raw audit strings.

## Event name = `category.verb`
**Categories:** `operation · ai · connection · change · rollback · approval · security · agent · patch · system`
**Verbs:** `started · completed · failed · cancelled · denied · exception · recorded · test · dispatched · applied · created · updated · deleted · occurred`

Example names: `operation.completed`, `operation.failed`, `connection.test`, `change.recorded`, `rollback.dispatched`, `security.denied`, `ai.created`.

## RuntimeEvent fields (immutable)
| Field | Meaning |
|---|---|
| `name()` | canonical `category.verb` — **key off this** |
| `category()` / `verb()` | the parsed parts |
| `action()` | raw runtime audit action (traceability only) |
| `subject()` | the operation/connection/feature id |
| `context()` / `get(k)` | already-redacted audit context (no secrets) |
| `timestamp()` | unix time |
| `actor()` | actor type/label (non-secret) |
| `severity()` | `info` / `warning` / `error` |
| `is_terminal()` | a finished unit of work |
| `correlation_id()` | job/request/change/session id, or `''` |

## Subscription patterns
- **Exact:** `operation.completed`
- **Category wildcard:** `operation.*` (any verb in the category)
- **All:** `*`

## Severity rules (deterministic)
- `error` when verb ∈ {failed, exception, denied}.
- `warning` when the action mentions warn/rate/slow.
- `info` otherwise.

## Mapping from raw actions (EventFactory precedence)
1. `*rollback*` / `*restore*` → **rollback**
2. `ai.connection*` / `ai.provider*` → **connection**
3. `mcp.*` → **agent**
4. `change*` → **change**
5. `approval*` / `*request*` / `*queue*` → **approval**
6. `security*` / `capability*` / `*denied*` → **security**
7. `patch*` → **patch**
8. `operation*` → **operation** (an operation on the SEO runtime is `operation`, NOT `ai`)
9. `*proposal*` / `*generate*` / `ai.*` / `seo_meta*` / `alt_text*` → **ai** (genuine AI generation only)
10. else → **system**

## Stability promise
Adding a new runtime action maps onto an existing category/verb — **no contract change, no subscriber change**. New categories/verbs are additive to `EventCatalog`. Subscribers that key off `name`/`category` (not raw `action`) are forward-compatible.
