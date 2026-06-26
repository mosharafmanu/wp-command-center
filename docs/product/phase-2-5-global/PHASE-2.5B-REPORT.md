# Phase 2.5B — Global Experience Polish (Report)

> **Type:** implementation documentation. **Date:** 2026-06-26.
> **Scope:** experience-layer only across Home · Built-in AI · Connect · Activity · History · Settings. **No** architecture/IA/routing/REST/MCP/approvals/rollback/capability/database/provider-execution change; no new features; no behavior change. The Four Guarantees are intact.

## 1. Executive Summary
WP Command Center read as several independent admin pages mainly because of two inconsistencies: **screen titles that contradicted their own nav breadcrumb**, and a **trust message that only existed on some screens**. 2.5B fixes both globally — every retitled screen now matches its nav label, the **single canonical trust strip** (the Four Guarantees) appears on every write screen, the Settings hub sub-navs join the design system, and residual engineering copy is gone. The product now reads as one premium, governed product. Zero behavior changed; net-new attributable test failures = 0.

## 2. UX improvements made
- **Titles match navigation** on six screens (below) — the single biggest "one product" fix.
- **One trust strip everywhere** — generalized the Built-in AI trust partial into a canonical `trust-strip.php` and applied it to **Tools** and **Recommendations** (in addition to the four Built-in AI screens), so every write surface consistently shows *Reviewed by you · Requires approval · Audited · Reversible*.
- **One sub-nav style** — the Settings **Diagnostics** and **Advanced** hubs now use a CDS `.wpcc-cds-subnav` (token-driven, subtle hover, visible focus) instead of bespoke inline blue pills.
- **Customer copy** — purged engineering terms from Activity › Live ("Data coverage"→"What's measured", "Operation telemetry"→"Activity tracking", "AI runtime is instrumented"→"AI usage reporting is available"); "Back to Approval Center"→"Back to Approvals".

## 3. Screens improved
| Section | Screen | Change |
|---|---|---|
| Activity | **Live** | H1 "Operations Center" → **"Live activity"**; engineering copy → customer language |
| Activity | **Approvals** | H1 "Approval Center" → **"Approvals"**; back-link copy |
| History | **History** | H1 "Change History" → **"History"** |
| Settings | **Access** | H1 "Tokens & Capabilities" → **"Access"** |
| Settings | **Capabilities** (Advanced) | H1 "Operations Explorer" → **"Capabilities"** |
| Settings | **Site report** (Diagnostics) | H1 "Site Intelligence" → **"Site report"** |
| Settings | **Tools**, **Recommendations** | trust strip added |
| Settings | **Diagnostics / Advanced hubs** | CDS sub-nav |
| Built-in AI | Providers/SEO/Alt/Content | re-pointed to the generalized `trust-strip.php` |

## 4. CDS adoption improvements
- New canonical `partials/trust-strip.php` (replaces the AI-specific `builtin-ai-trust.php`) — **one** trust pattern, no duplication, used on all six write screens.
- New `.wpcc-cds-subnav` component (token-driven) adopted by both Settings hubs — retires the third, bespoke tab style flagged in the 2.5 review.
- Both additions are **token-only** (`--wpcc-space-*`, `--wpcc-gray-*`, `--wpcc-blue-600`, `--wpcc-border-*`, `--wpcc-radius-sm`, `--wpcc-focus-ring-width`) — no new colors or magic numbers.

## 5. Visual hierarchy improvements
- Titles now establish a clear, correct page identity (primary), with the description (secondary) and trust strip (supporting) beneath — consistent reading order across sections.
- The hub sub-nav's active pane is now visually distinct via the accent token (clear "where am I"), with hover/focus affordances.

## 6. Typography improvements
- Page titles are consistent and match the nav vocabulary; engineering nouns removed from headings/labels (no "telemetry", "Operations Center", "Site Intelligence" in customer view).

