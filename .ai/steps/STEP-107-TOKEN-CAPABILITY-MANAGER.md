# STEP 107 — Token & Capability Manager — Architecture & Planning Report

**Status:** REPORT-ONLY (no code, no commits, no deployment). **Scope LOCKED — see §0.**
**Written:** 2026-06-18; **decisions locked 2026-06-18.** **Author handoff base:**
`HANDOFF-STEP-105.md` §G.
**Production at time of writing:** `c28d33d` / `v0.106.0`; op_map **34**, caps **23**,
MCP tools **40**, DB_VERSION **2.4.0**; security mode = developer.

This report plans STEP 107 the same way STEP 105 (Change History Admin) and STEP
106 (Approval Center) were planned: **report-first → phased build → reuse the
existing engine, never fork it → capability-scoped, audited, reversible →
`--changed` T0/T1 net-new 0 → pristine serial T2 → deploy on explicit direction.**

---

## 0. LOCKED Scope & Constraints (owner decision, 2026-06-18)

**STEP 107 is a VISIBILITY and MANAGEMENT milestone — NOT a capability-model
redesign.** Every locked item below is binding for all phases.

| # | Decision | Locked outcome |
|---|----------|----------------|
| D1 | **Custom scopes** | **DEFERRED.** STEP 107 manages the **existing** capability assignments only. No composable/custom token scope. |
| D2 | **Token rotation / re-issue** | **DEFERRED.** STEP 107 ships **create / revoke / delete** + **capability assign/remove** only. No rotate, no edit-secret. (Label/expiry edit also out — see §4.4.) |
| D3 | **`settings.php` migration** | **FULL migration.** Token *and* capability management move into the dedicated admin surface; `settings.php` retains only Security Mode + endpoint reference, with legacy redirects. |
| D4 | **FeatureGate key** | **`token_capability_manager`.** |

**Hard constraints (invariants — must hold through every phase):**
- **No new capabilities** (stays **23**).
- **No `OPERATION_MAP` changes** (stays **34**).
- **No MCP tool count changes** (stays **40**).
- **No new runtime operation, no schema change** (DB_VERSION stays **2.4.0**).
- **No new storage** — reuse the existing token file manifest + the
  `wpcc_capability_assignments` option.
- **No `AuthTokens` / `CapabilityRegistry` / `CapabilityManager` / `OperationExecutor`
  behavior change.** STEP 107 is additive admin surface only.

Consequence: STEP 107 touches **only** the `includes/Admin/` namespace (+ tests).
Any change outside `includes/Admin/` is out of scope and must be re-approved.

---

## 1. Current Architecture Summary

### 1.1 Token system (`includes/Security/AuthTokens.php`, STEP 10)
- **Storage:** a JSON manifest at `wp-content/uploads/wpcc-tokens/manifest.json`,
  web-protected by an auto-written `.htaccess` (`Require all denied`) + `index.php`.
  **Not a DB table** — a file manifest. No `DB_VERSION` impact.
- **Secret handling:** raw token = `wpcc_` + 64-char `wp_generate_password`. Stored
  only as `hash_hmac('sha256', raw, wp_salt('auth'))`. **Raw shown once at create.**
  A 12-char `token_preview` is persisted for display.
- **Record shape:** `{ id (uuid4), label, token_hash, token_preview, scope, status,
  user_id, created_at, expires_at|null, last_used_at|null }`.
- **Scopes:** `read_only` | `full` (binary). **Status:** `active` | `revoked`;
  `expired` is *computed* from `expires_at` (not a stored status).
- **Lifecycle API:** `create()`, `list()` (newest first), `revoke()`, `delete()`,
  `validate()` (records `last_used_at`). `validate()` is the bearer-auth chokepoint.
- **Display helpers:** `scope_label()`, `status_badge()` (escaped HTML).
- **Capability coupling:** `create()` calls
  `CapabilityRegistry::bootstrap_token()`; `revoke()`/`delete()` call
  `deprovision_token()`. So token lifecycle and capability assignment stay in sync.

