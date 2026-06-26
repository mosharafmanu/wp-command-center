# WP Command Center — Product & UX Audit + Design System

> 🎨 **AS-BUILT UX OVERLAY (2026-06-27).** This document is the **design blueprint** (written 2026-06-18). The audit/wireframes below describe the *target*; much of it is now **shipped**. Recent UX work (UI / view-copy only — no engine/REST/MCP/schema/governance change; invariants held **34/23/40/40/2.5.0**; net-new test failures **0**):
> - **Connect → AI Clients redesigned into a SaaS-style MCP setup page** — hero + value/trust chips → primary "Connect your assistant" panel (connection URL + copy, token status, read-only test) → compact "popular assistants" presets → the full supported-clients directory demoted to an **Advanced** collapsible. The old client-directory emphasis is removed (it no longer reads as a directory).
> - **Connect → Configuration redesigned into a guided setup wizard** — choose assistant → copy configuration → access-token create/use → safe read-only connection test → safety note; raw endpoint/paths live in a disclosure. **MCP config token-metadata cleanup**: generated configs stay minimal (no `WPCC_TOKEN_ID/LABEL/SCOPE`).
> - **History redesigned into "Review & Undo"** — premium hero + trust chips (Recorded · Reversible · Audited · Safe to undo), polished timeline, clear **reversible indicators**, confident consistent **Undo** action; terminology moved **Restore → Undo** throughout (improved trust messaging). Demo-ready for design partners.
> - **Trust chips** (Requires approval · Audited · Reversible · Scoped access · Your site, your token) and **onboarding** (5-step setup, plain-language copy) are now first-class on the connect/history surfaces.
> - **Design-consistency pass** — cards, radius, shadows, focus states, `aria-current` aligned across Connect/History; **code-block text-selection contrast fix** (selected text was near-invisible on the dark config block).
> - **Still bespoke (not full CDS):** the Connect/History views use scoped `wpcc-ai-*` / `wpcc-*` styles; the daily-loop Approvals/Access tables remain utilitarian. **Remaining UX backlog** (minor Configuration refinements · Activity-tab polish · Security-tab plain-language · CDS migration · minor copy) is tracked in [`CURRENT-PRODUCT-STATUS.md`](CURRENT-PRODUCT-STATUS.md) §Remaining UX backlog. Current-state authority: [`SESSION-HANDOFF-2026-06-27.md`](SESSION-HANDOFF-2026-06-27.md) and [`CURRENT-PRODUCT-STATUS.md`](CURRENT-PRODUCT-STATUS.md).

> 🔄 **Production status update (2026-06-23):** prod HEAD = **`7aa7e84`** (governance Phase 1+2+3 deployed: B2-2/B2-1/A-1/A2-1 CLOSED; **Phase 3 F-1 SEO delta rollback CLOSED for SEO**, OPEN systemically for sibling runtimes). UX/design content below is unaffected; invariants **34/23/40/40/2.5.0**; AI UI surfaces remain **dormant** (SEO Meta / AI Content / Alt Text flags OFF, key unset). Current-state authority: [`SESSION-HANDOFF-PHASE-3.md`](SESSION-HANDOFF-PHASE-3.md).

> **Status:** Product-level strategy & design blueprint. **No code, no implementation.**
> **Date:** 2026-06-18 · **Baseline:** Phase A complete (STEP 104–109, released `v0.109.0`).
> **Lenses:** AI SaaS Product Designer · WordPress Product Architect · Enterprise UX Designer · Dashboard Information Architect.
> **Reference set:** competitor screenshots of *AI Engine by Meow Apps* (v7.0).

---

## 0. Framing — why we are not AI Engine

AI Engine and WP Command Center (WPCC) are **not the same product** and must not be designed as if they were.

- **AI Engine** sells *AI features to content creators* — chatbots, content/image generation, a Playground.
- **WP Command Center** is the *control plane for agentic WordPress* — capabilities, approvals, change history, rollback, tokens, MCP.

AI Engine is the **creation** layer; WPCC is the **governance and trust** layer beneath it. They can coexist on the same site.

**What we copy from AI Engine:** the *UX chassis* — branded shell, one menu, setup assistant, modules/feature-flags, persistent quick actions, embedded docs, inline Pro seam, add-ons marketplace.

