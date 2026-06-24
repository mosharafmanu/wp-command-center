# WP Command Center — Session Handoff (Phase 3)

> **Purpose:** THE authoritative starting point for all future sessions as of 2026-06-23. Supersedes `SESSION-HANDOFF-2026-06-18.md` for current-state questions.
> **Type:** the original entry pass made no code/commit/push/deploy. **Reconciliation update (2026-06-23):** Phase 3 (F-1 SEO delta rollback) was subsequently committed (`7aa7e84`) and pull-deployed to production; this doc has been reconciled to that reality. The reconciliation pass itself made **no code changes and no push** — docs only.
> **Companion docs:** [`PRODUCT-MASTER-PLAN.md`](PRODUCT-MASTER-PLAN.md) · [`UX-AUDIT-AND-DESIGN-SYSTEM.md`](UX-AUDIT-AND-DESIGN-SYSTEM.md) · [`PHASE-B-P1-REMEDIATION-PLAN.md`](PHASE-B-P1-REMEDIATION-PLAN.md) · prior handoff `SESSION-HANDOFF-2026-06-18.md`.

---

## 0. PROGRAM-4 DEPLOYED & VERIFIED (2026-06-24) — supersedes §1 for current state

- **Production HEAD = `2657810`** on `origin/main`, local `main`, and the live server (`mosharafmanu.com`). Pull-deploy log: `DEPLOYED a41a9d7 -> 2657810 active=yes`.
- **Program-4 Rollback Integrity DEPLOYED & VERIFIED.** P4.0–P4.10 consolidated + certified: GATE-1 serial T2 **net-new attributable failures = 0**, GATE-1A stale-tests fixed, independent audit **GO**, and **production token-gated functional validation passed** on every dependency-present certified surface.
- **Certified surfaces** (field-scoped / atomic, drift-aware, sibling-safe, honest partial/conflict, legacy-compatible): SEO, Settings, Media metadata, Content, Comments, Users, **Woo Products**, Bulk, **ACF value_update**, Elementor — plus **Pattern-C** (byte snapshot+verify): Patch/File, Media bytes, Media Enhancement.
- **Production functional results (live, via certified rollback-delta suites):** core 25/0 · PostMetaRollbackStore 30/0 · SEO 56/0 · Settings 38/0 · Media 41/0 · Content 30/0 · Comments 27/0 · Users 28/0 · ACF 47/0 · Bulk 53/0.
- **Dormant-safe on prod:** WooCommerce and Elementor plugins are **inactive on production**, so Woo Products + Elementor certified code is **deployed but dormant** — the runtimes guard with `class_exists`/`defined` and no-op safely (no fatal, no effect) until those plugins are activated.
- **Posture UNCHANGED:** security mode **`developer`**, Anthropic key **UNSET**, `WPCC_SEO_META_UI` / `WPCC_AI_CONTENT_UI` / `WPCC_ALT_TEXT_UI` all **OFF**.
- **Invariants (verified live):** OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0**.
- **Acceptance gate CLOSED:** serial T2 net-new attributable 0; prod token-gated functional validation green; honesty verified (`plugin_update`/`theme_update` return `reversible:false`; `content.update` audit `old_status` non-null with no undefined-`$before` warning — D2 fixed live).
- **Remaining honest boundaries (NOT certified — unchanged from prior production, not regressions):** ACF **definition** ops (whole-def + fingerprint drift-guard, not field-scoped); Woo **orders / variation_update / coupon_update** (no rollback); CPT, Forms, Menu, Widgets, SiteBuilder, OptionManager (legacy, not drift-aware); plugin/theme **update** (now honestly `reversible:false`); non-field reversals. Residual reliability (non-blocking): option-tier **FIFO rollback-id eviction** (Settings/Media/Comments/Users + shared ACF-definition) — drift-correct, same storage class as pre-Program-4.
- **Recommended next phase: NOT STARTED.** No new program initiated. Optional, separately-scoped follow-ups (not begun): option-tier → `PostMetaRollbackStore` durability migration; A2-1 stale-`executing` reaper (schema-bearing); Woo orders rollback sub-design.
- Release commit: `2657810` (`release: PROGRAM-4 rollback integrity certification`, `--no-ff` merge of `program-4-certification @ 1e8d830`).

> The §1 facts below describe the **pre-Program-4 Phase-3 state** (HEAD `7aa7e84`) and are retained for history; §0 above is the authoritative current state.

