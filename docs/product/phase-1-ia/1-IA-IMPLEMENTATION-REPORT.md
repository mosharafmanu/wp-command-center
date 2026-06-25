# Phase 1 — IA Implementation Report

> **Type:** implementation documentation. **Date:** 2026-06-25.
> **Scope honored:** Narrative + Information Architecture only — navigation, page hierarchy, labels, grouping, onboarding entry points, empty states, progressive disclosure. **No engine, REST, MCP, capability, approval, rollback, schema, or provider-execution work.**
> **Governing docs:** `master-architecture/MASTER-AI-PLATFORM-BLUEPRINT.md` (§4 IA, §12 terminology, §15 migration) · `master-architecture/FINAL-UX-MASTER-BLUEPRINT.md` (§2 nav, §3 onboarding, §4 screen verdicts, §7 labels).

## What changed (one sentence)
The admin navigation moved from the architecture-flavoured **5-C IA** (Overview · Operate · Audit · Access · Connect) to the product-language **six-section IA** the blueprints mandate — **Home · Built-in AI · Connect · Activity · History · Settings** — with every legacy URL preserved by a tab-aware redirect, the value (Built-in AI) promoted to the top level, and the architecture words removed from anything a customer sees.

## Section / tab map (the new IA)

| Section (slug) | Tabs (key → label → hosted view) |
|---|---|
| **Home** `wp-command-center` | home → *Home / Mission Control* → `command-home` |
| **Built-in AI** `wpcc-built-in-ai` | providers → *Providers* → `ai-setup` · seo → *SEO* → `seo-meta`¹ · alt_text → *Alt Text* → `ai-alt-text`¹ · content → *Content* → `ai-content`¹ |
| **Connect** `wpcc-connect` | clients → *AI Clients* → `ai-integrations` · api → *API & Integrations* → `api-integrations` (**new view**) |
| **Activity** `wpcc-activity` | live → *Live* → `operations-center` · approvals → *Approvals* → `approval-center`² · drafts → *Drafts (Dev)* → `proposals`¹ |
| **History** `wpcc-history` | changes → *Changes* → `change-history`² |
| **Settings** `wpcc-settings` | security → *Security & Approvals* → `settings` · access → *Access* → `token-capability-manager`² · files → *File Access* → `file-access` · diagnostics → *Diagnostics* → `diagnostics` · patches → *Patches* → `patches` · intelligence → *Site Report* → `site-intelligence` · capabilities → *Capabilities* → `operations-explorer`² · runtime → *Runtime* → `dashboard` |

¹ Build-flag + FeatureGate gated (unchanged gating; off in production). ² FeatureGate-gated (gate preserved across the move).

**Re-homing, not rebuilding:** every tab points at an **existing** view file. Only one new file was created — `api-integrations.php`, a read-only Door-3 landing (below).

## Blueprint alignment (how each rule was honored)
- **Value before configuration** — *Built-in AI* is its own top-level section (was flag-gated tabs buried under "Operate").
- **The door you chose is the only door you see** — Door 1 (Built-in AI) is front-of-house; Doors 2 & 3 live together under *Connect* (AI Clients / API & Integrations), ending the old "AI Setup" vs "Connect an AI Agent" collision (the #1 documented confusion).
- **Plain English over audit-speak** — *Activity* + *History* replace *Operate* + *Audit*; *Capabilities* replaces the *Operations* catalogue (ending the "Operations" vs "Operations Center" clash).
- **Tokens are issued in context, managed centrally** — issued from *Connect › API & Integrations* / *AI Clients*, managed in *Settings › Access*.
- **Everything advanced collapses into Settings** — capabilities catalogue, runtime, diagnostics, patches, site report, file access.
- **Honest limits, never faked** — the new API landing shows only real facts (real Base URL, real read endpoint); no provider-execution or capability was implied or added.

## The one new file — `includes/Admin/views/api-integrations.php`
A read-only Door-3 (Remote Apps over REST) landing that surfaces **only existing facts**: the real Base URL (`{site}/wp-json/wp-command-center/v1`), the `Authorization: Bearer <token>` contract, a **real** read-only example (`GET /operations`), and a route to *Settings › Access* for token creation. It **adds no REST route, capability, operation, or schema**, dispatches no engine, and creates no tokens itself — verified by test (`register_rest_route` / `OperationExecutor` absent).

## Files changed
**Navigation core (2):** `AppShell.php` (six-section tree + `resolve_legacy()` tab-aware migration), `AdminMenu.php` (six submenus, redirect via `resolve_legacy`, admin-bar badge → Activity › Approvals).
**Re-pointed internal links / copy (8):** `AdoptionStatus.php`, `command-home.php` (+ door fork), `ai-setup.php` (H1 *Providers*), `ai-integrations.php` (H1 *AI Clients*), `operations-center.php`, `dashboard.php`, `ai-content.php`, `seo-meta.php`.
**New (1):** `api-integrations.php`.
**Tests (11):** new `test-ia-phase1.sh`; updated 10 suites whose structural assertions encoded the retired 5-C IA.

## Invariants — held
`OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB 2.5.0` — asserted green by the live wp-cli invariant check in `test-ia-phase1.sh` and `test-experience-layer.sh`.

## Explicitly NOT done (per scope)
Generation adapters / multi-provider execution; capability-scoped tokens; enabling AI feature flags; any view-merge requiring runtime; notifications/scheduling; engine, approval, rollback, audit, REST, or MCP changes; any push or deploy.
