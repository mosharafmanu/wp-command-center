# PROJECT HANDOFF — STEP 107

**Written:** 2026-06-18. Supersedes `HANDOFF-STEP-105.md` for current state.
STEP 106 (Approval Center) remains live at `v0.106.0`. **STEP 107 — Token &
Capability Manager (107.1–107.5) is COMPLETE, RELEASED, and PRODUCTION-VERIFIED.**

- **Production runs tag `v0.107.0`; origin/main == local HEAD == the v0.107.0
  milestone commit; working tree clean.** The exact deployed commit hash is
  recorded in the STEP 107 Deployment Verification Report. See **§A** for the
  release & production-verification proofs.

---

## A. STEP 107 — Token & Capability Manager: Release & Production Verification (deployed `v0.107.0` / `v0.107.0`)

**Current production release.** A dedicated wp-admin "Tokens & Capabilities"
manager over the existing API-token system (STEP 10 `AuthTokens`) and the
per-token capability assignments (STEP 38/44/79 `CapabilityRegistry`). Reuse-only,
report-first across five phases. **No new capability, operation, MCP tool, schema,
or storage; no engine/policy/AuthTokens-lifecycle behavior change.** Every change
lives in `includes/Admin/` + `tests/`.

- **107.1 — Read surface.** NEW `Admin/TokenCapabilityAdminQuery` (read-only
  presentation aggregation: token manifest × capability assignments × operation
  map, incl. the per-token 34-op access matrix). 4 cookie+nonce `manage_options`
  reads (`/admin/tokens`, `/admin/tokens/{id}`, `/admin/capabilities`,
  `/admin/operations-map`) gated by `FeatureGate('token_capability_manager')`.
  NEW `views/token-capability-manager.php` (Tokens / Capabilities / Operation Map
  tabs). Secrets never surfaced (`token_hash` dropped).
- **107.2 — Detail + audit trail.** Token detail folds a read-only, bounded,
  chronological, per-token AuditLog tail (`capability.*` + token events) into the
  `/admin/tokens/{id}` response — no new route, no writes.
- **107.3 — Capability writes (engine reuse, no bypass).** `POST
  /admin/tokens/{id}/capabilities` + `DELETE …/{cap}` route THROUGH
  `OperationExecutor::run('capability_manage', …)` with a token-LESS `admin_ui`
  actor (token-cap gate skipped → `manage_options` is the gate); inherits audit +
  security-mode + the `system.admin` refusal guard. Granular editing disabled for
  `system.admin` (full) tokens — honesty rule. Confirm modal + `role=status`.
- **107.4 — Token lifecycle + full settings migration.** `POST /admin/tokens`
  (create — raw secret once, never the hash), `POST …/{id}/revoke`, `DELETE
  …/{id}` — pure `AuthTokens` reuse (capability bootstrap/deprovision automatic);
  `admin.token.created/revoked/deleted` audit. Token UI fully removed from
  `settings.php` (no `AuthTokens` calls remain; keeps Security Mode + connection
  reference); `AdminMenu::redirect_legacy_tokens()` 302s legacy token deep-links
  to `wpcc-tokens`.
- **107.5 — FeatureGate + a11y + i18n + polish + validation.** Modal focus trap
  (Tab/Shift+Tab), focus return, `aria-describedby`/`aria-modal`/`role=dialog`,
  `role=status` live regions, Esc + full keyboard; empty-state guards for all
  tabs; i18n completeness (no raw JS strings); FeatureGate gating verified
  functionally; legacy redirect verified (positive + negative).

- **Commit of record:** the single coherent STEP 107 milestone commit
  (107.1–107.5, incl. this handoff), tagged **`v0.107.0`** (annotated). **Pushed:**
  `ad1c2b5..v0.107.0  main -> main`; tag pushed to origin.
- **Deployed-commit proof (SSH, allowlisted IP):** server `git describe` =
  `v0.107.0` and `git rev-parse HEAD` == the v0.107.0 commit (exact hash in the
  Deployment Verification Report); deploy log entry recorded. Plugin active.
