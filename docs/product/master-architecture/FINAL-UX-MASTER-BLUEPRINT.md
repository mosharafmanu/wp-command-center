# WP Command Center — Final UX Master Blueprint

> **Type:** UX & product-design planning. **No code, no commits, no runtime change.** The final planning document before implementation.
> **Date:** 2026-06-25 · **Hats:** Product Designer · UX Architect · WordPress Product Expert · Agency Owner · First-time User · AI Product Designer.
> **Canonical input:** `MASTER-AI-PLATFORM-BLUEPRINT.md` ("Three Doors, One Engine"). This document turns that architecture into an experience.
> **North stars:** understand WPCC in 30 seconds · every screen answers exactly one question · the architecture disappears behind the experience.

---

## 0. The one idea the whole UX must deliver

> **"However AI touches my site, I can approve it, watch it, and undo it."**

If a first-time user leaves with only that sentence, the UX succeeded. Everything below serves it. The customer never needs to know the words *MCP, REST, capability registry, event bus, telemetry, routing* — those live behind an "Advanced / Developer" curtain they open only on purpose.

---

## 1. UX Master Blueprint

### 1.1 Design philosophy (rules for every screen)
1. **One screen, one question.** If a page answers two questions, split it.
2. **Value before configuration.** Show what WPCC *does* before what it *needs*.
3. **The door you chose is the only door you see.** A Door-1 user never meets MCP or tokens.
4. **Honest over impressive.** "Not tracked yet" / "can't run this yet, here's why" beats a confident lie.
5. **Every empty state is an instruction.** No dead ends; each emptiness points to the next action.
6. **Approval, audit, rollback are felt, not explained.** The user *experiences* a reversible action in the first 5 minutes.
7. **Progressive disclosure is the default.** Advanced controls are collapsed, labelled, and reachable — never in the first view.

### 1.2 The three experiences, as the user feels them
| | Door 1 — Built-in AI | Door 2 — AI Clients | Door 3 — API & Integrations |
|---|---|---|---|
| Who | The site owner | Their AI assistant (Claude/Cursor/…) | Their software / SaaS |
| Mental model | "WPCC writes for me" | "My assistant can act here, safely" | "My app can drive WordPress, governed" |
| Setup | Add a key, pick a model | Pick a client → copy config (token issued) | Copy Base URL + token |
| Payoff | SEO/Alt/Content in minutes | Assistant operates under approval | Programmatic governed actions |
| Audience | **Everyone** (default) | Agencies / power users | Platforms / enterprise |

Door 1 is the front of the house. Doors 2 and 3 are present but quieter, reached by users who know they want them.

---

## 2. Navigation Blueprint

### 2.1 Recommended top-level (6 items, agency-first, value-forward)
```
WP Command Center
├── 🏠 Home            → "Is everything ok, and what needs me?"          (Mission Control)
├── ✨ Built-in AI      → "Use AI to do work on my site"                  (Door 1)
│      ├ Providers   · connect a provider, pick a model
│      ├ SEO         · generate titles & descriptions
│      ├ Alt Text    · generate image alt text
│      └ Content     · titles, excerpts, more (future)
├── 🔌 Connect          → "Let an external AI or app act here"            (Doors 2 & 3)
│      ├ AI Clients      · Claude, Cursor, Codex, Continue… (MCP)
│      └ API & Integrations · Base URL + tokens for apps/SaaS (REST)
├── 📡 Activity         → "What is happening, and what's waiting?"
│      ├ Live         · running / completed / failed (Operations Center)
│      └ Approvals    · what needs my sign-off
├── 🕘 History          → "What changed — and undo it"                    (Change History + rollback)
└── ⚙️ Settings         → "Rules & advanced controls"
       ├ Security & Approvals · mode (Client/Developer/Enterprise)
       ├ Access            · tokens, capabilities, scopes
       ├ Diagnostics       · health, patches, site report
       └ Advanced          · capability catalogue, routing, developer info
```

