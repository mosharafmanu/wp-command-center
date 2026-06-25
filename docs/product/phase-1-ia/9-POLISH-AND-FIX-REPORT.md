# Phase 1 — Polish & Fix Report (pre-beta hardening)

> **Type:** implementation documentation. **Date:** 2026-06-25.
> **Trigger:** Phase 1 not yet accepted — a beta-readiness review surfaced a broken Settings page (redirect loop). This report covers the root-cause fix, exhaustive navigation verification, and the self-critique + UX polish that followed. **No new runtime features; Three Doors, One Engine preserved.**

## 1. The Settings bug — root cause
`wpcc-settings` is the **live Settings section slug** *and* was also a key in `AppShell::legacy_map()` (it was the pre-Phase-1 standalone "Security Mode" page slug). `resolve_legacy()` consulted the standalone map even for live section slugs, so **every** Settings URL resolved to `[wpcc-settings, security]`:
- `?page=wpcc-settings` → redirect to `…&wpcc_tab=security` → resolves again to the same → **infinite loop** ("localhost redirected you too many times").
- `?page=wpcc-settings&wpcc_tab=access` (and every other Settings tab) → resolved to `[settings, security]` → **bounced to Security**, so the whole section was unreachable.

## 2. The fix (navigation-only, no engine change)
`AppShell::resolve_legacy()` now **short-circuits live section slugs**: a slug that is itself one of the six current sections is never treated as a legacy standalone slug. Reused live slugs (Connect) still remap their *old* tab keys; any current/empty tab on a live section renders as-is. A no-op guard also prevents emitting a redirect to the exact same `page+tab`. The dead self-referential `wpcc-settings` entry was removed from `legacy_map()` (with a comment explaining why). The old `?page=wpcc-settings&section=tokens` deep-link is still handled (in `AdminMenu`, before resolution) → Settings › Access.

```
Before: resolve_legacy('wpcc-settings','')        => [wpcc-settings, security]   // loop
        resolve_legacy('wpcc-settings','access')  => [wpcc-settings, security]   // bounce
After:  resolve_legacy('wpcc-settings', <any>)    => null                        // renders
```

## 3. Navigation verification (tasks 3–8) — all green

| # | Check | Method | Result |
|---|---|---|---|
| 3 | Every nav item works | Live render of all **6 sections + every tab (25 variants)** through the shell | **OK** — every variant renders with chrome, **zero fatals** |
| 4 | Every legacy redirect | `resolve_legacy()` swept over **all** `legacy_map` + `legacy_tab_map` entries; followed to fixpoint | **139/139** terminate on a real section, **no loops** |
| 4 | Live redirect (end-to-end) | `AdminMenu::redirect_legacy_slugs()` driven via `$_GET` with `is_admin()` forced | Settings bare/tabs **render**; `section=tokens`→Access; `operate/approvals`→`activity/approvals`; `wpcc-tokens`→`settings/access` — all correct |
| 5 | Every submenu | Six submenus registered; retired per-view submenus absent | asserted (`lacks` guards) |
| 6 | Breadcrumbs | Shell renders `Command Center › [Section]` (Home → "Mission Control"); active tab `aria-current="page"` | present in all 25 rendered variants |
| 7 | Back/forward | Live nav = plain `<a href>` to distinct URLs, **zero redirects**; legacy URLs do one 302 hop then render | native history works; no loop |
| 8 | No broken URLs | All internal links repointed (verified earlier); all redirect destinations are real sections | clean |

A durable regression guard was added — `test-ia-phase1.sh §8` exhaustively asserts every section/tab renders and every legacy path terminates on a real section (live wp-cli), so this class of bug cannot silently return.

## 4. UX self-critique (challenging my own IA) + polish applied
I reviewed against the milestone's questions and did **not** assume the IA was correct.

| Question | Verdict | Action |
|---|---|---|
| Is Home optimized for first-timers? | Mostly — the door fork leads. | Kept; tightened labels (below). |
| **Does Home expose architecture instead of goals?** | **Yes — a real miss.** The "Subsystems" cards read *Approval Center · Operations · Tokens & Capabilities · Change History* (pre-Phase-1 names). | **Fixed:** heading "Subsystems" → **"At a glance"**; cards → **Approvals · Capabilities · Access · History** (match the nav). |
| **Can users see how to generate SEO/Alt/Content?** | **No when the tools are flag-off** — Built-in AI showed only "Providers" with no explanation. | **Fixed (honest, no fake toggle):** Built-in AI now shows a note — *"SEO, Alt Text, and Content tools appear here once enabled for this site…"* — only when those tabs are gated off. Verified it shows with flags off and hides with flags on. |
| Does Built-in AI feel too technical? | Acceptable — "Providers" + clear tool names. | None. |
| Is Connect understandable without MCP? | Yes — "AI Clients" H1 + plain-language explainer; "API & Integrations" landing explains Door 3. | None. |
| Does Settings hide too much / have too many tabs? | 8 tabs is heavy but it is the deliberate advanced drawer; all reachable now. | Kept; grouping (Diagnostics⊇Patches/Report; Access⊇Files) noted as a forward view-merge. |
| Unnecessary clicks / duplicated concepts / misleading labels / unnecessary pages? | Minor: Home cards duplicate nav (intentional triage); File Access wants to fold into Access (forward). | Label consistency fixed; structural merges deferred (need view work, not Phase 1). |

## 5. Honesty & guarantees
No runtime feature added; no fake toggle; no provider over-promised. Approval, rollback, audit, and capability scoping untouched. The C1 (flag-gated tools) and C2 (Anthropic-only execution) contradictions remain **honestly surfaced**, not hidden.

## 6. Validation after polish
Lint clean on all changed files. Suites (standalone): `test-ia-phase1` **85/0** · `test-experience-layer` **118/0** · `test-first-value-5c` **24/0** · `test-usability-5b` **36/0** · `test-token-capability-admin` **155/0** (incl. live redirect) · `test-change-history-admin` **119/0** · `test-operations-explorer` **151/0** · `test-approval-center` **127/0** · `test-adoption-readiness` **44/0** · `test-dashboard` **69/0**. Invariants `34/23/40/40/2.5.0` held. The only non-passing assertions anywhere remain the **2 pre-existing** `test-seo-audit` classifier failures (proven unrelated).

## 7. Pre-existing observation (not introduced here, not fixed here)
`Settings › Patches` (the legacy `patches.php` view) renders a very large payload (~3 MB) on sites with many patches. This predates Phase 1 (the view was only re-homed, not modified). Flagged for a future performance pass; out of Phase-1 scope.

## Net
The production-blocking Settings loop is fixed and guarded against regression; all navigation is verified end-to-end; the Home no longer exposes architecture words; and Built-in AI honestly guides first-timers to its tools. Phase 1 is now genuinely polished and beta-ready at the IA layer.
