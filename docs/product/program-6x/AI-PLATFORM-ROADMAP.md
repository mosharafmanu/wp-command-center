# PROGRAM-6X — AI Platform Roadmap (target architecture + evolution)

> The target the data model must be able to reach without a from-scratch rewrite. **Architecture only — no implementation, no schema prescribed as code.**

## The target conceptual model — "Connections + Routing + Transport + Fleet"

```
                 ┌─────────────────────────────────────────────┐
   wp-admin /    │                 AI HUB                       │
   agent / MCP   │  Connections   Routing   Models   Usage&Cost │
                 └─────────────────────────────────────────────┘
                                   │
        feature emits a generic AI REQUEST (messages + optional schema + context)
                                   │
                          ┌────────▼────────┐
                          │  ProviderRouter │  ← resolves a CONNECTION via POLICY
                          │  (policy: scope, │     (scope: global/site/feature/user/team;
                          │   failover, cost,│      strategy: failover/cost/latency)
                          │   latency)       │
                          └────────┬────────┘
                                   │ chosen Connection {provider_type, dialect, endpoint, creds_ref, model, params}
                          ┌────────▼────────┐
                          │  Transport (by  │  ← ~3 adapters: anthropic | openai-compatible | gemini
                          │  API DIALECT)   │     (openai-compatible covers OpenAI, OpenRouter, Mistral,
                          └────────┬────────┘      Together, Groq, xAI, Ollama, LM Studio, self-hosted…)
                                   │ normalized result
                  ┌────────────────▼────────────────┐
                  │  GOVERNANCE (UNCHANGED MOAT):     │
                  │  capability → approval → execute  │
                  │  → audit → rollback               │
                  └──────────────────────────────────┘
```

### The four primitives
1. **Connection** — the unit of configuration. Opaque id; `{provider_type, dialect, label, tags[], endpoint?, extra{deployment,version,headers}?, credentials_ref, model, params, enabled, scope}`. Replaces "one record per type."
2. **Credential** — referenced by a Connection, resolved through a **Secret Provider** abstraction (`wp_options` default → encrypted → env/constant → external manager). Keys are never the Connection's identity and never stored inline.
3. **Routing Policy** — `context (feature + site + user + team) → ordered [connection_id] + strategy (single|failover|cheapest|fastest)`. Replaces the flat `feature→type` map; "single connection" is just the trivial policy.
4. **Transport** — one interface, adapters keyed by **API dialect** (not brand). New brands that speak an existing dialect = a catalogue entry, zero transport code.

## Phased evolution (each phase additive; no big-bang)

| Phase | Theme | What lands | Blocks merge of P6? |
|---|---|---|---|
| **6.1 — Model realignment (cheap, pre-adoption)** | Get the shape right while users = 0 | Connection = opaque id (not type); add `endpoint`/`extra`/`tags` (reserved, may be unused in v1); add **`dialect`** to the catalogue; unify secrets on connection-id (legacy Anthropic option = migration source); rename concept toward "AI Connections." Storage may stay `wp_options`. | **This is the only thing recommended before/with merge.** |
| **7.x — Transport + Router** | Real multi-provider runtime | `Transport` interface + 3 dialect adapters (Anthropic adapter wraps today's client → back-compat); features emit generic AI requests; central `ProviderRouter` (single-connection policy). Unlocks OpenAI/Gemini/local runtime. | No — additive after 6.1. |
| **8.x — Custom endpoints** | "Use my own model" | `base_url`/`extra` go live → Ollama, LM Studio, Azure, self-hosted, gateways become configurable + (where OpenAI-compatible) runtime-usable via the existing adapter. | No. |
| **9.x — Routing policy** | Failover / cost / latency | Ordered connections + strategy; per-feature already there → add per-site/per-user/per-team scopes; usage/cost/latency telemetry feeds policy. | No. |
| **10.x — Fleet + Secret providers** | Enterprise | Fleet control plane (network/external) over connections+routing across N sites; Secret-provider abstraction (encryption-at-rest / Vault); least-privilege over connections (W3). | No. |
| **11.x — Observability + Governance reporting** | AI OS | Per-connection spend/latency/error dashboards; cost attribution; exportable AI-usage provenance (ties Master Plan §2.3 + §5). | No. |

## Naming / IA evolution
- **v1 (now):** "AI Setup" — fine as the empty-state of one connection.
- **v2:** "AI Connections" (resource list) once >1 connection is possible.
- **v3:** an **"AI" hub** — Connections · Routing · Models · Usage & Cost — under the branded shell. "Setup" disappears as a noun.

## What to deliberately NOT build (scope discipline)
- **No chat UI / chatbot** (Open WebUI / LibreChat lane) — WPCC is governance/ops.
- **No visual flow/graph builder** for routing (Langflow lane) — routing is declarative policy, not a canvas.
- **No model marketplace / reselling** (OpenRouter's business) — WPCC routes the customer's own keys.
- **No content-generation product** (AI Engine's lane) — stay the *operational layer* (Master Plan §1.3).
- **No second ungoverned execution path** — every provider call still funnels through the one governed chokepoint.

## The north star
Adding the 30th provider or the 3rd local LLM should be a **catalogue entry** (`dialect` + `base_url`), and adding "failover for SEO on client X's site" should be a **routing policy row** — never new architecture. If those two statements are true, WPCC never needs another AI architecture rewrite. Today neither is true; after Phase 6.1's model realignment, both become reachable incrementally.
