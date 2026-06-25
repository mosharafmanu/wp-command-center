# PROGRAM-6X — Final Verdict

## 9. Migration strategy — incremental, or breaking redesign?

**It can be fully incremental — but ONLY if the conceptual model is realigned before Program-6 accrues real user data. After adoption, the same change becomes a data migration + UX re-education.**

### Why it's incremental if done now
- The product has **zero real users** (Reality Audit: N=1, the author's own site; AI dormant on prod). There is **no saved type-keyed config in the wild** to migrate.
- The realignment is a *shape* change, not a feature build: rekey records by opaque id, add reserved fields (`endpoint`/`extra`/`tags`), add a `dialect` classifier to the catalogue, unify secrets on connection-id. Storage can stay `wp_options`. No runtime rewrite (runtime stays Anthropic-only behind the new shape).
- Everything Program-6 already does (add/test/default/model/feature-map, honest labels, backward-compat with the legacy Anthropic key) maps onto the new shape 1:1 — the legacy Anthropic key/constant becomes a *seed* connection, not a special case.

### The migration path (conceptual, no code)
1. **Connection identity:** records keyed by **opaque id**; `provider_type` becomes a field. (v1 may still create exactly one connection per type — but the *key* is an id, so a second one is possible later with no migration.)
2. **Record fields:** add `endpoint`/`base_url`, `extra{deployment,version,headers}`, `tags[]`, `scope` — *reserved*, may be empty/ignored in v1. Their mere presence prevents the future field-add migration that today blocks Ollama/Azure/local.
3. **Catalogue:** add **`dialect`** (`anthropic` | `openai-compatible` | `gemini`) to each type. Free now; the lever that keeps the future transport at ~3 adapters.
4. **Secrets:** one store keyed by connection-id; read the legacy `wpcc_anthropic_api_key`/constant as a **seed/migration source** for the bootstrap Anthropic connection. Retire the `if type==='anthropic'` special-casing.
5. **Routing:** model the feature map as a **policy referencing connection-ids** (v1 policy = "the one connection"); not a flat `feature→type`.
6. **Naming:** present the concept as **"AI Connections"** (one-connection state still reads like "AI Setup").

**If steps 1–3 land before adoption:** every later layer (transport/router → custom endpoints → routing policy → fleet → secret providers → observability) is *additive*. **No breaking redesign ever.**

**If Program-6 ships type-keyed and gets users first:** steps 1–2 become a forced data migration, the `endpoint` gap blocks the most-requested feature, and at the 3-year mark the routing/fleet/secret layers force a provider-subsystem rewrite — the exact outcome this review exists to prevent.

## 10. Final verdict

### Should Program-6 merge exactly as implemented? → **NO.**
### Should it be redesigned before merge? → **YES — but narrowly: a conceptual *data-model* realignment only, not a feature rebuild and not a throwaway.**

Program-6 is ~90% right: it is honest, safe, additive, invariant-preserving, governance-untouching, and it correctly deferred the runtime. Its **runtime, UX, testing, and safety decisions are fine to ship.** But its **identity/data model is the wrong shape for the 5-year vision**, and the data model is the one thing that is cheap to fix now (zero users) and expensive to fix later (migration + re-education). Merging the type-keyed, endpoint-less, flat-routing model as-is would knowingly plant the seed of the next AI rewrite. That is the one thing this program was chartered to prevent.

**Recommendation: hold merge for a small "Phase 6.1 — model realignment," then merge. Do not ship Program-6's data shape to any user.** If the owner decides speed-to-design-partner outweighs this (defensible, since partners are concierge-onboarded and few), then merge as-is **with an explicit, scheduled commitment** to land 6.1 *before* any non-concierge/self-serve release and before the config format is documented as stable — and accept a one-time migration for the handful of concierge configs.

### Architectural changes required before merge (ONLY architecture — no implementation, no code, no DB changes, no commits, no branches)
1. **Connection-centric identity:** the configuration unit is a **Connection with an opaque id**; provider *type* is a property, not the key. (Enables multi-key, multiple environments, multiple endpoints per provider.)
2. **Endpoint/extra fields reserved on the Connection:** `endpoint`/`base_url` + `extra` (deployment/version/headers) + `tags` (prod/test/cheap/premium) + `scope` — present even if unused in v1. (Unblocks Ollama, LM Studio, Azure, self-hosted, gateways without a future migration.)
3. **API-dialect classification in the catalogue:** each provider type carries a `dialect` (`anthropic` | `openai-compatible` | `gemini`). (Keeps the future transport layer at ~3 adapters instead of O(N×M).)
4. **Secrets unified on connection-id:** single secret store keyed by connection; the legacy Anthropic option/constant is a **seed/migration source**, not a permanent `if type==='anthropic'` special case.
5. **Routing as a policy primitive:** feature/context → policy referencing **connection-ids** with a strategy (v1 strategy = single). Replace the flat `feature → type` map. (Forward-compatible with failover/cost/latency/per-site/per-user without reshaping data.)
6. **Concept/naming aligned to "AI Connections / Environments"** (progressive: one connection still presents as "AI Setup").

### Explicitly NOT required before merge (defer; all additive later)
- Transport interface + multi-provider runtime (Phase 7.x).
- Live custom-endpoint/local-LLM support (8.x).
- Routing strategies / cost / latency / per-site / per-user (9.x).
- Fleet control plane + secret-provider abstraction + least-privilege over connections (10.x).
- Usage/cost observability (11.x).
- Storage migration from `wp_options` to a table — an *implementation* choice that can wait, **provided the conceptual shape is already connection-centric.**

### What must NOT change
- The governance moat (capability → approval → execute → audit → rollback) — untouched, provider-agnostic, the real differentiator.
- The honesty principle (runtime-usable vs stored-only; no faked passes) — keep and extend (e.g., dialect-supported vs not).
- The "AI off by default, BYO key, no auto-enable, no posture change" safety posture.

---

## One-line answer
**Program-6 is good engineering on a data model that will not reach the AI-OS vision. Do a small, free-now conceptual realignment (connection-centric id + endpoint/extra/tags + API-dialect + routing-as-policy) before it has users, then merge — and everything else evolves incrementally, with no future AI rewrite.**