**What we reject from AI Engine:** the *positioning* (content creation) and its *visual excess* — the full-bleed saturated-blue container, infinite-scroll settings, and raw developer docs dumped into the admin.

---

## 1. Product positioning

**Current implicit position:** "a pile of admin pages for managing an AI agent gateway." Twelve submenus, default WP chrome, no identity, no narrative. A capable engine with no cockpit.

**Recommended position:** **Mission control for AI on WordPress** — *command, govern, and undo everything an AI agent does to your site.*

| Axis | AI Engine | WP Command Center (target) |
|---|---|---|
| Job-to-be-done | "Add AI features to my site" | "Stay in control of AI acting on my site" |
| Core promise | Capability | **Trust, safety, reversibility** |
| Hero verbs | Generate, chat, embed | Approve, audit, roll back, scope |
| Persona | Creator / marketer | **Operator + engineer** |
| Emotional payoff | "Look what AI can do" | "Nothing happens I didn't allow, and I can undo it" |

This is a defensible, complementary position. It also scales into a **product family**: the design language built here becomes the shared identity for future "Command" plugins. Position WPCC as the first citizen of a *Command* platform, not a one-off plugin.

---

## 2. UX audit

**Strengths today**
- Genuinely differentiated capabilities (human-in-the-loop approval, change history, rollback, capability scoping, destructive-action phrase confirmation) — premium trust features most WP-AI tools lack.
- Disciplined read-only / no-execution separation on the new surfaces.

**Problems (severity-ranked)**

| # | Problem | Why it hurts | Competitor contrast |
|---|---|---|---|
| UX-1 | **No product identity** — surfaces render as raw WP `widefat`/dashicons gray chrome | Feels like a settings dump, not a cockpit; zero memorability; undersells the premium trust story | AI Engine owns a branded header + container |
| UX-2 | **Two "Dashboards"** (legacy operational + read-only Overview) with different data | Users can't tell which is "home"; erodes trust in the numbers | AI Engine has exactly one Dashboard |
| UX-3 | **Menu sprawl** (~12 submenus) | No mental model; every capability is a top-level peer; overload | AI Engine: one menu entry, 5 internal tabs |
| UX-4 | **No onboarding / readiness state** | A fresh install is a blank, intimidating set of pages | AI Engine's Setup Assistant (3/8 complete) is its strongest pattern |
| UX-5 | **No persistent task launcher / command surface** | Operators must hunt through menus to act | AI Engine keeps Content/Images/Playground in the header |
| UX-6 | **Inconsistent micro-UX** — each surface re-implements its own filters, tables, formatting, empty states | Behavior/a11y drift between surfaces; certification risk | AI Engine reuses one card/panel system everywhere |
| UX-7 | **Silos, no cross-linking** — an Approval doesn't link to the Change it produced; an Operation doesn't link to the Tokens that can call it | The platform's real value (provenance, traceability) is hidden | — |
| UX-8 | **Reversibility is buried** — rollback lives inside Change History detail | The single most trust-defining action is not a first-class affordance | — |

**Adopt from AI Engine:** branded shell · single-menu + internal nav · Setup Assistant with completion % · Modules/feature-flag control plane · persistent quick actions · embedded API docs as first-class tabs · inline PRO badges · Add-ons marketplace.

**Avoid from AI Engine:** full-bleed saturated-blue container (color fatigue, weak hierarchy, contrast risk) · endlessly-scrolling settings · raw documentation dumped into admin · decorative color that carries no meaning.

---

## 3. Information architecture audit

**Current IA:** flat list of ~12 sibling pages. No grouping, no home, two dashboards, governance surfaces scattered between unrelated utilities (Site Intelligence, Diagnostics, File Access, Patches).

**Target IA principles**
1. **One home.** Merge the two dashboards into a single mode-aware Dashboard.
2. **Group by operator mental model, not by code module.** Five sections, verb-led.
3. **Progressive disclosure.** Depth lives *inside* a section, not as another top-level peer.
4. **Density follows mode** (Builder vs Engineer), not a different menu.

**Proposed section model (the "5 C's")**

