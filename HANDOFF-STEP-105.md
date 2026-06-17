# PROJECT HANDOFF — STEP 105: Change History Admin UI (in progress)

**Written:** 2026-06-17. Supersedes `HANDOFF-STEP-104.md` for current state.
STEP 104 (Change History backend) is COMPLETE, deployed, and prod-verified;
STEP 105 surfaces that backend in wp-admin. This handoff covers **STEP 105.1**
(committed locally, NOT pushed/deployed).

---

## A. Status

- **STEP 105.1 — Change History read-only admin UI: COMPLETE locally** (`1742ca8`;
  handoff `c8747dd`).
- **STEP 105.2 — Detail view + shared diff viewer: COMPLETE locally** (`ac85221`;
  handoff `5cf9f9b`).
- **STEP 105.3 — Rollback action UI + menu merge: COMPLETE locally** (`634803b`).
- **All on `main`, NOT pushed, NOT deployed** (deploy is pull-cron from
  origin/main, so local commits are inert until pushed).
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

## B3. What 105.3 Shipped (rollback action — first write surface)

The **only** write/mutating surface in STEP 105. Pure engine reuse — **no bypass,
no parallel rollback, no new storage, no capability change, no MCP**.

- **`includes/Admin/AdminRestApi.php` (MOD)** — `POST /admin/history/{id}/rollback`
  (CREATABLE, `manage_options` + nonce). Builds the `rollback_target` payload +
  an admin actor context (`source: admin_ui`, **no token_scope/token_id**) and
  calls **`OperationExecutor::run('change_history', …)`** — inheriting capability,
  DestructiveGuard (`ROLLBACK_CHANGE` handshake on high-risk-file patch reversals),
  security-mode approval, AuditLog, ChangeRecorder. Returns the structured result
  verbatim (HTTP 200) so the UI branches on `result.status` (success |
  pending_approval | confirmation_required). It never calls
  `ChangeHistoryRuntimeManager::rollback_target()` directly.
- **`includes/Admin/views/change-history.php` (MOD)** — secondary **Restore**
  control on Timeline rows **and** Detail view (only when reversible & not
  rolled-back). A confirmation modal opens for **every** restore; low-risk →
  confirm + POST; high-risk → backend replies `confirmation_required` and the
  modal **escalates** to require the `ROLLBACK_CHANGE` phrase + a reason before
  re-POSTing. `pending_approval` → "sent to Pending Approvals" link. 403 → nonce
  re-auth notice. No client-side rollback logic.
- **`includes/Admin/AdminMenu.php` (MOD)** — **menu swap (final sub-step):**
  removed the `wpcc-rollback` submenu + `render_rollback`; added an `admin_init`
  redirect `page=wpcc-rollback → page=wpcc-change-history` (bookmarks survive).
- **`includes/Admin/views/rollback.php` (DELETED)** — the legacy patch-only page
  that called `PatchApproval::rollback()` **directly** (a guard bypass). Restore
  now goes through OperationExecutor; the bypass is gone.
- **`tests/test-change-history-admin.sh` (MOD)** — now **93/93** (+ rollback
  endpoint reuse/no-bypass, developer-mode round-trip incl. value reverted +
  original stamped + reversal recorded + double-rollback refused, client-mode →
  pending_approval w/o execution, DestructiveGuard fast-path, menu-swap +
  deleted-view assertions).

**Invariants unchanged:** operation_map 34, capabilities 23, no MCP tool.

## C. Test Gates (all green)

- 105.1 admin suite 44/0 → 105.2 68/0 → **105.3 admin suite 93/0** (stable across reruns).
- `run.sh --changed` T0: 59/0, net-new 0.
- 105.2: `run.sh --changed --runtime patch` T1 (14 suites) 665/0, net-new 0.
- **105.3: `run.sh --changed --runtime patch` T1 (27 suites) 1081/0, net-new 0.**
- php -l clean on all touched PHP files.

## D. Repository State

- Branch `main`; local HEAD = `634803b` (105.3 feature) + a handoff commit.
  Commits ahead of origin (NOT pushed): `1742ca8`/`c8747dd` (105.1),
  `ac85221`/`5cf9f9b` (105.2), `634803b` + this handoff (105.3).
- Working tree otherwise clean. `v0.104.0` tag unchanged (→ `5abea8f`).

## E. Approved Decisions Baked In

- Thin admin aggregation read = presentation only (Admin namespace, read-only).
- Sessions tab + drill-by-`session_id`; **no** inline expand/collapse.
- Session grouping first-class but **no session-level restore** — only individual
  + existing change-set restore (none executed in 105.1).
- Audit-first / read-first; restore visually deferred.
- `actor_summary` included in the sessions response (cheap, no extra runtime API).

## F. Remaining STEP 105 Phases

- **105.2 — Detail view + diff viewer. ✅ DONE (`ac85221`).**
- **105.3 — Rollback action + menu merge. ✅ DONE (`634803b`).**
- **105.4 — Pro seam (single ungated `feature_gate()` at render+REST), a11y/i18n,
  error/empty polish, full SERIAL T2 net-new 0, deploy on explicit direction.**

### Behavioral note for release (105.3)
Admin restores now honor approval/DestructiveGuard/security-mode. In
client/enterprise mode a restore that the old Rollback page executed instantly
will now route to **Pending Approvals** — intended hardening; call out in
release notes.

## G. Next-Chat Starting Point

- Commit/push 105.1 only on explicit direction (push = live in ~1 min via cron).
- Otherwise proceed to **STEP 105.2 planning → implementation** (diff viewer +
  detail view), keeping every surface read-only until 105.3.
