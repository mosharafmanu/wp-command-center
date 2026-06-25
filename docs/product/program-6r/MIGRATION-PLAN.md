# PROGRAM-6R — Migration Plan & Backward Compatibility

## Why migration is near-free
Program-6 is **unmerged** and there is **no production data** in its type-keyed format. 6R **replaces** Program-6's config classes outright (deleted `Admin/ProviderStore`, `ProviderConfigController`, `ProviderConnectionTester`, `ProviderCatalog`). The only real-world data to preserve is the **legacy Anthropic key** that pre-6 installs (and production) already use.

## Backward compatibility — the runtime is untouched
`AnthropicClient` and all feature generators are **not modified**. The runtime keeps reading `WPCC_ANTHROPIC_API_KEY` (constant) → `wpcc_anthropic_api_key` (option) → legacy vision sources, and `wpcc_anthropic_model`.

### Bootstrap migration (automatic, lossless)
On first load, `ConnectionStore::all()`:
- If connections are stored → use them.
- Else if **any legacy Anthropic key is configured** (constant or option) → surface a **virtual "Anthropic (existing)" connection** (`conn_legacy_anthropic`, `bridge_legacy=true`). It is the default, runtime-usable, and shows the legacy model. **Nothing is lost; existing installs work unchanged.**
- Else → empty state.

The virtual connection is **materialized** (persisted) only when the user edits/sets-default/tests it — no write-on-read for untouched installs.

### Runtime bridge (keeps AnthropicClient fed)
`ConnectionStore::sync_runtime()` mirrors the **default Anthropic connection's** key+model into `wpcc_anthropic_api_key`/`wpcc_anthropic_model` (the options the runtime already reads). A **constant always wins** (never overwritten). The legacy/bootstrap connection already *is* those options → it only syncs the model. This is the single, localized place the legacy world is bridged — replacing Program-6's scattered `if type==='anthropic'`.

## Backward-compatibility cases (verified)
| Case | Behavior |
|---|---|
| Anthropic key in **constant** | virtual connection, key read-only; runtime uses the constant; sync never overwrites. (This dev env runs on `WPCC_VISION_API_KEY` — verified no fatal.) |
| Anthropic key in **option** | virtual connection; runtime unaffected; on materialize, key stays in the legacy option. |
| **No key** | empty state; AI optional; no fatal. |
| **Multiple connections** | opaque-id keyed; secrets isolated; default = the runtime-usable one. |
| **Default deleted/disabled** | default recomputed; sync clears/repoints safely. |
| **New Anthropic connection set default** | its key mirrors to the legacy option so the runtime uses it (constant still wins). |

## Storage / schema
WP options only (autoload=no): `wpcc_ai_connections`, `wpcc_ai_credentials`, `wpcc_ai_default_conn`, `wpcc_ai_routes` (+ the existing `wpcc_anthropic_*`). **No DB schema, no DB_VERSION bump, no destructive migration.** Future move to a table is an implementation detail with the same conceptual shape.
