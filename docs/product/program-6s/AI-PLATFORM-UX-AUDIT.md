# PROGRAM-6S — AI Platform UX Audit

> **Branch:** `program-6s-ai-platform-experience` (off 6R `889c518`; main untouched `94a716c`). **Experience only** — no architecture/runtime/data-model change.

## What was audited (6R's functional-but-plain UI)
A single scrollable page: "AI Connections" heading, a flat list of bordered connection rows with inline forms, an add form, and a routing table. Honest and functional — but it read like a *settings page*, not a platform.

## Findings (challenged everything)
| Area | Finding | Severity | Fixed in 6S |
|---|---|---|---|
| Landing | No dashboard — straight into a list; no at-a-glance state | **High** | Hero + readiness score + KPI grid + warnings |
| Create flow | One long technical form; intimidating | **High** | 5-step guided wizard (progressive, degrades w/o JS) |
| Cards | Weak bordered rows; status buried in a sentence | **High** | Premium cards: avatar, health dot+label, badges, capabilities, latency, model count |
| Health | Only a raw test code | **High** | Health Center states + next recommended action |
| Capabilities | None shown | Medium | Declared capability badges (honest, labelled "not live-tested") |
| Models | Plain text field; no guidance | Medium | Recommended/fastest/cheapest tags + honest model metadata |
| Routing | Plain table, hidden | Medium | Visual "Feature → Connection" rows with arrows |
| Empty state | "No connections" terse | Medium | Teaching empty state with benefit + CTA |
| Microcopy | Some jargon | Medium | Rewritten clear/confident/human |
| Visual hierarchy | Flat, dense | Medium | Cards, spacing, gradient hero, KPI tiles, badges, white space |
| Accessibility | Minimal ARIA | Medium | roles, aria-expanded, SR labels, focus mgmt, labels |
| Responsive | OK but untested | Low | Auto-fit grids + a mobile breakpoint |

## Principle applied
Make the user feel: *"I understand how AI works on my site, which models exist, which connection is healthy, and what each feature uses — without docs."* Every change serves that, while keeping the 6R foundation and honest status (CONFIGURED / TESTABLE / USED BY RUNTIME, never faked) intact.

## Scope discipline
No connection model, dialect, routing, runtime, registry, MCP, schema, or security change. The only non-view edits are read-only display helpers (`Capabilities`, `Health`) and a backward-compatible telemetry addition to the existing free-form `last_test` (latency + discovered-model count from the same test call). No new endpoints; same governed POST + audit.
