# WP Command Center — Master AI Platform Blueprint

> **Type:** architecture & product planning. **No code, no commits, no implementation.** The long-term reference for WPCC's platform shape.
> **Date:** 2026-06-25 · **Author hat:** Principal Platform Architect + Product.
> **Horizon:** optimise for the next 5–10 years, not the current build. Opinionated by mandate.
> **Grounded in:** Programs 5A→10, RC-1/RC-2, concierge-beta, PRODUCT-MASTER-PLAN. Current invariants 34 ops · 23 caps · 40 catalogue · 40 MCP tools · DB 2.5.0.

---

## 1. Executive Summary

WPCC has, almost by accident, built the right thing and described it as three different things. Under the hood there is **one governed execution engine** (Operation Registry → capability gate → approval → execute → audit → telemetry → event bus → rollback) and **three ways intent reaches it**: built-in AI, AI clients over MCP, and remote apps over REST. The product's confusion is not architectural — it is *narrative and navigational*. Users meet "AI Setup", "Connect an AI Agent", "Tokens", "Operations", "Mission Control" as a flat list of features and cannot form a mental model.

The fix is a single, durable frame: **Three Doors, One Engine.** Everything a user configures is either **a door** (a way for an actor to send intent) or **the engine's policy** (what's allowed, what needs approval, what's reversible). Navigation, onboarding, terminology, and the website should all be re-expressed in those terms.

The one genuine architectural debt that must be named honestly: **the connection layer is provider-agnostic but the execution runtime is Anthropic-only.** Experience 1 promises "connect any provider → use AI"; today only Anthropic can actually *generate*. The blueprint defines the path to provider-agnostic *execution* (a Generation Adapter per dialect) as the highest-value next investment, because it is the difference between the positioning being true and being marketing.

Recommendation: keep the engine (it is excellent and production-proven), **re-architect the information architecture and naming around Three Doors**, and **close the provider-execution gap**. Everything else is sequencing.

---

## 2. Product Vision (one paragraph)

**WP Command Center is the governed action layer for WordPress.** It lets any actor — a built-in AI using your chosen provider, a professional AI client over MCP, or a remote application over a REST API — propose and perform real work on your site, while WPCC guarantees the same four things every time: you can *see* what was done, *approve* before it happens, *undo* it afterwards, and *audit* it forever. Other tools help you add AI to WordPress; WPCC is the layer that makes AI **safe to act**.

---

## 3. Mental Model — "Three Doors, One Engine"

```
            THE THREE DOORS  (how intent enters)
   ┌─────────────────┬──────────────────┬───────────────────┐
   │  DOOR 1          │  DOOR 2          │  DOOR 3           │
   │  Built-in AI     │  AI Clients      │  Remote Apps      │
   │  (you + provider)│  (MCP)           │  (REST + token)   │
   │  SEO·Alt·Content │  Claude·Cursor·  │  ChatGPT Connectors│
   │                  │  Codex·Continue  │  SaaS·automation  │
   └────────┬─────────┴────────┬─────────┴─────────┬─────────┘
            │ compiles intent into a registered Operation
            ▼                  ▼                   ▼
   ┌──────────────────────────────────────────────────────────┐
   │            THE ENGINE  (what always happens)               │
   │  OPERATION REGISTRY  — 1 catalogue of everything WPCC can do│
   │       ↓ capability gate (least privilege)                  │
   │       ↓ approval (security mode: developer/client/enterprise)│
   │       ↓ execute (with pre-image capture)                   │
   │       ↓ audit + telemetry + event bus                      │
   │       ↓ rollback (field-scoped, drift-aware)               │
   └──────────────────────────────────────────────────────────┘
                              │
                              ▼
                         WORDPRESS
```

**The single sentence every user should internalise:** *"However AI reaches my site — built in, as a client, or as an app — it goes through the same gate, and I can always approve, watch, and undo it."*

Consequences for the whole product:
- **Doors are interchangeable and additive.** A solo blogger may only ever open Door 1. An agency opens 1 and 2. A platform vendor opens 3. None of them should be forced to understand the others.
- **The engine is the moat, not the AI.** Anyone can call an LLM. The governed engine applied *uniformly across all three doors* is the defensible product.
- **Configuration has exactly two kinds:** *door config* (providers, clients, tokens) and *engine policy* (capabilities, approval mode, reversibility). The IA should mirror this split precisely.

---

