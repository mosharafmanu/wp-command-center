# PROJECT HANDOFF — STEP 105: Change History Admin UI

**Written:** 2026-06-17 (updated post-105.5-deploy). Supersedes
`HANDOFF-STEP-104.md` for current state. STEP 104 (Change History backend) is
COMPLETE, deployed, prod-verified. STEP 105 surfaced it in wp-admin and is now
**COMPLETE (105.1–105.5), RELEASED, and PRODUCTION-VERIFIED.**

- **Production runs `14edea2` (tag `v0.105.1`); origin/main == local HEAD == `14edea2`; working tree clean.**
- See **§A2 Release & Production Verification** for the deployed-commit / route /
  actor-attribution proofs.

---

## A. Status

- **STEP 105.1 — Change History read-only admin UI: COMPLETE locally** (`1742ca8`;
  handoff `c8747dd`).
- **STEP 105.2 — Detail view + shared diff viewer: COMPLETE locally** (`ac85221`;
  handoff `5cf9f9b`).
- **STEP 105.3 — Rollback action UI + menu merge: COMPLETE locally** (`634803b`;
  handoff `2f1b098`).
- **STEP 105.4 — Feature-gate seam + a11y + i18n + polish + validation: COMPLETE
  locally** (`30ccaf2`).
- **STEP 105.1–105.4 RELEASED to production** — pushed `4d9c727..07aa951`,
  deployed via pull-cron, tag **`v0.105.0`** (→ `07aa951`). Prod verified:
  404→401 transition captured, all 6 admin routes registered, no fatals.
- **STEP 105.5 — Actor attribution hardening: RELEASED & PROD-VERIFIED**
  (feature `f4bc6cf` + handoff `14edea2`; tag **`v0.105.1`** → `14edea2`).
  Eliminates new "Actor: unknown" rows.
- STEP 104 backend remains live and prod-verified at `v0.104.0` (`5abea8f`).

## A2. Release & Production Verification (deployed commit `14edea2` / `v0.105.1`)

- **Pushed:** `07aa951..14edea2  main -> main` (commits `f4bc6cf` feature +
  `14edea2` handoff). **Deployed commit of record: `14edea2`.**
- **Tag `v0.105.1`** (annotated) → `14edea2`, pushed to origin (obj `2f023b5`).
- **Deployed-commit proof (SSH, this Mac is an allowlisted IP):** server
  `git rev-parse HEAD` = `14edea2`, `git describe` = `v0.105.1`; deploy log:
  `2026-06-17T09:48:07Z DEPLOYED 07aa951 -> 14edea2 active=yes`.
- **Route/health (anonymous HTTP):** homepage 200; namespace index 200; all 6
  admin routes registered (401 auth-gated, not 404); `POST .../rollback` 401
  (live); `change_history` runtime 401 (STEP 104 intact); admin page 302
  (login); **no 500s.**
- **Actor attribution (read-only via wp-cli over SSH — no prod data mutated):**
  `AuditLog::system_actor` present; labels **System (Cron)/(Queue)/(Workflow)/
  (Headless Request)** correct; backstop: empty→`system`, unknown+cron→
  `System (Cron)`, token **preserved**. **Production `change_log`: 0 `unknown`
  rows (of 272 total)** — and the backstop guarantees it stays 0.

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

## B4. What 105.4 Shipped (seam + a11y + i18n + polish)

Final polish, hardening, and the licensing seam — **no licensing logic, no
behavior change, no MCP, no new storage, no capability change**.

- **`includes/Admin/FeatureGate.php` (NEW)** — the single centralized Free/Pro
  switch point. `FeatureGate::allows( $feature )` returns **true today**
  (ungated) and is filterable via `wpcc_feature_allowed`. Call sites never
  change when licensing arrives; only this seam (or the filter) flips.
- **Wiring (one switch point, two call sites):** `AdminRestApi` gates all
  Change History routes behind a new `check_history_permission()` =
  `manage_options && FeatureGate::allows('change_history')`; `AdminMenu` gates
  the Change History submenu the same way. Ungated ⇒ identical behavior.
- **Accessibility:** restore modal is `role=dialog` + `aria-modal` +
  `aria-describedby`; result is a `role=status` `aria-live=polite` region;
  high-risk warning is `role=alert`; **focus trap** (Tab cycles within the
  modal) + **focus return** to the triggering control on close; Esc closes;
  detail header cells use `scope=row`; Restore controls carry `aria-label`.
