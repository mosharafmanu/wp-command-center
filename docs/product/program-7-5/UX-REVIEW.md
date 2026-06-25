# PROGRAM-7.5 — UX Review (Mission Control Polish)

> **Branch:** `program-7-5-mission-control-polish` (off Program-7 `7b2054b`; main untouched `94a716c`). **UX only** — no runtime/queue/approval/audit/rollback/security/capability/MCP/REST/provider/connection/schema/contract change. Only `views/ai-setup.php` + `views/command-home.php` (presentation) changed.

## Screens reviewed
1. **AI Connections / Mission Control** (`ai-setup.php`) — hero, readiness, KPIs, activity, connections, routing, wizard.
2. **Overview first-run** (`command-home.php`) — the "Run a site report" quick win.

## Design decisions + before/after reasoning

| # | Area | Before | After | Why |
|---|---|---|---|---|
| 1 | **Hierarchy / scanability** | Flat sections | Added a governed-workflow band (Inspect›Plan›Approve›Execute›Verify›Rollback), grouped zones, consistent spacing | The product's *promise* is now visible at a glance; the page reads like an ops center, not settings. |
| 2 | **Readiness %** | A bare ring + number | Ring **+ a 4-item checklist** (Connection added / Default chosen / Tested healthy / No issues) **+ honest "AI features: inactive"** context | The score is now **self-explanatory** — users see *why* it's that value. **Scoring logic unchanged** (same components, just exposed). |
| 3 | **Pending approvals** | A KPI number + a small link | A prominent **"N changes waiting for your approval — nothing applies until you review"** callout with a primary "Review now" | Answers "what needs me right now?" loudly, calmly, using the existing count. No grouping logic added (would need runtime). |
| 4 | **Recent activity** | A flat dotted list | A **timeline**: category **icons**, **Today / Earlier** grouping, row dividers, stronger visual weight | Scans like an operations timeline; grouping uses existing timestamps only. |
| 5 | **Connection cards** | Static cards | Subtle **hover lift** (shadow/border) | Higher perceived quality; zero functional change. |
| 6 | **Feature routing** | "Feature → [select]" | Each route gains a **"what it powers"** sublabel + a healthy-routes confirmation | Users instantly understand what each route drives. No routing logic change. |
| 7 | **Provider wizard** | Terse provider step | Step 1 explains **Cloud / Local / Gateway** in plain words; options already show runtime/test/stored status | Lower intimidation, clearer selection. No provider-support change. |
| 8 | **First-run** | A modest green strip | A **hero**: icon, larger heading "Start here…", hero button | "Run a site report" is now the unmistakable first action — instant, read-only win. |
| 9 | **Language** | "AI status: **Off**" | "AI status: **Inactive**" (+ friendlier readiness/approval copy) | Confidence-building, accurate, less blunt — "enable when you're ready." |

## Principle held throughout
Every improvement uses **existing data only** — no fabricated jobs/metrics, no placeholder logic, no runtime concepts. Honesty preserved: cost still "Not tracked yet"; stored-only/testable/runtime badges intact; keys never rendered.
