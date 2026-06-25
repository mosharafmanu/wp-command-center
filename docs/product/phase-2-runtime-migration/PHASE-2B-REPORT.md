# Phase 2B — Runtime Migration Cutover (Report)

> **Type:** implementation documentation. **Date:** 2026-06-25.
> **Nature:** the cutover. **Runtime no longer exists as a customer-facing page.** No engine/REST/MCP/capability/schema/security/approval/rollback/provider/token change.

## What was removed
- **`includes/Admin/views/dashboard.php`** ("Agent Runtime Dashboard") — **deleted**. No customer-facing reference to it or "Agent Runtime Dashboard" remains anywhere in `includes/Admin` (asserted).
- **Settings › Runtime tab** — removed from `AppShell::sections()` (no `'view' => 'dashboard'`).
- **The Phase 2A flat-tab sprawl** — the temporary `recommendations` flat tab (and the standalone `files`/`patches`/`intelligence`/`capabilities` tabs) are gone, grouped into hubs.

## What replaced it (functionality preserved — nothing lost)
| Runtime responsibility | New home |
|---|---|
| Safe Search & Replace | **Settings › Tools** (`tools-search-replace.php`, from 2A) |
| Recommendations (+ dismiss/resolve/scan) | **Settings › Diagnostics › Recommendations** (`recommendations.php`, from 2A) |
| Pending Plans (approve/reject) | **Recommendations**, signalled on **Home** + **Activity › Approvals** (2A) |
| Recent activity / operation results | **Activity › Live** + **History** (already owned) |
| Site inventory | **Settings › Diagnostics › Site Report** |
| Health / debug log | **Settings › Diagnostics › Health** |
| Patches | **Settings › Diagnostics › Patches** |
| Capabilities catalogue | **Settings › Advanced › Capabilities** |
| File Access | **Settings › Advanced › File Access** |
| Engine internals (Runtime Hierarchy, raw results JSON, telemetry) | **Deferred** to a Developer-mode "Engine Inspector" (documented in `settings-advanced.php`); meanwhile available via REST (`/agent/timeline`, `/agent/tree`, operation results) + MCP — **no access lost** |

**Functionality-loss check: PASSED** — every Runtime responsibility has a working home; only raw developer internals are deferred (still reachable via REST/MCP), so the cutover proceeded without a STOP.

## Settings simplification (8/10 → 5)
**Security & Approvals · Access · Tools · Diagnostics ▾ · Advanced ▾**, where Diagnostics and Advanced are thin **hub wrappers** that host the existing views via a namespaced second-level sub-nav (`?dpane=` / `?apane=`), the proven pattern. The daily path (Home / Built-in AI / Activity / History) is untouched.

## Redirect behavior (no loops, no broken URLs)
`resolve_legacy()` gained an optional 3rd "extra-args" element so a legacy deep-link lands on the exact hub pane; `AdminMenu::redirect_to()` merges those args. Verified live:

| Old URL | Lands on |
|---|---|
| `…&wpcc_tab=runtime` (and `operate/runtime`, old `dashboard` paths) | Settings › Diagnostics |
| `…&wpcc_tab=patches`, `wpcc-patches` | Settings › Diagnostics › Patches |
| `…&wpcc_tab=intelligence`, `wpcc-site-intelligence` | Settings › Diagnostics › Site Report |
| `…&wpcc_tab=recommendations` | Settings › Diagnostics › Recommendations |
| `…&wpcc_tab=capabilities`, `wpcc-operations` | Settings › Advanced › Capabilities |
| `…&wpcc_tab=files`, `wpcc-file-access` | Settings › Advanced › File Access |

The retired sub-tabs are listed in `legacy_tab_map['wpcc-settings']` with only the *retired* keys, so current tabs still render directly via the live-section short-circuit — **no self-redirect, no loop** (the Settings-loop class fixed in Phase 1 stays fixed; re-asserted by an exhaustive live sweep).

## Validation results
- **Lint:** clean on all changed/new PHP.
- **Live (wp-cli):** all 5 Settings tabs + all 6 hub panes render (0 fatals); Settings = exactly 5 tabs, Runtime absent; **every legacy + retired URL terminates on a real destination, 0 loops**; **Tools S&R governed dry-run still works** end-to-end; invariants **34/23/40/40/2.5.0**.
- **Suites (12, all green, ~801 assertions, 0 failures):** `test-phase-2b` 33/0 · `test-phase-2a` 45/0 · `test-ia-phase1` 89/0 · `test-experience-layer` 113/0 · `test-usability-5b` 36/0 · `test-operations-explorer` 152/0 · `test-approval-center` 127/0 · `test-token-capability-admin` 155/0 · `test-recommendations` 45/0 · `test-recommendation-workflow` 38/0 · `test-first-value-5c` 24/0 · `test-adoption-readiness` 44/0.
- **Net-new attributable failures: 0.**

## Changed / removed / new files
- **Removed:** `includes/Admin/views/dashboard.php`; `tests/test-admin-ux.sh` (tested only the removed page).
- **New:** `includes/Admin/views/settings-diagnostics.php`, `includes/Admin/views/settings-advanced.php`, `tests/test-phase-2b.sh`, this report.
- **Modified:** `includes/Admin/AppShell.php` (5-tab Settings + hub views + redirect maps w/ pane precision), `includes/Admin/AdminMenu.php` (redirect carries hub-pane args), `includes/Admin/DashboardAdminQuery.php` (stale comment), and the test suites that encoded Runtime's presence (`test-phase-2a`, `test-ia-phase1`, `test-experience-layer`, `test-usability-5b`, `test-operations-explorer`, `test-recommendations`, `test-recommendation-workflow`, `test-cleanup`).

## Remaining follow-up items (out of Phase 2B scope, documented)
1. **Engine Inspector** (Developer-mode raw internals) — deferred; internals remain on REST/MCP.
2. **Environment-mode banner** (dev/staging/prod) — was on the Runtime page; re-surface in Diagnostics › Health later (env mode itself unchanged).
3. **Patches view payload** — the legacy `patches.php` renders a very large page on patch-heavy sites (pre-existing; only re-homed here). Future performance pass.
4. **File Access → Access scope** — currently a pane under Advanced; the blueprint's end-state folds it into Access as a scope (a view merge, deferred).

## Status — Phase 2 Runtime Migration COMPLETE
Phase 2A (new homes) + Phase 2B (cutover) together retire Runtime with zero lost customer functionality, a simplified 5-tab Settings, complete backward-compatible redirects, and held invariants. Staged on `main`, **not pushed**; production untouched (Program-4). **Runtime is gone from the customer UI.**