| Section | Mental model | Absorbs today's surfaces |
|---|---|---|
| **Overview** | "How is my site, and what needs me?" | Dashboard + Dashboard Overview (merged) |
| **Operate** | "What can run, and what's waiting on me?" | Approval Center, Operations Explorer, (run/queue) |
| **Audit** | "What changed, by whom, and can I undo it?" | Change History, Rollback, Audit Log |
| **Access** | "Who/what can act, and how much?" | Tokens & Capabilities, Security Mode, policy/guardrails |
| **Connect** | "How do agents reach my site?" | MCP, AI Integrations, Settings, Add-ons |

Site Intelligence / Diagnostics / File Access become utilities *within* the relevant section (Audit or Connect), not top-level peers.

---

## 4. Dashboard redesign (wireframe)

A single, branded, mode-aware home. Builder mode shown; Engineer deltas noted.

```
┌──────────────────────────────────────────────────────────────────────────────┐
│  ◧ COMMAND CENTER          [ Security: ● Developer ▾ ]   ⌘K Search/Command     │
│  Mission control for AI      [ Mode: ◐ Builder | Engineer ]      ⚙  ? Help      │  ← Branded shell header
├──────────────────────────────────────────────────────────────────────────────┤
│  READINESS  ▓▓▓▓▓▓░░  6/8   ▸ Connect an agent · Create a token · Test an op    │  ← Setup Assistant (new installs / dismissible)
├──────────────────────────────────────────────────────────────────────────────┤
│  ┌─ Needs you ──────────────┐  ┌─ Site health ───────────────────────────────┐ │
│  │  ⏳ 3 approvals pending    │  │  ● Secure   2 reversible actions today       │ │  ← "Needs you" = action-first
│  │  ⚠ 1 critical             │  │  ◷ Last change 4m ago by  Agent: Claude       │ │     (Builder hero)
│  │  ✕ 1 queue failure        │  │  ⟲ 12 sessions · all reversible              │ │
│  │  [ Review queue → ]       │  │  Invariants ✓ 34 ops · 23 caps · 40 tools     │ │  ← Engineer surfaces invariants prominently
│  └──────────────────────────┘  └─────────────────────────────────────────────┘ │
│                                                                                  │
│  ┌─ Recent activity ─────────────────────────────────────────────────────────┐ │
│  │  When        Actor            Change                       Risk    Undo     │ │
│  │  4m ago      Agent · Claude   product_update #418          medium  [ ⟲ ]    │ │  ← Reversibility is a
│  │  22m ago     System · Cron    thumbnail_regenerate ×40     low     [ ⟲ ]    │ │     first-class column
│  │  1h ago      Admin · you      plugin_update woocommerce    high    [ ⟲ ]    │ │
│  │  [ Open full timeline → ]                                  [ Reversible ▾ ] │ │
│  └────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                  │
│  ┌─ Operate ──────┐ ┌─ Access ───────┐ ┌─ Connect ──────┐  (entry-point cards)   │
│  │ 40 operations  │ │ 5 tokens        │ │ MCP ● live      │                       │
│  │ 6 need approval│ │ 23 capabilities │ │ 40 tools exposed│                       │
│  │ [ Explore → ]  │ │ [ Manage → ]    │ │ [ Configure → ] │                       │
│  └────────────────┘ └─────────────────┘ └─────────────────┘                       │
└──────────────────────────────────────────────────────────────────────────────┘
```

- **Builder mode:** "Needs you" + Recent activity + one-click Undo dominate; counts/invariants are secondary.
- **Engineer mode:** invariant strip, risk distribution, MCP/tool wiring, and capability-matrix density move forward; the activity table gains operation IDs, session IDs, and capability columns.
- The **two dashboards collapse into this one**; the legacy operational dashboard's actions move into *Operate*.

---

## 5. Navigation structure

```
Command Center                      ← single top-level menu entry
│
├─ Overview            (home; mode-aware)
│
├─ Operate
│   ├─ Approvals        (pending · history · queue)
│   ├─ Operations       (catalogue · detail)         [Engineer-dense]
│   └─ Run              (guided task launcher)         [Builder-first]
│
├─ Audit
│   ├─ Change History   (timeline · sessions · reversible)
│   ├─ Rollback         (promoted to first-class)
│   └─ Activity Log     (raw audit)                    [Engineer]
│
├─ Access
│   ├─ Tokens & Capabilities
│   ├─ Security Mode
│   └─ Policies / Guardrails
│
└─ Connect
    ├─ Agents & MCP
    ├─ Integrations
    ├─ Settings
    └─ Add-ons          (marketplace, Pro seam)
```

