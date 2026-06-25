# Phase 2.5A — Built-in AI Experience Polish (Report)

> **Type:** implementation documentation. **Date:** 2026-06-26.
> **Scope:** experience-layer only — Providers · SEO · Alt Text · Content. **No** architecture/IA/navigation/routing/approvals/rollback/REST/MCP/capability/schema/provider-execution/security change; no new features; no fake data. The Four Guarantees and "Three Doors, One Engine" are untouched.

## 1. Design Token Audit
**Finding:** the four Built-in AI screens were visually inconsistent and diverged from the Home reference (which is fully on the CDS). Audit (cds-refs per view): Home **39**; Providers/SEO/Alt Text/Content **0** — all four used bespoke inline styles and hardcoded hex (`#b32d2e`, `#bd8600`, `#c3c4c7`). Titles also diverged from the nav labels ("SEO Meta," "AI Alt Text," "AI Content"), and Providers carried a "Mission control" sub-heading that collided with the Home (Mission Control) page. Token coverage confirmed available: `--wpcc-space-1..8`, `--wpcc-radius-*`, `--wpcc-fs-*`, `--wpcc-state-*-fg/bg`, `--wpcc-text-secondary`, plus CDS components (`card · chip · pill · empty · notice · btn · loading · field`).

## 2. CDS Adoption Report
- **New shared trust strip** (`partials/builtin-ai-trust.php`) — reuses CDS chip tokens (`--audited`/`--scoped`/`--reversible`) to surface the Four Guarantees consistently on **all four** screens. New `.wpcc-bai-trust` style added to `wpcc-cds.css`, **token-driven only** (no new colors/spacing).
- **Alt Text readiness strip** migrated to `.wpcc-cds-card`; bespoke status hex replaced with `var(--wpcc-state-danger-fg)` / `var(--wpcc-state-warning-fg)`.
- No component was duplicated; existing CDS classes/tokens were used throughout. JS hooks (every `id="wpcc-*"`, `nav-tab-wrapper`) were preserved unchanged.

## 3. UX Before / After Review
| Screen | Before | After |
|---|---|---|
| **Providers** | H1 "Providers" + dark hero; "Mission control" sub-heading (collides with Home); no trust signal | Same hero + **trust strip**; sub-heading → **"Recent AI activity"**; warmer, honest intro |
| **SEO** | H1 **"SEO Meta"** (engineering); plain WP header | H1 **"SEO"** (matches nav) + outcome subtitle + trust strip |
| **Alt Text** | H1 **"AI Alt Text"**; bespoke hex readiness strip | H1 **"Alt Text"** + subtitle + trust strip; readiness strip on CDS card + tokens |
| **Content** | H1 **"AI Content"** | H1 **"Content"** + subtitle + trust strip |

**One-product result:** all four now open with the same header rhythm (title matching the nav · one-line outcome subtitle · the same trust strip), so the journey reads as one polished product.

## 4. Accessibility Report
- View titles now match nav labels (clear page identity). Heading order within each view preserved (h1 → h2 sections).
- Trust strip is a labelled `role="note"` region; chips are token-contrast pairs (AA-aligned).
- No JS hook, tab semantics, focus order, or ARIA was altered — **no a11y regression**.
- Carried-forward global item (not 2.5A scope): the shell also renders an `<h1>` brand, so pages technically have two h1s — a shell-level concern flagged for the global Phase-2.5 pass, unchanged here.

## 5. Customer Experience Review (five personas)
- **Freelancer / small-business owner / AI beginner:** the trust strip ("Reviewed by you · Requires approval · Audited · Reversible") on every screen answers "is this safe?" before they act; outcome subtitles ("Generate… review… approve to apply — nothing changes until you say so") set correct, calm expectations.
- **Agency owner:** consistent headers + nav-matching titles make the four screens feel like one governed surface; no engineering jargon.
- **Power user:** Providers keeps its richer hero + readiness ring; advanced provider details remain where they were (no functional loss).

## 6. Regression Report
- **Lint:** clean on all changed PHP + the new partial; CDS JS parses.
- **Render smoke:** all four screens render with the trust strip, flags on (Providers 52.7k · SEO 64.6k · Alt 42.8k · Content 38.5k).
- **Suites (17, all green):** alt-text-ui 76/0 · seo-review 36/0 · seo-apply 76/0 · seo-undo 33/0 · seo-bulk 36/0 · seo-row-actions 66/0 · ai-content-builder 81/0 · ai-activity-7 15/0 · mission-control-polish-7-5 29/0 · first-value-5c 24/0 · ia-phase1 89/0 · experience-layer 113/0 · usability-5b 36/0 · adoption-readiness 44/0 · wizard-provider-metadata 29/0 · connection-discovery-routing 29/0 · phase-2b 33/0.
- **Invariants:** `34/23/40/40/2.5.0` held.
- **Net-new attributable failures: 0.** The only failing assertions anywhere — `test-seo-audit` classify ×2 and `test-seo-generate` "prior captures" — were **proven pre-existing** (they fail identically with all 2.5A changes stashed); they are functional/environmental, unrelated to header-copy.
- **Tests updated (legitimately affected by UX):** the "Mission control"→"Recent AI activity" rename (×2), reversibility-moved-to-trust-strip (×1), Providers intro reword (×1), and two **Phase-1 link canonicalizations** (`wpcc-ai-integrations`→`wpcc-connect`) that those suites still encoded but had never been re-run.

## 7. Independent Critique
- **I own the inconsistency I just reduced.** My own Phase 2A/2B views also used bespoke styles; the trust strip + token cleanup begins paying that down, but full body-CDS migration of these four (tables, KPI strips, panels) is **deliberately not done** here to keep net-new failures at zero on JS-heavy, flag-gated views. That deeper migration is the documented follow-up.
- **Risk: "polish theater."** Mitigated — the trust strip and nav-matching titles are felt, measurable changes, not decoration; they directly answer the persona questions "is this safe?" and "where am I?".
- **Honest limits:** the trust strip states only real guarantees; no metric, capability, or autonomy is implied. Providers' dark hero vs the three plainer screens still differ in chrome — acceptable for now; full hero harmonization is a follow-up, not a regression.
- **Pre-existing functional failures** in the SEO suites should be triaged separately (they predate this work and the IA phases).

## 8. Final Implementation Report
**Changed files (code):** `assets/css/wpcc-cds.css` (+trust-strip tokens), `includes/Admin/views/ai-setup.php`, `seo-meta.php`, `ai-alt-text.php`, `ai-content.php`; **new** `includes/Admin/views/partials/builtin-ai-trust.php`.
**Changed files (tests):** `test-ai-activity-7`, `test-mission-control-polish-7-5`, `test-seo-audit`, `test-seo-generate`, `test-ai-content-builder`, `test-usability-5b` (copy/UX-affected assertions only).
**Result:** the Built-in AI journey now opens consistently and reassuringly across all four screens, on the CDS, in customer language, with the Four Guarantees always visible — materially closer to Home-level premium quality, with zero behavioral change and zero net-new failures.

**Follow-ups (out of 2.5A scope):** full body-CDS migration (tables/KPI/panels) of the four; harmonize Providers' hero with the three plainer screens; the global shell double-`<h1>`; triage the pre-existing SEO functional failures. Staged on `main`, not pushed; production untouched (Program-4).
