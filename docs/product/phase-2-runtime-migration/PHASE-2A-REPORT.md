# Phase 2A — Runtime Migration: Add New Homes First (Report)

> **Type:** implementation documentation. **Date:** 2026-06-25.
> **Nature:** **additive only.** Runtime was **not** deleted; `dashboard.php` remains; no engine/REST/MCP/capability/schema/security/approval/rollback change. Implements the Phase 1.5B blueprint, "build the new destinations first."

## What moved (new homes built)
| New home | Source | Behavior |
|---|---|---|
| **Settings › Tools › Safe Search & Replace** (`views/tools-search-replace.php`, new) | Runtime's S&R panel | Governed flow preserved **exactly**: dry-run preview, table-risk model, confirmation modal for live runs, `OperationManager::create_request('safe_search_replace')` → approve → `OperationQueue::run_item`. Copy updated to point at **Activity › Approvals**. |
| **Settings › Recommendations** (`views/recommendations.php`, new) | Runtime's recommendation cards + table + Pending Plans | Surfaces real findings via `RecommendationEngine::list()` + count queries; **dismiss/resolve** via `transition()`; **plan approve/reject** via the verbatim `wpcc_agent_plans` + `AuditLog` + `sync_plan_status` flow; **Run a scan** via `scan()` (non-destructive). Honest empty state. |
| **Home signal** (`views/command-home.php`) | — | A calm "*N recommendations to review →*" line, shown **only** when real open findings exist. No fabrication, no alarm. |
| **Activity › Approvals pointer** (`views/approval-center.php`) | — | "*N suggested fixes awaiting your review →*" shown **only** when real pending plans exist; links to Recommendations (no duplicate UI). |

## What stayed (intentionally, temporarily duplicated)
- **Runtime (`dashboard.php`) is untouched** and still reachable at Settings › Runtime — it keeps its own S&R panel, recommendation cards, and Pending Plans. This duplication is deliberate and temporary; **Phase 2B** removes Runtime and deletes the duplicate copies.
- No engine method changed; both new views call **existing** engine/registry methods only.

## Settings tab count (temporary)
Settings went from **8 → 10** tabs (added Tools + Recommendations; Runtime retained). This temporary growth is expected for an additive phase; **Phase 2B** removes Runtime and groups the advanced items into Diagnostics/Advanced hubs to reach the target **5 tabs**.

## Validation results
- **Lint:** clean on all 5 changed/new PHP files.
- **New suite `test-phase-2a.sh`: 45 / 0** — Tools governed primitives present; Recommendations actions present + honest empty state; Home/Approvals signals real-data + conditional; Runtime still present; new+existing tabs render; **no redirect loops**; invariants held.
- **Functional (wp-cli):** the S&R governed dry-run path (`create_request → approve_request → run_item`) **works end-to-end** from the new Tools view's code path.
- **Affected suites green:** `test-ia-phase1` 85/0 · `test-experience-layer` 118/0 · `test-usability-5b` 36/0 · `test-approval-center` 127/0 · `test-token-capability-admin` 155/0 · `test-first-value-5c` 24/0 · `test-operations-explorer` 151/0 · `test-recommendations` 45/0 · `test-recommendation-workflow` 39/0.
- **Invariants:** `34 / 23 / 40 / 40 / 2.5.0` — unchanged (live wp-cli).
- **No drift:** new views add no `register_rest_route`, no capability, no schema; DB_VERSION 2.5.0.

## Remaining Phase 2B cutover tasks (NOT done here)
1. Delete `dashboard.php`; remove the Settings › Runtime tab.
2. Redirect legacy `…&wpcc_tab=runtime` (and the old `dashboard`/`wpcc-operations`/`wpcc-patches`/`wpcc-diagnostics`/`wpcc-site-intelligence` slugs) into Diagnostics/Advanced.
3. Group Settings into **Security & Approvals · Access · Tools · Diagnostics ▾ · Advanced ▾** (8/10 → 5).
4. Optional Developer-mode **Engine Inspector** for raw internals (pipeline, results, JSON, telemetry).
5. Remove the now-duplicate telemetry/Operation-Results/Agent-Activity panels.
6. Update tests that assert Runtime trims; update IA/Navigation docs.

## Changed files
- **New:** `includes/Admin/views/tools-search-replace.php`, `includes/Admin/views/recommendations.php`, `tests/test-phase-2a.sh`, this report.
- **Modified:** `includes/Admin/AppShell.php` (added Tools + Recommendations tabs; Runtime retained), `includes/Admin/views/command-home.php` (recommendations signal), `includes/Admin/views/approval-center.php` (pending-plans pointer).

## Status
Phase 2A complete and validation-green. **Runtime was NOT deleted.** Staged on `main`, not pushed; production untouched (Program-4). Phase 2B (the cutover) can begin on approval.