Internal nav = **left rail (sections) + horizontal sub-tabs (views)** — mirrors AI Engine's tab discipline but two-level to fit WPCC's greater depth. Persistent header carries **⌘K command palette**, **security-mode pill**, **mode toggle**, and global help.

---

## 6. Design system — token specification

A **three-tier token model** so the same system themes the whole future plugin family: **Primitives → Semantic → Component**. Plugins share primitives + semantics; each swaps **one brand accent** primitive.

**Tier 1 — Primitives** (raw, never used directly in UI)
```
color.blue.600   color.gray.50…900   color.green.500   color.amber.500
color.red.600    color.purple.500    radius.{sm,md,lg}  space.{1..8}
font.sans / font.mono   shadow.{1,2,3}   z.{base,sticky,overlay,modal}
```

**Tier 2 — Semantic** (intent; what UI references)
```
brand.accent            = color.blue.600     ← the ONE per-plugin override
surface.page / .card / .raised / .sunken
text.primary / .secondary / .inverse / .link
border.subtle / .strong / .focus
state.success / .warning / .danger / .info

# Risk tiers — semantic, NOT decorative (WPCC's signature)
risk.diagnostic = green   risk.low = teal   risk.medium = amber
risk.high = orange        risk.critical = red

# Actor identity
actor.human  actor.system  actor.agent     ← distinct, consistent hues

# Mode theming
mode.builder.density  = comfortable
mode.engineer.density = compact
```

**Tier 3 — Component** (bound to components)
```
badge.risk.bg/fg (× tier)   stat.value.size   table.row.height (× density)
approval.row.accent         diff.add/remove   palette.surface
button.primary.bg = brand.accent             nav.active.indicator = brand.accent
```

**Type scale** (compact, operator-dense): Display 24 / H1 20 / H2 16 / Body 13 / Caption 11; mono for IDs, hashes, capabilities, code.
**Spacing:** 4-pt base. **Motion:** 120ms micro / 200ms panel; respect `prefers-reduced-motion`. **Density:** comfortable vs compact row heights bound to mode.

**Critical principle:** color carries *meaning* (risk, status, actor), never decoration — the deliberate corrective to AI Engine's wall-of-blue. Neutral surfaces let the risk/status colors *signal*.

---

## 7. Visual hierarchy

1. **WP-native, branded — not WP-hostile.** Live inside WP's grid and a11y norms, but own a restrained branded header + neutral card system. Avoid AI Engine's full-bleed saturated container.
2. **Neutral canvas, semantic color.** Gray/white surfaces; color only for risk, status, actor. A "critical" pill must never compete with decorative blue.
3. **Action-first hierarchy.** On every surface: *what needs you* → *what happened* → *reference data*. Undo/approve are primary buttons; browsing is secondary.
4. **Density modes** instead of one-size tables.
5. **One type rhythm**, monospace reserved for machine identifiers (treat IDs/hashes/capabilities as a first-class type style).

---

## 8. AI-era UX patterns (WPCC's differentiators, made first-class)

| Pattern | Role in WPCC | Make it… |
|---|---|---|
| **Command palette (⌘K)** | Operator entry to any op/surface/action; the agentic-era "address bar" | Global, in the header |
| **Human-in-the-loop consent** | Approve/reject pending agent actions | A dedicated, always-visible "Needs you" queue |
| **Destructive confirmation w/ phrase + reason** | Already in the engine (DestructiveGuard) | Standardize as one modal component with phrase escalation |
| **Reversibility everywhere** | Rollback as a *property of every change* | Undo column + a promoted Rollback surface |
| **Actor provenance** | human vs system vs agent attribution | Consistent actor chip with identity color + avatar |
| **Activity/audit timeline** | "who/what did this, when, and what it touched" | First-class, cross-linked to the change + the operation |
| **Trust signals** | security mode, capability scope, gated/Pro state | Persistent posture pill + scope badges |
| **Live status** | queue/MCP/streaming state | Subtle live dots + `role=status` regions |