---

## 1. Production state (pre-Program-4 Phase-3 — historical; see §0 for current)

- **Production HEAD = `7aa7e84`** on `origin/main` and the live server (`mosharafmanu.com`).
- Local `main` == `origin/main` == production (0 ahead / 0 behind); working tree clean (apart from in-flight doc edits).
- **Deploy model:** PULL-BASED. Server cron (every minute) runs `~/wpcc-deploy.sh` → `git fetch` → `git reset --hard origin/main` → reactivate plugin → `wp cache flush`. `git push origin main` ⇒ live ~1 min. Deploy log: `~/wpcc-deploy.log`. Server plugin dir: `~/domains/mosharafmanu.com/public_html/wp-content/plugins/wp-command-center`. Runbook: `.ai/DEPLOY.md`.
- **Deploy of record:** `a254a52 -> 7aa7e84 active=yes` (Phase 3 F-1 SEO delta rollback, pull-deployed; owner-confirmed live 2026-06-23).

### Commits live in production (latest first)
- `7aa7e84` — fix(governance): add field-scoped SEO rollback delta records (**F-1 / Phase 3**) — closes F-1 for the SEO runtime.
- `a254a52` — fix(governance): unify execution truth, atomic claim, exception-safe finalize (**B2-1, A-1, A2-1**) — Phase 2.
- `3cbb1d9` — fix(governance): prevent duplicate execution of approved requests (**B2-2**) — Phase 1.
- `d6a7447` — (prior baseline; CDS UI tokenization).

### Production verification (2026-06-23, reconciliation pass)
- **HEAD = `7aa7e84`** — confirmed via git (`origin/main`, 0 ahead / 0 behind) + owner confirmation + the pull-deploy invariant (server hard-resets to `origin/main`). *Note: HTTP cannot reveal a commit hash; HEAD identity rests on those, not an independent SSH `wp` probe this pass.*
- **Phase 3 code present in the deployed tree:** `SeoRuntimeManager` carries `rollback_format='delta'`, `restore_delta()`, drift detection, `capture_prior()`, format-v2 records (verified in working tree == `origin/main`).
- Invariants **34 / 23 / 40 / 40 / 2.5.0** — re-verified against live code (`CapabilityRegistry::OPERATION_MAP`=34, `ALL_CAPABILITIES`=23, `OperationRegistry` ops=40, mcp_tools=40 by construction, `Schema::DB_VERSION`=2.5.0).
- Anonymous HTTP smoke: homepage 200 · REST root 200 · `wp-command-center/v1` 200 · `/v1/health` 401 (token-gated) · `/v1/admin/approvals` 401 · **no 500s**.
- **OUTSTANDING (at the time of this Phase-3 pass; NOW CLOSED — see §0):** the full serial **T2 + acceptance gate** and the **prod token-gated functional verify** were a known residual for the SEO Phase-3 fix. These were **closed under Program-4** (GATE-1 serial T2 net-new attributable = 0; production functional validation green; SEO 56/0 live), which subsumes and extends the SEO delta rollback. Deployed **and** acceptance-gated as of 2026-06-24 (§0).

---

## 2. Invariants (must never regress)

| Invariant | Value |
|---|---|
| OPERATION_MAP | **34** |
| capabilities | **23** |
| catalogue (operations) | **40** |
| MCP tools | **40** |
| DB_VERSION | **2.5.0** |

**The Four Guarantees (P0):** Approval · Rollback · Audit · Capability Scoping. Status after Phase 1+2+3: Approval restored on all paths, Audit consistent, Capability Scoping intact; **Rollback's F-1 defect is now CLOSED for the SEO runtime** (`7aa7e84`, deployed) but **remains OPEN systemically** for the deferred sibling runtimes (Media/ACF/Woo/Settings — see §4). Do not market full "audited reversibility" until F-1 is closed everywhere **and** the SEO fix clears its acceptance gate (still outstanding — §1/§5).

---

## 3. AI / feature-flag state (dormant — DO NOT change without explicit direction)

- Anthropic key: **UNSET** on production.
- `WPCC_SEO_META_UI` (+ `wpcc_seo_meta_ui` filter): **OFF**.
- `WPCC_AI_CONTENT_UI` (+ `wpcc_ai_content_ui` filter): **OFF**.
- `WPCC_ALT_TEXT_UI` (+ `wpcc_alt_text_ui` filter): **OFF**.
- Security mode: **`developer`** (unchanged).
- Prod SEO provider: **Rank Math** (Yoast inactive).