## 4. Information Architecture (recommended)

The current nav (Overview · Operate · Audit · Access · Connect) is close but blurs the doors and scatters policy. Recommended top level — **four sections that map 1:1 to the mental model**:

### A. **Mission Control** (was: Overview / command-home)
The cross-door landing. What needs attention (pending approvals, failures from *any* door), recent activity, provider/client/token health, readiness. One screen that answers "is everything okay, and what wants me?"

### B. **Connect** — *the three doors* (one tab each; this is the heart of the IA change)
| Tab | Was | Experience | Purpose |
|---|---|---|---|
| **AI Providers** | "AI Setup" | 1 | Add provider keys, pick models (recommended + discovered + custom), route features to a connection. *"WPCC's own AI."* |
| **AI Clients** | "Connect an AI Agent" | 2 | One-click MCP setup recipes for Claude Desktop, Cursor, Codex, Continue, Windsurf, … Each issues a scoped token. *"Let your AI assistant act here."* |
| **API & Connectors** | (implicit in Tokens) | 3 | Base URL + scoped tokens for ChatGPT Connectors, SaaS, automation, custom apps. Copy-paste connection details, examples. *"Let software act here."* |

> File Access is **not** a door — it is a *capability*. It moves into engine policy (Access & Security), surfaced inside scopes.

### C. **Operate** — *act through and watch the engine*
| Tab | Was | Purpose |
|---|---|---|
| **Operations Center** | Operations Center | The live heartbeat across all three doors — running/completed/failed, review & undo. |
| **Built-in AI** | flag-gated Alt/SEO/Content tabs | The *output* surface of Door 1: run SEO, Alt text, Content under governance. Appears once a provider is connected. |
| **Approvals** | Approvals | Pending governed actions from any door, one queue. |
| **Operations** | Operations explorer | The read-only catalogue of *everything WPCC can do* (the registry) — the contract all doors share. |

### D. **Govern** — *the engine's policy + memory* (merges Audit + Access)
| Tab | Was | Purpose |
|---|---|---|
| **Change History** | Audit › Changes | Review & undo every change, any actor. |
| **Access & Security** | Access (Tokens, Security Mode) + Capabilities | The shared policy layer: security mode, capability matrix, **scoped tokens** (the keys Doors 2 & 3 issue), file/scope grants. |
| **Diagnostics** | Audit › Diagnostics/Patches/Intelligence | Health, patches, site intelligence. |

**Why this is better:** a user can always answer "where am I?" — *Connect* (set up a door), *Operate* (do/see work), *Govern* (set the rules / review history). Tokens live in one place (Govern › Access) but are *issued in context* from Doors 2 & 3, resolving today's "tokens are scattered" problem.

---

## 5. Onboarding (ideal)

Onboarding must be **door-aware and progressive** — never show Door 2/3 to someone who wants Door 1. Drive off a single question on first run.

**First install → one fork:**
> *"How will AI work on this site?"*
> ① **I'll use WPCC's built-in AI** (pick a provider) → Door 1 path
> ② **I'll connect my AI assistant** (Claude, Cursor, ChatGPT…) → Door 2 path
> ③ **I'm connecting an app or service** → Door 3 path
> *(Choosing one does not hide the others; it just orders the journey.)*

**Door 1 — first generated content (the "magic moment"):**
1. Add a provider key → **Test** (real, shows discovered models).
2. Confirm Client-safe mode is on (governed by default).
3. Enable one feature (Alt text or SEO).
4. Generate on one item → **review** → **approve** → **apply** → see it in Change History → **undo** once.
Target: a real, reversible AI result in < 5 minutes, on one item, fully governed.

**Door 2 — first AI client:**
1. Pick a client (Claude Desktop / Cursor / …) → WPCC shows the exact config + issues a scoped token.
2. Client connects (MCP). WPCC confirms "Claude Desktop connected · read-only" in Mission Control.
3. First client-initiated operation lands in **Approvals**; admin approves; it executes and is auditable.

**Door 3 — first API token / operation:**
1. Create a scoped token; copy Base URL + token + a ready example request.
2. App authenticates; a read call works immediately; a write call enters Approvals (per mode).
3. The call appears in Operations Center + Change History.

**Universal onboarding invariant:** the *first write through any door* is a governed, reversible, audited action the user explicitly experiences. That is the product's first impression, deliberately.

---

## 6. Provider Architecture (Door 1)