### 2.2 Why this beats today's nav (Overview · Operate · Audit · Access · Connect)
- **Door 1 is split out and named the value** ("Built-in AI"), not buried as flag-gated tabs under "Operate". A solo user lives in **Home + Built-in AI** and never opens anything else.
- **Doors 2 & 3 are grouped as "Connect"** (external actors), cleanly separated from "the AI WPCC runs for you". Today "AI Setup" (Door 1) and "Connect an AI Agent" (Door 2) sit side by side and read as the same thing — the #1 navigational confusion. Fixed.
- **"Activity" + "History" are plain-English** replacements for "Operate"/"Audit". Customers don't think "audit"; they think "what changed".
- **Everything advanced (capabilities catalogue, routing, patches, diagnostics) collapses into Settings**, off the daily path.
- **Tokens stop being a top-level feature.** They're issued *in context* (inside AI Clients / API & Integrations) and *managed* in Settings › Access — resolving today's "tokens are scattered and scary" problem.

### 2.3 Page relationships (the spine)
```
Home  ──points to──►  every other page (triage)
Built-in AI › Providers ──powers──► Built-in AI › SEO/Alt/Content
Connect (Clients/API)   ──issues──► tokens (managed in Settings › Access)
Any door ──creates──► Activity (live) ──needs──► Approvals ──results in──► History (undo)
Settings ──governs──► everything (mode, capabilities, scopes)
```

---

## 3. Onboarding Blueprint

### 3.1 First-run (immediately after activation)
A single welcome screen — **not** a settings dump:
> **"AI can now change your WordPress site. WP Command Center makes that safe."**
> *One question:* **How do you want to use AI here?**
> ① **WPCC's built-in AI** — *write SEO, alt text, and content using your own provider key* → **Recommended**
> ② **Connect my AI assistant** — *Claude, Cursor, ChatGPT, Codex…*
> ③ **Connect an app or service** — *REST API & tokens*
> *Footer: "You can do all three later. Pick where to start."*

Below the fold, one reassurance line: *"Whatever you choose, every change waits for your approval and can be undone."* Plus the current posture chip: **Client-safe mode: ON**.

**What is hidden at first run:** capabilities catalogue, routing, telemetry, MCP/REST internals, diagnostics, security-mode switching. **What is optional:** everything except picking a starting door.

### 3.2 Journey A — Built-in AI (the "magic moment", target < 5 min)
1. **Add a provider** → paste key → **Test** (real; shows discovered models). Honest if a provider can't run yet.
2. **Pick a model** (recommended default pre-selected; discovered/custom available).
3. **Turn on one tool** (Alt Text or SEO). *(See contradiction C1 — enabling currently needs a code flag; UX must either gain a real toggle or guide the founder.)*
4. **Generate on one item** → **review the proposal** → **Approve** → **Apply**.
5. **See it in History → Undo it once.** The product's promise, felt.
6. Success card: *"You just made a governed AI change — and undid it. That's WPCC."*

### 3.3 Journey B — AI Clients (MCP)
1. **Pick your client** from a catalogue (Claude Desktop, Cursor, Codex, Continue, Windsurf, …).
2. WPCC shows the **exact config snippet** + **issues a scoped token** (default: read-only).
3. **Verify connection** → Home shows *"Cursor connected · read-only"*.
4. First client-initiated change → lands in **Approvals** → approve → executes → visible in History.
5. Success card: *"Your assistant can now act here — and nothing happens without your approval."*

### 3.4 Journey C — API & Integrations (REST)
1. **Create a token** (name it, choose scope; future: capability-scoped).
2. WPCC shows **Base URL + token + a copy-paste example request** (and a "test it" button).
3. A read call works instantly; a write call enters **Approvals**.
4. Success card: *"Your app is connected. Reads are instant; changes are governed."*

**Cross-journey rule:** the three journeys never share a screen. Choosing a door routes you down a self-contained path; the other doors are reachable later from **Connect**, never injected mid-journey.

---

## 4. Information Architecture Blueprint — screen-by-screen verdicts