- **i18n:** every formerly-raw JS string is now localized (detail-table labels,
  counts format, session column headers, the change-set chip, the session
  summary, empty/busy strings). No raw UI strings remain in render logic.
- **Error/empty states:** dedicated empty-reversible message; empty timeline /
  empty sessions; nonce-expiry notice; pending-approval; not-reversible /
  already-rolled-back surfaced from the engine response.
- **`tests/test-change-history-admin.sh` (MOD)** — now **118/118** (+ FeatureGate
  ungated-but-filterable, gated permission/menu wiring, a11y markers, i18n
  coverage).

**Invariants unchanged:** operation_map 34, capabilities 23, no MCP tool.

## B5. What 105.5 Shipped (actor attribution hardening — `f4bc6cf`)

Post-release audit fix. Non-interactive executions (cron queue worker, workflow
steps, headless `execute_request`) reached `ChangeRecorder` with no actor + no
WP user → `resolve_actor()` → `{type:unknown}`, surfaced by 105 as
"Actor: unknown". **No schema change, no historical-row edits, no MCP.**

- **`AuditLog::system_actor($via)`** — descriptive non-interactive actor; labels
  **System (Cron) / (Queue) / (Workflow) / (Headless Request)** + plain "System".
  Carries `label` so the admin UI renders it unchanged.
- **`ChangeRecorder` backstop** (`resolve_change_actor()`, used by `record()` +
  `record_rollback()`): when the resolved actor is empty/`unknown`, substitute
  `system_actor(context.system_via ?? 'system')`. **Change-log only** —
  `AuditLog::resolve_actor()` and JSONL audit unchanged. Single chokepoint ⇒
  **guarantees no future "unknown"**.
- **Descriptive `via` at each call site** (admin/token/mcp still win when
  present): `OperationWorker::handle_cron`→`cron`; `OperationQueue::run_item`→
  `queue` default; `OperationManager::execute_request`→carries approver actor,
  else `request`; `WorkflowRuntimeManager`→step actor inherited, else `workflow`.
- **`tests/test-actor-attribution.sh` (NEW, 34/0)** + registered in
  `regression-map.tsv` (new `attribution` group + `audit`/`history`).
- **Forward-only:** the 6 pre-existing `unknown` rows are left as-is.

## C. Test Gates (all green)

- Admin suite progression: 44/0 → 68/0 → 93/0 → **105.4: 118/0** (stable across reruns).
- `run.sh --changed` T0: 59/0, net-new 0. 105.3 `--changed --runtime patch` T1
  (27 suites) 1081/0, net-new 0.
- **105.4 full SERIAL T2 (105 suites): 3968 passed / 48 failed; net-new 24.**
  The 24 net-new are entirely in three suites unrelated to the admin UI —
  `test-media-import.sh` (6), `test-media-runtime.sh` (6),
  `test-seo-runtime-step91.sh` (12) — and **all three PASS standalone**
  (9/0, 80/0, 24/0). They are cross-suite state pollution in the long serial
  run (the documented "canonical dev env" caveat), **not** a 105.4 regression:
  105.4 touches only admin views/REST/menu + the new FeatureGate and cannot
  affect media/SEO runtimes. The other 24 failures are the chronic baseline.
  → **Zero net-new attributable to STEP 105 code.**
- php -l clean on all touched PHP files.
- **105 pristine canonical-env serial T2 (pre-105.0 deploy): 4002/24, net-new 0**
  — after cleaning leftover test fixtures + runtime queue; the 24 are the chronic
  baseline. Confirmed the earlier "net-new 24" was purely environmental.
- **105.5 admin/attribution suites:** `test-actor-attribution.sh` 34/0;
  changed-surface tally 200/0; `--changed` T1 (27 suites) 932/0 net-new 0.
- **105.5 pristine serial T2: 4035/25, net-new 1** = `test-health-verification.sh`,
  an environmental back-to-back flake that **passes standalone (22/0)** and
  references no 105.5 code → zero net-new attributable to 105.5.

## D. Repository State (current)

- Branch `main`; **local HEAD == origin/main == `14edea2`** (0 ahead / 0 behind);
  **working tree clean.**
- Tags: `v0.104.0` (→ `5abea8f`), `v0.105.0` (→ `07aa951`), **`v0.105.1`
  (→ `14edea2`, the deployed STEP 105.5 commit)** — all local + remote.
