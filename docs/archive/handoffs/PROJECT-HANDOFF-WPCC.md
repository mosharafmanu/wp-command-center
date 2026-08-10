# PROJECT HANDOFF — WP Command Center (WPCC)

**Written:** 2026-06-15. **Purpose:** let a fresh Claude Code session resume immediately with zero prior chat history. Read this top-to-bottom, then jump to **START HERE** at the bottom.

---

## 1. Current Status

| Item | Value |
|---|---|
| **Plugin** | WP Command Center, version **0.1.0** |
| **Product** | WordPress management platform for AI agents — REST API + MCP server + token auth + approval/audit/rollback. Patch-centric: rollback is the core safety guarantee. |
| **Repo** | `github.com/mosharafmanu/wp-command-center` |
| **Local working dir** | `/Applications/AMPPS/www/ClientProjects/WordPress/2026/plugins-dev/wp-content/plugins/wp-command-center` (AMPPS, macOS) |
| **Current git branch** | `main` |
| **Latest commit (= origin/main)** | **`a819f4f`** — `fix(rollback): standardize rollback contract across runtimes` |
| **In sync with origin** | Yes — `main` == `origin/main` == `a819f4f` (pushed 2026-06-15) |
| **Production site** | `https://mosharafmanu.com` |
| **Production deployed commit** | Expected `a819f4f` via pull-cron, but **NOT yet functionally verified on prod** (see §6). Prior confirmed-live baseline was `5d0efaa` (STEP 100). |
| **Deployment method** | **Pull-based.** Hostinger blocks inbound SSH from GitHub runners, so a server cron (`* * * * *`) runs `~/wpcc-deploy.sh` → `git fetch` → if origin advanced `git reset --hard origin/main` → reactivate plugin + `wp cache flush`. **`git push origin main` = live in ~1 min.** `.github/workflows/deploy.yml` is a green no-op notice. Runbook: `.ai/DEPLOY.md`. Server script `/home/u916998506/wpcc-deploy.sh`, log `~/wpcc-deploy.log`. |
| **Security mode (dev & prod)** | `developer` (writes execute directly; no approval gating). `client`/`enterprise` modes gate by risk and require a human approver. |

### MCP Architecture (how the system is shaped)
- **Tools = operations, 1:1.** `McpServerRuntime::tools_list()` iterates `OperationRegistry::get_operations()`; each operation `id` is exactly one MCP tool. **39 tools** total. There are **no** MCP tools outside the operation registry.
- **7 MCP resources:** `wpcc://` manifest, context, capabilities, operations, queue, results, recommendations.
- **Multi-action operations** expose sub-actions via an `action` param, each with its own risk tier in `action_risks`. ~264 distinct sub-actions across the 39 tools.
- **Execution path (all writes):** MCP `tools/call` (or REST `/operations/<id>/run`) → `OperationExecutor::run()` → capability check → DestructiveGuard (confirmation handshake for permanent deletes) → SecurityMode approval gate → handler (`*RuntimeManager`/`*Manager`) → normalize → audit + result record. Errors are structured `{isError, code, message}` (STEP 89 contract).
- **Transport:** REST namespace `wp-command-center/v1`; MCP at `POST {base}/mcp` (JSON-RPC 2.0, protocol `2024-11-05`), bearer token auth (token-only, no cookies for agents).
- **Key files:** `includes/Operations/OperationRegistry.php` (the 39-op catalog), `includes/Operations/OperationExecutor.php` (dispatch + rollback dispatcher + STEP-102 rollback surfacing), `includes/Mcp/McpServerRuntime.php`, `includes/AiAgent/RestApi.php` (REST routes incl. per-runtime `/rollback`), `includes/Operations/*RuntimeManager.php` (per-runtime handlers).

---

## 2. Completed Validation History (STEP 100 → 102.6)