| Current screen (view) | Verdict | Becomes | Why |
|---|---|---|---|
| `command-home` (Overview) | **Stay, elevate** | **Home / Mission Control** | The triage landing. Make it the default and the only thing a healthy site needs to glance at. |
| `ai-setup` (AI Setup) | **Rename + move** | **Built-in AI › Providers** | "Setup" is vague & collides with Door 2. It is Door 1 provider config. |
| `seo-meta`, `ai-alt-text`, `ai-content` | **Merge + promote** | **Built-in AI › SEO / Alt Text / Content** | These are the product's *value*; today they're flag-gated tabs under "Operate". Give them a named home that appears once a provider is connected. |
| `proposals` (Governed Drafts) | **Disappear as a page** | folds into each tool's *review* state + **Approvals** | "Drafts" is an internal concept; the user experiences review inside the tool flow, not a separate dev tab. |
| `ai-integrations` (Connect an AI Agent) | **Rename** | **Connect › AI Clients** | "Agent" is overloaded; users connect *clients*. |
| `token-capability-manager` | **Split** | issue-in-context (Connect) + manage in **Settings › Access** | Tokens are a credential, not a destination; capabilities are policy. |
| `file-access` | **Demote** | a *scope* inside **Settings › Access** | Not a door; it's a permission grant. |
| `operations-center` | **Stay, rename group** | **Activity › Live** | The live feed; "Activity" reads better than "Operate". |
| `approval-center` | **Stay, elevate** | **Activity › Approvals** (+ admin-bar badge) | Action-critical; must be glanceable. |
| `change-history` | **Stay, rename** | **History** | Customers think "history/undo", not "audit". |
| `operations-explorer` | **Move to advanced** | **Settings › Advanced › Capabilities** | The catalogue/contract is reference material, not daily UI. |
| `dashboard` (Runtime advanced) | **Disappear from nav** | fold useful bits into **Settings › Diagnostics** | Legacy; not a customer concept. |
| `diagnostics` | **Stay, move** | **Settings › Diagnostics** | Advanced/support surface. |
| `patches` | **Move to advanced** | **Settings › Diagnostics › Patches** | Niche; power-user only. |
| `site-intelligence` | **Merge** | a card on **Home** + detail in **Settings › Diagnostics** | A read-only site report belongs in triage + diagnostics, not its own tab. |
| `settings` (Security Mode) | **Stay, expand** | **Settings › Security & Approvals** | The policy home. |

**Net:** ~18 screens → **6 sections / ~14 surfaces**, with the value (Built-in AI) promoted and the internals (capabilities, patches, runtime, telemetry) tucked into Settings › Advanced/Diagnostics.

---

## 5. Screen Hierarchy Blueprint (wireframe-level)

Apply this four-tier rule to **every** page:

| Tier | Definition | Example (Built-in AI › Providers) |
|---|---|---|
| **Primary** | The one answer the screen exists to give | "Which provider powers your AI, and is it working?" → provider card + status + model |
| **Secondary** | Supporting actions/info, visible but quieter | Add provider, Test, set default, feature routing |
| **Advanced** | Collapsed by default; opt-in | Base URL, deployment, tags, discovered-model search, custom model |
| **Hidden** | Never shown unless a developer mode is on | dialect names, runtime flags, raw capability ids, internal routing tables |

Per-screen primary questions (the contract):
- **Home:** "Is everything ok, and what needs me?"
- **Built-in AI › Providers:** "Is my AI provider connected and working?"
- **Built-in AI › SEO/Alt/Content:** "Generate this, review it, apply it."
- **Connect › AI Clients:** "Connect my assistant in three steps."
- **Connect › API & Integrations:** "Get a Base URL + token and a working example."
- **Activity › Live:** "What's happening right now?"
- **Activity › Approvals:** "What needs my sign-off?"
- **History:** "What changed — undo it."
- **Settings › *:** "Set the rules / open advanced controls."

Example wireframe (Built-in AI landing, before a provider exists):
```
✨ Built-in AI
┌───────────────────────────────────────────────┐
│  Connect an AI provider to start.               │  ← PRIMARY (empty-state instruction)
│  [ Connect a provider → ]                       │
│  Works with OpenAI, Anthropic, Gemini, Groq,    │  ← SECONDARY (reassurance)
│  Ollama, and more.                              │
└───────────────────────────────────────────────┘
(SEO · Alt Text · Content tabs appear, greyed, with “connect a provider first”)
```

---

## 6. Empty State Blueprint