### 1.2 Capability system (`includes/Operations/CapabilityRegistry.php`, STEP 38/44/79)
- **23 capabilities** in `ALL_CAPABILITIES` (`content.manage`, `plugin.manage`,
  …, `history.read`). `system.admin` is special: in `validate()` it is an
  **unrestricted shortcut** (`$has_admin` ⇒ allow any operation).
- **`OPERATION_MAP` — 34 operations → required capability.** This is the single
  source of truth the platform's invariant count ("op_map 34") refers to.
- **`READ_ONLY_SCOPE_OPERATIONS`** — the allowlist a `read_only` token may call
  (`database_inspect`, `search_manage`, `file_manage`, `code_search`,
  `change_history`). Everything else ⇒ `requires_full_scope()` true (fail-closed,
  including unmapped/seed ops).
- **Profiles (STEP 79):** `read_only` → `[database.inspect, search.manage,
  history.read]`; `full_access` → `[system.admin]`; `system_admin` →
  `[system.admin]`. `profile_for_scope()` maps token scope → profile.
- **Assignment storage:** the WP option `wpcc_capability_assignments`, keyed
  `"{subject}:{subject_id}"` → `[caps]`. Subject is `token` in practice
  (`token:{uuid}`). **Not a DB table — an option.**
- **Provisioning:** `bootstrap_token()` (on create / overwrite),
  `deprovision_token()` (on revoke/delete), `ensure_token_capabilities()`
  (idempotent **self-heal**: empty assignment → bootstrap from scope; non-empty
  left untouched — manual customizations survive).
- **`assign()` guard:** rejects unknown caps and **refuses `system.admin`**
  (`wpcc_cannot_assign_admin`) — anti-escalation. Removal is unguarded except for
  unknown-cap.

### 1.3 Capability runtime op (`CapabilityManager`, op `capability_manage`)
- Actions: `capability_list | capability_get | capability_assign |
  capability_remove | capability_validate`. Mapped to **`CAP_CAPABILITY_ADMIN`**
  in `OPERATION_MAP` (prevents privilege escalation via the API itself).
- Every action audits (`capability.assigned/removed/validated/denied`).
- `get_summary()` returns `{capabilities, operation_map, assignment_count,
  subject_counts}` — already a ready-made read aggregate.

### 1.4 Enforcement chokepoints
- **`OperationExecutor::run()` lines 76-92:** when `wpcc_enforce_capabilities`
  (default true) **and** a non-empty `token_id` is present, validates
  `token:{token_id}` against the op's required cap; denial → audited
  `capability.denied` + `wpcc_capability_denied` fail. **Key nuance:** if
  `token_id` is empty (admin-cookie actor, `source: admin_ui`, no token), the
  token-cap check is **skipped** — authorization falls to `manage_options` at the
  REST/permission layer. This is exactly the model STEP 105.3 / 106.3 write
  actions rely on, and it is what makes admin-driven capability edits authorize
  cleanly.
- **`AiAgent/RestApi.php`:** binds `token_id` + `token_scope` into the actor/
  context; enforces `requires_full_scope()` for `read_only` tokens
  (`require_write()` mirror).
- **`Mcp/McpServerRuntime.php` line 170:** calls `ensure_token_capabilities()`
  before dispatch — MCP tokens self-heal on first use.

### 1.5 Where this is exposed in wp-admin today
- **Only `includes/Admin/views/settings.php`** — a raw form-POST surface
  (`check_admin_referer('wpcc_settings')`) for create / revoke / delete tokens, a
  static token table, the security-mode picker, and a static endpoint reference.
- **No admin REST for tokens. No capability visibility at all in the UI.** The
  per-token capability set (`wpcc_capability_assignments`) is **invisible** to an
  admin — reachable only via the `capability_manage` API (which itself needs a
  `capability.admin`/full token) or by editing the option directly.

### 1.6 The 105/106 admin pattern (the template to reuse)
1. **Thin Admin-namespace Query class** (`ChangeHistoryAdminQuery`,
   `ApprovalAdminQuery`) — presentation-only, read-only, cheap (grouped SELECTs,
   no N+1, no persistence, *not* a runtime/MCP API, *not* a new source of truth).