## 7. Density reductions
- No new density added. The hub sub-nav is lighter/cleaner; the trust strip is compact chips (no walls of text). (Deeper density work — large tables, Patches payload — remains a documented follow-up, out of 2.5B scope.)

## 8. Accessibility improvements
- `.wpcc-cds-subnav__item` adds a **visible `:focus-visible` ring** (token-driven) and honors `prefers-reduced-motion`; `aria-current="page"` preserved on the active pane.
- Trust strip is a labelled `role="note"` region with token-contrast chips.
- Titles matching nav improve screen-reader wayfinding. No ARIA/focus/tab semantics were removed.

## 9. Validation results
- **Lint:** clean on all 16 changed PHP files; CDS JS parses; new CSS is token-only.
- **Render smoke:** every section + hub pane renders (0 fatals); sub-nav + trust strip confirmed present.
- **Suites green (18):** operations-center-10 28/0 · approval-center 127/0 · token-capability-admin 155/0 · operations-explorer 152/0 · cds-foundation 53/0 · phase-2a 45/0 · phase-2b 33/0 · recommendations 45/0 · ia-phase1 89/0 · alt-text-ui 76/0 · ai-content-builder 81/0 · seo-apply 76/0 · experience-layer 113/0 · usability-5b 36/0 · first-value-5c 24/0 · change-history-admin 119/0 · change-history-runtime 57/0.
- **Invariants:** `34/23/40/40/2.5.0` held.
- **Net-new attributable failures: 0.** Only `test-seo-audit`'s 2 classify assertions still fail — **proven pre-existing** (in 2.5A) and unrelated to copy/CDS.
- **Tests updated (UX-affected only):** `test-approval-center` (back-link copy), `test-operations-center-10` ("Data coverage"→"What's measured"), `test-seo-audit` (trust-partial path rename). The six retitled views' suites passed unchanged (their tests did not assert the old H1s).

## 10. Independent critique
- **Bounded by design.** I deliberately did *not* attempt a full body-CDS migration of every JS-heavy view (approval-center, operations-center, change-history, token-capability-manager tables) — that carries real net-new-failure risk on flag-driven/REST views for incremental gain. The high-leverage, low-risk levers (titles, trust, sub-nav, copy) were prioritized; deeper table/card CDS migration is the documented follow-up.
- **Honesty preserved.** The trust strip states only real guarantees; the "what's measured" copy stays honest ("nothing is estimated").
- **Risk owned.** The trust-partial rename touched the four Built-in AI includes + one test grep; verified green. The title changes were the riskiest test surface (broad references) but proved safe — most references were audit/REST/capability strings, not H1s.

## 11. Remaining polish opportunities
- Full body-CDS migration of the daily-loop tables/cards (Activity, History, Approvals, Access).
- Global shell double-`<h1>` (brand + view) — a shell-level change deferred to a dedicated pass.
- Patches view payload density; loading-skeleton + success-toast consistency; Providers hero vs the three plainer Built-in AI screens.
- Triage the pre-existing SEO functional test failures.

## 12. Final recommendation
2.5B delivers the "one product" feel at the global level with zero behavioral risk: consistent titles, one trust language, one sub-nav style, customer copy. Recommend proceeding (when scheduled) to the deeper body-CDS migration as 2.5C, then the global shell heading pass. Staged on `main`, **not pushed**; production untouched (Program-4).

**Changed files (code):** `assets/css/wpcc-cds.css`; views `ai-setup`, `seo-meta`, `ai-alt-text`, `ai-content`, `tools-search-replace`, `recommendations`, `operations-center`, `approval-center`, `change-history`, `token-capability-manager`, `operations-explorer`, `site-intelligence`, `settings-diagnostics`, `settings-advanced`; partial renamed `builtin-ai-trust.php` → `trust-strip.php`. **Tests:** `test-approval-center`, `test-operations-center-10`, `test-seo-audit`.
