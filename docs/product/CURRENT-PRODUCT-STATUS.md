# WP Command Center — Current Product Status

> **The fastest onboarding document.** A new AI/engineer session should be able to read *this one file* and understand the product correctly. **Date:** 2026-06-27 · **As-built tip:** `87a18f2` (local `main`, ahead of `origin/main` by 39, **not pushed**) + one uncommitted CSS fix (code-block text-selection contrast) and the untracked landing page (`docs/product/landing-page/index.html`). **Production:** unchanged — Program-4 (`2657810`), AI dormant.
> Companions: [`SESSION-HANDOFF-2026-06-27.md`](SESSION-HANDOFF-2026-06-27.md) (repo state + next steps) · [`PRODUCT-MASTER-PLAN.md`](PRODUCT-MASTER-PLAN.md) (strategy) · [`UX-AUDIT-AND-DESIGN-SYSTEM.md`](UX-AUDIT-AND-DESIGN-SYSTEM.md) (UX + design system) · `master-architecture/*` (binding blueprints) · the phase folders (per-phase detail).

> ## ▶ Current phase: Real-World Validation
> The architecture is **stable** and the UI/onboarding foundation is **substantially complete**. The focus is **no longer adding infrastructure** — it is **validating real user workflows** (Generate → Review → Approve → Apply → Undo) on live WordPress sites. Future UX changes should be driven by **observed real usage**, not speculative redesign.

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
| **Universal AI Provider Runtime (Phases A–D)** (neutral runtime seam; provider-neutral generation; capability gate; OpenAI-compatible execution backend; SSRF endpoint guard + honest copy) | ✅ **Done** | commits `8a9d34d`…`2972242` |
| **Connect & History UX redesign** (AI Clients→MCP setup page; Configuration→SaaS setup wizard; History→Review & Undo; MCP config token-metadata cleanup; code-block selection fix) | ✅ **Done** | `87a18f2` (+ uncommitted CSS fix) |
| **Phases 3 / 3A / 3B / 5** — strategy & validation reviews (review-only) | ✅ **Done** (decisions captured) | `phase-3-5-reviews/` |
| **Real-World Workflow Validation** — internal dry-run on live sites, then design-partner pilots | 🟡 **In progress** (current milestone) | this doc §roadmap |
| Generation Adapters (multi-provider execution) | 🚧 **Deferred** | blueprint §6 |
| Capability-scoped tokens · key encryption-at-rest | 🚧 **Deferred** | |
| Enterprise envelope (SSO/RBAC/export/fleet) · pricing/licensing | 🔮 **Future** | post-PMF |

## Current architecture (as-built)
- **Three Doors:** Built-in AI (Door 1, your provider) · AI Clients (Door 2, MCP) · API & Integrations (Door 3, REST). Each is an adapter + catalogue + credential.
- **One Engine:** Operation Registry (40 ops) → capability gate (23 caps) → approval (security mode) → execute (pre-image) → audit + telemetry + event bus → field-scoped rollback. **Unchanged by Phases 1–4.**
- **Provider reality (honest, post Phases A–D):** a neutral runtime seam now drives generation through the **one provider you set as the default** — **Anthropic *or* an OpenAI-compatible endpoint** can generate when selected. Other providers connect/test but don't generate; **nothing is auto-selected**; content is sent only to the provider you pick. Custom endpoints pass an **SSRF guard** (blocks loopback/private/link-local/cloud-metadata; declared-local providers excepted). Production posture: AI dormant, key unset.

