# WP Command Center — Product Master Plan (Post STEP-109)

> **Status:** Product blueprint — positioning, roadmap, UX strategy, commercial direction. **No code, no implementation details.**
> **Date:** 2026-06-18 · **Baseline:** Phase A complete (STEP 104–109, released `v0.109.0`).
> **Companion docs:** [`UX-AUDIT-AND-DESIGN-SYSTEM.md`](UX-AUDIT-AND-DESIGN-SYSTEM.md) · `PRODUCT-STRATEGY-REPORT.md` · the prior architecture release audit.
> **Operating assumptions (given):** STEP 109 prod-verified · architecture + UX audits complete · no backward-UX-compat constraint · major navigation restructuring permitted · new capabilities permitted · intentional positioning as an **AI Operations Platform for WordPress**.

---

## 1. Product positioning

### 1.1 The refined position — *AI Operations Platform for WordPress* (AIOps for WP)

WordPress is gaining AI *capabilities* fast (chatbots, generation, agents via MCP). What it lacks is an **operational layer** that makes that activity **safe, visible, reversible, and accountable** — and that also lets a human **act through AI under governance**.

> **WP Command Center is the operational layer for AI-powered WordPress sites.**
> Other tools help you *add* AI. WPCC helps you **operate, control, audit, approve, monitor, roll back, and manage** what AI does — and increasingly, **perform governed AI actions yourself** from one console.

The deliberate evolution beyond Phase A: WPCC is **no longer governance-only**. It becomes the place where meaningful AI-powered work *happens*, with governance, auditability, reversibility, and trust as the substrate rather than an afterthought. Every action is a **Governed Action** (see §5).

### 1.2 Target users

| Tier | Persona | Mode (default) | Core need |
|---|---|---|---|
| **Primary** | WordPress **agencies & freelancers** running AI on client sites | Builder | "Let AI help, but never break a client site, and always be able to undo" |
| **Primary** | **Technical site owners / operators** | Builder→Engineer | At-a-glance control, approvals, rollback |
| **Secondary** | **Developers & integrators** building agentic/MCP workflows | Engineer | Full catalogue, capability matrix, MCP wiring, API |
| **Secondary** | **Compliance-conscious / enterprise** teams | Engineer | Audit trail, least-privilege, provenance, export |
| **Tertiary** | **Managed hosts / platform teams** | Engineer | Fleet visibility, policy, observability |

These map directly onto the **Builder vs Engineer mode** strategy (§4.4) and the existing **security modes** (developer / client / enterprise).

### 1.3 What WPCC **is** and **is not**