These are exactly the patterns AI Engine *lacks* — WPCC's moat. Designing them as reusable components is the highest-leverage move.

---

## 9. Builder mode vs Engineer mode

**One product, one data model, two lenses — a header toggle, not two apps.**

| | **Builder / Operator mode** | **Engineer / Developer mode** |
|---|---|---|
| Persona | Site owner, agency PM, content lead | Developer, integrator, platform admin |
| Mental question | "Is my site OK? What needs me? Undo it." | "What can run, what's wired, what's the exact state?" |
| Density | Comfortable | Compact |
| Dashboard hero | Needs-you queue + Undo + plain-English activity | Invariants, risk distribution, MCP/tool wiring, capability matrix |
| Operations view | Guided "Run a task" + outcomes | Full 40-op catalogue, params, per-action risk, availability |
| Tokens view | "Who/what has access" summary | Full capability matrix, scopes, audit trail |
| Language | Outcome words ("undo this change") | System words ("rollback change_set #…") |
| Hidden by default | Operation IDs, capability strings, raw audit | Nothing |

The toggle is a **disclosure + density control over shared data**, persisted per user — the structural answer to "who is this product for?": *both*, without forking the UI. It maps cleanly onto the existing **security modes** (developer/client/enterprise) and the **FeatureGate Free/Pro seam**: Builder mode is the natural default for client/enterprise installs; Engineer mode for developer installs.

---

## 10. Reusable design system specification ("Command Design System" / CDS)

A shared kit so **every future plugin in the family inherits one identity** with a per-product accent.

**A. Foundations** — the 3-tier tokens above; light/dark; density modes; a11y baked into tokens (focus ring, contrast-safe risk pairs).

**B. Core components** (plugin-agnostic)
- **App Shell:** branded header (logo slot, posture pill, mode toggle, ⌘K, help), left section rail, sub-tab bar, content canvas.
- **Command Palette** (⌘K).
- **Stat / KPI card**, **Entry-point card**, **Section card**.
- **Data Grid:** density-aware, server-paginated, filterable, `scope` semantics, with empty/loading/error/no-match states as standard variants.
- **Badge family:** Risk pill (5 tiers), Status badge, Actor chip, Pro/gated badge.
- **Approval row** + **Confirmation modal** (phrase + reason escalation).
- **Diff viewer** (shared).
- **Timeline / Activity feed** with provenance + cross-links.
- **Setup Assistant** (steps, completion %, dismiss).
- **Toast / inline notice**, **Empty-state** (illustration + primary action).

**C. Patterns** — human-in-the-loop consent · reversibility affordance · destructive-action handshake · provenance display · mode-aware disclosure · gated/Pro upsell seam.

**D. Voice & content** — plain-English in Builder, precise/system in Engineer; consistent terminology dictionary (operation, capability, token, change set, session, rollback, security mode); never expose raw error codes to Builder users.

**E. Multi-plugin theming contract** — a plugin sets exactly **one** token (`brand.accent`) + its logo + its section map; it inherits everything else. This guarantees a recognizable family while letting each product feel native. CDS is versioned and shared; plugins consume it, never fork it.

---

## 11. Executive summary

WP Command Center has **premium, differentiated substance** (governance, approval, audit, rollback, scoping) wrapped in **commodity, identity-less packaging** (raw WP pages, 12 submenus, two dashboards). AI Engine teaches the *chassis* — one branded home, internal nav, setup assistant, modules, persistent actions — but WPCC must keep its own *positioning*: not "AI features," but **"mission control and the trust layer for AI on WordPress."**

**Four highest-leverage moves**
1. Collapse to one branded shell + one home + the 5-C IA.
2. Ship Builder/Engineer mode as a disclosure lens over shared data.
3. Make the trust patterns (consent queue, reversibility, provenance, command palette) first-class components.
4. Extract all of it into a versioned **Command Design System** themed by a single accent token, so the whole future plugin family shares one language.

Positioning, IA, dashboard, navigation, tokens, and component kit above are the product-level blueprint. **None of it is implemented here** — this document is strategy and design specification only.