## Current IA / UX
- **Six sections:** Home · Built-in AI · Connect · Activity · History · Settings. **Settings = 5 tabs** (Security & Approvals · Access · Tools · Diagnostics ▾ · Advanced ▾ via hub sub-nav). Runtime page retired (redirects preserved).
- **Design system (CDS):** tokens + components; consistent **trust strip / trust chips** (Reviewed · Requires approval · Audited · Reversible · Scoped access) on write + connect screens; nav-matching titles; unified sub-nav.
- **Connect experience (2026-06 redesign):** **AI Clients** is now a **SaaS-style MCP setup page** (hero + value/trust chips → primary "Connect your assistant" panel with connection URL, copyable config, token status, read-only connection test → compact "popular assistants" presets → the full supported-clients directory demoted to an **Advanced** collapsible — the old client-directory emphasis is gone). **Configuration** is a **guided setup wizard** (choose assistant → copy configuration → access-token create/use → safe read-only test → safety note), with raw endpoint/paths in a disclosure. Generated MCP configs are now **minimal** — "Use in config" no longer injects unused `WPCC_TOKEN_ID/LABEL/SCOPE` metadata.
- **History experience (2026-06 redesign):** **Review & Undo** — premium hero + trust chips (Recorded · Reversible · Audited · Safe to undo), polished timeline rows, clear **reversible badges**, and a confident, consistent **Undo** action. Terminology moved from *Restore* → **Undo** throughout (modal/confirm included). This upgrades one of the previously-utilitarian daily-loop screens.
- **Polish details:** code/config blocks now have high-contrast **text-selection** styling (was near-invisible on the dark block); design-consistency pass across Connect/History (cards, radius, shadows, focus states, `aria-current`).
- **Remaining utilitarian surfaces:** Approvals and Access tables are still pragmatic (not yet full CDS) — see §Weaknesses/debt.
- **Built-in AI:** SEO · Alt Text · Content, now **enabled from the UI** (Phase 4) — option-governed, constants/filters still win (shown "Locked"); a tool with no provider reads `requires_provider`. Home shows a **first-value panel** (one next action + readiness checklist).

## Strengths
1. Governance is the substrate, not a feature — approve/audit/undo/scope applied uniformly across all doors (the moat).
2. Real field-scoped, drift-aware **rollback**; append-only **audit** with human/agent provenance.
3. **Radical honesty** ("not tracked yet", "can't generate yet") — trust asset.
4. Self-hosted + BYO-key — no vendor in the data path.
5. Coherent IA + design system — rare for a WordPress plugin.

## Weaknesses / debt
- **UX debt:** Connect + History are now premium; **Approvals and Access tables still utilitarian** (not yet full CDS); global shell double-`<h1>`; `patches.php` ~3 MB render. See §Remaining UX backlog.
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

## Remaining UX backlog (intentionally deferred — pull, not push)
Only genuine remaining work; tackle when real usage justifies it, not speculatively.
- **Minor Configuration refinements** — collapse the setup card's three sequential `panel__body` blocks; soften connection-test result rows ("Found N tools" vs developer diagnostics); optional inline (no-reload) "Use in config".
- **Activity tab polish** — still a plain table; bring to the Connect/History standard.
- **Security tab plain-language** — soften the developer-flavoured "Architecture path" diagram into customer language.
- **CDS migration** — finish moving the remaining daily-loop tables (Approvals, Access) onto full CDS components; the Connect/History views still use bespoke `wpcc-ai-*` / `wpcc-*` styles rather than full CDS.
- **Minor copy refinements** — detail-drilldown labels, residual engineering terms on secondary surfaces.
- **Dead CSS** — orphaned `.wpcc-ai-client-card*` rules left after the AI-Clients restructure (harmless).

## Product readiness assets (design-partner)
- **Landing page** — `docs/product/landing-page/index.html` (self-contained design-partner pilot page; **untracked**, ready to commit).
- **Design-partner strategy** — `phase-3-5-reviews/` (PHASE-3A readiness, 3B positioning/GTM, 5 validation) + `phase-4-design-partner-readiness/` (FIRST-WORKFLOW, READINESS-CHECKLIST).
- **90-sec demo script + outreach messages** — drafted as deliverables; **not yet persisted as standalone files** (capture them under `phase-4-design-partner-readiness/` before outreach).

## Design-partner readiness
**Ready for hand-held pilots.** The first governed-AI demo (connect → enable in-admin → generate → review → approve → apply → undo) runs end-to-end **without code editing**. Before inviting external design partners we will: **(1) deploy the latest build** (`git push origin main` → pull-deploy; set Client mode on existing installs), **(2) perform internal real-world validation** on live WordPress sites, **(3) record the 90-second demo**, and **(4) fix any workflow friction discovered**. Founder still pastes a real Anthropic key (BYO; never committed).

## Current roadmap position & next step
All *implementation* phases through Phase 4 plus the Universal AI Provider Runtime (A–D) and the Connect/History UX redesign are complete; *review* phases (3/3A/3B/5) are complete. Infrastructure and the UI/onboarding foundation are **substantially complete**. **Next milestone = Real-World Workflow Validation** — exercise Generate → Review → Approve → Apply → Undo on live sites (internal first, then design-partner pilots). **The recommended next task is not a build phase**: validate real workflows and let observed behavior decide the wedge, positioning, and whether the next phase is scale, pivot, or stop.
