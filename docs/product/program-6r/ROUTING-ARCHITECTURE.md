# PROGRAM-6R — Routing Architecture

## The chain (designed now, even though v1 is simple)
```
Feature  →  Routing  →  Connection  →  Dialect  →  Transport  →  Provider
```
- **Feature** emits the intent (SEO meta / alt text / AI content).
- **Routing** resolves a **Connection** (v1: a `feature → connection_id` map; the seam where failover/cost/latency/scope policies will live).
- **Connection** carries provider + dialect + endpoint + creds + model.
- **Dialect → Transport** executes (v1 runtime: Anthropic only).
- Everything still funnels through the unchanged governance chokepoint (capability → approval → execute → audit → rollback).

## v1 routing (implemented, honest)
- `ConnectionStore::routes()` = `feature → connection_id`, resolving to the **default** when unmapped.
- `set_route(feature, id)` **only accepts a runtime-usable connection** (Anthropic-dialect, configured). Stored-only/testable connections are **not offered** in the routing UI — you cannot route a feature to something WPCC can't run. Functionally tested.
- Single global **default connection** (`set_default` rejects non-runtime connections).

## Why this is forward-compatible (no future re-model)
- The routing primitive already references **connection-ids** (not provider types), so when multiple runtime-usable connections exist (e.g. after the openai-compatible transport ships), per-feature selection already works.
- A **Routing Policy** (ordered connection-ids + strategy: `single | failover | cheapest | fastest`, scoped to site/user/team) is an *additive* upgrade of the same `routes` structure — v1's `feature → id` is just the `single` strategy. No data reshape.
- **Scope** is reserved on the Connection, so per-site/per-user/per-team routing bolts on without migration.

## What is deliberately NOT built (scope discipline)
- No failover/cost/latency engine (needs telemetry + multiple runtime providers — future).
- No per-site/per-user routing UI (needs fleet/scope — future).
- No visual routing builder (Langflow lane — explicitly avoided).
v1 ships the *seam*, honest and tested; the policies are additive.
