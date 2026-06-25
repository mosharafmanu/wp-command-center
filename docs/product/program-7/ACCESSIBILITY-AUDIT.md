# PROGRAM-7 — Accessibility Audit

Scope: the new Mission Control activity surface (inherits the 6S a11y baseline).

| Area | Implementation |
|---|---|
| **Activity feed** | a real `<ul>` with an `aria-label`; each row is text (category + label + actor + time), not color-only. |
| **Status dots** | decorative → `aria-hidden="true"`; the category label carries the meaning. |
| **Counters** | real numbers in text; the cost tile has a `title` explaining "not tracked yet". |
| **Links/actions** | native `<a class="button">` — keyboard-focusable, real focus rings preserved. |
| **Color independence** | category conveyed by label text, never by dot color alone. |
| **Contrast** | body `#50575e`/`#1d2327` on white; muted `#646970` meets AA for its size. |
| **Inherited (6S)** | notices `role="alert"`/`status`; wizard `aria-expanded` + focus management; routing SR labels; responsive grids. |

## Dark mode / responsive / large sites
- **Responsive:** Mission Control is a 2-col grid that stacks under the 6S mobile breakpoint.
- **Dark mode:** the surface uses WP-admin neutrals + the 6S tokens; a future CDS dark theme would themable via the existing token approach (no hard-coded blacks beyond the hero gradient).
- **Large sites / thousands of events:** the feed is **bounded** (reads a capped audit tail and renders ≤12 rows) — O(1) render regardless of history size (see PERFORMANCE-REVIEW). No unbounded list.

## Known limitations (honest)
- No automated axe / screen-reader pass in this environment — manual structural review only; recommend a live pass before GA.
- The feed relies on `<ul>` semantics + labels; an `aria-live` region could announce new activity in a future real-time pass (not needed for the current page-load model).
