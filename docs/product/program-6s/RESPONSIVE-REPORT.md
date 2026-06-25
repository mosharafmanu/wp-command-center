# PROGRAM-6S — Responsive Report

## Layout strategy
- **Fluid grids:** KPI tiles `repeat(auto-fit, minmax(170px,1fr))` and connection cards `repeat(auto-fill, minmax(330px,1fr))` reflow from multi-column (desktop) → fewer columns (tablet) → single column (mobile) with no breakpoints needed for the grids themselves.
- **Mobile breakpoint (`max-width:782px`, WP-admin's mobile cutoff):** the hero stacks vertically (score below title); cards force a single column.
- **Constrained max-width (1080px):** prevents over-stretch on large screens.

## Per-viewport check (manual/structural)
| Viewport | Result |
|---|---|
| **Desktop (≥1080px)** | hero row, 4 KPI tiles, multi-column cards, routing panel — balanced. |
| **Tablet (~768–1024px)** | KPI tiles wrap to 2-up; cards 1–2 columns; hero still row until 782px. |
| **Mobile (<782px)** | hero stacks; KPIs stack/2-up; cards single column; forms full-width (`max-width` caps removed by the narrow viewport); wizard scrolls naturally (inline, not a trapped modal). |

## Touch & overflow
- Action buttons wrap (`flex-wrap`) rather than overflow.
- `<code>` endpoints/models wrap within cards; long base URLs don't break layout.
- Inputs are `width:100%` within their fields, so no horizontal scroll on mobile.

## Known limitation
This is a structural/CSS review; no device-lab screenshot pass was run in this environment. The grid + single breakpoint approach is conservative and standard for WP-admin; a visual pass on real devices is recommended before GA.
