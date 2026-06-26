# WP Command Center — Current Product Status

> **The fastest onboarding document.** A new AI/engineer session should be able to read *this one file* and understand the product correctly. **Date:** 2026-06-26 · **As-built tip:** `214f623` (local `main`, ahead of `origin/main` by 31, **not pushed**). **Production:** unchanged — Program-4 (`2657810`), AI dormant.
> Companions: [`SESSION-HANDOFF-2026-06-26.md`](SESSION-HANDOFF-2026-06-26.md) (repo state + next steps) · [`PRODUCT-MASTER-PLAN.md`](PRODUCT-MASTER-PLAN.md) (strategy) · `master-architecture/*` (binding blueprints) · the phase folders (per-phase detail).

## What the product is (one paragraph)
WP Command Center is **the governed action layer for AI on WordPress** — "Three Doors, One Engine." Any actor (built-in AI using your provider · AI clients over MCP · remote apps over REST) proposes work; it all flows through **one engine** that applies the **Four Guarantees** every time: **human approval** before execution, full **audit**, one-click **rollback**, and **capability scoping**. One-sentence positioning: *"The safe way to let AI change your WordPress site — approve, watch, and undo everything it does."*

## Invariants (held across every phase)
`OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB_VERSION 2.5.0` · `WPCC_VERSION 0.2.0-rc.2`.

## Current implementation status
| Initiative | State | Where |
|---|---|---|
| **Phase 1 — Narrative + IA** (5-C → six product-language sections; door-fork; redirects; loop fix) | ✅ **Done** | `phase-1-ia/` |
| **Phase 2 — Runtime Migration** (2A new homes; 2B retire `dashboard.php`, Settings 8/10→5 tabs) | ✅ **Done** | `phase-2-runtime-migration/` |
| **Phase 2.5 — Experience Polish** (2.5A Built-in AI; 2.5B global titles/trust-strip/CDS sub-nav) | ✅ **Done** | `phase-2-5-builtin-ai/`, `phase-2-5-global/` |
| **Phase 4 — Design-Partner Readiness** (in-admin tool enablement; readiness checklist; Home first-value) | ✅ **Done** | `phase-4-design-partner-readiness/` |
| **Phases 3 / 3A / 3B / 5** — strategy & validation reviews (review-only) | ✅ **Done** (decisions captured) | `phase-3-5-reviews/` |
| Generation Adapters (multi-provider execution) | 🚧 **Deferred** | blueprint §6 |
| Capability-scoped tokens · key encryption-at-rest | 🚧 **Deferred** | |
| Enterprise envelope (SSO/RBAC/export/fleet) · pricing/licensing | 🔮 **Future** | post-PMF |

## Current architecture (as-built)
- **Three Doors:** Built-in AI (Door 1, your provider) · AI Clients (Door 2, MCP) · API & Integrations (Door 3, REST). Each is an adapter + catalogue + credential.
- **One Engine:** Operation Registry (40 ops) → capability gate (23 caps) → approval (security mode) → execute (pre-image) → audit + telemetry + event bus → field-scoped rollback. **Unchanged by Phases 1–4.**
- **Provider reality (honest):** the connection layer is provider-agnostic, but **generation runs on Anthropic only today**; other providers connect/test but can't generate. Surfaced honestly in-product.

## Current IA / UX
- **Six sections:** Home · Built-in AI · Connect · Activity · History · Settings. **Settings = 5 tabs** (Security & Approvals · Access · Tools · Diagnostics ▾ · Advanced ▾ via hub sub-nav). Runtime page retired (redirects preserved).
- **Design system (CDS):** tokens + components; consistent **trust strip** (Reviewed · Requires approval · Audited · Reversible) on write screens; nav-matching titles; unified sub-nav. Front surfaces premium; **daily-loop tables (Approvals/History/Access) still utilitarian** (debt).
- **Built-in AI:** SEO · Alt Text · Content, now **enabled from the UI** (Phase 4) — option-governed, constants/filters still win (shown "Locked"); a tool with no provider reads `requires_provider`. Home shows a **first-value panel** (one next action + readiness checklist).

## Strengths
1. Governance is the substrate, not a feature — approve/audit/undo/scope applied uniformly across all doors (the moat).
2. Real field-scoped, drift-aware **rollback**; append-only **audit** with human/agent provenance.
3. **Radical honesty** ("not tracked yet", "can't generate yet") — trust asset.
4. Self-hosted + BYO-key — no vendor in the data path.
5. Coherent IA + design system — rare for a WordPress plugin.

## Weaknesses / debt
- **UX debt:** daily-loop screens not yet on full CDS; global shell double-`<h1>`; `patches.php` ~3 MB render.
- **Product debt:** Anthropic-only generation; single-site; built-in tools enable per-site.
- **Platform debt:** deferred Engine Inspector (raw internals available via REST/MCP); coarse token scopes.
- **Security debt:** provider keys plaintext at rest (self-hosted mitigates).
- **Commercial debt:** no pricing/licensing/tiering; no SSO/RBAC/export/fleet (all post-PMF, intentionally).
- **Test debt:** 2 pre-existing `test-seo-audit` classify failures (environmental; proven unrelated to recent work).
- **Repo hygiene:** working tree carries one long-standing noise file (`artifacts/step-36-validation/validation-evidence.json`), unrelated to any phase.

## ICP & positioning (from Phase 3B/5)
- **Buy now:** WordPress **agencies/operators running AI on client sites** (Door 1 pain) · **AI-forward teams wiring MCP agents** (Door 2 pain). **The wedge between these is the open question the pilot must resolve.**
- **Do not target:** hobby/solo bloggers (governance is a vitamin for them) · enterprises expecting SSO/fleet today.
- **Positioning:** lead with the *fear→relief* (AI broke a client site, no undo) — never "AI dashboard." The name "Command Center" slightly mis-signals "dashboard"; flagged, not changed.

## Design-partner readiness
**Ready for hand-held pilots.** The first governed-AI demo (connect → enable in-admin → generate → review → approve → apply → undo) runs end-to-end **without code editing**. Founder still pastes a real Anthropic key (BYO; never committed) and confirms Client-safe mode on existing installs.

## Current roadmap position & next step
All *implementation* phases through Phase 4 are complete; *review* phases (3/3A/3B/5) are complete. **Phase 5 verdict: GO to recruit 3–5 design partners, NO-GO to further building.** The recommended next action is **not a build phase** — it's running pilots and letting partner behavior decide the wedge, positioning, and whether the next phase is scale, pivot, or stop.
