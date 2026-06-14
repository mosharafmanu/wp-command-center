# STEP 98 — Reporting Runtime

## Goal

Generate **read-only operational reports** for AI agents and admins over REST and
MCP — the final step of the runtime roadmap.

## Architecture

A new `report_manage` operation (`ReportingRuntimeManager` + `ReportingRegistry`).
Every action is `diagnostic` (no writes, no rollback, `requires_approval: false`).
Inventory reports read WordPress / plugin data directly; activity reports
aggregate the append-only audit log (`AuditLog::tail()`). Reports degrade
gracefully when a subsystem is absent (e.g. WooCommerce inactive → `available:
false`) instead of erroring.

Posture mirrors `system_info`: read-only diagnostic, **not** added to
`CapabilityRegistry::OPERATION_MAP` (operation_map stays 32), REST route gated by
`require_read` (`scope: read_only`).

## Reports (REST `/operations/report_manage/run`, MCP `report_manage`)

| Action | Contents |
|--------|----------|
| `report_list` | Catalogue of the 8 reports + per-report `available` flag. |
| `report_site_health` | PHP/WP/MySQL versions, memory, exec time, upload size, HTTPS, multisite, debug, active theme, core/plugin/theme update counts, `status`. |
| `report_plugin_health` | total / active / inactive / must-use / dropins, updates available + slugs. |
| `report_security` | security mode, approval enforcement, capability enforcement, token totals by scope, pending approvals, recent denied/blocked/destructive audit events. |
| `report_content` | post-type counts by status, media, comments, taxonomy term counts, user count. |
| `report_woocommerce` | products (total/published/draft), orders by status, out-of-stock, customers (gated on WooCommerce). |
| `report_agent_activity` | operations started/completed/failed, top operations, recent execution events (from audit). |
| `report_approval_activity` | approval requests by status, pending count, auto-requested/approved/rejected (from audit). |
| `report_patch_activity` | patches by status, totals, created/applied/rolled-back/rejected (from audit). |

Activity reports accept an optional `limit` (audit entries scanned; default 1000,
max 5000).

## Data sources

- `AuditLog::tail()` — agent / approval / patch activity aggregation.
- `wp_get_update_data()`, `get_plugins()`, `get_plugin_updates()` — site/plugin health.
- `SecurityModeManager::current()`, `AuthTokens::list()`, `OperationManager::list_requests()` — security/approval.
- `wp_count_posts/comments/terms`, `count_users()` — content.
- `wc_orders_count()`, `wc_get_products()` — WooCommerce.
- `PatchManager::list()` — patch status breakdown.

## Wiring

- `OperationExecutor::resolve_handler` → `new ReportingRuntimeManager()`.
- `OperationRegistry` op def (all `action_risks` diagnostic, `requires_approval`
  false, `available` true).
- `RestApi`: `run_report_manage` (mirrors `run_database_inspect`, `require_read`)
  + `/operations/report_manage/run` route + `ROUTE_MANIFEST` entry (`read_only`).
- No `CapabilityRegistry` mapping (read-only diagnostic, like `system_info`) →
  operation_map unchanged at 32.

## Security / safety

- Strictly read-only: a dedicated acceptance test asserts content counts are
  unchanged after running reports.
- No secrets/token values exposed — security report returns counts and modes only.
- Audited (`report.*` events).

## Tests

`tests/test-reporting-step98.sh` — **29/29 PASS**: report_list → all 8 reports'
shapes → MCP parity (site_health + security) → read-only invariance → structured
error. Full bash regression: 0 net-new failures (24 pre-existing baseline).

## Roadmap status

**STEP 98 completes WPCC-RUNTIME-ROADMAP.md (STEPs 89–98).** All steps committed
locally; STEPs 89–98 await the owner's batch deploy decision (production runs
through STEP 88).
