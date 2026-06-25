# PROGRAM-6R — Foundation Architecture (design-first)

> **Branch:** `program-6r-ai-foundation-realignment` (off Program-6 `aa40eb2`; main untouched `94a716c`).
> **Mandate (from 6X, accepted):** do NOT extend Program-6's type-keyed model; realign to a **connection-centric** foundation that supports unlimited providers/environments/routing with no future rewrite. **Runtime (Program-4/rollback/MCP/security/approvals) is out of scope and untouched.**

## 1. Current architecture analysis (Program-6)
Provider records keyed by **provider type** (one per type); secrets split (Anthropic→legacy option, others→map); single global default; flat `feature→type` routing; 8 provider types with bespoke runtime/test flags; runtime Anthropic-only via per-feature resolvers.

## 2. Weaknesses (from 6X, accepted)
- **Identity = type** → no multiple environments, no multiple keys, no two endpoints of a provider.
- **No `endpoint`/`base_url`/`extra`** → Ollama/LM Studio/Azure/self-hosted/gateways unconfigurable.
- **No API-dialect classification** → future transport is O(N×M) instead of ~3 adapters.
- **Flat `feature→type` routing** → no failover/cost/latency/per-site/per-user.
- **`if type==='anthropic'` secret special-casing** → spreads.

## 3. Future scalability analysis
The unit of config must be a **Connection** (opaque id) whose *provider* and *dialect* are properties. Adding the Nth provider becomes a **catalogue entry** (dialect + default endpoint); adding the Nth environment becomes a **new Connection row**; adding routing becomes a **policy** referencing connection ids. None requires new architecture.

## 4. Enterprise scenarios (target: 100 sites · 20 envs · multi-key · multi-team)
Connection-centric model supports 20 environments (20 connection rows), multi-key (each connection its own credential), tags (prod/test/cheap/premium), and scope (global/site/feature/user/team — reserved). Fleet (100 sites) + secret-provider abstraction + least-privilege are **future layers** that all assume the connection model; v1 reserves `scope`/`tags`/`metadata` so they bolt on without migration. (See ENTERPRISE-SCENARIOS.md.)

## 5. Local-LLM scenarios (Ollama / LM Studio / vLLM)
All speak the **OpenAI-compatible** dialect over a `base_url` (e.g. `http://localhost:11434/v1`). With a Connection carrying `endpoint` + `dialect=openai-compatible`, they are **configurable + connection-testable today**, and become **runtime-usable** the moment the openai-compatible transport ships — zero new architecture. (See LOCAL-LLM-SUPPORT.md.)

## 6. AI-gateway scenarios (OpenRouter / LiteLLM / Portkey / internal proxy)
A gateway is just a Connection with `dialect=openai-compatible` + the gateway `base_url` + optional headers (`metadata.headers`, reserved). One adapter, one connection. No special architecture.

## 7. Migration strategy (incremental, zero-user window)
Program-6 is unmerged and has no production data. 6R **replaces** Program-6's config classes with the connection model; a **bootstrap migration** synthesizes a Connection from any legacy Anthropic key (constant or `wpcc_anthropic_api_key`) so existing installs keep working. (See MIGRATION-PLAN.md.)

## 8. Backward compatibility (the spine — runtime untouched)
`AnthropicClient` and all generators are **not modified**. The **runtime-wired Anthropic connection** mirrors its key→`wpcc_anthropic_api_key` and model→`wpcc_anthropic_model` (the options the runtime already reads); a `WPCC_ANTHROPIC_API_KEY`/`WPCC_VISION_API_KEY` **constant still wins** and renders the connection's key read-only. Non-runtime connections never touch those options. So the runtime sees exactly what it sees today, now *driven by* the connection model.

## 9. Risk analysis
| Risk | Mitigation |
|---|---|
| Runtime regression | AnthropicClient untouched; only the legacy options it already reads are written, via a single mirror point; constant priority preserved. |
| Secret leak via more surfaces | One `CredentialStore`; secrets never in connection records or audit; `type=password`, never echoed; autoload=no. |
| Honest-execution violation | Dialect testability ≠ runtime usability. Connections are labelled **CONFIGURED / TESTABLE / USED BY RUNTIME** independently; only the Anthropic-dialect runtime connection is "used by runtime." Never faked. |
| Test churn from replacing Program-6 | Program-6 tests re-pointed/retired; new 6R functional tests; 5A/5B/5C safety assertions preserved. |
| Scope creep into runtime | Hard STOP boundary; no transport/router built for non-Anthropic generation (config + test only). |

## 10. Final architecture
```
Feature → RoutingPolicy → Connection(opaque id){provider, dialect, endpoint, creds_ref, model, tags, scope}
                                   │
                              Dialect (anthropic | openai-compatible | gemini)
                                   │
                       Transport adapter (v1: anthropic runtime only; tester for all 3 dialects)
                                   │
                        GOVERNANCE (unchanged): capability→approval→execute→audit→rollback
```
**Primitives:** Connection (identity) · Credential (via CredentialStore, legacy-anthropic bridge) · Dialect (transport family) · RoutingPolicy (feature/context → ordered connection ids + strategy; v1 strategy = single). **Storage:** WP options (autoload=no), connection-id-keyed — conceptually table-ready, no schema/DB_VERSION change. **Honesty:** configurable vs testable vs runtime-usable shown independently; no faked execution.

**Components to build:** `Ai\Platform\Dialect`, `Ai\Platform\ProviderCatalog`, `Ai\Platform\ConnectionStore`, `Ai\Platform\CredentialStore`, `Ai\Platform\ConnectionTester`, `Ai\Platform\Routing`; Admin `ConnectionController` + rebuilt `ai-setup.php` (Connections UI). Program-6's `Admin/ProviderStore`/`ProviderConfigController`/`ProviderConnectionTester`/`ProviderCatalog` are retired/replaced.

**STOP-clearance:** options only (no schema/DB_VERSION); no operation/capability/MCP/REST/rollback/security change; AnthropicClient + runtime untouched; AI stays off; no real key.
