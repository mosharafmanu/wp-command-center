# PROGRAM-6R — Final Report: AI Platform Foundation Realignment

> **Branch:** `program-6r-ai-foundation-realignment` (off Program-6 `aa40eb2`; main untouched `94a716c`). **Not pushed, not merged, not deployed.**
> **Mandate:** realign the AI config foundation to connection-centric per 6X — one architecture, unlimited providers/environments/routing, no future rewrite. Runtime/governance untouched.

## What changed
- **Replaced** Program-6's type-keyed config layer (deleted `Admin/ProviderStore`, `ProviderConfigController`, `ProviderConnectionTester`, `ProviderCatalog`, `test-ai-config-6.sh`).
- **New foundation (`includes/Ai/Platform/`):**
  - `Dialect` — 3 API dialects (anthropic / openai-compatible / gemini) with honest runtime+test flags.
  - `ProviderCatalog` — 15 providers as thin entries pointing at a dialect (incl. Ollama, LM Studio, vLLM, OpenRouter, Groq, Together, Fireworks, DeepInfra, Azure, Mistral, Perplexity, xAI, custom gateway).
  - `ConnectionStore` — **Connection (opaque id)** as the primitive; CRUD, default, routes, bootstrap migration, runtime bridge.
  - `CredentialStore` — one secret store keyed by connection id; the single legacy-Anthropic bridge.
  - `ConnectionTester` — minimal secret-safe tests **by dialect** (covers all providers incl. local/gateway).
- **New `Admin/ConnectionController`** + **rebuilt `views/ai-setup.php`** → connection-centric "AI Connections" platform UI.

## The architecture (one architecture, no future rewrite)
`Feature → Routing → Connection(opaque id){provider, dialect, endpoint, creds, model, tags, scope} → Dialect → Transport → Provider`, all under the unchanged governance chokepoint. Adding the Nth provider = a catalogue row; the Nth environment = a Connection; routing/failover/fleet = additive policies over reserved seams (`scope`, `metadata`, `CredentialStore`, `routes`).

## How 6R answers the 6X verdict
| 6X corner | 6R |
|---|---|
| Identity = type (no envs/multi-key) | **Connection opaque id** — multiple connections per provider (functionally tested). |
| No endpoint field (no local/Azure/gateway) | **`endpoint`/base_url** + openai-compatible dialect → Ollama/LM Studio/vLLM/gateways configurable + testable. |
| No dialect classification (O(N×M)) | **Dialects** — 15 providers, 3 dialects; future transport ~3 adapters. |
| Flat feature→type routing | **Routing seam** referencing connection-ids; policy/scope additive. |
| `if type==='anthropic'` special-casing | **One bridge** (`sync_runtime` + `CredentialStore`). |
| "AI Setup" caps the mental model | **"AI Connections"** platform UX. |

## Honesty (no faked execution)
Independent badges: **CONFIGURED · TESTABLE · USED BY RUNTIME**. Only Anthropic-dialect connections are runtime-used; everything else is "Saved, not used by WPCC runtime yet." Default/route reject non-runtime connections. Nearly every provider is *genuinely* connection-testable (upgrade over Program-6's 3/8).

## Backward compatibility (runtime untouched)
`AnthropicClient` + generators unmodified (`ai-assist` 92/0). A virtual "Anthropic (existing)" connection bootstraps from any legacy key/constant; `sync_runtime()` mirrors the default Anthropic connection into the options the runtime already reads; a constant always wins. The dev env runs on a `WPCC_VISION_API_KEY` constant — verified no fatal.

## Security findings
**No BLOCKER/HIGH** (20-vector audit). Key never echoed/logged/REST-exposed/in-JS; nonce+cap+CSRF; XSS-safe (escaped + validated, `esc_url_raw` endpoints); autoload=no; duplicate omits key; no AI auto-enable; no posture change; Program-4/runtime untouched. LOW (documented): plaintext-option key (masked; `CredentialStore` seam for encryption later); Gemini key-in-query (never logged); runtime-mirror key duplication into the legacy option (constant wins; localized).

## Validation
`test-ai-platform-6r.sh` **38/0** (22 functional). 5A/5B/5C **44/36/23 0** (assertions re-pointed; safety preserved). **ai-assist 92/0** (runtime unbroken). admin-permissions 51/0; security 28/0; registry/capability/MCP 18/61/18 0. Pre-existing env failures only. **Net-new attributable = 0.** Invariants **34/23/40/40/2.5.0** held. **No STOP condition triggered** (options only; no schema/DB_VERSION/registry/MCP/REST/rollback/security/runtime change).

## Remaining limitations (honest, future layers)
- Runtime generation still **Anthropic-only** (openai-compatible + gemini transports are the next localized addition; the moment they ship, all configured connections of that dialect light up — no rewrite).
- Routing policy (failover/cost/latency), per-site/user/team scope, fleet, encrypted secret providers, usage metering — **reserved seams, not built.**
- One credential per connection (sufficient); secret-provider abstraction is future.

## Merge GO / NO-GO: **GO (for review)**
Connection-centric foundation, additive, invariant-preserving, no STOP, no BLOCKER/HIGH, net-new 0, runtime untouched. Supersedes Program-6's config layer (stack: 5A→5B→5C→6→**6R**; 6R deletes 6's config classes). Recommend a human glance at the documented LOW items.

## Deploy GO / NO-GO: **Code-safe; DO NOT deploy from this program.**
No schema/registry/posture change; AI stays off; no real key; security mode unchanged. Deployment is a separate owner-authorized step.

## Verdict
**The foundation is now one architecture that supports unlimited providers, unlimited environments, local models, gateways, and future routing — with no anticipated AI rewrite.** It is something to confidently build the next five years on: adding a provider is a row, adding an environment is a connection, and enabling a new runtime is a single dialect adapter that lights up every connection already configured for it.