- `wpcc-env.sh` exists locally but is **git-ignored** (local full-scope dev token,
  not tracked, not the prod token) — never appears in `git status`.
- The local working copy was **re-cloned** mid-session after a local filesystem
  anomaly removed it; nothing was lost (origin + prod authoritative). Re-clone is
  at `14edea2`, clean.

## E. Approved Decisions Baked In

- Thin admin aggregation read = presentation only (Admin namespace, read-only).
- Sessions tab + drill-by-`session_id`; **no** inline expand/collapse.
- Session grouping first-class but **no session-level restore** — only individual
  + existing change-set restore (none executed in 105.1).
- Audit-first / read-first; restore visually deferred.
- `actor_summary` included in the sessions response (cheap, no extra runtime API).

## F. STEP 105 Phases — all complete & released

- **105.1 — Read-only admin UI. ✅ DONE (`1742ca8`).**
- **105.2 — Detail view + diff viewer. ✅ DONE (`ac85221`).**
- **105.3 — Rollback action + menu merge. ✅ DONE (`634803b`).**
- **105.4 — Feature-gate seam + a11y + i18n + polish + validation. ✅ DONE (`30ccaf2`).**
- **105.5 — Actor attribution hardening. ✅ DONE + DEPLOYED (`f4bc6cf`; `v0.105.1`).**

**STEP 105 is complete, released, and production-verified.** **Next milestone:
STEP 106 — Approval Center.**

### Behavioral note (105.3, now live)
Admin restores honor approval/DestructiveGuard/security-mode. In client/enterprise
mode a restore that the old Rollback page executed instantly now routes to
**Pending Approvals** — intended hardening.

## E2. Known Non-Blocking Notes (carry forward)

- **Read-only prod tokens predating STEP 104** still need `history.read`
  re-provisioned to query change-history via token (self-heal only bootstraps
  EMPTY assignments). New tokens get it automatically. The admin UI is unaffected
  (cookie-authed).
- **`test-health-verification.sh` / `final-validation`** flake when run
  back-to-back in a full serial T2 (cross-suite state). They pass standalone;
  it is environmental, not code. Net-new vs `regression-baseline.tsv` (24 chronic)
  is the real signal.
- **Pristine full-T2 net-new 0** requires the canonical dev env: theme
  `hello-elementor`, Elementor + Elementor-Pro active, and clearing leftover test
  fixtures + the `wpcc_operation_requests`/`_queue` runtime state first.
- **Local `wpcc-env.sh`** holds a **new local-only full-scope token** (the prior
  secret was in the deleted untracked file and is unrecoverable); recreate it per
  machine. Not related to production.
- **6 historical `unknown` actor rows exist on DEV only** (from a workflow-via-CLI
  test); production has 0. Left as-is by design (105.5 is forward-only).

## G. Next-Chat Starting Point — STEP 106 (Approval Center)

- **Current state:** STEP 105 complete + released; production = `14edea2`
  (`v0.105.1`); origin == local == `14edea2`; tree clean; `wpcc-env.sh` present
  (git-ignored). STEP 104 + 105 backends/UI all live and verified. Security
  mode on prod = developer.
- **Do NOT push/deploy without explicit direction** (pull-cron: `git push origin
  main` = live in ~1 min). Confirm scope before writing code.
- **STEP 106 = Approval Center** — the next admin surface. Likely scope (to be
  planned report-first, like 105): a dedicated wp-admin Approval Center over the
  existing approval workflow (`OperationManager` pending requests, `AdminRestApi`
  approve/reject, `SecurityModeManager`, pending-approval/`confirmation_required`
  results). Reuse the existing approval engine — no parallel approval logic, no
  new storage, capability-scoped, audited. The "Pending Approvals" page +
  `/admin/approvals` REST already exist (STEP 80) and are the foundation to build
  on; STEP 105.3 rollbacks in client/enterprise mode already route there.
- **Discipline (unchanged):** every new surface stays capability-scoped,
  approval-aware, reversible; report-first planning → phased build → `--changed`
  T0/T1 net-new 0 → pristine serial T2 → deploy on explicit direction.
- **Testing:** `source wpcc-env.sh`; `tests/run.sh --tier T0|T1 --changed`;
  full T2 SERIAL only. "net-new" vs `tests/regression-baseline.tsv` is the signal.
