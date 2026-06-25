# PROGRAM-6X — Future Scalability Audit

## 5. Future-feature compatibility (can Program-6 evolve naturally, or rewrite?)

> Legend: **Natural** = additive, the current model grows into it. **Field-add** = needs a record-shape addition (migratable, but blocked today). **Identity-blocked** = blocked by the type-keyed identity (needs the Connection primitive). **New-layer** = needs a whole subsystem the current model doesn't anticipate.

| Future feature | Verdict | Why |
|---|---|---|
| **Ollama** (local) | **Field-add (blocked today)** | Needs `base_url` (e.g. `localhost:11434`) + dialect `openai-compatible`. Record has no endpoint field. |
| **LM Studio** (local) | **Field-add (blocked today)** | Same — `base_url` + OpenAI-compatible dialect. |
| **Self-hosted endpoints** | **Field-add (blocked today)** | `base_url` + optional custom headers/auth scheme. |
| **Azure OpenAI** | **Field-add (blocked today)** | Needs endpoint + deployment name + api-version — three fields the record lacks. (Already in the catalogue as "stored only" but **cannot actually be configured** without these.) |
| **Enterprise gateways** (LiteLLM/Portkey/internal proxy) | **Field-add + dialect** | `base_url` + headers; usually OpenAI-compatible. |
| **OpenRouter** | **Natural-ish** | Single key + base_url; already catalogued. Conceptually overlaps WPCC's own future routing. |
| **Provider failover / automatic fallback** | **New-layer** | Needs routing *policy* = ordered list of connection ids + strategy. Flat `feature→type` cannot express "primary then fallback." |
| **Cost routing** | **New-layer** | Needs per-connection cost metadata + a policy that picks cheapest acceptable. No cost model today. |
| **Latency routing** | **New-layer** | Needs latency observability + policy. No telemetry today. |
| **Per-feature provider selection** | **Natural (already exists)** | Program-6 has `feature→type`. Should retarget to connection-id. |
| **Per-user provider selection** | **New-layer** | No user scope anywhere. Needs scope dimension on routing. |
| **Per-site provider selection** | **New-layer (fleet)** | Single-site options can't express 100-site routing. Needs a fleet/network control plane. |

**Conclusion:** *No single future feature requires throwing Program-6 away* — but **the most-demanded near-term feature (local/Ollama/Azure "use my own model") is blocked by a missing field**, and **the headline platform features (failover/cost/latency/fleet) require new layers the flat routing model does not anticipate**. The model evolves naturally **only if** the Connection primitive + endpoint field land before users; otherwise each of these arrives with a migration tax.

## 8. Technical-debt forecast

### 1 year (SMB / first design partners)
- **Pain:** the first "can I use my local LLM / Ollama / our Azure endpoint?" request — and the answer is "no, the record can't hold an endpoint." This is the most likely *first* feature request from technical partners and Program-6 can't serve it without a field-add + (for two endpoints) the identity change.
- **Pain:** `if ('anthropic' === $type)` special-casing spreads as OpenAI/Gemini get runtime-wired; every new branch is a place to leak a secret or diverge behavior.
- **Still fine:** single-key SMB usage, the honest labels, the testing. No crisis — but the corners are visible.

### 3 years (agencies, multi-environment)
- **Pain → blocking:** environments (prod/test/cheap/premium), multi-key, failover, cost routing all land at once — and the flat `feature→type` + single global default cannot express any of them. This is where a *forced* routing-layer build happens.
- **Pain → blocking:** **fleet.** Agencies with 100 sites cannot manage 100 separate options blobs by hand. Per-site routing has no home. Without a control plane, WPCC is "a settings page you repeat 100 times."
- **Pain → blocking:** **enterprise security review** rejects plaintext keys in `wp_options`; demands encryption-at-rest / external secret manager / env injection. No secret-provider abstraction exists.

### 5 years (AI Operating System)
- **Becomes impossible without rework:** being an "AI OS" *at all* — multi-environment, fleet-wide routing, failover, cost/latency policy, observability — none of which the type-keyed, single-default, no-endpoint, options-only model can host. If the conceptual model is not connection-centric by then, this is a from-scratch AI-subsystem rewrite (exactly what this program is meant to prevent).
- **What stays valuable forever:** WPCC's governance moat (approval/audit/rollback/capability) — it is provider-agnostic and is the real differentiator. The provider layer must be rebuilt *under* it without disturbing it.

### Debt summary
| Horizon | Severity if model unchanged | Nature |
|---|---|---|
| 1 yr | Medium | Field-add + identity blocks the first real request (local/Azure); special-casing spreads |
| 3 yr | High | Forced routing-layer + fleet + secret-abstraction builds; flat model can't express them |
| 5 yr | Critical | "AI OS" unreachable; from-scratch AI-subsystem rewrite — the outcome this review exists to prevent |

**The cheapest possible intervention is now (zero users): fix the *conceptual model* (connection identity + endpoint/extra + dialect), defer the *features* (transport/router/fleet/secret-provider) to phased, additive work.**
