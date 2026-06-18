# PROJECT HANDOFF — STEP 108

**Written:** 2026-06-18. Supersedes `HANDOFF-STEP-107.md` for current state.
STEP 107 (Token & Capability Manager) remains live at `v0.107.0`. **STEP 108 —
Operations Explorer (108.1–108.3) is COMPLETE, RELEASED, and PRODUCTION-VERIFIED.**

- **Production runs tag `v0.108.0`; origin/main == local HEAD == the v0.108.0
  milestone commit; working tree clean.** The exact deployed commit hash is
  recorded in the STEP 108 Deployment Verification Report. See **§A** for the
  release & production-verification proofs.

---

## A. STEP 108 — Operations Explorer: Release & Production Verification (deployed `v0.108.0`)

**Current production release.** A dedicated, READ-ONLY wp-admin "Operations
Explorer" — a browser over the operation catalogue (`OperationRegistry`, STEP
15/80) joined with the authorization map (`CapabilityRegistry`, STEP 38/44/79)
and the live security posture (`SecurityModeManager`, STEP 80). Reuse-only,
report-first, discovery-only across three phases. **No new capability, operation,
MCP tool, schema, or storage; no execution, no write routes, no engine/policy
change.** Every change lives in `includes/Admin/` + `tests/`.

- **108.1 — Read surface.** NEW `Admin/OperationExplorerAdminQuery` (read-only
  presentation aggregation: the 40-operation catalogue LEFT-joined to the 34-entry
  `OPERATION_MAP` for the required capability, with availability, read-only-scope
  eligibility, and the current security mode). 2 cookie+nonce `manage_options`
  reads (`/admin/operations`, `/admin/operations/summary`) gated by
  `FeatureGate('operations_explorer')`. NEW `views/operations-explorer.php` (header
  stat strip + filterable catalogue table). Catalogue is authoritative and broader
  than the map: the 6 unrestricted operations (e.g. `system_info`, seeds) are
  surfaced honestly with a null required capability.
- **108.2 — Operation detail.** `operation($id)` + `GET /admin/operations/{id}`
  (404 on unknown). The detail panel folds the full description, the parameters
  table (verbatim from the catalogue, incl. the auto-appended `reason` param on
  non-diagnostic ops), the per-action risk breakdown (each sub-action with its risk
  tier + per-action approval in the current mode), the authorization block
  (required capability or "Unrestricted", read-only-scope eligibility, the
  system.admin unlock), the approval requirement, and an honest availability
  explanation — no new route is registered beyond the single `/{id}`, no writes.
- **108.3 — Filters + a11y + i18n + states + FeatureGate + validation.** Text /
  risk / available-only filters wired to a `role=status` live count region;
  `scope="col"`/`scope="row"` table semantics, badge `aria-label`s, corrected
  heading hierarchy (page `h1` → op `h2` → section `h3`), labeled filter controls;
  i18n completeness (no raw JS strings); empty/error states (list-empty, load-fail,
  detail-404, no-params, no-actions); FeatureGate verified functionally
  (per-key gate via `wpcc_feature_allowed`); final invariant re-assertion.

- **Commit of record:** the single coherent STEP 108 milestone commit
  (108.1–108.3, incl. this handoff), tagged **`v0.108.0`** (annotated). **Pushed:**
  `a0ab014..v0.108.0  main -> main`; tag pushed to origin.
- **Deployed-commit proof (pull-cron):** after `git push origin main` the server
  cron (`~/wpcc-deploy.sh`, `* * * * *`) fast-forwards `origin/main` and reactivates
  the plugin; the commit is live within ~1 minute. Server HEAD == the v0.108.0
  milestone commit (exact hash in the Deployment Verification Report). Plugin
  active.
- **Invariants (unchanged from STEP 107):** operation_map **34**, capabilities
  **23**, catalogue **40**, MCP tools **40**, DB_VERSION **2.4.0**. STEP 108 is an
  admin-only read surface and adds no runtime op/MCP tool/capability/schema.
- **Functional verification (read-only):** the catalogue read returns 40
  operations (34 with a required capability matching `OPERATION_MAP`, 6
  unrestricted), the 5 read-only-scope operations are flagged, per-action risk is
  preserved (`plugin_delete` = critical), the injected `reason` param is exposed on
  non-diagnostic ops, the approval display mirrors `SecurityModeManager`, and
  availability mirrors `OperationRegistry::get_operations()` with zero drift. The
  surface never executes an operation.