| WPCC **is** | WPCC **is not** |
|---|---|
| The control & operations plane for AI on WordPress | An AI provider / model host (it's BYO-agent / BYO-key) |
| A trusted execution surface for **governed** AI actions | A chatbot or content-generation product (that is AI Engine's lane) |
| An approval / audit / rollback / change-history system | A page builder or design tool |
| A capability & token (access) manager | A security/malware scanner or firewall |
| An MCP gateway between agents and the site | A replacement for WP core admin |
| A governed action console for operators | A black-box "auto-pilot" that acts without consent or provenance |

**Coexistence principle:** WPCC complements creation tools (AI Engine, Elementor, Woo). It is the *operational substrate beneath them*, not a competitor to their creation features.

---

## 2. Capability model

### 2.1 Current capabilities (Phase A baseline)
- **Operation catalogue** — 40 operations across runtimes: content, media, SEO, ACF, WooCommerce (product/order), site builder, Elementor, plugin/theme management, options, DB inspect, WP-CLI bridge, workflow, reporting.
- **Patch Engine** + **Rollback** (snapshot-backed, byte-for-byte reversibility).
- **Change History** (table-backed change log, sessions, timeline, reversible flagging).
- **Approval workflow** (pending/queue/history; human-in-the-loop).
- **Security modes** (developer / client / enterprise) governing approval gates.
- **Tokens & Capabilities** — 23 capabilities, token scoping, read-only scope, system.admin unlock.
- **MCP server** — 40 tools (1:1 with operations), error-surfaced for agents.
- **Audit log** (append-only) + **DestructiveGuard** (phrase + reason confirmation).
- **Admin read surfaces** (Phase A): Dashboard Overview, Operations Explorer, Approval Center, Tokens & Capabilities, Change History.

### 2.2 The Four Guarantees (capability invariants — non-negotiable for *every* capability, current and future)
1. **Approval flow** — risk-tiered, security-mode-aware human-in-the-loop.
2. **Rollback** — every mutating action is reversible (or explicitly, visibly irreversible with a guard).
3. **Audit trail** — every action is attributed (human / system / agent) and recorded.
4. **Capability scoping** — nothing runs outside the token/capability/least-privilege boundary.

### 2.3 Capability gaps (what's missing for an Operations Platform)
- **Governed action console** — operators can browse but not *act* from the admin (Phase A is read-only).
- **Scheduling / recurring governed operations** (e.g., nightly governed maintenance).
- **Notifications & alerting** (pending approvals, failures, anomalies).
- **Least-privilege roles** (read-only viewer vs operator vs admin — see W3).
- **Policy templates / guardrail presets** (per-environment governance baselines).
- **Usage & cost metering** of AI activity (operator-visible, cost control).
- **Fleet / multisite governance** (one pane across many sites).
- **Compliance reporting / export** (audit export, provenance reports).
- **Drift & anomaly detection** (unexpected agent behavior, config drift).
- **Approval delegation / escalation** (who approves what, fallback approvers).
- **Multi-step governed workflows** with single-gate approval (Phase E).

### 2.4 Capabilities to **add** (sequenced into phases)
Governed action console (D) → scheduling (D) → notifications (D) → least-privilege roles (B/D) → policy templates (D) → usage/cost metering (D) → compliance export (B/D) → AI-assisted multi-step workflows (E) → fleet/multisite (E/F).

### 2.5 Capabilities to **never** add (guardrails on scope)
- **Autonomous unattended destructive actions** that bypass approval.
- **Any path that bypasses audit, rollback, or capability scoping** (breaks the Four Guarantees).
- **Content generation / chatbots / model hosting** (ceded to AI Engine and providers — stay in lane).
- **Malware scanning / WAF / general security-plugin scope** (different product category).
- **Silent telemetry** that violates a Privacy-First stance.
- **"Auto-fix" mutations without provenance** or a reversible record.
- **A second, ungoverned execution path** parallel to the engine (one chokepoint, always).

---

## 3. Architecture debt backlog (prioritized)

Findings carried from the architecture release audit, prioritized by **severity × leverage** and mapped to a phase. Priority tiers: **P0** (blocks certification) → **P3** (cleanup / accept).

| ID | Finding (short) | Type | Severity | Priority | Target phase |
|---|---|---|---|---|---|
| **W2** | Aggregator doesn't re-check each sub-surface's FeatureGate → latent info-disclosure under licensing | Security/consistency | High (latent) | **P0** | B |
| **S1** | Catalogue rebuilt + re-probed every request, no caching | Scalability | High (latent) | **P0** | B |
| **D1** | View-layer JS copy-pasted across surfaces → a11y/i18n drift | Maintainability | High | **P0** | B→C |
| **S2** | Operations Explorer / Tokens unbounded (no pagination) | Scalability | Medium-High | **P0** | B |
| **W1** | Catalogue is a hardcoded inline array (no per-op registration unit) | Architecture | High (latent) | **P1** | B |
| **S3** | Token surfaces scale super-linearly (matrix O(tokens × ops)) | Scalability | Medium | **P1** | B |
| **W3** | No least-privilege tiering (everything `manage_options`) | Security/Product | Medium | **P1** | B (also a D capability) |
| **UX-1** | No product identity (raw WP chrome) | UX | High | **P1** | C |
| **UX-2** | Two "Dashboards" with different data | UX | High | **P1** | C |
| **UX-3** | Menu sprawl (~12 submenus) | UX/IA | High | **P1** | C |
| **C1** | 6 near-identical permission callbacks | Maintainability | Low-Med | **P2** | B/C |
| **C3** | Duplicated security-mode presenter | Maintainability | Low | **P2** | B/C |
| **M1** | Monolithic admin controller | Maintainability | Medium | **P2** | C |
| **UX-4..8** | No onboarding, no command surface, silos, buried rollback, inconsistent micro-UX | UX | Medium | **P2** | C |
| **C2** | Dead `OperationRegistry` instantiation on hot path | Perf/cleanup | Low | **P3** | B (ride-along) |
| **D2** | Cross-surface data duplication (by design, drift-safe) | — | Accept | **P3** | — |

**Sequencing rule:** P0 + P1-security/scalability resolve **inside Phase B** (they are exactly what certification audits). P1-UX + P2-UX resolve in **Phase C**. The remainder rides along.

---

## 4. UX transformation roadmap

Derived from [`UX-AUDIT-AND-DESIGN-SYSTEM.md`](UX-AUDIT-AND-DESIGN-SYSTEM.md). Lands primarily in **Phase C**.

### 4.1 Navigation migration strategy (12 submenus → branded shell + 5-C IA)
- **Strangler migration, not big-bang.** Stand up the **branded App Shell + single "Command Center" entry** with the new **Overview** home first; legacy slugs **redirect** into the new sections (the codebase already uses legacy-slug redirects, so deep links survive).
- Migrate surfaces section-by-section into the **5 C's** — *Overview · Operate · Audit · Access · Connect* — collapsing the two dashboards (UX-2) and the sprawl (UX-3) as each section lands.
- No backward-UX-compat constraint means we can **retire** legacy pages once redirected, keeping only URL redirects for bookmarks.

### 4.2 Dashboard redesign phases
1. **Unify** — one mode-aware home; retire the second dashboard (read-only first).
2. **Action-first** — "Needs you" approval queue + recent-activity timeline + first-class **Undo** column.
3. **Entry points + readiness** — section cards + Setup Assistant.
4. **Mode-aware density** — Builder vs Engineer reshaping of the same data.

### 4.3 Design System rollout (the CDS)
1. **Tokenize** — establish the 3-tier token system (primitives → semantic → component).
2. **Extract CDS v0** — shared component kit (shell, cards, data grid, badges, approval row, confirmation modal, timeline, empty/error states) — directly retires **D1**.
3. **Migrate** — move every Phase A surface onto CDS components.
4. **Freeze CDS v1** — versioned, do-not-fork, themeable by one `brand.accent`.

### 4.4 Builder vs Engineer mode strategy
- **One product, two lenses** — a header toggle that controls **density + disclosure** over shared data, persisted per user.
- **Default by context:** Builder for client/enterprise security modes; Engineer for developer mode.
- **Phasing:** density toggle first → progressive content reshaping (Builder hides operation IDs / capability strings / raw audit; Engineer surfaces invariants, risk distribution, MCP wiring).

### 4.5 Command Palette strategy (⌘K)
- **Phase 1 — Navigate:** jump to any surface/operation/token.
- **Phase 2 — Act (governed):** launch a Governed Action; routes through capability + approval.
- **Phase 3 — Intent:** natural-language "do X" → proposes a governed action/workflow for approval (the agentic-era entry point; never a bypass).

### 4.6 Onboarding strategy
- **Setup Assistant / Readiness** with completion %: connect an agent (MCP) → create a scoped token → choose a security mode → run a test operation → review the first change & undo it.
- Dismissible; re-openable; the readiness state lives on the Overview home.

---

## 5. AI-powered capabilities roadmap (the Governed Action console)

The defining Phase D/E shift: **users perform meaningful AI-powered actions directly in WPCC**, each as a **Governed Action**.

### 5.1 The Governed Action contract (applies to every capability below)
```
Propose (read / AI suggestion)
   → Capability check (scope)
      → Approval (if risk tier requires, per security mode)
         → Execute (single engine chokepoint)
            → Record change (audit + provenance)
               → Reversible (rollback available)
```
**Propose ≠ Apply.** AI may freely *propose*; *applying* always passes the Four Guarantees. This is the product's moat — AI power *with* a seatbelt.

### 5.2 Capability categories (examples — all preserve approval + rollback + audit + scoping)

| Category | Example governed actions |
|---|---|
| **Content** | Bulk rewrite / translate / tone-adjust posts; fix broken structure; governed publish/update; summarize-and-link |
| **Media** | AI alt-text generation; image optimization; thumbnail regeneration; guarded unused-media cleanup *(several already exist as reversible ops — surface them as actions)* |
| **WooCommerce** | Bulk product-description enrichment; category/tag normalization; order-triage summaries; refund preparation (review-gated) |
| **SEO** | Meta title/description generation + validation; schema; internal-linking proposals; governed writes to Rank Math/Yoast |
| **Site maintenance** | Safe updates; patch application; snapshot + rollback; drift remediation |
| **Diagnostics** | Health & security reports; capability/usage audits; "explain what changed"; anomaly flags |
| **AI-assisted workflows** | Multi-step governed sequences ("prepare site for launch") with a single approval gate (Phase E) |

### 5.3 Hard rule
No category above may ship a path that skips any of the Four Guarantees. A "fast" mode = fewer *prompts* (security-mode dependent), never less *governance*.

---

## 6. Product phases

> Dependency order: **B → C → (D, E)**; **F** can begin partially once C lands the Free/Pro seam and marketplace shell. The Four Guarantees and "net-new 0 vs regression baseline" discipline apply throughout.

### Phase B — Platform Hardening & Certification
- **Goals:** retire P0/P1 security & scalability debt; make the platform certify-and-freeze ready; ready the catalogue for 200+ operations; establish least-privilege and a compliance baseline.
- **Deliverables:** gate-coherence fix (W2); catalogue-as-registry + caching foundation (W1/S1); pagination/scalability consistency (S2/S3); shared substrate seams that unblock CDS (D1 precursors, C1/C3); least-privilege viewer role (W3); compliance/audit export baseline; a certification pass + security review.
- **Risks:** scope creep into UX work; touching the engine chokepoint; certification slipping if debt is certified-around instead of resolved.
- **Success criteria:** clean security review; 200-op readiness demonstrated; invariants held (ops/caps/tools/schema); least-privilege role shipped; net-new 0 through the phase; certification stamp achieved.

### Phase C — UX & Design System Transformation
- **Goals:** give WPCC a product identity and a coherent operator cockpit; ship the CDS; collapse IA.
- **Deliverables:** branded App Shell + single menu; 5-C IA; unified mode-aware Dashboard; CDS v1 (tokens + component kit) with all Phase A surfaces migrated; Builder/Engineer mode; Command Palette (navigate + act); Setup Assistant onboarding.
- **Risks:** migration breakage of deep links (mitigate with redirects); design-system over-engineering; mode toggle adding complexity instead of clarity.
- **Success criteria:** one menu / one home; every surface on CDS; measurable reduction in task time and clicks-to-action; onboarding completion rate; consistent a11y/i18n across surfaces (D1 closed).

### Phase D — Operations Layer Expansion
- **Goals:** turn WPCC from read-only governance into a **governed action console**; close the operational capability gaps.
- **Deliverables:** Governed Action console; scheduling/recurring governed ops; notifications/alerting; policy templates/guardrail presets; usage/cost metering; expanded reversible operations across content/media/Woo/SEO/maintenance.
- **Risks:** any new action weakening a guarantee; cost/usage data accuracy; alert fatigue; permission surface growth.
- **Success criteria:** users routinely perform governed actions from admin; N governed actions shipped, **100% preserving the Four Guarantees**; zero ungoverned-mutation incidents; adoption of scheduling/notifications.

### Phase E — AI-Powered Workflows
- **Goals:** compose governed actions into **multi-step AI-assisted workflows** with single-gate approval; deliver the agentic intent layer.
- **Deliverables:** workflow composer + workflow/recipe library/templates; Command-Palette **intent** mode (NL → proposed governed workflow); sequence-level approvals; workflow provenance & rollback-of-a-sequence.
- **Risks:** complexity of multi-step rollback; partial-failure semantics; over-automation eroding the human-in-the-loop principle.
- **Success criteria:** workflow adoption & time-saved metrics; sequences fully reversible and audited; no workflow can bypass approval/scoping.

### Phase F — Commercialization & Marketplace
- **Goals:** monetize via Free/Pro tiers and an ecosystem; build durable revenue.
- **Deliverables:** Free/Pro tiering on the existing **FeatureGate** seam; licensing; pricing (incl. agency/multisite/enterprise plans); **Add-ons marketplace**; partner/extension program; in-product upgrade seams (inline Pro badges already pattern-ready).
- **Risks:** gating value that should stay free (trust erosion); pricing mismatch to agency vs enterprise; marketplace quality control.
- **Success criteria:** Free→Pro conversion; MRR/ARR targets; marketplace listings & partner adoption; healthy retention of the trust narrative (governance core stays accessible).

---

## 7. Future plugin ecosystem — CDS as the shared design language

Assume a **family of "Command" plugins** over time. The **Command Design System (CDS)** is how they share one identity and one trust model.

### 7.1 Two shared layers
1. **CDS (design language)** — versioned tokens + component kit + patterns + voice. A plugin overrides exactly **one** primitive (`brand.accent`) + its logo + its section map, and inherits everything else. Consume, never fork.
2. **Command Platform spine (governance services)** — the Four Guarantees as *shared platform services*, not just UI: a common approval/audit/rollback/capability backbone that any family plugin plugs into. The *trust contract* becomes reusable infrastructure.

### 7.2 Why this compounds
- **Instant brand recognition** across the family (one language, per-product accent).
- **Lower build cost** — new plugins start from the shell + governed-action contract.
- **Consistent trust UX** — approval, provenance, rollback feel identical everywhere.
- **Single pane potential** — WPCC, as the flagship control plane, can aggregate governance/audit **across** the whole family (cross-plugin Command Center).

### 7.3 Governance of the system itself
- **Versioned & semver'd**; documented deprecation policy.
- **Do-not-fork rule** (directly answers the D1 finding at the platform layer).
- **Contribution model** — components graduate from a plugin into CDS only when generalized.
- **WPCC is the reference implementation** — the first and canonical consumer.

---

## 8. One-page summary

- **Position:** the **AI Operations Platform for WordPress** — operate, control, audit, approve, monitor, roll back, manage AI activity, *and now act through AI under governance*. Complements (does not compete with) creation tools.
- **Capability model:** keep the Four Guarantees inviolable; add a governed action console, scheduling, notifications, least-privilege, metering, workflows; never add ungoverned/auto-destructive/out-of-lane scope.
- **Debt:** resolve P0 (W2, S1, D1, S2) + P1 security/scalability (W1, S3, W3) in **Phase B**; UX debt in **Phase C**.
- **UX:** branded shell + 5-C IA + unified dashboard + CDS + Builder/Engineer mode + command palette + onboarding (**Phase C**).
- **Phases:** **B** Hardening & Certification → **C** UX & Design System → **D** Operations Layer → **E** AI Workflows → **F** Commercialization & Marketplace.
- **Ecosystem:** CDS + a shared governance spine make WPCC the flagship of a recognizable, trust-first **Command** plugin family.

*Product architecture, roadmap, UX strategy, and commercial direction only. No code or implementation detail is specified here.*
