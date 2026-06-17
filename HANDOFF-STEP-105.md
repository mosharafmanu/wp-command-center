# PROJECT HANDOFF — STEP 105: Change History Admin UI (in progress)

**Written:** 2026-06-17. Supersedes `HANDOFF-STEP-104.md` for current state.
STEP 104 (Change History backend) is COMPLETE, deployed, and prod-verified;
STEP 105 surfaces that backend in wp-admin. This handoff covers **STEP 105.1**
(committed locally, NOT pushed/deployed).

---

## A. Status

- **STEP 105.1 — Change History read-only admin UI: COMPLETE locally** (`1742ca8`;
  handoff `c8747dd`).
- **STEP 105.2 — Detail view + shared diff viewer: COMPLETE locally** (`ac85221`).
- **All on `main`, NOT pushed, NOT deployed** (deploy is pull-cron from
  origin/main, so local commits are inert until pushed). `main` is ahead of
  origin by the 105.1 + 105.2 commits.
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

## B2. What 105.2 Shipped (commit `ac85221`)

Change-detail diff viewer over the STEP 104 backend. **Still read-only — no
rollback/restore (105.3), no MCP, no new persistence.**

- **`includes/Admin/DiffRenderer.php` (NEW)** — the single shared unified-diff
  renderer. `summarize()` (files changed / additions / deletions / per-file
  list), `render_summary()` (compact header), `render_file_diff()` (escaped
  `<pre>`, truncates >600 lines with a notice), `render_accordion()` (per-file
  collapsible `<details>`). Escaped HTML only; file content is untrusted.
- **`includes/Admin/views/patches.php` (MOD)** — dropped the inline
  `$render_diff` closure; renders via `DiffRenderer::render_accordion(files,
  open=true)`. **Patches and Change History share ONE renderer — no fork.**
- **`includes/Admin/AdminRestApi.php` (MOD)** — `GET /admin/history/{id}/diff`
  (read-only). kind via `history_get` → **patch** (real unified diff + summary)
  | **patch_unavailable** (snapshot rotated → graceful metadata degrade) |
  **metadata** (runtime/option → "what changed" from `target_summary` + counts,
  no synthesized diff) | **none**. Returns `{diff_kind, available, summary,
  html, note}`. Registered before the bare `/{change_id}` route.
- **`includes/Admin/views/change-history.php` (MOD)** — detail panel fetches
  `/diff` and **injects the server-rendered, escaped HTML only** — no
  client-side diff parsing.
- **`tests/test-change-history-admin.sh` (MOD)** — now **68/68** (+24:
  renderer counts/escaping/truncation, diff endpoint metadata + not-found, and
  a no-forked-renderer guard).

## C. Test Gates (all green)

- 105.1 admin suite: 44/0. 105.2 admin suite: **68/0** (stable across reruns).
- `run.sh --changed` **T0: 59/0, net-new 0**.
- `run.sh --changed` **T1 (105.1: 15 suites 493/0)**.
- `run.sh --changed --runtime patch` **T1 (105.2: 14 suites 665/0, net-new 0)**
  — patch group included to confirm the patches.php refactor caused no engine
  regression.
- php -l clean on all touched PHP files.

## D. Repository State

- Branch `main`; local HEAD = `ac85221` (105.2). Commits ahead of origin (NOT
  pushed): `1742ca8` (105.1 feature), `c8747dd` (105.1 handoff), `ac85221`
  (105.2 feature) + this handoff update.
- Working tree otherwise clean. `v0.104.0` tag unchanged (→ `5abea8f`).

## E. Approved Decisions Baked In

- Thin admin aggregation read = presentation only (Admin namespace, read-only).
- Sessions tab + drill-by-`session_id`; **no** inline expand/collapse.
- Session grouping first-class but **no session-level restore** — only individual
  + existing change-set restore (none executed in 105.1).
- Audit-first / read-first; restore visually deferred.
- `actor_summary` included in the sessions response (cheap, no extra runtime API).

## F. Remaining STEP 105 Phases (NOT started)

- **105.2 — Detail view + diff viewer. ✅ DONE (`ac85221`).**
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
