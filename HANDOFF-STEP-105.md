# PROJECT HANDOFF — STEP 105: Change History Admin UI (in progress)

**Written:** 2026-06-17. Supersedes `HANDOFF-STEP-104.md` for current state.
STEP 104 (Change History backend) is COMPLETE, deployed, and prod-verified;
STEP 105 surfaces that backend in wp-admin. This handoff covers **STEP 105.1**
(committed locally, NOT pushed/deployed).

---

## A. Status

- **STEP 105.1 — Change History read-only admin UI: COMPLETE locally.**
- **Committed:** `1742ca8` on `main`. **NOT pushed, NOT deployed** (deploy is
  pull-cron from origin/main, so a local commit is inert until pushed).
- STEP 104 backend remains live and prod-verified at `v0.104.0` (`5abea8f`).

## B. What 105.1 Shipped (commit `1742ca8`)

Read-only audit/investigation surface over the STEP 104 backend. **No rollback
execution, no diff viewer, no MCP, no new storage.**

- **`includes/Admin/ChangeHistoryAdminQuery.php` (NEW)** — Admin-namespace
  presentation-layer aggregation. `sessions()` = one `GROUP BY` over
  `wpcc_change_log` (change_count / reversible_count / change_set_count /
  first_at / last_at / runtimes / sources) + one **bounded** secondary query
  for `actor_summary` (first-actor label per page, no N+1). Read-only; excludes
  session-less rows (`session_id IS NOT NULL`). Explicitly **not** a runtime/MCP
  API or a new source of truth.
- **`includes/Admin/AdminRestApi.php` (MOD)** — 4 cookie+nonce, `manage_options`,
  READABLE routes: `/admin/history`, `/admin/history/timeline`,
  `/admin/history/sessions`, `/admin/history/{change_id}`. list/timeline/get
  delegate to `ChangeHistoryRuntimeManager::run()` (identical envelope to token
  REST/MCP — zero new read logic); sessions → `ChangeHistoryAdminQuery`. Literal
  routes registered before the `{change_id}` wildcard. **No write/rollback route.**
- **`includes/Admin/views/change-history.php` (NEW)** — server-rendered +
  inline vanilla JS (approvals.php convention). URL-driven tabs: Timeline
  (default, flat newest-first) / Sessions / Reversible-only; session drill via
  `?session_id=`; minimal Details panel via `?view=` (metadata only — diff
  viewer is 105.2). Reversibility is a read-only badge; **no Restore control**
  (105.3). All API output escaped via `escHtml`.
- **`includes/Admin/AdminMenu.php` (MOD)** — "Change History" submenu (position
  2, after Dashboard) + `render_change_history`. **Rollback submenu RETAINED** —
  its removal/redirect into Change History is **deferred to 105.3** to avoid an
  admin-restore capability gap (approved decision).
- **`tests/test-change-history-admin.sh` (NEW, 44/44)** + registered into
  `tests/regression-map.tsv` history group (trigger + suites).

**Invariants held:** operation_map = 34, capabilities = 23 (no runtime op, MCP
tool, or capability added).

## C. Test Gates (all green)

- Standalone admin suite: **44 passed / 0 failed** (incl. functional aggregation:
  session counts match the table exactly; session-less rows excluded from
  Sessions yet present in Timeline; invariants; route/menu/escaping structure).
- `run.sh --changed` **T0: 59/0, net-new 0**.
- `run.sh --changed` **T1 (15 suites incl. the new admin suite): 493/0, net-new 0**.
- php -l clean on all four PHP files.

## D. Repository State

- Branch `main`; local HEAD = `1742ca8` (105.1). **Local is AHEAD of origin by
  this commit — NOT pushed.** Working tree otherwise clean.
- `v0.104.0` tag unchanged (→ `5abea8f`).

## E. Approved Decisions Baked In

- Thin admin aggregation read = presentation only (Admin namespace, read-only).
- Sessions tab + drill-by-`session_id`; **no** inline expand/collapse.
- Session grouping first-class but **no session-level restore** — only individual
  + existing change-set restore (none executed in 105.1).
- Audit-first / read-first; restore visually deferred.
- `actor_summary` included in the sessions response (cheap, no extra runtime API).

## F. Remaining STEP 105 Phases (NOT started)

- **105.2 — Detail view + diff viewer.** Patch changes → real unified diff via a
  **shared** `DiffGenerator` partial (no forked renderer); runtime/option → metadata
  "what changed" summary (no fake diff); `kind=none` → metadata card; **degrade
  gracefully if the patch snapshot is missing.** Still read-only.
- **105.3 — Rollback action.** `POST /admin/history/{id}/rollback` routed THROUGH
  `OperationExecutor` (preserves DestructiveGuard `ROLLBACK_CHANGE` handshake +
  security-mode approval routing). Then **swap the menu**: remove/redirect
  `wpcc-rollback` → `wpcc-change-history`. Individual + change-set restore only.
- **105.4 — Pro seam (single ungated `feature_gate()` at render+REST), a11y/i18n,
  error/empty polish, full SERIAL T2 net-new 0, deploy on explicit direction.**

## G. Next-Chat Starting Point

- Commit/push 105.1 only on explicit direction (push = live in ~1 min via cron).
- Otherwise proceed to **STEP 105.2 planning → implementation** (diff viewer +
  detail view), keeping every surface read-only until 105.3.
