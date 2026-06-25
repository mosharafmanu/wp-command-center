# PROGRAM-6R — Enterprise Scenarios

Re-running the 6X stress test (100 sites · 20 envs · multi-key · multi-provider · multi-team) against the **new** connection model.

| Requirement | 6X verdict (Program-6) | 6R verdict | How |
|---|---|---|---|
| **20 AI environments** | ❌ failed at #1 (type-keyed) | ✅ **supported** | 20 Connections (opaque ids); functionally tested with multiple connections per provider. |
| **Multiple keys per provider** | ❌ | ✅ | each Connection has its own credential (`wpcc_ai_credentials[id]`). |
| **Prod / Test / Cheap / Premium** | ❌ | ✅ | `tags[]` on each Connection. |
| **Multiple providers** | ⚠ (8 types, bespoke) | ✅ | 15 providers across 3 dialects; adding more = catalogue rows. |
| **Local + gateways** | ❌ (no endpoint) | ✅ config+test | `endpoint`/base_url + openai-compatible dialect (Ollama, LM Studio, vLLM, LiteLLM/Portkey/custom). |
| **100 websites (fleet)** | ❌ | ⚠ **reserved, not built** | `scope` reserved on Connection; fleet control plane is a future layer that assumes this model — no re-model needed. |
| **Multiple teams (least-privilege)** | ❌ | ⚠ **reserved** | `scope` (team) reserved; least-privilege over connections (W3) is a future layer. |
| **Secret governance (no plaintext-at-rest)** | ❌ | ⚠ **abstraction-ready** | one `CredentialStore` is the single seam to swap in encrypted/Vault/env secret providers without touching callers. Today: option (autoload=no, masked, audit-free). |
| **Routing / failover / cost** | ❌ | ⚠ **seam present** | `feature → connection_id` routing primitive; policy/strategy is additive (see ROUTING). |
| **AI usage/cost audit** | ❌ | ⚠ **future** | per-connection metering is a future observability layer (Master Plan §2.3). |

## Honest enterprise position
6R **closes the structural blockers** (environments, multi-key, multi-provider, local/gateway, tagging) that made Program-6 fail at the first enterprise requirement. The remaining enterprise needs — **fleet, least-privilege, encrypted secret providers, routing policy, usage metering** — are **future layers**, and every one of them now has a **reserved field or a single seam** (`scope`, `metadata`, `CredentialStore`, `routes`) so it bolts on **without a data re-model or a rewrite**. That is the whole point of the realignment: enterprise readiness becomes additive, not a redesign.

## The differentiator, still intact
Governance (capability → approval → execute → audit → rollback) is untouched and provider-agnostic — the one thing no AI-config competitor offers. The connection model plugs *under* it.