- **Route/health (anonymous HTTP):** homepage 200; namespace index 200;
  `/admin/operations`, `/admin/operations/summary`, `/admin/operations/{id}` all
  **401** (live, auth-gated, not 404); admin page `wpcc-operations` 302 (login when
  unauthenticated); **no 500s.**
- **Test gates:** `test-operations-explorer.sh` **111/0**; `--changed` T0 lint
  clean, T1 **97/0** net-new 0; **pristine serial T2 4352/25, net-new 0** (111
  suites; the 25 are the chronic 24 baseline — ai-client-layer 1 / ai-integration-ux
  3 / claude-integration 4 / cursor-certification 2 / documentation-consistency 11 /
  security-redaction 3 — plus one transient cross-suite flake, `test-bulk-runtime.sh`,
  which passes 41/0 standalone; zero net-new attributable to STEP 108 code).
- **Anomaly:** none affecting the release. The T2 net-new of 1 was an environmental
  cross-suite state flake (`test-bulk-runtime.sh` — unrelated to the admin-only
  change), confirmed by a clean standalone run.

## B. Locked scope (carried from the approved STEP-108 proposal)
- **Execution — PERMANENTLY OUT OF SCOPE.** The Operations Explorer never runs an
  operation: no write routes, no execution controls, no `OperationExecutor` usage.
  It is discovery-only, mirroring the `report_manage`/`system_info` read posture.
- **FeatureGate key:** `operations_explorer`.
- Hard invariants held all three phases: 34 ops mapped / 23 caps / 40 catalogue /
  40 MCP tools / DB 2.4.0; no new storage; `OperationRegistry`/`CapabilityRegistry`/
  `SecurityModeManager`/`AuthTokens`/`AuditLog`/`McpServerRuntime` byte-unchanged.
- **Menu placement (locked):** Command Center → … → Approval Center → Tokens &
  Capabilities → **Operations Explorer** (the operation-side complement to the
  token-side capability map; registered last, FeatureGate-gated).

## C. Repository State (current)
- Branch `main`; **local HEAD == origin/main == `v0.108.0`** (0 ahead /
  0 behind); **working tree clean.** Production server HEAD == `v0.108.0`
  (tag `v0.108.0`).
- Tags: `v0.104.0`, `v0.105.0/1/2`, `v0.106.0`, `v0.107.0`, **`v0.108.0` (the
  current deployed STEP 108 release)**.
- `wpcc-env.sh` exists locally but is git-ignored (local full-scope dev token).
- STEP 108 files: NEW `includes/Admin/OperationExplorerAdminQuery.php`,
  `includes/Admin/views/operations-explorer.php`, `tests/test-operations-explorer.sh`;
  MODIFIED `includes/Admin/AdminRestApi.php`, `includes/Admin/AdminMenu.php`.

## D. Next-Chat Starting Point — STEP 109 (Dashboard)
- **Current state:** STEP 108 complete + released (108.1–108.3); production =
  `v0.108.0`; origin == local == `v0.108.0`; tree clean. STEP 104/105/106/107/108
  backends + UI all live. Security mode on prod = developer. **DB_VERSION on prod =
  2.4.0.**
- **STEP 109 = Dashboard** — the next admin surface (Phase A, the final read
  surface of the arc). Plan it report-first like 105/106/107/108: a wp-admin
  landing/overview that aggregates the existing surfaces (Change History / Approval
  Center / Tokens & Capabilities / Operations Explorer) into a single at-a-glance
  view + the live invariants. Reuse-only, read-first, FeatureGate-seamed,
  capability-scoped. No execution.
- **Phase B: STEP 110 = Platform Hardening & Certification.**
- **Discipline (unchanged):** every new surface stays capability-scoped,
  approval-aware, reversible (where it writes — 108 does not); report-first → phased
  build → `--changed` T0/T1 net-new 0 → pristine serial T2 → deploy on explicit
  direction. "net-new" vs `tests/regression-baseline.tsv` is the signal. Do NOT
  push/deploy without explicit direction (pull-cron: `git push origin main` = live
  ~1 min).