Keep the connection-centric model from 6R (it is right and future-proof). Make execution match the promise.

- **Connection lifecycle:** create → store key (CredentialStore) → **test** (real `/models`, captures discovered models) → set default → route features → enable/disable → rotate key → delete. Already implemented; keep.
- **Dialects (transports):** anthropic · openai-compatible · gemini cover ~16 providers incl. local (Ollama/LM Studio/vLLM), gateways (OpenRouter/Groq/…), Azure, custom. Keep.
- **Discovery:** curated *recommended* models at setup; **discovered** models from the test's `/models` response after save; **custom** always. Providers without a model list keep recommended + custom. Keep (just shipped).
- **Routing:** feature → connection map; falls back to the default connection. Keep, and **generalise to provider/model/cost routing** (below).
- **THE GAP (must close): execution is Anthropic-only.** `Dialect::runtime_supported` is true only for Anthropic. The fix is a **Generation Adapter** interface (`generate(messages, model, opts): result`) with one implementation per dialect, mirroring how the *test* path already speaks three dialects. When the OpenAI/Gemini generation adapters exist, `runtime_supported` flips on per dialect with **zero UI change** (the routing/feature UI already renders from `runtime_usable`). This is the single highest-leverage investment in the platform: it makes Experience 1's core promise true for every provider, not just one.
- **Fallback strategy:** routing should support an ordered list per feature (primary → secondary) with automatic failover on transport error / rate limit, and an honest "degraded" state in Mission Control. Design the data model now (a route is a list, not a scalar); implement when multi-provider execution lands.

---

## 7. AI Client Architecture (Door 2 — MCP)

- **One protocol surface:** `McpRestApi` exposes the **same Operation Registry** as everything else — an MCP "tool" is a thin projection of a registered Operation. New capability in the registry ⇒ new MCP tool automatically. Keep; this is the right design (STEP 87 bridge).
- **Client = a recipe, not a code path.** Every client (Claude Desktop, Cursor, Codex, Continue, Aider, Roo, Windsurf, Gemini CLI, OpenCode) is the *same* MCP endpoint + a *client-specific config snippet* + a *scoped token*. WPCC should ship a **Client Catalogue** (like the Provider Catalogue): each entry = {name, config template, transport (stdio/http), docs link}. Adding a client = a catalogue row, no engine change.
- **Auth:** token-only (no cookies), scoped. The token *is* the client's identity; revoking it disconnects exactly that client. Per-client tokens (not one shared token) so revocation and audit are per-client.
- **Connection method matrix (design intent, not implementation):**
  - **Claude Desktop / Cursor / Continue / Windsurf / Cline / Roo** → MCP (stdio or HTTP) using the issued token.
  - **Codex / Aider / OpenCode / Gemini CLI** → MCP over HTTP (or stdio bridge) with the token.
  - **ChatGPT (as an MCP client / Connector)** → see Door 3 (it connects as a remote app/connector, not a desktop MCP client) — *this distinction matters and the UI must not blur it.*
- **Future clients:** any MCP-speaking tool is a catalogue entry; the protocol adapter is shared. The architecture must track MCP spec evolution behind the `McpRestApi` adapter so spec churn never reaches the engine.

---

## 8. REST / Token Architecture (Door 3 — Remote Apps)

- **Base URL + Access Token** is the entire contract. `AiAgent/RestApi` under `wp-command-center/v1`. Keep.
- **Tokens** are the shared credential for Doors 2 **and** 3 — one token model, issued in two contexts. Manage centrally in Govern › Access; issue in-context from each door.
- **Scopes today are coarse (`read_only` / `full`).** This is the second real debt. Evolve toward **capability-scoped tokens**: a token grants a *subset of the 23 capabilities* (e.g. "content.read + seo.write", no user/destructive ops). This makes Door 3 safe for narrow integrations and is the natural unit because the engine already gates on capabilities. Design now; migrate `full`→"all capabilities", `read_only`→"read capabilities" with no breakage.
- **Security:** HMAC-hashed at rest, shown once, per-token audit, expiry, revoke. Keep. Add per-token **last-used + source** visibility (partly present via telemetry).
- **Future compatibility:** the REST surface must stay a thin adapter over the Operation Registry, exactly like MCP. ChatGPT Connectors, webhooks, and future protocols become new *adapters*, never new engines. Versioned namespace (`/v1`) protects consumers.

---

