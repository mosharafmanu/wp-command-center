# PROGRAM-6S — Design Consistency

## A small, consistent design language (scoped `wpcc-aip-`)
| Token / pattern | Value | Used by |
|---|---|---|
| Card radius | 12px | cards, wizard, hero, routing, empty state |
| Border | `#dcdfe3` | all surfaces |
| Card shadow | `0 1px 2px rgba(0,0,0,.04)` | cards |
| Badge | pill, 11px/700 | status, runtime, tags, default |
| Health dot | 9px circle, state color | every card |
| Muted text | `#646970` | secondary copy |
| Primary ink | `#1d2327` | headings/values |
| Brand gradient | `#1d2734→#2c3a4f` | hero |
| Status greens/ambers/reds | `#0a7a33/#dba617/#d63638` | health, KPIs, warnings |
| Spacing rhythm | 8/12/14/18/28px | consistent gaps/margins |

## Consistency rules applied
- **One badge style** everywhere (DEFAULT / USED BY RUNTIME / TESTABLE / STORED ONLY / tags) — same shape, only color differs by meaning.
- **One health vocabulary** (Health helper) — the same state names/colors on cards and in the KPI rollup.
- **One capability vocabulary** (Capabilities helper) — same labels/values across all providers.
- **Progressive disclosure** is consistent: `<details>` for Capabilities and Edit on every card; wizard for create.
- **Honest-status colors are semantic, not decorative:** green = runtime/healthy, blue = testable/info, amber = stored/attention, red = failure — applied identically everywhere.

## Alignment with the broader product
The page lives inside the existing 5-C AppShell (Connect → AI Setup tab) and reuses WP-admin `.button`/`.button-primary` so it feels native while the card/KPI/hero layer lifts it to a platform. It does not fork the global CDS; the `wpcc-aip-` scope is local to this surface and could graduate into the CDS later.

## Anti-patterns avoided
No inconsistent button sizes for the same action; no color-only status; no mixed terminology (e.g., "provider" vs "connection" — "connection" is the consistent noun, "provider" only as a property).