Every empty state = **headline (what's missing) + one sentence (why it matters) + one primary action**. Never a blank table.

| Screen | Headline | Subtext | Primary action |
|---|---|---|---|
| No providers | "Connect an AI provider to begin." | "Use your own key from OpenAI, Anthropic, Gemini, and more." | **Connect a provider** |
| Built-in AI tool, no provider | "Connect a provider to use SEO/Alt/Content." | "Built-in AI needs a connected model." | **Go to Providers** |
| Built-in AI tool, provider but flag off | "This tool is turned off." | "Enable it to start generating (or ask your developer)." *(see C1)* | **How to enable** |
| No AI clients | "Connect your AI assistant." | "Claude, Cursor, Codex and others can operate this site — safely." | **Choose a client** |
| No tokens / API | "Create an access token to connect an app." | "Apps use a Base URL + token; changes stay governed." | **Create token** |
| No activity | "No operations yet." | "When AI does work — built-in, a client, or an app — it shows here." | **Use Built-in AI** |
| No telemetry/cost | "Not tracked yet." | "Usage and cost appear once the AI runtime reports them." | *(none — honest)* |
| No approvals | "Nothing waiting." | "Changes that need sign-off will appear here." | *(none — calm)* |
| No history | "No changes yet." | "Every change is recorded here and can be undone." | **Use Built-in AI** |
| Healthy/all-clear (Home) | "All clear." | "Nothing needs you, nothing failed recently." | *(none — reassurance)* |

Principle: empties either **drive the next setup step** (when something's missing) or **reassure calmly** (when nothing's wrong) — never both, never blank.

---

## 7. Label & Terminology Guide

| Current | Verdict | Use instead | Rationale |
|---|---|---|---|
| AI Setup | ✗ | **Built-in AI › Providers** | names Door 1; ends the Setup/Agent collision |
| Connect an AI Agent | ✗ | **Connect › AI Clients** | users connect clients, not "agents" |
| AI Connections | ◑ | **Connections** (within Providers) | fine as the noun for a configured provider |
| Mission Control | ✓ | **Home** (subtitle "Mission Control") | "Home" for navigation; keep Mission Control as the flavour name |
| Operations Center | ◑ | **Activity › Live** | "Operations Center" overlaps "Operations"; "Activity" is plainer |
| Operations (explorer) | ✗ | **Capabilities** (Settings › Advanced) | it's the capability contract, not daily ops |
| Runtime | ✗ | retire | not a customer word |
| Diagnostics | ✓ | **Diagnostics** (Settings) | clear |
| Reports | ◑ | fold into **Home** / **Diagnostics** | a report isn't a place |
| Approval Center | ◑ | **Approvals** | shorter, plainer |
| Tokens & Capabilities | ✗ split | **Access** (tokens) + capabilities under Advanced | two different concepts |
| Governed Drafts / Proposals | ✗ | "**Review**" step inside each tool | internal term; users "review" |
| Change History | ◑ | **History** | customers think history/undo |
| File Access | ✗ | a **scope** under Access | a permission, not a page |

**Naming law:** a label must tell the user *which door they're at* or *which rule they're setting*. If it doesn't, rename it. Avoid internal nouns (runtime, registry, proposal, dialect, telemetry) in customer-facing labels.

---

## 8. Progressive Disclosure rules

| Control | Default | Reveal when |
|---|---|---|
| Base URL | hidden | provider requires an endpoint (local/Azure/gateway/custom) |
| Deployment name | hidden | provider needs it (Azure) |
| Tags | hidden | user opens **Advanced options** |
| Provider metadata / dialect | hidden | Developer mode on |
| Discovered-model search | hidden | model list exceeds threshold |
| Custom model field | hidden | user picks "Custom model ID…" |
| Custom headers / timeout | hidden | Developer mode on **and** backend supports it (don't show dead fields) |
| Capability catalogue | Settings › Advanced | always reachable, never default |
| Routing / fallback | Advanced | user has >1 connection |
| Security-mode switch | Settings | always reachable, with confirm guard |
| MCP/REST internals, raw ids | hidden | Developer mode on |
| Enterprise (SSO, scoped tokens, fleet) | hidden | enterprise licence/flag |

**Two visibility modes, one switch:** a single **"Developer mode"** toggle (Settings) flips all "Hidden" tier content on. Everything else uses inline disclosure (`<details>`, conditional fields). No third mode.

---

## 9. Information Density — designing for three users at once

| User | Need | How the UX serves them |
|---|---|---|
| **First-timer** | Understand + first win fast | First-run fork → Built-in AI journey → governed result in 5 min. Sees only Home + Built-in AI. |
| **Power user (agency)** | Efficiency, many sites/clients | Dense tables, keyboard-reachable actions, Activity/Approvals/History as a daily loop, all doors available. |
| **Enterprise admin** | Find advanced controls + assurance | Settings › Advanced (capabilities, scopes), audit export, security mode, honest limits documented. |

Rule: **the same screen serves all three via progressive disclosure** — primary view is first-timer-simple; advanced tiers satisfy power/enterprise without a separate UI. If a screen can't serve all three, it's doing two jobs — split it.

---

## 10. Documentation Strategy

| Doc | Reader | Contains |
|---|---|---|
| **Quick Start** | first-timer | the three journeys, 1 page, screenshots, "first governed change in 5 min" |
| **User Guide** | agency/owner | Built-in AI tools, approvals, history/rollback, day-to-day |
| **Advanced Guide** | power user | routing, multiple connections, capability scopes, security modes |
| **Developer Guide** | integrator | how the engine works (doors/engine), extending, flags |
| **Enterprise Guide** | enterprise admin | SSO/roles→capabilities, scoped tokens, audit export, limits/compliance |
| **API Guide** | Door 3 devs | Base URL, auth, endpoints, scopes, examples, versioning |
| **MCP Guide** | Door 2 devs | client recipes (Claude/Cursor/Codex…), tokens, tools |
| **Roadmap** | everyone | what's coming (esp. provider-agnostic execution) |
| **Architecture** | contributors | the canonical blueprint ("Three Doors, One Engine") |

Principle: **docs mirror the doors.** A Door-1 user reads Quick Start + User Guide and never sees MCP/API guides. Each guide states its audience in the first line.

---

## 11. Product Positioning Guide (one consistent story everywhere)

| Surface | Message |
|---|---|
| **One sentence** | "The safe way to use AI with WordPress — approve, watch, and undo everything AI does." |
| **Landing hero** | "AI can now change your site. WP Command Center keeps you in control." + the Three Doors visual. |
| **WP admin (Home)** | the same promise, lived: pending/activity/undo at a glance. |
| **Marketing** | "Built-in AI, your AI assistant, or your own app — all governed by one console." |
| **Repository / readme** | "The governed action layer for WordPress. Three Doors, One Engine." + honest current limits. |
| **Anti-positioning** | NOT a chatbot, NOT an MCP server to run, NOT an OpenAI/Anthropic plugin, NOT content-spam AI. |

Consistency law: the words on the website, the admin, and the docs must be the **same words**. "Approve, watch, undo" appears everywhere; "Three Doors, One Engine" is the structural story everywhere.

---

## 12. Independent UX Critique (challenge this design)

- **C1 — Built-in AI can't be enabled from the UI (code flag).** The single biggest UX contradiction with the architecture/impl: turning on SEO/Alt/Content needs a `define()` constant — a non-technical agency can't self-serve. *Recommendation (documented, not implemented): add a real in-admin enable toggle per tool, gated by Client-safe mode; until then, onboarding must show "ask your developer / paste this line".* **Do not let the UX promise a toggle that doesn't exist.**
- **C2 — Provider-agnostic UX vs Anthropic-only execution.** "Built-in AI" implies any connected provider can generate; today only Anthropic runs. *Recommendation: in Built-in AI › Providers, clearly mark which providers can run now vs are "connected & testable, runtime support coming" (the honest copy already exists on the routing page — extend it here). Don't surface unrunnable providers as ready in the tool screens.*
- **C3 — Home vs Activity overlap.** Mission Control (triage) and Activity › Live (feed) risk feeling like two activity screens. *Recommendation: Home = "what needs me + health + shortcuts"; Activity = "the chronological feed". If users still confuse them in testing, merge Activity into Home as a tab.*
- **C4 — Six top-level items may still be too many for a blogger.** *Recommendation: collapse to **Home / Built-in AI / Settings** for non-developers, revealing **Connect / Activity / History** progressively (Connect appears after first interest; Activity/History appear once there's activity). Empty sections can hide themselves until relevant.*
- **C5 — "Approvals" friction.** Client mode means every write waits — great for trust, annoying at volume. *Recommendation: batch-approve, "approve all from this client", and per-capability auto-approve rules (enterprise) — designed now, not built.*
- **C6 — Token scope coarseness (UX symptom).** "Full access" is scary to grant an app. *Recommendation: capability-scoped tokens with a friendly picker ("this app may: read content, write SEO") — the UX that makes Door 3 trustworthy.*
- **C7 — Discoverability of rollback.** Undo is the killer feature but lives in "History". *Recommendation: surface "Undo" inline on the just-applied result and in Activity, not only in History.*
- **Self-challenge:** is splitting Door 1 into "Built-in AI" (vs the architecture's single "Connect") a contradiction? No — the architecture allows Built-in AI as Door 1's output surface; the UX simply promotes it because it's the value. Documented as a refinement, not a conflict.

---

## 13. Future UX Evolution

- **New built-in AI tool** (titles, translation, internal links, image gen) → a new tab under **Built-in AI**, same generate→review→approve→undo flow. Zero new patterns.
- **New AI client** → a new card in the **AI Clients** catalogue (recipe + token). No new UI.
- **New integration/protocol** → a new card in **API & Integrations**. No new UI.
- **Provider-agnostic execution lands** → the "runtime support coming" marks disappear automatically; no screen redesign (the honest flags flip).
- **Enterprise** → Settings gains **Roles & Policies** and **Audit Export**; scoped-token picker matures. Additive.
- **Fleet / multi-site** → **Home** becomes a fleet Mission Control (N sites), Activity/History gain a site filter. The single-site screens are unchanged; a site dimension is added.
- **Cloud / hosted** → the same UX, with the engine hosted; Door 3 is the integration seam.

The UX scales by **adding cards and tabs to existing patterns** — never by inventing new navigation. That is the test for any future screen.

---

## 14. Contradictions between current implementation and the architecture (documented, NOT resolved here)

| # | Contradiction | Long-term direction |
|---|---|---|
| C1 | AI features enabled only via PHP flag (no admin toggle) | add a governed in-admin enable toggle per tool |
| C2 | Connection model provider-agnostic; execution Anthropic-only | Generation Adapters per dialect (architecture §6); UX marks honestly until then |
| C3 | Two activity-ish surfaces (Home vs Operations Center) | distinct roles, or merge — decide via user testing |
| C4 | Tokens span Door 2 & 3 but lived as one "Tokens" page | issue-in-context, manage centrally in Settings › Access |
| C5 | Coarse token scopes (`read_only`/`full`) | capability-scoped tokens + friendly picker |
| C6 | "Operations" (catalogue) vs "Operations Center" (feed) name clash | rename catalogue → "Capabilities"; feed → "Activity" |
| C7 | Existing prod still `developer` mode (RC-2 seed only affects fresh installs) | onboarding/Settings must make mode explicit & switchable with confirm |

These are **not** fixed by this document. Implementation should follow the recommended directions; none requires a runtime/engine redesign.

---

## 15. FINAL UX MASTER BLUEPRINT (the canonical statement)

**WP Command Center's experience is "Three Doors, One Promise."**

- **One promise, everywhere:** *"However AI touches your site — built in, as a client, or as an app — you can approve it, watch it, and undo it."* It is the hero line, the admin subtitle, and the felt experience of the first five minutes.
- **Six surfaces, each answering one question:** **Home** (what needs me?), **Built-in AI** (do AI work), **Connect** (let external AI/apps in), **Activity** (what's happening + what's waiting), **History** (what changed + undo), **Settings** (rules + advanced).
- **The door you choose is the only door you see.** First-run asks one question; each journey is self-contained; the other doors wait in Connect.
- **The architecture disappears.** MCP, REST, capability registry, event bus, telemetry, routing, dialects — none appear unless the user turns on **Developer mode**. Customers see providers, clients, apps, approvals, history.
- **Every empty state instructs; every advanced control hides; every label names a door or a rule.**
- **Honesty is the brand.** Unknown is "not tracked yet"; an unrunnable provider says so; nothing is faked. This is the trust that the whole governed-action promise rests on.
- **It scales by adding cards and tabs to existing patterns** — new tools, clients, integrations, enterprise, fleet, and cloud all fit without new navigation or new mental models.

**Implementation may begin.** Where this document and the current code disagree (§14), build toward the documented direction — never paper over it, never fake it, and never make the customer learn the architecture to use the product.