2. **`AdminRestApi` routes** — cookie + nonce + `manage_options`, gated behind a
   `FeatureGate` seam. Literal routes registered before wildcard routes.
3. **Server-rendered view** (`views/*.php`) + inline vanilla JS, URL-driven tabs,
   all API output escaped, a11y (`role=dialog` modals, focus trap/return,
   `role=status` live regions, `scope` headers), full i18n.
4. **Write actions route THROUGH `OperationExecutor::run()`** — no bypass; inherit
   capability/DestructiveGuard/security-mode/audit. The admin actor carries
   `source: admin_ui` and **no** `token_id`/`token_scope`.
5. **`FeatureGate::allows('<feature_key>')`** gates the menu + routes (ungated
   today; the Free/Pro switch point via `wpcc_feature_allowed`).

---

## 2. Gaps STEP 107 Must Close

| # | Gap | Impact |
|---|-----|--------|
| G1 | **Capability assignments are invisible in wp-admin.** No way to see which caps a token holds, or which operations it can/can't run. | Admins can't audit or reason about agent permissions; the whole STEP 38/44/79 system is "headless." |
| G2 | **No per-token capability editing in the UI.** Granular scoping is API-only (and needs a privileged token to reach). | Can't tailor a token below "full" without hand-editing an option or scripting the API. |
| G3 | **Token CRUD is form-POST in `settings.php`**, inconsistent with the 105/106 REST+JS pattern; no detail view, no audit trail surfaced. | Divergent UX; no insight into token activity beyond a `last_used` column. |
| G4 | **`OPERATION_MAP` (34 ops → caps) and the capability catalog are undiscoverable.** | Admins/operators can't see "what does `media.manage` actually unlock?" |
| G5 | **The `full` ⇒ `system.admin` ⇒ unrestricted reality is hidden.** A `full` token ignores granular caps entirely. | Without surfacing this honestly, a capability UI would *imply* granular control that a full token overrides. |
| G6 | **Capability/token audit events exist but are unsurfaced** (`capability.assigned/removed/denied/bootstrap/deprovisioned` in the JSONL AuditLog). | No "who changed this token's permissions, when" view. |
| G7 | **No token rotation / re-issue, no edit of label/expiry.** Lost tokens require delete-and-recreate. | Operational friction. **OUT OF SCOPE for 107 (D2 deferred)** — listed for roadmap completeness only; G1–G6 are the 107 targets. |

---

## 3. Proposed STEP 107 Architecture

A dedicated wp-admin **"Token & Capability Manager"** surface over the **existing**
`AuthTokens` + `CapabilityRegistry`. **Reuse-only — no parallel auth/capability
logic, no new storage, no new runtime op, no MCP tool, no schema change.**

### 3.1 The authorization model (decided, not invented)
- **Reads** are gated by `manage_options` + nonce at the admin REST layer (105/106
  pattern).
- **Capability writes** (assign/remove) route **through
  `OperationExecutor::run('capability_manage', …)`** with an admin actor
  (`source: admin_ui`, **no `token_id`**). Per §1.4, the token-cap check is
  skipped for token-less actors, so the op executes — authorized by
  `manage_options` and **fully audited** (`capability.assigned/removed`),
  DestructiveGuard/security-mode-aware, **no bypass.** This mirrors how 105.3/106.3
  route restores/retries. We do **not** call `CapabilityRegistry::assign()`
  directly from the admin layer.
- **Token lifecycle writes** (create/revoke/delete) reuse `AuthTokens` directly
  (there is no runtime op for token CRUD, and creating one is out of scope). These
  are wrapped in admin REST with nonce + `manage_options`, replacing the
  `settings.php` form-POST. Capability sync stays automatic via the existing
  `bootstrap_token`/`deprovision_token` hooks inside `AuthTokens`.

