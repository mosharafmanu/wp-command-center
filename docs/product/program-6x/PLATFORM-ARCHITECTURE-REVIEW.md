# PROGRAM-6X — AI Platform Architecture Review

> **Type:** architecture & product review. **No implementation.** Reviews whether Program-6's architecture can carry WPCC to "the AI Operating System for WordPress." Brutally honest; does not defend prior work.
> **Subject:** Program-6 (`aa40eb2`) — `ProviderCatalog` (8 types), `ProviderStore` (records keyed by provider TYPE, secrets split Anthropic-legacy vs map, single global default, flat feature→type map), `ProviderConnectionTester` (3 live tests), `ProviderConfigController`, rebuilt `ai-setup.php`. Runtime: Anthropic-only via per-feature resolvers.

## 0. One-paragraph verdict (the spine)
Program-6 is a **correct, honest, safe v1 configuration layer** — and the **wrong conceptual data model** for the 5-year vision. Three identity decisions are corners: **(a) a provider record is keyed by its TYPE** (so you can never have two OpenAI configs, two environments, or two keys); **(b) the record has no `endpoint`/`base_url` field** (so Ollama, LM Studio, Azure OpenAI, self-hosted, and enterprise gateways — the most-requested "use my own model" features — are literally unconfigurable); **(c) routing is a flat `feature → type` map** (no failover, cost, latency, per-site, or per-user). None of these is a *runtime* problem (Program-6 wisely deferred runtime). They are *data-shape* problems, and data shape is the single most expensive thing to change after real users exist. The product has **zero real users today**. Therefore the disciplined move is to fix the conceptual model now, while it is free, not after a design partner has saved configs in the type-keyed format. The runtime, transport, fleet, and naming layers can — and should — evolve incrementally and need not block merge.

---

## 1. Provider Architecture — provider-centric vs environment-centric

**Should WPCC remain provider-centric? → No. It must become connection/environment-centric.**

Program-6's primitive is a *provider type* (`anthropic`, `openai`). The 5-year primitive must be a **Connection** (a.k.a. Environment): *a configured endpoint you can call* = `{ id (opaque), provider_type, label, credentials_ref, endpoint, extra (deployment/version/region/headers), model, params, tags, scope }`.

Why the type-keyed model breaks:
- **"One provider = one configuration" does NOT scale.** Real agencies need: a *production* Claude key and a *cheap* Haiku-only key; a client-billed OpenAI key separate from the agency's; a *test* environment that can't touch prod. Type-keying makes all of these collide on one record.
- **Multiple environments per provider are mandatory**, not optional: Production / Staging / Cheap / Premium / per-client. This is exactly the "AI Environments" concept the WP-AI market already expects (AI Engine ships it).
- **Some providers need more than a key.** Azure OpenAI = endpoint + deployment + api-version. Ollama/LM Studio = `base_url` (often `localhost:11434`). Self-hosted/enterprise gateway = base_url + custom headers. **Program-6's record has nowhere to put these.** This is the hardest, most concrete corner: a whole class of providers is unrepresentable.

**Recommendation:** the primitive is a **Connection with an opaque id**; "provider type" becomes a *property* of a connection, not its identity. Tagging (`prod`/`test`/`cheap`/`premium`) and scope are properties, not new top-level concepts. This is the one change that, if not made before adoption, forces a data migration.

## 2. Runtime Architecture — does routing scale?

**Program-6 has no runtime router (Anthropic-only, by design). The *future* router is where the real debt lives — and the catalogue is missing the one classifier that prevents an O(N×M) explosion.**

- Today: each feature (alt-text, SEO, content) has its own resolver that registers an *Anthropic* provider implementing that feature's interface. Adding a provider to the runtime = **M feature-provider classes per provider** (vision, seo, content, …). With N providers × M features this is O(N×M) bespoke classes. **That is the wrong long-term shape** and the prompt is right to worry a naive `ProviderRouter` becomes too complex.
- The fix the industry already discovered: **most providers speak one of ~3 API dialects** — `anthropic`, `openai-compatible` (OpenAI, OpenRouter, Mistral, Together, Groq, xAI, **Ollama, LM Studio, most self-hosted**), and `gemini`. So the transport layer needs **~3 adapters, not 8+**. Adding "Groq" or "a local Ollama" becomes a *catalogue entry* (`dialect: openai-compatible` + `base_url`), **zero new transport code**.
- Therefore:
  - **Abstract transports behind one `Transport` interface** (`send(messages, params) → normalized result`), with adapters keyed by **API dialect**, not by provider brand.
  - **Move feature routing out of the per-feature resolvers** into a single **ProviderRouter**: a feature emits a generic *AI request* (messages + optional output schema); the router resolves a Connection (via policy); the dialect transport executes; a normalizer returns a uniform result. Features depend on "an AI request bus," not on `AnthropicClient`.
- **Critical catalogue gap:** Program-6's `ProviderCatalog` classifies providers by brand and runtime/test flags but **does not record API dialect**. Adding a `dialect` field to the catalogue is the cheap, forward-compatible move that makes the future transport layer ~3 adapters instead of 8. This belongs in the pre-merge realignment.