## 9. Built-in AI (Door 1 execution)

- **Three layers of routing, all data-driven:**
  1. **Feature routing** — which *connection* powers each feature (SEO, Alt, Content). Exists.
  2. **Provider routing** — the connection's provider/dialect. Exists (config); execution gap per §6.
  3. **Model routing** — recommended/discovered/custom per connection. Exists.
- **Future feature routing:** new built-in features (titles, summaries, translations, internal linking, image gen…) register as **Operations** and plug into the same feature-routing map — no new routing system.
- **Fallback strategy:** ordered routes + automatic failover (designed in §6). Until multi-provider execution exists, fallback is within Anthropic models only; the data model should already be list-shaped.
- **Governance is non-negotiable for built-in AI:** every generated change is a *proposal* → review → approval (per mode) → apply → rollback. Built-in AI is not a shortcut around the engine; it is Door 1 *into* it.

---

## 10. Unified User Journey

```
DOOR 1 — Built-in AI
  Connect provider → Generate → Review → Approve → Apply → (verify) → Undo
        (one engine path: proposal → capability+approval → execute → audit → rollback)

DOOR 2 — AI Client (MCP)
  Connect client (token) → Client inspects → Plans → Proposes operation
        → Approve (admin) → Execute → Audit → Rollback
        (same engine path; the only difference is intent originated in Claude/Cursor/…)

DOOR 3 — Remote App (REST)
  Authenticate (base URL + scoped token) → Operate (read freely; writes gated)
        → Approve (per mode) → Execute → Audit → Rollback
        (same engine path; intent originated in software)
```
**The point of drawing all three identically:** they ARE identical past the door. Inspect/plan may live in the client (Door 2) or be implicit (Door 3), but capability gate → approval → execute → audit → rollback is one code path (`OperationExecutor`). This is why the engine is the moat and why a single audit/rollback/approval UX serves all three.

---

## 11. Product Positioning

- **One sentence:** *WP Command Center is the governed action layer for WordPress — it makes everything AI does to your site safe to approve, watch, and undo.*
- **One paragraph:** *AI can now write, edit, and operate WordPress — through built-in features, assistants like Claude and Cursor, or your own software. WP Command Center is the layer that makes all of it safe: one console where any AI provider, any AI client, and any app act through the same gate, with human approval before changes, a full audit trail, and one-click rollback. You stay in control no matter how the AI connects.*
- **One-page overview (structure):** the Three Doors diagram · "the engine is the moat" · governed-action guarantees (approve / watch / undo / audit) · provider-agnostic + client-agnostic · honest limits (what's certified, what's not) · who it's for (agencies, technical owners, platforms).
- **Anti-positioning (say what it is NOT):** not a chatbot, not an MCP server you have to run, not an OpenAI/Anthropic plugin, not "AI content spam." It is the *control plane*.

---

## 12. Terminology Review

| Current | Verdict | Recommended | Why |
|---|---|---|---|
| **AI Setup** | ✗ rename | **AI Providers** | "Setup" is vague and collides with "Connect an AI Agent". "Providers" names Door 1 exactly. |
| **AI Connections** | ◑ keep as sub-term | **Connections** (within AI Providers) | Fine as the noun for a configured provider instance. |
| **Connect an AI Agent** | ✗ rename | **AI Clients** | "Agent" is overloaded; users connect *clients* (Claude, Cursor). "Agent" implies autonomy WPCC governs, not provides. |
| **Providers / Clients** | ✓ adopt as the two external nouns | keep | Clean, industry-aligned, mutually exclusive. |
| **Tokens** | ◑ reframe | **Access Tokens** (under Access & Security) | Keep the noun; stop treating it as a top-level feature — it's the key to Doors 2 & 3. |
| **Mission Control** | ✓ keep | **Mission Control** | Strong, accurate, ownable name for the landing. |
| **Operations Center** | ✓ keep | **Operations Center** | Good; the live activity surface. (Mission Control = overview; Operations Center = live feed — keep the distinction but make it obvious.) |
| **Operations (explorer)** | ◑ clarify | **Capabilities** or **What WPCC can do** | "Operations" overloads with "Operations Center". The catalogue is really the *capability contract*. |
| **Runtime (advanced)** | ✗ retire from primary nav | fold into Diagnostics | Legacy dashboard; not a user concept. |
| **File Access** | ✗ demote | a *scope/capability* under Access & Security | Not a door; it's a permission. |