### STEP 100 — Media Enhancement (COMPLETE, DEPLOYED)
- **Goal:** add a media-enhancement runtime (`media_enhance`) — responsive/thumbnail/WebP/optimization audits + reversible writes + guarded cleanup.
- **Outcome:** phases 100.1–100.9 done (`d30844f`→`da3fcc9` + `279f6e9`). Reversible thumbnail regen, WebP sidecars, image optimization (dims preserved), cross-runtime usage analysis, and `unused_media_cleanup` (re-verify → DestructiveGuard `CLEANUP_MEDIA` → snapshot → **trash, never permanent** → verify; fully reversible). 39 ops / 39 MCP tools; `operation_map`=33.
- **Real-world validated** on a live Woo+ACF+Elementor stack: 41/41 classification, 0 false positives, byte-for-byte reversibility. **Verdict: safe for staging; NOT yet safe for unattended prod cleanup** (6 reversible blind spots gated behind human review / `wpcc_media_cleanup_protected`).
- Deployed to prod 2026-06-15 (was at `5d0efaa`).

### STEP 101.1 — Runtime Discovery (PASS)
- **Goal:** discover & document every runtime/capability via actual MCP, not docs.
- **Findings:** 39 MCP tools (live `tools/list` == registry), 7 resources, **29 runtimes**, **0 unclassified**. Documented read/write/approval/dangerous actions + missing metadata per runtime.
- **Outcome:** inventory + matrix produced. No code change.