All governed AI runtimes (SEO Meta · Alt Text · Title · Excerpt) are deployed but **dormant** behind these flags. Enablement is a product/config decision, not engineering — do not enable, set keys, or change security mode without explicit direction.

---

## 4. Governance remediation status

### CLOSED + DEPLOYED (Phase 1+2, live since `a254a52`)
- **B2-2 — execute-once invariant (`3cbb1d9`).** Duplicate execution of an approved request (synchronous vs. queued paths) is prevented; Phase 2's atomic claim is now the primary mechanism, Phase 1's queue-ledger guard a secondary layer.
- **B2-1 — execution-truth unification (`a254a52`).** Every approved request finalizes its `request.status` on all paths; `ProposalSync` resolves proposals from the durable `operation_results` envelope (independent of `request.status`). Worker/MCP-approved proposals now reach `applied` (previously stuck `pending_approval`).
- **A-1 — atomic execution claim (`a254a52`).** Single-winner CAS (`approved|failed → executing`) at the `OperationExecutor::run` chokepoint; losers return a structured no-op.
- **A2-1 — catchable-exception hardening (`a254a52`).** `$handler->run()` wrapped in `try/catch(\Throwable)`; a thrown exception/Error finalizes the request as `failed` (re-claimable) + records a durable result + `operation.execution.exception` audit, instead of stranding it in `executing`.

### CLOSED FOR SEO + DEPLOYED (Phase 3, live at `7aa7e84`)
- **F-1 — rollback snapshot over-reach (HIGH) → CLOSED for the SEO runtime (`7aa7e84`).** SEO rollback now stores/restores a **versioned field-scoped delta** (format v2) instead of a full-object snapshot: each touched field carries prior value + prior-existed flag + apply-time after-value + provider + content identity. Restore writes back **only** touched fields, preserves untouched siblings, detects drift (live vs apply-time after-value) and **skips** drifted fields rather than clobbering, preserves existed-vs-empty fidelity, reports restored/skipped/conflict truthfully, and keeps legacy full-snapshot records compatible. Eliminates sibling-field loss, same-field clobber, and out-of-order resurrection **for SEO**. **No schema / DB_VERSION / op / cap / tool change.**
  - **Acceptance gate NOT yet run** — full serial T2 + Stage-A S3B/S4/S5 + B3/B4 + prod token-gated functional verify remain outstanding (§5). Deployed, prod-smoke-clean, not acceptance-gated.

### OPEN (remaining governance debt)
- **F-1 systemic (HIGH) — sibling runtimes still over-reach.** The full-snapshot over-reach pattern remains in **Media (`SnapshotManager`), ACF, Woo, and Settings/Options**. F-1 is a *pattern*; only the SEO instance is fixed. Extending the field-scoped delta approach to these runtimes is the next F-1 work.
- **A2-1 residual — uncatchable-fatal reaper (Phase 2.x).** OOM / `max_execution_time` / process-kill can still strand a request in `executing` (uncatchable by `try/catch`). Fix requires a **stale-`executing` reaper** distinguishing a dead process from a slow valid handler → needs a **claim-timestamp (`claimed_at`) column = schema migration + DB_VERSION bump**. **Still deferred** (a column-free reaper would risk stealing a legitimately in-flight execution and re-opening A-1).
- **A2-2 (REDUCED, not eliminated).** A handler that mutates then throws now records the failure (audit + failed result + failed change row), but a partial mutation before the throw may lack a clean rollback handle. Narrow, handler-dependent; minimal for near-atomic handlers.
- **A2-3 (intended consequence).** A proposal marked `failed` cannot be rescued by a later successful retry (terminal-state freeze). Document for operators; queue/request-level retry is preserved.

Detailed root-cause + design for these live in project memory (`project_b2_approval_findings`, `project_b4_drift_f1_escalation`) and the Governance Remediation Architecture Review / Design produced in the Phase-2 session.

---

## 5. Phase 3 — F-1 delta-snapshot rollback (DEPLOYED for SEO; acceptance gate + systemic rollout OUTSTANDING)

**Done (`7aa7e84`, deployed):** SEO rollback reversibility unit changed from full-object snapshot to **operation-touched-field delta** (format v2), restoring commutative/idempotent, history-accurate rollback for the SEO runtime. Implemented as designed: delta capture with existed flag + apply-time after-value, field-scoped restore, drift detection (skip + report on drift). Affected files: `SeoRuntimeManager` (store_rollback / seo_update / seo_restore via `capture_prior` / `restore_delta`), `SeoProvider` (field-scoped write honoring existed-vs-empty). Legacy full-snapshot records remain compatible.