Naming north star: **a name should tell you which door you're at or which engine-policy you're setting.** Anything that doesn't is a candidate for renaming.

---

## 13. Design Principles (permanent)

1. **Approval before execution.** Nothing irreversible happens without the configured approval.
2. **Rollback by default.** If it can't be undone, it must be loudly labelled and confirmed.
3. **Never fake data.** Unknown is shown as "not tracked yet" / "unknown" — never invented (no fake models, jobs, cost, health).
4. **Always explain why.** Every limit, exclusion, or disabled control states its reason in plain language (e.g. "healthy, but WPCC can't run it yet").
5. **Provider-agnostic.** No provider is privileged in the model; the only differences are honest capability flags.
6. **Client-agnostic.** Any MCP client / any app is a catalogue recipe, not a special case.
7. **One engine, many doors.** Every actor flows through the same capability/approval/audit/rollback path; no door bypasses the gate.
8. **The registry is the contract.** Capabilities live once in the Operation Registry; all doors project from it.
9. **Least privilege.** Capabilities and (future) capability-scoped tokens default to the minimum.
10. **Progressive disclosure.** A user sees only the door(s) they chose; advanced surfaces stay out of the way until needed.
11. **Honest limits over impressive lies.** Ship "not certified" / "not usable yet" rather than imply coverage that isn't real.
12. **Adapters insulate the engine.** Protocol/provider/spec churn lives in adapters; the engine never learns a vendor's name.
13. **Single source of truth per fact.** Audit is authoritative; telemetry/event-bus observe; the UI renders, never re-derives.

---

## 14. Future Growth (how the architecture expands without redesign)

- **New AI provider:** a Provider Catalogue row (config) + a Dialect if novel + a **Generation Adapter** (execution). UI adapts automatically (metadata-driven wizard).
- **New AI client:** a Client Catalogue row (config template + transport). Shared token/MCP adapter; zero engine change.
- **New AI capability:** a new Operation in the Registry → instantly available to all three doors (built-in feature, MCP tool, REST endpoint) + auto-governed (capability, approval, audit, rollback).
- **Enterprise:** capability-scoped tokens, SSO/role mapping onto capabilities, approval policies per role, signed audit export. All extensions of existing primitives.
- **SaaS / control plane:** the engine is per-site; the *registry, policies, telemetry, and audit* can be aggregated to a central plane. Door 3 (REST) is already the integration seam a control plane would use.
- **Multi-site / fleet:** the engine must never assume single-site state. A fleet is N engines reporting to one Mission Control via the event bus / REST. Design the event/telemetry schema with a `site_id` dimension now (even if unused) so fleet is additive, not a rewrite.
- **Cloud / hosted:** WPCC-as-a-service is "the engine + control plane, hosted." Nothing in the door/engine split prevents it.

The test for every future feature: *does it add a door, extend the engine's policy, or register an operation?* If it's none of those, it probably doesn't belong in WPCC.

---

## 15. Migration Strategy (no breaking changes)

- **Renames are nav-only + legacy slugs.** AppShell already redirects legacy slugs (`wpcc-ai-setup`→connect/setup, etc.). Add redirects for any renamed page; keep old URLs working. Zero capability/route changes.
- **REST/MCP contracts untouched.** Namespace, tools, scopes stay; capability-scoped tokens are *additive* (old `full`/`read_only` map forward).
- **Provider execution is additive.** Generation Adapters light up dialects; nothing that works today (Anthropic) changes.
- **Feature flags persist.** Built-in AI features stay flag-gated through migration; the "Built-in AI" area simply gives them a coherent home.
- **Phased rollout:**
  1. **Narrative + IA** (rename, regroup into Connect/Operate/Govern, onboarding fork) — pure UX, no engine risk.
  2. **Capability-scoped tokens** (additive) + Client Catalogue.
  3. **Generation Adapters** (multi-provider execution) — the big one; flips `runtime_supported` per dialect.
  4. **Fallback routing, fleet `site_id`, control-plane seams** — when demand exists.
- **Each phase ships independently, reversibly, and is invariant-preserving.** No phase requires the next.

---

## 16. Independent Critique (challenging this design)

