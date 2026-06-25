# PROGRAM-6R — Connection Model

## The primitive: a Connection (opaque id)
The unit of configuration is a **Connection**, not a provider type. Provider and dialect are *properties*. Unlimited connections, including multiple of the same provider (environments).

### Fields (stored in `wpcc_ai_connections[id]`, autoload=no — never a secret)
| Field | Purpose | Status |
|---|---|---|
| `id` | opaque id (`conn_` + uuid4) | active |
| `name` | display name ("Production Claude") | active |
| `provider` | catalogue provider id | active |
| `dialect` | derived from provider (anthropic / openai-compatible / gemini) | active |
| `endpoint` | base URL (for local/gateway/Azure/custom) | active (editable when dialect allows) |
| `model` | model id (provider default if blank) | active |
| `deployment` | Azure deployment name | active (Azure) |
| `organization` / `project` | OpenAI org/project | reserved |
| `enabled` | on/off | active |
| `tags[]` | prod/test/cheap/premium… | active |
| `scope` | global / site / feature / user / team | reserved (default `global`) |
| `metadata{}` | custom settings / headers | reserved |
| `bridge_legacy` | this connection's key = the legacy Anthropic option/constant | internal (bootstrap) |
| `last_test` | `{ok,code,time}` | active |
| `created_at` | timestamp | active |

Secrets live in a **separate** store (`wpcc_ai_credentials[id]`, autoload=no), never in the record. Default = `wpcc_ai_default_conn` (id). Routes = `wpcc_ai_routes` (feature→id).

### What this unlocks that Program-6 could not
- **Multiple environments per provider** (Prod GPT + Cheap GPT = two connections) — functionally tested.
- **Custom endpoints** (Ollama/LM Studio/Azure/self-hosted/gateways) via `endpoint`.
- **Tagging** (prod/test/cheap/premium) and **scope** (reserved) without a future migration.
- **Duplicate** a connection (key intentionally NOT copied — security).

### Storage = options, conceptually table-ready
v1 uses `wp_options` (no schema, no DB_VERSION). Because the model is connection-id-keyed, migrating to a `wpcc_ai_connections` table later is an *implementation* change with the same conceptual shape — no re-modelling. **No schema/DB_VERSION change in 6R.**

### Honesty triad (independent flags)
A connection is **CONFIGURED** (has a usable credential), optionally **TESTABLE** (its dialect has a tester), and **USED BY RUNTIME** only if its dialect is runtime-wired (Anthropic today). The UI shows these independently — never conflated, never faked.