### STEP 101.2 — Read-Only Validation (PASS WITH OBSERVATIONS)
- **Goal:** every runtime accessible, returns valid data, handles errors, performs acceptably — read-only.
- **Findings:** 29/29 accessible; 73 read calls, 0 fail; STEP 89 error contract holds (17/17 invalid inputs → structured errors). Observations: thin parameter schemas on `settings_manage`/`woocommerce_manage`/`menu_manage`/`user_manage` (params accepted but not declared — discoverability gap, not a bug); `media_search` uses undeclared `search` key; `wp_cli_bridge` structured command registry not enumerated; `media_enhance/media_usage_report` slow (~25s, library aggregate); no Elementor-built page on dev (can't positively exercise Elementor).
- **Outcome:** no code change; observations logged.

### STEP 101.3 — Reversible Write Validation (PASS WITH RISKS)
- **Goal:** prove create→verify→audit→rollback→verify across write-capable runtimes; approval/audit/timeline.
- **Findings:** writes worked everywhere, but **rollback was driveable on only 6/12** runtimes. Approval pipeline 9/9 (incl. gating/duplicate/invalid negatives); audit + timeline intact. Three findings:
  - **F-1 (HIGH):** Content `content_rollback` action blocked by allow-list → `rollback_id` unconsumable.
  - **F-2 (MED-HIGH):** Menu `rollback_id` never surfaced (12/13 write paths) + `menu_update` had no reversal arm.
  - **F-3 (MED, systemic):** `rollback_id` inconsistently surfaced (ACF/User/Woo/Settings stored a rollback but never returned the id; no discovery list).
- **Outcome:** motivated STEP 102. (Proven-working rollback in 101.3: Option, SEO, Media, Snapshot, Patch, Workflow.)

### STEP 102 — Rollback Remediation (FULLY REMEDIATED)
- **Goal:** make every runtime that stores a rollback expose a consistent, discoverable, executable rollback contract.
- **Fixes:** see §3. Verified 6/6 affected runtimes (Content, Menu, ACF, User, WooCommerce, Settings) round-trip create→verify→rollback→restore with `rollback_id`+`rollback_available`. Reads carry neither field. Regression on Option intact.
- **Outcome:** runtime rollback coverage 6/12 → 12/12 (+ shared mechanism covers the rest).

### STEP 102.5 — Regression Validation (PASS WITH RISKS → then fully resolved in 102.6)
- **Goal:** confirm STEP 102 introduced no regression; verify contract across write runtimes.
- **Findings:** 13/14 PASS, no regression. **NEW F-4 (MEDIUM, pre-existing):** ACF group rollback executed but did **not** restore — `group_update` stored `before_state = summarize_group()` (lossy: `location`→int count, no post ID), so `rollback()`'s `acf_update_field_group($before)` couldn't restore. STEP 102's ACF PASS had been a false positive (corrupted/empty read). Also confirmed shared surfacing propagates to Forms/SiteBuilder/CPT (end-to-end PASS).
- **Outcome:** motivated STEP 102.6.

### STEP 102.6 — ACF Rollback Restoration Fix (PASS)
- **Goal:** smallest correct fix for F-4.
- **Fix:** `ACFRuntimeManager::group_update()` now stores `$before = $g` (the full original group) instead of the lossy summary; the existing `rollback()` arm restores exactly.
- **Outcome:** verified 3/3 deterministic — title, location rules (OR-groups 1→2→1), and field definitions all restored; no corruption; rollback_id/available present; audit+timeline intact. Regression matrix → **14/14**. No remaining rollback issues.

### Commit & DEV smoke (post-102.6)
- Committed `a819f4f`. **T1 `--changed` final: 586 passed / 0 failed / 0 net-new.** DEV smoke 6/6 (discovery 39, read, write→rollback, approval gating, audit, timeline).
- During commit smoke, found & cleaned **7 corrupt/stray ACF groups** (`WPCC V102/R1025/det`) left in the **DEV** DB by the *pre-fix* buggy rollback (it saved `location` as int → PHP 8 fatal in `acf_get_field_groups()` → `acf_group_list` 500). Removed via guarded `wp_delete_post`. **Production never ran that buggy path.**

---

## 3. Rollback Remediation Summary

### Root causes found
- **F-1 Content:** `content_rollback` dispatched in `ContentManager::run()` + declared in `OperationRegistry` action_risks, but missing from `ContentRegistry::ACTIONS` → the run() allow-list rejected it (`wpcc_invalid_content_action`). Dead code path.
- **F-2 Menu:** `store_rollback()` generated a UUID but write responses omitted it; no `menu_rollback_list`; `menu_update` had no arm in `rollback()`.
- **F-3 systemic:** write methods discarded the generated `rollback_id`; no per-runtime discovery list. **User sub-bug:** `UserManager` used SHORT action names (`update`) but `UserRegistry::supports_rollback()` is keyed by LONG constants (`user_update`), so the gate always returned false → **User never stored any rollback at all.**
- **F-4 ACF (found in 102.5):** `group_update` stored a lossy `summarize_group()` as before-state → `rollback()` couldn't restore.

### Files changed (commit `a819f4f`)
| File | Change |
|---|---|
| `includes/Operations/RollbackContext.php` | **NEW** shared collector (see below) |
| `includes/Operations/OperationExecutor.php` | `RollbackContext::boot()/reset()` before handler dispatch; inject `rollback_id` + `rollback_available` in `normalize_success()` |
| `includes/Operations/ContentRegistry.php` | add `content_rollback` to `ACTIONS` (F-1) |
| `includes/Operations/MenuRuntimeManager.php` | add `menu_update` arm to `rollback()` (F-2) |
| `includes/Operations/UserManager.php` | normalize action key in `store_rollback()` support gate (F-3 user) |
| `includes/Operations/ACFRuntimeManager.php` | `group_update` stores full group as before-state (F-4) |
| `tests/test-content-runtime.sh` | update supported-action count 10→11 (+`content_rollback`) |

### Shared abstraction introduced
**`WPCommandCenter\Operations\RollbackContext`** — every runtime persists rollbacks to an option named `wpcc_<runtime>_rollbacks` (uniform across 18 managers). RollbackContext hooks `updated_option`/`added_option` once, and on any `wpcc_*_rollbacks` write it diffs old-vs-new to capture the newly stored id (handles both list `['id'=>…]` and assoc-keyed-by-id shapes, e.g. Content). `OperationExecutor` resets it per run and injects `rollback_id`+`rollback_available` at the single `normalize_success()` chokepoint. → fixes the whole F-3 surfacing class with **zero per-manager edits**, idempotent for managers that already return the id, and never pollutes a rollback (marking applied adds no new id).

### Final rollback architecture (the contract every reversible write now meets)
```
Write op → store_rollback() persists to wpcc_<runtime>_rollbacks
        → RollbackContext captures the new id (shared hook)
        → response carries rollback_id + rollback_available:true  (uniform)
        → executable: action (content_rollback/option_rollback/seo_restore/snapshot_restore)
                      OR REST POST /operations/<runtime>/rollback {rollback_id}
                      OR rollback_manage (patches) / workflow_rollback (workflows)
        → auditable: OperationExecutor records operation.* events + result record
```
Per-runtime execution paths: **action-based** (Content, Option, SEO, Snapshot), **REST `/rollback` route** (Media, Menu, ACF, User, WooCommerce, Settings, SiteBuilder, Elementor, CPT, Widgets, Forms, Comments), **dedicated engines** (Patch→`rollback_manage`, Workflow→`workflow_rollback` via the unified `OperationExecutor::rollback` dispatcher for managers with a public `rollback()`).

---

## 4. Current Project Health

### Known issues
- **None blocking** in the rollback subsystem. 14/14 validated runtimes round-trip correctly.

### Deferred / accepted observations (not bugs)
- **Thin parameter schemas** (`settings_manage`, `woocommerce_manage`, `menu_manage`, `user_manage`; `media_search`'s `search` key): params work but aren't declared in tool metadata — agent discoverability gap. Deferred.
- **WP-CLI structured `command_id` registry** not enumerated in `wp_cli_bridge` metadata. Deferred.
- **`media_enhance/media_usage_report`** is slow (~25s, full-library aggregate); per-item `media_usage_scan` is ~1s. Consider a paged/limited variant. Deferred.
- **Create operations don't emit `rollback_id`** by design (reversed by delete). Accepted, not a bug.
- **Rollback shared-cover runtimes not individually re-verified end-to-end:** Comments, Elementor, Widgets (Bulk partially). They inherit the shared surfacing + have rollback paths, but lacked clean DEV fixtures to exercise. Low risk; verify opportunistically.
- **`unused_media_cleanup`** safe for staging, **not** for unattended production cleanup (6 reversible blind spots gated behind human review / `wpcc_media_cleanup_protected`).

### Technical debt
- **Secrets in committed docs:** `.ai/audits/*.md` contain production API tokens (now revoked, but still present). Should be scrubbed.
- **`summarize_*()` lossy-before-state pattern:** F-4 was one instance (ACF group). Audit other managers' `store_rollback` before-states for similar lossiness if more rollback bugs surface (ACF group is fixed; field_update/value_update were not re-audited this round).
- **Tests not concurrency-safe:** `tests/run.sh --tier T2` MUST be run serially (a `-j6` T2 produced 168 false net-new failures — suites share one WP/DB). Use T0/T1/`--changed`; T2 serial before deploy.
- **Chronic test baseline:** ~24 pre-existing failures (ai-client-layer, ai-integration-ux, claude-integration, cursor-certification, documentation-consistency, security-redaction). Tracked in `tests/regression-baseline.tsv`; net-new is the signal, not absolute count.

### Explicit status flags (as requested)
- **F2.1:** DEFERRED — "requires production reproduction." It was an acceptance-report finding with no reproducible local/dev repro; never closed, never re-opened in STEP 101/102. Still open-but-deferred.
- **Production validation status:** **INCOMPLETE.** Push + GitHub deploy workflow + endpoint health (`/health`=401, live) confirmed for `a819f4f`, but the 6 functional checks (commit-hash behavior, tool discovery, read, write→rollback, approval/audit/timeline) were **NOT run** — every production token on disk is revoked (`wpcc_invalid_token`). A ready-to-run validator exists: `artifacts/step-102-rollback-remediation/prod-validate.py` (needs `WPCC_PROD_TOKEN`).
- **Deployment status:** `a819f4f` is **pushed to origin/main and deploy-triggered** (pull-cron + green GitHub workflow #32). Treated as **deployed-but-unverified** on production until `prod-validate.py` runs green.

---

## 5. Validation Evidence (reports & artifacts)

**STEP 101 — `artifacts/step-101-runtime-validation/`**
- `STEP-101.1-RUNTIME-DISCOVERY.md`, `runtime-inventory.json`, `unclassified-tools.md`, `next-validation-plan.md`, `raw-operations.json`
- `STEP-101.2-READ-ONLY-VALIDATION.md`, `runtime-validation-matrix.json`, `observed-findings.md`, `performance-observations.md`
- `STEP-101.3-REVERSIBLE-WRITE-VALIDATION.md`, `write-validation-matrix.json`, `rollback-validation-report.md`, `approval-validation-report.md`, `observed-issues.md`, `evidence.json`

**STEP 102 — `artifacts/step-102-rollback-remediation/`**
- `STEP-102-ROLLBACK-REMEDIATION.md`, `rollback-contract-matrix.md`, `remediation-summary.md`, `targeted-verification-results.json`
- `STEP-102.5-REGRESSION-VALIDATION.md`, `regression-results-102.5.json`
- `STEP-102.6-ACF-ROLLBACK-FIX.md`, `acf-rollback-verification.json`
- `STEP-102-COMMIT-AND-SMOKE.md`, `smoke-results.json` *(uncommitted working artifacts)*
- `STEP-103-PRODUCTION-DEPLOYMENT-VALIDATION.md`, `prod-validate.py` *(uncommitted; validator has no embedded secrets)*

**Reference docs:** `.ai/DEPLOY.md` (deploy runbook), `.ai/handoffs/resume.md` (prior session state), `.ai/steps/` (per-step design docs), `WPCC-RUNTIME-ROADMAP.md` (STEP 89→98 roadmap, untracked).

**Uncommitted at handoff time** (intentionally not in `a819f4f`):
- Pre-existing/unrelated: `.claude/scheduled_tasks.lock` (deleted), `artifacts/step-36-validation/validation-evidence.json` (modified), `WPCC-RUNTIME-ROADMAP.md` (untracked).
- Post-commit deliverables: `STEP-102-COMMIT-AND-SMOKE.md`, `smoke-results.json`, `STEP-103-PRODUCTION-DEPLOYMENT-VALIDATION.md`, `prod-validate.py`, and this `PROJECT-HANDOFF-WPCC.md`.

---

## 6. Open Tasks

### Immediate
1. **Complete production validation of `a819f4f`.** Run `WPCC_PROD_TOKEN='<current full token>' python3 artifacts/step-102-rollback-remediation/prod-validate.py` (or generate a fresh token in WP Admin → WP Command Center → tokens). Confirm: tools/list=39, read OK, write→rollback restores, `rollback_available` present (= commit live), approval gated, audit + timeline OK. Then record the final DEPLOYMENT SUCCESSFUL/FAILED verdict in `STEP-103-PRODUCTION-DEPLOYMENT-VALIDATION.md`.

### Near Term
2. **Scrub production tokens** from `.ai/audits/*.md` (revoked but committed).
3. **Commit the post-commit artifacts** (`STEP-102-COMMIT-AND-SMOKE.md`, `STEP-103-…md`, `prod-validate.py`, `smoke-results.json`, `PROJECT-HANDOFF-WPCC.md`) once prod validation is recorded — and decide on the 3 pre-existing unrelated changes.
4. **Verify shared-cover rollback runtimes end-to-end** (Comments, Elementor, Widgets) once fixtures exist (esp. create an Elementor-built page fixture on dev).

### Future
5. **Enrich thin parameter schemas** (settings/woo/menu/user; declare `search`/per-action params) and enumerate the WP-CLI `command_id` registry in tool metadata.
6. **Audit remaining `store_rollback` before-states for lossiness** (ACF field_update/value_update; any other `summarize_*`-as-before-state).
7. **Page/limit `media_usage_report`** (or move to background) to fix the ~25s aggregate.
8. **Re-evaluate F2.1** if a production reproduction becomes available.

### Nice To Have
9. Add a unified `rollback_list` (or extend `rollback_manage`) so an agent can discover rollback ids after the fact, not just from the write response.
10. Per-worker isolated WP/DB so `tests/run.sh` T2 can run in parallel safely.
11. Make `media_enhance` `unused_media_cleanup` safe for unattended prod (close the 6 reversible blind spots).
12. Licensing / Free–Pro gating (long-standing top product gap; currently unscheduled).

---

## 7. Recommended Next Step

**Run the production validation of `a819f4f` (Open Task #1).** Everything else is green and committed; the only unverified link in the chain is whether the rollback-contract fix is functioning on `mosharafmanu.com`. It needs a current production token, then a single command (`prod-validate.py`). Until that runs green, treat production as deployed-but-unverified.

---

# START HERE

Paste the following into a brand-new Claude Code session (assumes no prior context):

```
You are resuming work on WP Command Center (WPCC) — a WordPress management plugin that exposes
39 operations to AI agents over REST + an MCP server (JSON-RPC), with token auth, approval gating,
audit log, timeline, and a rollback safety guarantee. Working dir:
/Applications/AMPPS/www/ClientProjects/WordPress/2026/plugins-dev/wp-content/plugins/wp-command-center
(local AMPPS/macOS). Production: https://mosharafmanu.com. Git branch: main, in sync with origin
at commit a819f4f ("fix(rollback): standardize rollback contract across runtimes").

FIRST: read PROJECT-HANDOFF-WPCC.md in the plugin root — it has full status, validation history
(STEP 100→102.6), the rollback architecture, known/deferred issues, evidence locations, and open tasks.

CONTEXT YOU NEED:
- Deploy is PULL-BASED: `git push origin main` triggers a server cron that goes live in ~1 min.
  Do NOT push unless explicitly told to deploy. Runbook: .ai/DEPLOY.md.
- Tests: use tests/run.sh with --tier T0|T1 and --changed (T2 must be run SERIALLY, never -j). The
  "net-new" count vs tests/regression-baseline.tsv is the pass signal, not the absolute number.
- Rollback contract (STEP 102): every write that stores a rollback returns rollback_id +
  rollback_available; shared via includes/Operations/RollbackContext.php + OperationExecutor
  normalize_success injection. 14/14 runtimes verified.
- Local dev env: source ./wpcc-env.sh for $WPCC_BASE/$WPCC_TOKEN (localhost). Production env files
  are NOT on disk; the tokens in .ai/audits/*.md are REVOKED.

IMMEDIATE TASK: complete the production validation of commit a819f4f (STEP 103). The endpoint is live
(/health=401) and the GitHub deploy workflow was green, but the functional checks were never run
because no valid production token was available. Ask the user for a current full-scope production
token (or have them generate one in WP Admin → WP Command Center → tokens), then run:
   WPCC_PROD_TOKEN='<token>' python3 artifacts/step-102-rollback-remediation/prod-validate.py
It runs 6 read-mostly checks against mosharafmanu.com with ONE self-reversing write
(posts_per_page +1 → option_rollback → restore) and prints a verdict. Record the result in
artifacts/step-102-rollback-remediation/STEP-103-PRODUCTION-DEPLOYMENT-VALIDATION.md and give the
final DEPLOYMENT SUCCESSFUL / FAILED verdict.

Then review Open Tasks §6 in the handoff (scrub committed tokens, commit post-commit artifacts,
verify shared-cover rollback runtimes) and ask the user which to pursue. Confirm before any
production write or any git push. Do not start new feature work without direction.
```