### 3.2 The `system.admin` honesty rule (addresses G5)
The manager must render the **effective** permission truthfully:
- A `full` token (profile `full_access` = `[system.admin]`) is shown as
  **"Full access — all operations"** with granular cap editing **disabled** and an
  explanatory note ("This token has system.admin; it can run every operation
  regardless of individual capabilities").
- Granular per-cap assignment is meaningful **only** for tokens *without*
  `system.admin`. The UI surfaces an **"effective operations" computation** per
  token: for each of the 34 ops in `OPERATION_MAP`, resolve allow/deny via
  `CapabilityRegistry::validate()` semantics + `requires_full_scope()` + the
  read-only allowlist. This is the single most valuable new read.
- **Custom scopes are DEFERRED (D1, locked).** STEP 107 surfaces and edits the
  *existing* assignment model only. A composable/"custom" scope so a non-admin
  token can be hand-built from individual caps is a separate product decision —
  flagged for the roadmap (STEP 110 / later), explicitly **not** in 107.

### 3.3 New/changed components (all additive)
- **`includes/Admin/TokenCapabilityAdminQuery.php` (NEW)** — thin presentation
  aggregation, read-only. Joins the token manifest (`AuthTokens::list()`) with
  `wpcc_capability_assignments`, the capability catalog, and `OPERATION_MAP` to
  produce: token rows enriched with `effective_scope_label`, assigned caps,
  is_admin flag, and a computed **operation-access matrix**. Plus a catalog view
  (capabilities + which ops each unlocks) and an operation-map view. Cheap: in-
  memory joins over a file manifest + one option read; no DB, no N+1, no caching.
  *Not* a runtime/MCP API; *not* a new source of truth.
- **`includes/Admin/AdminRestApi.php` (MOD)** — new `manage_options`+nonce routes
  under `/admin/tokens*` and `/admin/capabilities*`, gated by
  `check_tokens_permission()` = `manage_options && FeatureGate::allows(
  'token_capability_manager')`. Literal-before-wildcard ordering preserved.
- **`includes/Admin/views/token-capability-manager.php` (NEW)** — server-rendered
  + inline JS; tabs **Tokens / Capabilities / Operation Map**; token detail panel;
  confirm modals for write actions; full escaping/a11y/i18n.
- **`includes/Admin/AdminMenu.php` (MOD)** — "Tokens & Capabilities" submenu gated
  by `FeatureGate::allows('token_capability_manager')`; `admin_init` redirect to
  preserve any old deep links; **the token section is removed from `settings.php`**
  (or left as a redirect link) — decided in 107.4.
- **`includes/Admin/FeatureGate.php`** — **unchanged**; reuse with the new feature
  key `token_capability_manager`.
- **No change to** `AuthTokens`, `CapabilityRegistry`, `CapabilityManager`,
  `OperationExecutor`, `OPERATION_MAP`, profiles, or any storage. **Invariants held:
  op_map 34, caps 23, MCP 40, DB 2.4.0.**

---

## 4. Phased Plan (report-first, like 105/106)

### 107.1 — Read surface (read-only)
- `TokenCapabilityAdminQuery`: token roll-up + per-token assigned caps + the
  **operation-access matrix** + capability catalog + operation map.
- Admin REST reads: `GET /admin/tokens`, `/admin/tokens/{id}`,
  `/admin/capabilities`, `/admin/operations-map`.
- View with **Tokens / Capabilities / Operation Map** tabs; token table (label,
  preview, scope, status badge, created/expires/last-used, effective-access
  summary). **No write controls yet.**
- Invariants unchanged. Tests: new `tests/test-token-capability-admin.sh`
  (query shape, escaping, matrix correctness, admin-only gating).

### 107.2 — Token detail panel (read-only)
- Per-token detail: scope, status, effective capabilities, the 34-op allow/deny
  matrix, `last_used_at`, and a **per-token audit trail** (AuditLog tail filtered
  to `capability.*` + token events for that `token_id`) — reuse the AuditLog
  `tail()` already used by 106.2. Honesty rule (§3.2) surfaced here.

### 107.3 — Capability write actions (engine reuse, no bypass)
- `POST /admin/tokens/{id}/capabilities` (assign) and `DELETE …` (remove) route
  **through `OperationExecutor::run('capability_manage', …)`** with an admin actor
  (no `token_id`). Inherits audit + security-mode + the `system.admin` refusal
  guard. Granular editing **disabled for `full`/admin tokens** (§3.2); a clear
  note explains why. Confirm modal on every mutation; `role=status` result region.
- Never calls `CapabilityRegistry::assign()`/`remove()` directly.

### 107.4 — Token lifecycle write actions + full settings migration
- `POST /admin/tokens` (create — returns the raw token once, copy-once UX),
  `POST /admin/tokens/{id}/revoke`, `DELETE /admin/tokens/{id}`. Reuse
  `AuthTokens` verbatim; capability bootstrap/deprovision stays automatic.
- **NO rotation, NO edit-secret, NO label/expiry edit (D2, locked).** Lifecycle is
  exactly create/revoke/delete — a 1:1 reuse of the existing `AuthTokens` API with
  zero additions to that class.
- **FULL migration of token UI out of `settings.php` (D3, locked)** into the new
  manager, with an `admin_init` legacy redirect (106.4 pattern). `settings.php`
  retains **only** Security Mode + the endpoint reference; its token table, create
  form, and revoke/delete POST handlers are removed. No `AuthTokens` calls remain
  in `settings.php`.

### 107.5 — Rename/redirect + FeatureGate + a11y + i18n + polish + validation
- `FeatureGate('token_capability_manager')` on menu + routes; legacy redirects;
  full a11y (modal `role=dialog`/`aria-modal`/focus trap+return, live regions,
  `scope` headers, aria-labels), full i18n (no raw JS strings), empty/error/
  nonce-expiry states. **STEP 107 feature-complete.**

---

## 5. Invariants, Risks, (Decisions Locked)

### Invariants (must hold through all phases)
- **operation_map = 34, capabilities = 23, MCP tools = 40, DB_VERSION = 2.4.0.**
  STEP 107 adds **no** runtime op, MCP tool, capability, or schema. No new storage
  (reuses the file manifest + the existing option). See §0 for the binding list.

### Risks (post-lock)
- **R1 — Honesty of effective access (G5).** If the matrix is computed wrong, the
  UI could misrepresent what an agent can do. Mitigation: compute strictly via the
  existing `validate()` + `requires_full_scope()` + read-only allowlist; cover with
  matrix-correctness tests (admin token = all-allow; read-only token = exactly the
  5 allowlist ops; non-admin custom-assignment token = its mapped subset).
- **R2 — Write-path authorization.** Confirmed: token-less admin actors skip the
  token-cap check (`OperationExecutor` line 80) so `capability_manage` runs under
  `manage_options`. Build must not alter `wpcc_enforce_capabilities` semantics.
- **R3 — Settings migration completeness (D3).** Removing token CRUD from
  `settings.php` must (a) keep old bookmarks working via redirect, (b) remove the
  now-orphaned `create_token`/`revoke_token`/`delete_token` nonce actions cleanly,
  and (c) leave Security Mode handling intact. Migration is verified by a test
  asserting `settings.php` no longer references `AuthTokens`.
- **R4 — No-scope-creep.** With rotation/custom-scope deferred, the temptation is
  to "just add" an `AuthTokens` helper. **Forbidden** — §0 caps STEP 107 to the
  `includes/Admin/` namespace. Any cross-namespace need ⇒ stop and re-approve.

### Decisions — LOCKED (see §0)
1. **D1 Custom scopes — DEFERRED.** Existing assignments only.
2. **D2 Token rotation — DEFERRED.** create/revoke/delete + capability assign/remove only.
3. **D3 `settings.php` — FULL migration** into the dedicated surface (redirect legacy).
4. **D4 FeatureGate key — `token_capability_manager`.**

No open decisions remain. Build may proceed phase-by-phase on explicit direction.

### Carry-forward from STEP 106 (relevant if 107 touches these)
- `OperationExecutor` still emits the legacy `page=wpcc-approvals` `approval_url`
  (harmless; 106.4 redirect catches it). Optional one-line cleanup only if 107
  edits `OperationExecutor` (it should not need to).

---

## 6. Test & Release Discipline (unchanged)
- New `tests/test-token-capability-admin.sh`, registered into
  `tests/regression-map.tsv` (token/capability group).
- Per phase: `tests/run.sh --tier T0|T1 --changed`; **net-new vs
  `tests/regression-baseline.tsv` is the signal** (24 chronic baseline failures).
- Pristine **serial** T2 before any deploy (canonical env: `hello-elementor`,
  Elementor + Pro, cleared runtime/queue fixtures).
- **No push/deploy without explicit direction** (pull-cron: `git push origin main`
  = live in ~1 min).

---

## 7. One-paragraph summary
STEP 107 is a wp-admin **Token & Capability Manager** built strictly on the 105/106
template: a thin read-only `TokenCapabilityAdminQuery`, `manage_options`+nonce
admin REST, a tabbed server-rendered view, and write actions that route **through**
`OperationExecutor::run('capability_manage', …)` (capability mutations) and reuse
`AuthTokens` (token create/revoke/delete) — **no parallel auth, no bypass, no new
storage, no schema/op/MCP/cap change.** Its signature value is making the
*invisible* capability layer (G1/G2) and the 34-op → 23-cap map (G4) **visible and
editable**, while **honestly surfacing** that `full`/`system.admin` tokens are
unrestricted (G5). Phased 107.1 read → 107.2 detail/audit → 107.3 capability writes
→ 107.4 token lifecycle + full settings migration → 107.5 gate/a11y/i18n/polish.
**Custom scopes and rotation are deferred (D1/D2).** Invariants 34/23/40/2.4.0 hold
throughout.

---

## 8. FINAL Implementation Proposal (locked scope)

The blueprint a builder follows once a phase is greenlit. Everything below honors
§0. **All changes live in `includes/Admin/` + `tests/`.**

### 8.1 File-level change set
| File | Action | Responsibility |
|------|--------|----------------|
| `includes/Admin/TokenCapabilityAdminQuery.php` | **NEW** | Read-only presentation aggregation: enrich `AuthTokens::list()` with assigned caps (`wpcc_capability_assignments`), `is_admin`/`effective_scope_label`, and the **34-op access matrix**; plus capability catalog + operation-map views. No writes, no persistence, no caching, not MCP-exposed. |
| `includes/Admin/AdminRestApi.php` | **MOD** | Add `/admin/tokens*` + `/admin/capabilities*` + `/admin/operations-map` routes behind `check_tokens_permission()`. Literal-before-wildcard. |
| `includes/Admin/views/token-capability-manager.php` | **NEW** | Tabbed view (Tokens / Capabilities / Operation Map) + token detail panel + confirm modals. Escaped, a11y, i18n. |
| `includes/Admin/AdminMenu.php` | **MOD** | "Tokens & Capabilities" submenu gated by `FeatureGate::allows('token_capability_manager')`; `admin_init` legacy redirect. |
| `includes/Admin/views/settings.php` | **MOD** | **Remove** the token table, create form, and `create_token`/`revoke_token`/`delete_token` handlers; keep Security Mode + endpoint reference. No `AuthTokens` usage remains. |
| `tests/test-token-capability-admin.sh` | **NEW** | Per-phase assertions; registered in `tests/regression-map.tsv`. |

`includes/Admin/FeatureGate.php` — **unchanged** (reused with the new key).
**Untouched (verify byte-stable):** `AuthTokens`, `CapabilityRegistry`,
`CapabilityManager`, `OperationExecutor`, `OPERATION_MAP`, profiles, McpServerRuntime.

### 8.2 Admin REST surface (cookie + nonce + `manage_options` + FeatureGate)
**Reads** (gated by `check_tokens_permission()`):
- `GET  /admin/tokens` — enriched token list (no secrets/hashes; preview only).
- `GET  /admin/tokens/{id}` — detail: scope, status, effective caps, **34-op access
  matrix**, `last_used_at`, + `capability.*`/token audit tail for that `token_id`.
- `GET  /admin/capabilities` — the 23-cap catalog + which ops each unlocks.
- `GET  /admin/operations-map` — the 34-entry `OPERATION_MAP` + read-only allowlist.

**Writes** (same gate; capability writes route through the engine):
- `POST   /admin/tokens` → `AuthTokens::create()` (raw token returned **once**).
- `POST   /admin/tokens/{id}/revoke` → `AuthTokens::revoke()`.
- `DELETE /admin/tokens/{id}` → `AuthTokens::delete()`.
- `POST   /admin/tokens/{id}/capabilities` (assign) and
  `DELETE /admin/tokens/{id}/capabilities/{cap}` (remove) →
  `OperationExecutor::run('capability_manage', {action, subject:'token',
  subject_id:{id}, capability}, {actor:{source:'admin_ui'}})` — **no `token_id`** so
  the token-cap check is skipped and `manage_options` is the gate; inherits audit +
  `system.admin` refusal. **Never** calls `CapabilityRegistry` directly.

ID regex for `{id}` mirrors the approval routes' uuid4 pattern
(`[a-f0-9-]{36}`); `{cap}` is validated against `ALL_CAPABILITIES` server-side.

### 8.3 The access matrix (the headline read — exact algorithm)
For a token with assigned caps `A` and scope `S`, for each `op` in `OPERATION_MAP`:
1. If `system.admin ∈ A` → **allow** (unrestricted; render "Full access").
2. Else if `S == read_only` and `op ∉ READ_ONLY_SCOPE_OPERATIONS` → **deny**
   (scope-blocked) — mirrors `requires_full_scope()`.
3. Else let `req = OPERATION_MAP[op]`; **allow** iff `req ∈ A`; else **deny
   (missing: req)**. (Unmapped ops are allowed by the engine but are not in the
   34-map, so they don't appear in the matrix.)
This reproduces `CapabilityRegistry::validate()` + the RestApi scope gate **without
re-implementing them as new policy** — it reads the same inputs the engine reads.

### 8.4 UX honesty (G5) — non-negotiable
- `full`/`system.admin` token detail → granular cap controls **disabled** + note:
  "This token has system.admin and can run every operation regardless of individual
  capabilities." Matrix shows all-allow.
- `read_only` token → matrix shows exactly the 5 allowlist ops allowed, the rest
  scope-blocked; assign/remove still available but annotated that scope gates first.

### 8.5 Phase → deliverable → gate
| Phase | Ships | Exit gate |
|-------|-------|-----------|
| 107.1 | Query + 4 read routes + read-only tabbed view | `--changed` T0/T1 net-new 0; matrix unit asserts |
| 107.2 | Token detail panel + audit tail + honesty note | net-new 0 |
| 107.3 | Capability assign/remove via OperationExecutor (no bypass) | net-new 0; audit + no-bypass + admin-disabled asserts |
| 107.4 | Token create/revoke/delete REST + **full `settings.php` migration** + legacy redirect | net-new 0; `settings.php`-has-no-`AuthTokens` assert |
| 107.5 | FeatureGate + a11y + i18n + empty/error states + validation | full pristine **serial** T2; net-new 0 → ready to deploy on direction |

### 8.6 Acceptance criteria (definition of done)
- Admin can **see** every token's effective capabilities and per-op access; **see**
  the 23-cap catalog and 34-op map; **assign/remove** caps on non-admin tokens
  (audited, engine-routed, no bypass); **create/revoke/delete** tokens.
- `settings.php` no longer manages tokens; legacy links redirect; Security Mode
  intact.
- **Invariants verified post-build:** op_map 34, caps 23, MCP 40, DB 2.4.0; no files
  outside `includes/Admin/` + `tests/` changed.
- All write paths audited; `system.admin` never assignable; secrets shown once and
  never logged.
- `--changed` T0/T1 net-new 0 each phase; pristine serial T2 net-new 0 before deploy.

### 8.7 Out of scope (explicit, locked)
Custom/composable scopes (D1); token rotation / re-issue / edit-secret / edit
label-expiry (D2); any new capability, operation, MCP tool, schema, or storage; any
change to `AuthTokens`/`CapabilityRegistry`/`OperationExecutor` behavior.
