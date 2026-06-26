# Session Handoff — 2026-06-26 (current)

> **This is the current authoritative handoff.** Supersedes `SESSION-HANDOFF-2026-06-25.md` (now historical). Fastest product onboarding: [`CURRENT-PRODUCT-STATUS.md`](CURRENT-PRODUCT-STATUS.md).

## TL;DR
Implementation Phases **1 · 2 · 2.5 · 4** are complete, validation-green, and **staged on local `main` (NOT pushed).** Production is untouched. The first governed-AI demo now runs end-to-end without code editing. Strategy/validation reviews (Phases 3/3A/3B/5) are captured in `phase-3-5-reviews/`. The standing recommendation (Phase 5): **stop building, recruit 3–5 design partners.**

## Repository state
| Item | Value |
|---|---|
| Current branch | `main` |
| Current HEAD | `214f623` — `test(phase-4): readiness acceptance suite + design-partner docs` |
| Local `main` vs `origin/main` | **ahead 31, behind 0** (staged, **unpushed**) |
| `origin/main` | `94a716c` (unchanged) |
| Production | **Program-4 `2657810`** — nothing pushed, nothing deployed |
| Version / invariants | `WPCC_VERSION 0.2.0-rc.2` · OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB 2.5.0 |
| Uncommitted tracked code/test | **NONE** |
| Working-tree noise (pre-existing, not phase work) | `artifacts/step-36-validation/validation-evidence.json` modified — leave as-is or `git checkout --` it |

## Completed implementation phases (newest first)
- **Phase 4 — Design-Partner Readiness** (`5fc9c98`, `214f623`): in-admin enablement of built-in AI tools (SEO/Alt Text/Content) — option-governed, constants/filters still win (shown "Locked"), tool-without-provider reads `requires_provider`; governed (nonce + `manage_options` + audited). New `BuiltinAiSettings` + `DesignPartnerReadiness` (live 8-item readiness) + Home first-value panel. No schema/DB_VERSION change (a WP option). Docs: [`phase-4-design-partner-readiness/`](phase-4-design-partner-readiness/). Suite `test-phase-4-readiness` 58/0; net-new 0.
- **Phase 2.5 — Experience Polish** (`cc0faa9` = 2.5A, `352cc7f` = 2.5B): 2.5A unified the Built-in AI screens (titles, subtitles, shared trust strip); 2.5B took it global — view titles now match nav (Operations Center→Live activity, Approval Center→Approvals, Change History→History, Tokens & Capabilities→Access, Operations Explorer→Capabilities, Site Intelligence→Site report), the canonical `trust-strip.php` on all write screens, a CDS `.wpcc-cds-subnav` for the Settings hubs, and engineering-copy purge. Docs: [`phase-2-5-builtin-ai/`](phase-2-5-builtin-ai/), [`phase-2-5-global/`](phase-2-5-global/). Net-new 0.
- **Phase 2 — Runtime Migration** (`cf20e50` = 2A, `ed79d9e` = 2B): retired the legacy "Agent Runtime Dashboard" (`dashboard.php` deleted); built new homes (Settings › Tools = Safe Search & Replace; Settings › Diagnostics › Recommendations); grouped Settings **8/10 → 5 tabs** with pane-precise backward-compatible redirects (0 loops). Docs: [`phase-2-runtime-migration/`](phase-2-runtime-migration/). Net-new 0.
- **Phase 1 — Narrative + IA** (`67faa58`, polish `4938b9a`): 5-C → six product-language sections (Home · Built-in AI · Connect · Activity · History · Settings); first-run door fork; tab-aware legacy redirects; fixed a Settings redirect loop. Docs: [`phase-1-ia/`](phase-1-ia/) (9 deliverables). Net-new 0.

## Strategy & validation reviews (review-only; decisions captured)
- [`phase-3-5-reviews/`](phase-3-5-reviews/): **Phase 3** (product review), **3A** (design-partner readiness), **3B** (positioning & GTM), **5** (real design-partner validation → GO-to-recruit / NO-GO-to-build).

## Current roadmap position
Implementation through Phase 4 is done; reviews through Phase 5 are done. **Next recommended action is NOT a build phase** — recruit and run design-partner pilots (see `phase-3-5-reviews/PHASE-5-DESIGN-PARTNER-VALIDATION.md` and `phase-4-design-partner-readiness/FIRST-WORKFLOW.md`). Build only what a partner's behavior justifies.

## Known technical / product / commercial debt
See [`CURRENT-PRODUCT-STATUS.md`](CURRENT-PRODUCT-STATUS.md) §Weaknesses/debt. Headlines: daily-loop CDS migration incomplete; Anthropic-only generation; single-site; plaintext keys at rest; deferred Engine Inspector; 2 pre-existing `test-seo-audit` classify failures (environmental); no pricing/SSO/fleet (post-PMF, intentional).

## Resume / deploy notes (unchanged from prior handoffs)
- Nothing reaches production until an explicit `git push origin main` → pull-deploy (~1 min). Local `main` ahead of `origin/main` is expected (staged), not "behind."
- On deploy: **set Client mode on existing production sites** (the client-safe seed only affects fresh installs — finding I1).
- Built-in AI generation needs a **real Anthropic key** (BYO; must not be committed).