- **Invariants (read from deployed code):** operation_map **34**, capabilities
  **23**, DB_VERSION **2.4.0**, MCP tools **40** — unchanged from STEP 106.
- **Functional prod verification (read-only via wp-cli over SSH):** FeatureGate
  `token_capability_manager` allows = true (filterable); the 6 read +
  capability-write + lifecycle routes registered; token create→revoke→delete
  round-trip + capability assign/remove (engine-routed) confirmed on a throwaway
  token, then cleaned up; `admin.token.*` + `capability.*` audit recorded.
- **Route/health (anonymous HTTP):** homepage 200; namespace index 200;
  `/admin/tokens`, `/admin/capabilities`, `/admin/operations-map` all **401**
  (live, auth-gated, not 404); admin page `wpcc-tokens` 302 (login); legacy
  `wpcc-settings&section=tokens` 302 → `wpcc-tokens`; **no 500s.**
- **Test gates:** `test-token-capability-admin.sh` **139/0**; `--changed` T0
  **310/0** net-new 0, T1 **815/0** net-new 0 (21 suites); **pristine serial T2
  4353/24, net-new 0** (110 suites; the 24 are the chronic baseline:
  ai-client-layer 1 / ai-integration-ux 3 / claude-integration 4 /
  cursor-certification 2 / documentation-consistency 11 / security-redaction 3).
- **Anomaly:** none affecting the release. Verification was SSH reads + wp-cli
  reflection + anonymous HTTP; a throwaway token was created and deleted, no other
  production data modified.

## B. Locked scope (carried from the approved STEP-107 proposal §0)
- **Custom scopes — DEFERRED (D1).** Manager edits the existing assignment model only.
- **Token rotation / edit-secret / label-expiry edit — DEFERRED (D2).** Lifecycle
  is exactly create/revoke/delete.
- **FeatureGate key:** `token_capability_manager`.
- Hard invariants held all five phases: 23 caps / 34 ops / 40 MCP tools / DB 2.4.0;
  no new storage; `OperationExecutor`/`CapabilityRegistry`/`AuthTokens`/
  `CapabilityManager`/`AuditLog`/`McpServerRuntime` byte-unchanged.

## C. Repository State (current)
- Branch `main`; **local HEAD == origin/main == `v0.107.0`** (0 ahead /
  0 behind); **working tree clean.** Production server HEAD == `v0.107.0`
  (tag `v0.107.0`).
- Tags: `v0.104.0`, `v0.105.0/1/2`, `v0.106.0`, **`v0.107.0` (→
  `v0.107.0`, the current deployed STEP 107 release)**.
- `wpcc-env.sh` exists locally but is git-ignored (local full-scope dev token).

## D. Next-Chat Starting Point — STEP 108 (Operations Explorer)
- **Current state:** STEP 107 complete + released (107.1–107.5); production =
  `v0.107.0` (`v0.107.0`); origin == local == `v0.107.0`; tree
  clean. STEP 104/105/106/107 backends + UI all live. Security mode on prod =
  developer. **DB_VERSION on prod = 2.4.0.**
- **STEP 108 = Operations Explorer** — the next admin surface (Phase A). Plan it
  report-first like 105/106/107: a wp-admin browser over `OperationRegistry`
  (operations, parameters, risk, required capability, availability) + the live
  invariants. Reuse-only, read-first, FeatureGate-seamed, capability-scoped.
- **STEP 109 = Dashboard.** **Phase B: STEP 110 = Platform Hardening &
  Certification.**
- **Discipline (unchanged):** every new surface stays capability-scoped,
  approval-aware, reversible; report-first → phased build → `--changed` T0/T1
  net-new 0 → pristine serial T2 → deploy on explicit direction. "net-new" vs
  `tests/regression-baseline.tsv` is the signal. Do NOT push/deploy without
  explicit direction (pull-cron: `git push origin main` = live ~1 min).