**Still outstanding (the real "next" work):**
1. **Acceptance gate for the live SEO fix (do this first).** Re-run Stage A S3B/S4/S5 + B3/B4 (fidelity preserved, drift handled), targeted regression, and **serial T2** (net-new attributable = 0), then a **prod token-gated functional verify** of SEO delta rollback. The fix is live but has not cleared this gate — that is the highest-priority follow-up.
2. **Systemic F-1 rollout.** Extend the field-scoped delta pattern to the deferred sibling runtimes that still over-reach: Media (`SnapshotManager`) → ACF → Woo → Settings/Options. F-1 remains OPEN until these land.
3. **A2-1 reaper (schema-bearing)** can be scheduled independently of F-1.

> Reconciliation note (2026-06-23): this section previously framed Phase 3 as the *next, not-yet-started, design-first* initiative. It has since been implemented, committed (`7aa7e84`), and pull-deployed. The remaining gap is **verification + systemic rollout**, not design.

---

## 6. Operational notes (learned this campaign)

- **Stage A backup is the WRONG baseline.** `/Users/mosharafmanu/wpcc-stageA-backup-20260623-003022.sql` is an **earlier Yoast-active, no-seed-posts** state (predates the Rank Math + seed-fixture setup). Restoring it yields `detect()=yoast` and removes seeds 29494–29499. **Do not** use it for a clean-DB SEO/Rank Math baseline. Capture a correct snapshot (Rank Math active + seeds at intended meta + no campaign pollution) before any future clean-DB validation.
- **Dev DB-CLI:** `wp db export/import` fails auth in local AMPPS. Use AMPPS `mysql`/`mysqldump` with socket `/Applications/AMPPS/apps/mysql/var/mysql.sock` (the `/tmp/mysql.sock` is a stale instance).
- **Seed fixtures 29494–29499 are polluted** from the validation campaign (several carry applied-but-not-rolled-back SEO meta). The SEO/alt-text test suites **self-seed** (no dependency on these IDs), so their T2 failures were accumulated-DB-pollution (they carry `baseline 0` = green on clean DB), not code regressions.
- **`test-change-history-rollback`** flakes only when run back-to-back (heavy `change_log` backfill over ~74k rows); **48/0 standalone** — non-attributable.
- **Local safety backup retained:** `/tmp/wpcc-pre-restore-safety.sql` (current-DB state). Safe to delete once satisfied.

---

## 7. Test / regression posture

- **Tiered runner:** `tests/run.sh --tier T0|T1|T2 [--changed] [-j N]` (default `-j 1` serial). Baseline of known failures: `tests/regression-baseline.tsv` (~24).
- **Phase 1+2 final serial T2:** 5575 passed / 37 failed / **net-new attributable = 0** (the 13 raw net-new were all seed-fixture pollution, the rollback flake, or the admin-ux UI badge — none touching the changed execution-lifecycle files).
- Execution-lifecycle suites (operation-requests/retry/worker, approval-enforcement, mcp-approval-runtime, proposal-store/rest/admin, approval-center, security-modes, workflow-runtime/step97, change-history-runtime, operations-registry, capability-runtime) — **all green** with the deployed code.
- Do **not** refresh the regression baseline as part of Phase 3 unless explicitly directed.

---

## 8. Next-session GO / NO-GO

**Phase 3 (F-1 SEO delta rollback) is DEPLOYED (`7aa7e84`), not yet acceptance-gated.** The execution lifecycle (B2-2/B2-1/A-1/A2-1) and the SEO delta rollback are live and prod-smoke-clean. The two open fronts, in priority order:

1. **GO to verify the live SEO fix** — run the Stage-A acceptance gate (S3B/S4/S5 + B3/B4) + targeted regression + serial T2 + prod token-gated functional verify. This closes the "deployed-but-not-gated" gap and lets F-1/SEO be marked truly closed.
2. **GO to extend F-1 systemically** — design-first, item-by-item, to the sibling runtimes (Media/ACF/Woo/Settings) once the SEO fix is gated.

Do not enable AI flags, set keys, change security mode, or refresh the regression baseline. Any new code or deploy requires explicit owner authorization.