**Will ProviderRouter become too complex?** Only if routing policy is entangled with transport. Keep them separate: Router = *which connection* (policy); Transport = *how to call it* (dialect). That separation keeps each simple.

## 3. UX Architecture — is "AI Setup" the right concept?

**"AI Setup" is right for an SMB one-time-config mental model and wrong for a platform.** "Setup" implies a thing you finish once. A platform manages an ongoing *resource*.

- **Better concept: "AI Connections"** (each connection = a configured provider endpoint you manage over time), with **"AI Environments"** as the grouping/tagging layer when multiple connections exist. "AI Configuration" is more accurate than "Setup" but still settings-flavored; "Connections/Environments" conveys managed resources.
- First-time customer comprehension (validated by Program-5C's work): a newcomer must still understand "what is an AI provider/agent." Keep the plain-language onboarding from 5A–5C **on top of** a connections model — the model can be advanced while the *first* experience stays "add one provider, paste a key." Progressive disclosure: one connection looks like "AI Setup"; many connections reveal "Environments."
- **v3.0 navigation (concept):** an **"AI" hub** with: **Connections** (CRUD + test + status), **Routing** (which connection each feature/site/user uses, with failover/cost policy), **Usage & Cost** (per-connection spend/metering), **Models** (per-connection). "Setup" disappears as a noun; it becomes the empty-state of "Connections."

## 6. Product Vision — "Anthropic settings" or "AI Operating System"?

**Program-6 feels like a competent "AI providers settings page," not yet an "AI Operating System."** That is *appropriate for where the product is* (pre-PMF, single site) but the architecture must not *cement* the settings-page ceiling.

- What makes it feel like settings, not an OS: single global default; flat feature→type; no environments; no routing/failover/cost; no per-site/per-user; runtime locked to one brand; the pervasive `if type === 'anthropic'` special-casing.
- What an "AI OS" requires (and the model must be able to grow into): **Connections** as managed resources, **Routing policy** (failover/cost/latency/scope), **Fleet** (one control plane over 100 sites), **Observability** (usage, cost, latency, errors per connection), **Governance** (which already exists — approval/audit/rollback is WPCC's actual moat and the thing competitors lack).
- The good news: WPCC's *differentiator* (governed actions) is orthogonal to the provider model and already strong. The provider model just must stop being the ceiling. Get the Connection primitive right and the OS vision is reachable; keep type-keying and it is not.

## 7. Industry lessons (concepts, not code)

| Product | Lesson to ADOPT | What to AVOID |
|---|---|---|
| **AI Engine (WP)** | Ships **"AI Environments"** — multiple env records (provider+key+settings), features pick an environment. Direct proof the WP-AI market expects environment-centric, not type-centric. | Its sprawl of features (chatbots, embeddings UI, image gen) — WPCC should stay in the governance/ops lane (Master Plan §1.3), not become a content/chat product. |
| **OpenRouter** | **One key, many models, with routing/fallback/cost/latency preferences as first-class.** Routing-policy + fallback is an *expectation*, not a luxury. | Becoming a model *marketplace*/reseller — WPCC routes the customer's own keys, it doesn't broker models. |
| **LibreChat / Open WebUI** | **"Endpoints/Connections" abstraction with `base_url`** — custom OpenAI-compatible endpoints (Ollama, LM Studio, self-hosted) are first-class. Confirms base_url + dialect is table-stakes. | They are **chat applications** — WPCC is an operations/governance plane, NOT a chat client. Do not build a chat UI. |
| **AnythingLLM** | Pluggable "LLM provider" interface where adding a provider is config + a thin adapter — the dialect-adapter pattern. | Per-workspace model sprawl complexity; keep routing centralized. |
| **Langflow** | Visual flow/graph *concept* is powerful for power users. | **Do NOT build a visual flow builder** for provider routing — massive over-engineering for WPCC's job; routing should be declarative policy, not a canvas. |

**The single most important industry lesson:** classify providers by **API dialect** and ship a **base_url/custom-endpoint** field. Together these collapse the provider explosion (one OpenAI-compatible adapter covers ~8 providers + all local LLMs) and unlock the most-demanded feature ("use my local/Ollama/Azure model") — both of which Program-6 currently cannot express.

---

## Summary of architectural findings
1. **Identity is wrong:** records keyed by provider *type* → no multi-key, no environments. **(corner — fix before adoption)**
2. **No `endpoint`/`extra` field:** Ollama/LM Studio/Azure/self-hosted/gateways unrepresentable. **(corner — fix before adoption)**
3. **No API-dialect classification:** future transport becomes O(N×M) instead of ~3 adapters. **(cheap catalogue fix now)**
4. **Routing is a flat feature→type map:** no failover/cost/latency/per-site/per-user. **(primitive should be a routing *policy* referencing connection ids)**
5. **Split/anthropic-special-cased secrets:** `if type==='anthropic'` will metastasize. **(unify on connection-id; legacy option = migration source)**
6. **Naming caps the mental model:** "AI Setup" → "AI Connections/Environments." **(incremental)**
7. **Runtime/transport abstraction absent:** acceptable now (deferred), but features must eventually depend on a `Transport` interface + central router, not `AnthropicClient`. **(incremental, additive)**

Findings 1–3 are the ones that turn into a *data migration* if shipped to users; 4–7 are incrementally additive.