- **Risk 1 — provider-agnostic narrative vs Anthropic-only execution.** The single biggest credibility gap. If marketing says "any provider" before §6 lands, partners will feel misled. **Mitigation:** ship the honest "can't run it yet" copy (done) and treat Generation Adapters as the gating investment before broad positioning.
- **Risk 2 — Three Doors could overwhelm a solo user.** Three front doors is more concepts than "install and go." **Mitigation:** the onboarding fork + progressive disclosure; Door 1 must feel complete alone.
- **Risk 3 — Coarse token scopes.** `full` tokens handed to a SaaS app are over-privileged. **Mitigation:** capability-scoped tokens (§8) before pushing Door 3 commercially.
- **Risk 4 — "Mission Control" vs "Operations Center" overlap.** Two activity-ish surfaces risk confusing users. **Mitigation:** Mission Control = *triage/overview* (what needs me), Operations Center = *live log* (what's happening). If they can't be made obviously distinct, **merge them** — do not ship two screens that feel the same.
- **Risk 5 — MCP spec & ChatGPT-Connector churn.** External protocols move fast. **Mitigation:** strict adapter boundary; never let protocol details into the engine or registry.
- **Risk 6 — Secret-at-rest (provider keys plaintext options).** Acceptable for SMB, a blocker for enterprise. **Mitigation:** `CredentialStore` is the seam; add encryption before enterprise GA.
- **Risk 7 — Engine assumes single-site.** If `site_id` isn't designed into telemetry/events early, fleet becomes a rewrite. **Mitigation:** add the dimension now, unused.
- **Risk 8 — Demand unproven (N=1).** The whole platform is still pre-product-market-fit (per PMF Discovery). **Mitigation:** the concierge beta exists to retire this; do not over-build doors before a paying partner validates one.
- **Self-challenge on the frame itself:** is "Three Doors, One Engine" too clever? Tested against every current page and every future feature, it held — each maps cleanly to a door, the engine, or an operation. It is the simplest frame that covers the whole surface. Keep it.

---

## 17. MASTER AI PLATFORM BLUEPRINT (the canonical statement)

**WPCC is the governed action layer for WordPress: Three Doors, One Engine.**

- **Three Doors** (how intent enters): **Built-in AI** (your provider), **AI Clients** (MCP), **Remote Apps** (REST + scoped token). Each is a thin **adapter** + a **catalogue** (providers, clients) + a **credential** (key or token). Adding a provider, client, or integration is a catalogue/adapter addition — never an engine change.
- **One Engine** (what always happens): the **Operation Registry** (the single catalogue of capabilities) feeding the **governed execution path** — capability gate → approval (mode-aware) → execute (with pre-image) → audit + telemetry + event bus → field-scoped rollback. Every door, every actor, one path.
- **Configuration is exactly two things:** *door config* (Connect) and *engine policy* (Govern). Activity lives in *Operate*. The IA is those three words.
- **The product promise, identical through every door:** *see it, approve it, undo it, audit it.*
- **The next decisive investment:** **Generation Adapters** to make execution provider-agnostic (close the Anthropic-only gap) — the one thing that makes the positioning literally true.
- **Permanent guardrails:** never fake data; always explain why; approval before execution; rollback by default; adapters insulate the engine; the registry is the contract.
- **Growth is monotonic:** new providers, clients, capabilities, enterprise, SaaS, and fleet are all *additions* to doors, policy, or the registry — the architecture never has to be redesigned to absorb them.

**Implementation may follow this document section by section. When in doubt, ask: is this a door, engine policy, or an operation? If it is none of those, it does not belong in WP Command Center.**

---

### Appendix — Current → Target IA crosswalk
| Today | Target |
|---|---|
| Overview (command-home) | **Mission Control** |
| Connect › AI Setup | **Connect › AI Providers** |
| Connect › Connect an AI Agent | **Connect › AI Clients** |
| (tokens, implicit Door 3) | **Connect › API & Connectors** (issues tokens) |
| Connect › File Access | **Govern › Access & Security** (as a scope) |
| Operate › Operations Center | **Operate › Operations Center** |
| Operate › Alt/SEO/Content (flag tabs) | **Operate › Built-in AI** |
| Operate › Approvals | **Operate › Approvals** |
| Operate › Operations | **Operate › Capabilities** (the registry/contract) |
| Operate › Runtime (advanced) | retire → **Govern › Diagnostics** |
| Audit › Changes | **Govern › Change History** |
| Access › Tokens & Capabilities + Security Mode | **Govern › Access & Security** |
| Audit › Diagnostics/Patches/Intelligence | **Govern › Diagnostics** |
