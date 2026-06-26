# Session Handoff — 2026-06-27 (current)

> **This is the current authoritative handoff.** Supersedes `SESSION-HANDOFF-2026-06-26.md` (now historical). Fastest product onboarding: [`CURRENT-PRODUCT-STATUS.md`](CURRENT-PRODUCT-STATUS.md).

## TL;DR
Implementation Phases **1 · 2 · 2.5 · 4**, the **Universal AI Provider Runtime (A–D)**, and the **Connect + History UX redesign** are complete, validation-green, and **staged on local `main` (NOT pushed).** Production is untouched. The architecture is **stable**; the product has entered the **Real-World Validation** phase. The standing recommendation is unchanged: **stop building infrastructure, validate real workflows** (Generate → Review → Approve → Apply → Undo) on live sites, then recruit 3–5 design partners.

## Repository state
| Item | Value |
|---|---|
| Current branch | `main` |
| Current HEAD | `87a18f2` — `chore(ui): polish connect and history design-partner flows` |
| Local `main` vs `origin/main` | **ahead 39, behind 0** (staged, **unpushed**) |
| Production | **Program-4 `2657810`** — nothing pushed, nothing deployed |
| Version / invariants | `WPCC_VERSION 0.2.0-rc.2` · OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB 2.5.0 |
| Uncommitted tracked code | **1 CSS-only fix** — code-block text-selection contrast in `includes/Admin/views/ai-integrations.php` (lint-clean, invariants unchanged; ready to commit) |
| Untracked asset | `docs/product/landing-page/index.html` — design-partner pilot landing page (ready to commit) |
| Working-tree noise (pre-existing, not phase work) | `artifacts/step-36-validation/validation-evidence.json` modified — leave as-is or `git checkout --` it |

## Completed milestones (newest first)
- **Connect + History UX redesign** (`87a18f2`, 2026-06-26/27): four design-partner-facing redesigns, UI/view-copy only — **no engine, REST, MCP, token, schema, or governance changes**. Validated net-new failures **0**; invariants held.
  - **Connect → AI Clients = SaaS MCP setup page.** Hero + value/trust chips → primary "Connect your assistant" panel (connection URL + copy, token status, read-only test) → compact "popular assistants" presets → full supported-clients directory demoted to an **Advanced** collapsible. Old client-directory emphasis removed.
  - **Connect → Configuration = guided setup wizard.** Choose assistant → copy configuration → access-token create/use → safe read-only test → safety note; raw endpoint/paths in a disclosure.
  - **MCP config token-metadata cleanup.** "Use in config" no longer injects unused `WPCC_TOKEN_ID/LABEL/SCOPE`; generated configs stay minimal (`WPCC_MCP_URL`, `WPCC_SITE_URL`, `WPCC_TOKEN`, `WPCC_CONTEXT_MODE`). Proven in live render.
  - **History = Review & Undo.** Premium hero + trust chips (Recorded · Reversible · Audited · Safe to undo), polished timeline, clear reversible badges, confident **Undo** action; terminology moved **Restore → Undo** throughout (modal/confirm included).
  - **Code-block selection-contrast fix** (uncommitted): high-contrast `::selection` on `.wpcc-ai-config` / `#wpcc-config-block` / `.wpcc-ai-code`.
- **Universal AI Provider Runtime — Phases A–D** (`8a9d34d`…`2972242`): neutral Anthropic runtime seam (byte-identical) → provider-neutral generation → behaviour-neutral capability gate → OpenAI-compatible execution backend → **SSRF endpoint guard + honest "default provider" copy**. Generation runs through the **one provider set as default** (Anthropic *or* OpenAI-compatible); others connect/test only; nothing auto-selected. No engine/REST/MCP/schema change. (See `phase-5-universal-ai-runtime/PHASE-D-SAFETY-REPORT.md`.)
- **Phase 4 — Design-Partner Readiness** (`5fc9c98`, `214f623`): in-admin enablement of built-in AI tools (option-governed, constants/filters win), `DesignPartnerReadiness` checklist, Home first-value panel. Docs: `phase-4-design-partner-readiness/`.
- **Phase 2.5 — Experience Polish** (`cc0faa9`, `352cc7f`): unified Built-in AI screens, global nav-matching titles, canonical trust strip, CDS sub-nav, engineering-copy purge.
- **Phase 2 — Runtime Migration** (`cf20e50`, `ed79d9e`): retired legacy dashboard; new Settings homes; 8/10→5 tabs with backward-compatible redirects.
- **Phase 1 — Narrative + IA** (`67faa58`, `4938b9a`): six product-language sections (Home · Built-in AI · Connect · Activity · History · Settings); first-run door fork; legacy redirects.

## Strategy & validation reviews (review-only; decisions captured)
- `phase-3-5-reviews/`: **Phase 3** product review, **3A** design-partner readiness, **3B** positioning & GTM, **5** real design-partner validation (GO-to-recruit / NO-GO-to-build).

## Current validation status
**Entering Real-World Validation.** Architecture and the UI/onboarding foundation are substantially complete. No further infrastructure is planned before validation. The governed-AI demo (connect → enable → generate → review → approve → apply → undo) runs end-to-end without code editing.

## Product readiness assets
- Landing page: `docs/product/landing-page/index.html` (untracked, ready to commit).
- Design-partner strategy: `phase-3-5-reviews/` + `phase-4-design-partner-readiness/`.
- 90-second demo script + outreach messages: drafted as deliverables, **not yet persisted as files** — capture them under `phase-4-design-partner-readiness/` before outreach.

## Before inviting external design partners
1. **Deploy the latest build** — `git push origin main` → pull-deploy (~1 min). On deploy, **set Client mode on existing production sites** (the client-safe seed only affects fresh installs).
2. **Internal real-world validation** — run the full Generate → Review → Approve → Apply → Undo loop on live WordPress sites.
3. **Record the 90-second demo.**
4. **Fix any workflow friction discovered** (let real usage drive the next UX changes, not speculative redesign).

## Next recommended task
**Real-world workflow validation on live WordPress sites.** Exercise the five-step governed loop — **Generate → Review → Approve → Apply → Undo** — with a real Anthropic key (BYO; never committed), internally first, then with 3–5 recruited design partners. Build only what partner behaviour justifies.

## Remaining UX backlog (deferred — pull, not push)
Minor Configuration refinements · Activity-tab polish · Security-tab plain-language · CDS migration of the remaining daily-loop tables (Approvals/Access) · minor copy refinements · orphaned `.wpcc-ai-client-card*` dead CSS. Full list in [`CURRENT-PRODUCT-STATUS.md`](CURRENT-PRODUCT-STATUS.md) §Remaining UX backlog.

## Known debt headlines
Daily-loop CDS migration incomplete (Approvals/Access); single-site; plaintext provider keys at rest; deferred Engine Inspector; coarse token scopes; 2 pre-existing `test-seo-audit` classify failures (environmental); no pricing/SSO/fleet (post-PMF, intentional). Detail in `CURRENT-PRODUCT-STATUS.md` §Weaknesses/debt.

## Resume / deploy notes
- Nothing reaches production until an explicit `git push origin main` → pull-deploy (~1 min). Local `main` ahead of `origin/main` is expected (staged), not "behind."
- Built-in AI generation needs a **real Anthropic key** (or an OpenAI-compatible default) — BYO, never committed.
- Two items are staged-but-uncommitted on the working tree (the CSS selection fix + the landing page) — commit when ready; both are safe and validated.
