# WP Command Center — Session Handoff (2026-06-18)

> **Purpose:** continuity doc for future sessions. Captures release state, audit findings, product decisions, and priority order **after STEP 109**.
> **Type:** documentation only. No code, no commits, no deploy in the session that produced this.
> **Companion docs:** [`UX-AUDIT-AND-DESIGN-SYSTEM.md`](UX-AUDIT-AND-DESIGN-SYSTEM.md) · [`PRODUCT-MASTER-PLAN.md`](PRODUCT-MASTER-PLAN.md) · `HANDOFF-STEP-109.md` (repo root).

---

## 1. Current release state

- **STEP 109 (Dashboard Overview, 109.1–109.3): COMPLETE, RELEASED, PRODUCTION-VERIFIED.**
- **Tag `v0.109.0`** = commit **`079496a`** = `origin/main` = local HEAD = **production server HEAD** (0 ahead / 0 behind; working tree was clean at release).
- **Deploy model:** pull-cron (Hostinger) on `mosharafmanu.com`; `git push origin main` → live ~1 min.
- **Production verification (SSH wp-cli + anonymous HTTP):** deployed HEAD `079496a` · `git describe` = `v0.109.0` · plugin active · `/admin/dashboard` 404→**401** (auth-gated) · admin page 302 · homepage + namespace 200 · no 500s.
- **Invariants on production (unchanged):** OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.4.0**.
- **Test posture at release:** `test-dashboard.sh` 114/0; sibling admin suites clean; T1 `--changed` 97/0 net-new 0; **pristine serial T2 4353/24 net-new 0** (112 suites; the 24 are the chronic baseline matched suite-for-suite).
- **Phase A (admin read-surface arc, STEP 104→109) is COMPLETE.** All Phase A surfaces are **read-only** (no execution, no write/POST routes, no `OperationExecutor`).

---

## 2. Architecture audit findings (reference index)

From the architecture release audit (severity-ranked). These are the canonical IDs used across the product docs.

### Architectural weaknesses
- **W1** — `OperationRegistry::get_operations()` is a single hardcoded inline array with embedded availability probes, rebuilt every request, no caching. *Severity: high (latent).* Root of the 200+-op scalability story.
- **W2** — **FeatureGate coherence gap:** the Dashboard aggregator calls sub-surface summaries directly **without re-checking each sub-surface's own FeatureGate**. Latent today (all ungated); becomes an info-disclosure bug the moment licensing turns a sub-surface off. *Severity: high (latent), security/consistency.*
- **W3** — **No least-privilege tiering:** every surface gates only on `manage_options`; there is no read-only "viewer" role for a governance/visibility console. *Severity: low/medium.*

### Unnecessary complexity
- **C1** — Six near-identical permission callbacks differing only by FeatureGate key string.
- **C2** — `ApprovalAdminQuery` constructs an `OperationRegistry` in its constructor that `summary()` never uses (dead instantiation on the dashboard hot path). *Severity: low (P3).*
- **C3** — Duplicated security-mode presenter (identical wrapper in `DashboardAdminQuery` and `OperationExplorerAdminQuery`).

### Duplicated concepts
- **D1** — **View-layer JS copy-pasted across every view** (`escHtml`, `apiFetch`, `setHtml`, `sprintf`, `fmtTime`, badge/risk renderers). **Largest duplication in Phase A**; blocks consistent a11y/i18n. *High.*
- **D2** — Data concepts surfaced in multiple places **by design** (the Dashboard rolls up by *calling the owning method*, so drift risk is low). **Accept.**

### Scalability (40 → 200+ ops)
- **S1** — Catalogue rebuilt + re-probed (plugin-active / class_exists / WP-CLI) on every request, uncached. *High (latent).*
- **S2** — Operations Explorer (and Tokens) are **unbounded** (no `LIMIT`/offset/cursor); Operations Explorer loads all ops client-side and filters in JS — diverges from the platform's own pagination contract. *Medium-high.*
- **S3** — Token surfaces scale super-linearly: per-token access matrix is `O(tokens × operations)`; `tokens()` unbounded. *Medium.*

### Maintainability
- **M1** — `AdminRestApi` is a ~1169-line monolith with 26 routes + 6 permission callbacks (all five surfaces share one controller). *Medium.*
- **M2** — View-layer JS duplication (same root as D1) → an N-place edit for any security/i18n fix. *Medium.*
- **M3** — Catalogue-as-inline-array (same root as W1) → adding an operation edits a giant literal; merge-conflict + readability cost. *Medium.*

### UX findings
- **UX-1** — No product identity (raw WP `widefat`/dashicons chrome). *High.*
- **UX-2** — Two "Dashboards" (legacy operational + read-only Overview) with different data. *High.*
- **UX-3** — Menu sprawl (~12 submenus, no IA grouping). *High.*
- **UX-4** — No onboarding / readiness state.
- **UX-5** — No persistent task launcher / command surface.
- **UX-6** — Inconsistent micro-UX (each surface reinvents filters/tables/states).
- **UX-7** — Silos; no cross-linking between Approvals ↔ Changes ↔ Operations ↔ Tokens.
- **UX-8** — Reversibility (rollback) is buried inside Change History detail rather than first-class.

---

## 3. Product documents created (this session)

| Document | Location | Contents |
|---|---|---|
| **UX Audit & Design System** | `docs/product/UX-AUDIT-AND-DESIGN-SYSTEM.md` | Positioning vs AI Engine · UX audit (UX-1..8) · IA audit (the "5 C's") · dashboard wireframe · navigation tree · 3-tier design token system · Builder/Engineer mode · AI-era UX patterns · the **Command Design System (CDS)** spec |
| **Product Master Plan** | `docs/product/PRODUCT-MASTER-PLAN.md` | Refined positioning · capability model + **Four Guarantees** · prioritized debt backlog · UX transformation roadmap · Governed Action capabilities roadmap · **Phases B–F** (goals/deliverables/risks/success) · future plugin ecosystem (CDS + governance spine) |
| **This handoff** | `docs/product/SESSION-HANDOFF-2026-06-18.md` | Continuity snapshot + priority order + recommended next prompt |

> Status: all three are **uncommitted working-tree files** in `docs/product/` (documentation only; not yet committed).

---

## 4. Decisions already made (locked)

1. **WPCC is evolving into an *AI Operations Platform for WordPress*** — operate, control, audit, approve, monitor, roll back, manage AI activity.
2. **Governance remains the core moat** — the Four Guarantees are inviolable for every capability.
3. **We will add user-facing AI capabilities** — WPCC is no longer governance-only; it becomes a **Governed Action console** (Propose ≠ Apply).
4. **Builder Mode + Engineer Mode approved conceptually** — one product, two lenses (density + disclosure over shared data).
5. **Command Design System (CDS) approved conceptually** — versioned, themeable by one `brand.accent`, do-not-fork; shared across a future plugin family + a governance spine.
6. **No backward-UX-compatibility constraint** — legacy surfaces may be retired (keep URL redirects for bookmarks only).
7. **Major navigation restructuring is allowed** — collapse ~12 submenus into a branded shell + the 5-C IA (Overview · Operate · Audit · Access · Connect).

---

## 5. Current priority order

### P0 — Inviolable (never regress, in any phase)
- **Preserve the Four Guarantees** on every capability:
  - **Approval** (risk-tiered, security-mode aware, human-in-the-loop)
  - **Rollback** (reversibility, or explicit guarded irreversibility)
  - **Audit** (attributed: human / system / agent)
  - **Capability scoping** (nothing runs outside the token/capability/least-privilege boundary)

### P1 — Phase B: Platform Hardening & Certification
- **W2** — close the FeatureGate coherence gap (latent security).
- **D1** — extract the shared view substrate (unblocks consistent a11y/i18n).
- **S1** — catalogue caching / stop re-probing every request.
- **S2** — pagination consistency for Operations Explorer + Tokens.
- **S3** — token-surface scaling (access matrix).
- **C1** — consolidate the duplicated permission callbacks.
- **C3** — consolidate the duplicated security-mode presenter.
- *(carry-along: W1 catalogue-as-registry, W3 least-privilege role, C2 dead instantiation.)*

### P2 — Pre-transformation groundwork
- **Feature Inventory** — full catalogue of existing surfaces/operations/capabilities as the source of truth for migration.
- **Migration Map** — legacy slug → new 5-C section/route mapping (with redirects), so navigation restructuring is deterministic.

### P3 — Phase C: UX & Design System
- **CDS implementation** (tokens → component kit → migrate surfaces → freeze v1).
- **UX redesign** (branded shell, single menu, 5-C IA, unified dashboard, command palette, onboarding).
- **Builder / Engineer modes** (density + disclosure toggle).

### P4 — Phase D/E: AI Workbench capabilities
- **Governed Action console** + scheduling + notifications + policy templates + metering (Phase D).
- **AI-assisted multi-step workflows** + command-palette intent mode (Phase E).
- Every capability ships **through** the P0 Four Guarantees — no parallel ungoverned path.

---

## 6. Recommended next prompt for a future session

> **Suggested opening prompt:**
>
> "Read `docs/product/SESSION-HANDOFF-2026-06-18.md`, `docs/product/PRODUCT-MASTER-PLAN.md`, and `HANDOFF-STEP-109.md` completely. Confirm the release baseline is still `v0.109.0` (HEAD == origin == tag == prod, tree clean) and invariants are 34/23/40/40/2.4.0.
>
> Then begin **Phase B — Platform Hardening & Certification**, report-first. Produce a REPORT-ONLY remediation plan for the P1 debt in priority order — **W2, D1, S1, S2, S3, C1, C3** — that preserves the Four Guarantees (P0) and all invariants. For each finding: the fix's product intent, blast radius, the invariants/guarantees it must not disturb, and a phased, test-gated sequence (`--changed` T0/T1 net-new 0 → pristine serial T2 → deploy on explicit direction). Do not write code, modify files, or propose STEP 110 implementation until the plan is approved."

This keeps the discipline intact: **report-first → phased build → net-new 0 vs `tests/regression-baseline.tsv` → deploy on explicit direction**, with the Four Guarantees and invariants as the non-negotiable backstop.

---

*Documentation only. No code changes and no commits were made in the session that produced this handoff.*

---

# STEP 110 — Proposal Store + AI Alt Text (Governed Action #1) — milestone

> Added after the Proposal Store primitive (Tasks 1–6) and AI Alt Text (Tasks 7–8.3) were built and validated. Committed as one milestone on `main` (not pushed, not deployed).

## Proposal Store primitive — COMPLETE (Tasks 1–6)
The canonical **Propose** stage of the Governed Action contract — a generic, pre-decision staging primitive that reaches the site only through the existing `OperationExecutor` chokepoint (never a second write/approval/audit/rollback path):
- **Schema:** `wpcc_proposals` table; **DB_VERSION 2.4.0 → 2.5.0** (additive; idempotent `dbDelta`; no backfill).
- **`ProposalStore`** — state-machine repository, **sole writer** of proposal rows (`status`/`request_id`/`change_id`/`error_json`); statuses draft → pending_approval → applied/dismissed/failed (terminal, idempotent).
- **`ProposalApplyService`** — the single execution crossing point (developer direct apply + gated branch → approval).
- **`ProposalOutcome`** — shared executor-envelope interpreter (success / in-band error / gated / hard failure) — the one definition consumed by ApplyService and Sync.
- **`ProposalSync`** — pull-only authority resolver (read-through of requests/results/change_log; lazy materialize).
- **`ProposalReconciler`** — bounded sweep reusing Sync (cron wiring deferred).
- **Proposal REST** — list/get/create/edit(final_payload)/apply/dismiss under `wp-command-center/v1/admin/proposals`, gated by the C1 resolver (`proposal_store` feature key); read-through on GET; rollback-aware presentation.
- **Governed Drafts (Dev) UI** — `wpcc-proposals` admin surface, **build-flag OFF by default**.

## AI Alt Text (Governed Action #1) — COMPLETE through Task 8.3
First user-facing consumer, entirely on the proposal store; apply/undo route through the engine:
- **7A** read-only scan (`AltTextScanQuery`, `GET /admin/alt-text/scan`) — missing/weak/ok audit.
- **7B** provider abstraction (`AltTextProvider`/`ProviderResult`/`ProviderResolver`) + **Anthropic BYO** vision provider (`AnthropicVisionProvider`; key via constant/option; Redactor-scrubbed; outbound isolated; 30s timeout; size guard).
- **7C** `AltTextGenerator` + `POST /admin/alt-text/generate` — provider suggestion → governed **drafts** only (provenance: provider/model/confidence/batch_id/proposed_by).
- **7D** live validation (Anthropic) — quality 9/10, 0 content hallucinations; drafts only.
- **8.1** Builder surface scaffold + **Review tab** (scan/readiness, read-only).
- **8.2** **Suggestions tab** — chunked Generate, edit (`final_payload`), dismiss.
- **8.3** **Approve & Apply** (mode-aware) + **Applied tab** (rollback-aware) + per-image **Undo** (existing rollback route).
- Builder UI is `wpcc-alt-text`, **build-flag OFF by default**.

## Four Guarantees — INTACT
Approval (gated apply → real Approval Center request) · Rollback (existing change-history rollback; rollback-aware status) · Audit (executor/ChangeRecorder; store only reflects) · Capability Scoping (REST gate + executor `media_manage` capability + actor propagation). No second Approval Center / Change History / rollback system; no ungoverned write path.

## Invariants (unchanged across the whole arc)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** (the only intended delta).

## Build flags & local enablers
- Governed Drafts dev UI: **off by default** (`WPCC_PROPOSALS_DEV_UI` const / `wpcc_proposals_dev_ui` filter).
- AI Alt Text UI: **off by default** (`WPCC_ALT_TEXT_UI` const / `wpcc_alt_text_ui` filter).
- Local-only mu-plugins (`wp-content/mu-plugins/wpcc-dev-*.php`) enable these on dev — **outside the plugin repo, NOT committed, never deployed.**

## Testing state (clean env, key removed)
- `tests/test-alt-text.sh` **129/0** · `tests/test-alt-text-ui.sh` **57/0**
- `tests/test-proposal-store.sh` **161/0** · `tests/test-proposal-rest.sh` **23/0** · `tests/test-proposal-admin.sh` green
- `--changed` **T0/T1 net-new 0** throughout.

## Current recommendation
- **Hold deployment** (Builder UI is build-flag off; deploy changes nothing user-facing until flipped — your call).
- **Next task = Task 8.4 Bulk Workflows**, on the committed baseline.

*Milestone committed locally on `main`; not pushed, not deployed.*

---

# Proposal Store + AI Alt Text milestone — DEPLOYED to production (2026-06-19)

The milestone (`3c37cbf`) was pushed and **deployed to production** via the Hostinger pull-cron (`45971e1 -> 3c37cbf active=yes`). Post-deploy SSH+HTTP verification all green: plugin active · `wpcc_db_version` = **2.5.0** · `wp_wpcc_proposals` table created · invariants **34/23/40/40** (MCP tools verified via live `tools/list`) · dev flags `WPCC_ALT_TEXT_UI`/`WPCC_PROPOSALS_DEV_UI` UNDEFINED → both Builder UIs HIDDEN · no fatals · new routes `/admin/proposals` + `/admin/alt-text/scan` = 401 (live, auth-gated); existing admin routes (`/admin/dashboard`, `/admin/history`, `/admin/operations`, `/admin/tokens`, `/admin/approvals`) = 401. Prod REST ns = `wp-command-center/v1`. Deploy gotcha: hosting account has a STALE second checkout at `~/domains/mosdev.site/.../purple-surgical/` (at `5abea8f`) — prod path is the explicit `~/domains/mosharafmanu.com/public_html/wp-content/plugins/wp-command-center`.

---

# Task 8.4 — Tier-1 Bulk Workflows — COMPLETE (committed, NOT pushed)

Committed locally on `main` as **`0b74293`** (`feat(ai-alt-text): Task 8.4 — Tier-1 bulk workflows (UI-only)`); **not pushed, not deployed.** Production remains at `3c37cbf`.

**Scope delivered (UI-only, one view file + its test):** Suggestions-tab bulk action bar (scope selector All drafts / Last generated · select-all · Apply selected · Dismiss selected) + per-row checkboxes. Bulk **Apply**/**Dismiss** run **sequentially** over the existing `POST /admin/proposals/{id}/apply` and `/dismiss` — each item governed individually (own approval gate, `change_id`, rollback). Developer → applied; client/enterprise → `pending_approval`. Mode-aware `confirm()`. **Per-item failure never aborts the run**; failed rows kept with a message. Progress in a `role=status` region (processed/total · applied · submitted · dismissed · failed). **Batch-scoped review:** Generate captures chunk `batch_id`s and the view auto-scopes to the last run; `batch_id` used only as an opaque grouping key (never displayed). Per-item Undo unchanged.

**Explicitly deferred (Tier-2):** batch-level approval, atomic batch rollback, async/queue generation, cross-page **server-side selection** (→ **S2**), raising `MAX_BATCH`. No new endpoint/route/operation/capability/MCP tool/schema/batch primitive.

**Files:** `includes/Admin/views/ai-alt-text.php` (+183/−7), `tests/test-alt-text-ui.sh` (+13 assertions).

**Validation:** `test-alt-text-ui.sh` **69/0**; T1 `--changed` **97/0 net-new 0**; invariants live-verified **34/23/40/40/2.5.0**; Four Guarantees preserved per item. Pre-existing env failures (`test-alt-text.sh` 125/4, `test-proposal-admin.sh` 24/1) confirmed identical at the clean `3c37cbf` baseline (Anthropic key present + dev mu-plugin flags ON) — **0 net-new attributable to 8.4**.

**Next:** S2 — Selection & Pagination Consistency (architecture review first; this is the prerequisite for cross-page/server-side bulk selection).

---

# STEP 111 — Task 8.4 Bulk Workflows + S2.1 Pagination Consistency

> Two committed-locally slices on `main` after the milestone deploy. **Not pushed, not deployed.** Production remains at **`3c37cbf`**.

## Task 8.4 — Tier-1 Bulk Workflows (committed `0b74293`)
- **Tier-1, UI-only** bulk workflows on the AI Alt Text Suggestions tab (one view file + its test).
- Bulk **Apply**/**Dismiss** reuse the **existing per-item endpoints** (`POST /admin/proposals/{id}/apply`, `/dismiss`) run sequentially; each item governed individually (own approval gate, `change_id`, rollback). Developer → applied; client/enterprise → `pending_approval`. Per-item failure never aborts the run; progress in a `role=status` region.
- **No batch approval. No batch rollback. No async queue. No cross-page selection. No new backend/schema/operation/capability/MCP tool.**

## S2.1 — Selection & Pagination Consistency (this section's commit)
- **Operations Explorer** moved **away from load-all + client-side filtering** → server-paginated **and** server-filtered (search / risk / available-only applied before pagination).
- **Tokens & Capabilities** token list moved **away from load-all** → server-paginated (the per-token access matrix is now computed only for the page, not the whole manifest; the matrix itself is unchanged — S3 untouched). `capabilities()` (23) and `operations_map()` (34) left as-is (invariant-bounded).
- Both now use the **canonical pagination envelope** shared with Approval Center / Change History / ProposalAdminQuery / AltTextScanQuery:
  `items` · `total_count` · `returned` · `has_more` · `next_cursor` · `limit` · `offset` · `filters`.
  (Tokens also keeps a `total` alias for `DashboardAdminQuery`.)
- Views consume the envelope and add **Prev/Next** pagers; no new REST routes (existing list handlers parse `limit`/`offset`/`cursor`/filters via a shared `list_paging()` helper).
- **S2.2 explicitly deferred:** no cross-page selection · no select-all-matching · no server-side selection contract · no batch approval/rollback.
- **Files (7):** `includes/Admin/OperationExplorerAdminQuery.php`, `includes/Admin/TokenCapabilityAdminQuery.php`, `includes/Admin/AdminRestApi.php`, `includes/Admin/views/operations-explorer.php`, `includes/Admin/views/token-capability-manager.php`, `tests/test-operations-explorer.sh`, `tests/test-token-capability-admin.sh`.

## Invariants (unchanged, live-verified)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0**.

## Testing
- `test-alt-text-ui.sh` **69/0**
- `test-operations-explorer.sh` **130/0**
- `test-token-capability-admin.sh` **153/0**
- `test-dashboard.sh` **123/0** (confirms the `tokens()['total']` back-compat alias)
- `tests/run.sh --tier T0 --changed` **253/0 net-new 0** · `--tier T1 --changed` **584/0 net-new 0** (14 suites)

## Deployment
- **Production remains at `3c37cbf`.** S2.1 **not deployed** (and Task 8.4 not deployed). Builder UIs stay build-flag OFF either way.

**Next:** S2.2 (cross-page server-side selection) only on explicit direction — it is the unlock for cross-page bulk; design as select-by-criteria → bounded, capability-scoped, server-resolved id set → existing per-item governed action (no batch primitive).

> **Deploy status update:** Task 8.4 + S2.1 were subsequently **released to production** — prod HEAD = **`9259c7e`** (`git describe` v0.109.0-8-g9259c7e), pull-cron verified, invariants 34/23/40/40/2.5.0, Builder UIs still build-flag OFF. (The "Production remains at 3c37cbf" line above reflects the moment those commits were made, not the later deploy.)

---

# STEP 112 — S2.2.1 Cross-Page Server-Side Selection (committed locally, NOT pushed)

> The S2.2 "smallest safe slice" from the S2.2 architecture review. Committed on `main`; **not pushed, not deployed.** Production is at `9259c7e`.

## What shipped
A **stateless, read-only selection primitive** that turns "select all matching {filter}" into a bounded, capability-scoped id set — feeding the **existing** per-item governed apply/dismiss. No new operation/capability/MCP tool/schema/persistence/authority/write path.

- **`SelectionContract`** (`includes/Admin/SelectionContract.php`) — stateless value object: `by = ids | criteria`, `filters` (snapshot), `cap` clamped to `HARD_CAP = 100`. Validates `by`; never persisted; no selection table.
- **`SelectionResolver`** (`includes/Admin/SelectionResolver.php`) — **read-only** resolver over the **existing `ProposalStore`** source (`count()`/`list()` only). **Bounded:** when matches exceed `min(cap, MAX_SELECTION = 100)` it **REFUSES** (`over_cap`, empty ids) rather than truncating into a partial mass action. **Capability-scoped:** criteria for an operation outside the caller's `allowed_operations` resolve to nothing. **Whitelisted filters** (`operation_id/status/target_type/batch_id`) — no arbitrary column injection. No alt-text/media literals (surface-agnostic).
- **Route** — `GET /admin/alt-text/selection` (**READABLE only**), gated by the existing `check_alt_text_permission`. Criteria are **fixed server-side** to this surface (`media_manage` drafts); the caller cannot widen scope. AI Alt Text is the **only** wired consumer.
- **AI Alt Text UI** — a "Select all matching" control resolves server-side, previews the count (or shows over-cap refusal / empty), and **persists a cross-page selection across paging**. On Apply/Dismiss it **RE-RESOLVES at action time** and feeds the fresh, bounded id set into the existing per-item `runApply`/`runDismiss` loops. Per-page selection and match-all are mutually exclusive.

## Architecture posture (sanity-reviewed pre-commit)
`SelectionContract` is fully generic; `SelectionResolver` is reusable **unmodified by any proposal-backed governed action** (pass its own `operation_id` + `allowed_operations`). AI Alt Text coupling is **isolated to the route + UI** (the resolver has zero alt-text knowledge). A `SelectionSource` abstraction (for non-proposal sources) is deliberately **deferred to the second consumer** (extract-on-second-use, not speculative generality).

## Four Guarantees — preserved (per item)
Approval (each resolved id → existing per-item apply → its own `pending_approval` in gated modes; **no batch approval**) · Rollback (per-`change_id`; **no batch rollback**) · Audit (existing chokepoint) · Capability scoping (resolver scope + per-item `OperationExecutor`). Bounded execution via `MAX_SELECTION`; re-resolution at action time so actions run against current governed truth.

## Explicitly NOT built (deferred)
Persisted/server-materialized selections · saved selections · batch approval · batch rollback · async queue · selection on Approval Center / Change History · a `SelectionSource` source-abstraction.

## Invariants (unchanged, live-verified)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** (`Schema.php` untouched; no migration; no new table).

## Files (7)
New: `includes/Admin/SelectionContract.php`, `includes/Admin/SelectionResolver.php`, `tests/test-selection-resolver.sh`. Modified: `includes/Admin/AdminRestApi.php` (read route + handler), `includes/Admin/views/ai-alt-text.php` (select-all-matching + id-list bulk loops), `tests/test-alt-text-ui.sh` (+7), `tests/regression-map.tsv` (+`selection` group).

## Testing
- `test-selection-resolver.sh` **48/0** · `test-alt-text-ui.sh` **76/0**
- `tests/run.sh --tier T0 --changed` **89/0 net-new 0** · `--tier T1 --changed` **541/0 net-new 0** (15 suites)

**Next:** deploy decision for S2.2.1, or the next S2.2 increment (server-materialized/saved selections, or extend the resolver to a second governed action) — report-first, on explicit direction.

---

# S2.2.1 (STEP 112) — DEPLOYED to production (2026-06-19)

> Deployment record for the STEP 112 section above. Supersedes its "committed locally, NOT pushed" status: S2.2.1 is now **live in production**.

## Deployment
- **Commit:** `f5c19ea` — *feat(selection): add bounded cross-page server-side selection (S2.2.1)*
- **Date:** 2026-06-19 · **Model:** Hostinger pull-cron
- **Deploy log:** `DEPLOYED 9259c7e -> f5c19ea active=yes` @ 2026-06-19T14:15:09Z

## Production status
- **Production HEAD = `f5c19ea`** (`git describe` = `v0.109.0-9-gf5c19ea`); `origin == prod == local`.
- Production **healthy** · pull-cron deployment **successful** · **no PHP fatals** (no `debug.log`/`error_log`) · plugin **active**.

## Architecture summary (deployed)
- **S2.2.1 deployed.** `SelectionContract` added (stateless: `by = ids | criteria`, cap clamped to `HARD_CAP = 100`). `SelectionResolver` added (read-only over the existing `ProposalStore`).
- **Cross-page server-side selection** available for **AI Alt Text** ("Select all matching") via the READABLE route `GET /admin/alt-text/selection`.
- **Stateless criteria-based selection** · **capability-scoped resolution** · **refuse-over-cap** (no truncation) · **re-resolve at action time** before feeding the existing per-item apply/dismiss loops.
- **No persistence · no selection table · no batch approval · no batch rollback · no new operations · no new capabilities · no new MCP tools · no schema change.**
- Live verification: selection route auth-gated (anon `401`, not 404); authenticated resolve returns the bounded envelope; **read-only confirmed** (draft count unchanged before/after resolve).

## Invariants (verified live on production)
- **OPERATION_MAP = 34**
- **capabilities = 23**
- **catalogue = 40**
- **MCP tools = 40**
- **DB_VERSION = 2.5.0** (14 `wpcc_*` tables; none added)

## Current production baseline
**Production HEAD: `f5c19ea`**

Latest deployed milestones:
1. Proposal Store primitive
2. AI Alt Text (7A–8.4)
3. S2.1 Pagination Consistency
4. S2.2.1 Cross-Page Server-Side Selection

Current state:
- Builder UIs remain **build-flag OFF** · Governed Drafts **hidden** · AI Alt Text **hidden**.
- **Four Guarantees intact** (approval · rollback · audit · capability scoping).
- Production **stable**.

**Next:** report-first planning of the next architectural task (e.g., next S2.2 increment — server-materialized/saved selections, or a second governed-action consumer of the resolver — or other Phase B/C debt) on explicit direction. **S2.2.2 not started.**

---

# Governed Action #2 — SEO Meta Generator · Slice 1 (Read-Only SEO Audit) — committed locally, NOT pushed

> The first slice of GA#2, per the GA#2 architecture + SEO-rollback verification reviews. Committed on `main`; **not pushed, not deployed.** Production is at `da95a0f`.

## What shipped (read-only only)
- **Read-only SEO audit** of public content (posts / pages / public CPTs, published): which items are **missing / weak / ok** on SEO title + meta description, with a per-item score.
- **Plugin support via the existing `SeoProvider`:** Rank Math / Yoast / **NONE**. When no supported SEO plugin is active → `provider_available:false`, empty population, Builder shows an empty-state and **no controls**.
- **Canonical pagination envelope:** `items · total_count · returned · has_more · next_cursor · limit · offset · filters` (plus `provider`, `provider_available`, `summary`) — identical to every other list surface.
- **Build flag OFF by default** (`WPCC_SEO_META_UI` const / `wpcc_seo_meta_ui` filter) AND FeatureGate `seo_meta_generator`. Menu `wpcc-seo` hidden until flipped.
- Classification thresholds **mirror `SeoRuntimeManager`** (title ≤60, description 120–160, focus keyword present).

## Explicitly NOT in Slice 1 (later slices)
**No** AI text generation · **no** provider/model call · **no** proposal creation · **no** `seo_update` · **no** approve/apply · **no** undo · **no** bulk/selection · **no** writes of any kind · **no** schema change · **no** new operation/capability/MCP tool.

## Reuse / new
- **Reused:** `SeoProvider` (detect/read/label), the canonical pagination contract, the build-flag + FeatureGate + C1 `gate()` admin patterns, the read-only Builder view pattern.
- **New:** `includes/Seo/SeoAuditQuery.php` (read-only audit), `includes/Admin/views/seo-meta.php` (read-only Builder view), one READABLE route `GET /admin/seo/audit` + `seo_audit()` handler + `check_seo_permission()` (`FEATURE_KEYS['seo']='seo_meta_generator'`), a build-flagged `wpcc-seo` menu, and one **additive read-only** helper `SeoProvider::meta_key()`.

## Four Guarantees & invariants
Four Guarantees untouched (read-only surface; no execution/approval/rollback/audit-write path). Invariants unchanged: OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** (the `seo` FeatureGate key is a UI Free/Pro seam, not a `CapabilityRegistry` capability).

## Files (7)
New: `includes/Seo/SeoAuditQuery.php`, `includes/Admin/views/seo-meta.php`, `tests/test-seo-audit.sh`. Modified: `includes/Operations/SeoProvider.php` (additive `meta_key()`), `includes/Admin/AdminRestApi.php` (route + handler + gate), `includes/Admin/AdminMenu.php` (build-flagged menu), `tests/regression-map.tsv` (+`seo_audit` group).

## Testing
- `test-seo-audit.sh` **54/0** (dev provider: Yoast)
- `tests/run.sh --tier T0 --changed` **126/0 net-new 0** · `--tier T1 --changed` **299/0 net-new 0**

**Next:** Slice 2 (shared AI text provider + `SeoMetaGenerator` → governed drafts), report-first, on explicit direction. **Slice 2 not started.**

---

# GA#2 SEO Meta Generator — Slice 2a (Shared Anthropic Transport) — committed locally, NOT pushed

> The first half of Slice 2: extract the Anthropic transport (extract-on-second-use) so the vision provider and the future SEO text provider share one outbound path. Committed on `main`; **not pushed, not deployed.** Production is at `ff64e9e`.

## What shipped
- **`AnthropicClient` added** (`includes/Ai/AnthropicClient.php`) — the single low-level Anthropic Messages transport: URL/version/headers/timeout/`wp_remote_post`, key + model resolution, response parsing + HTTP error mapping, Redactor scrubbing, **errors-as-data (never thrown)**. Operation-agnostic: caller supplies `messages` + `max_tokens` + `model`. No prompt construction, no `ProviderResult` coupling, no SEO/alt-text *logic* (architecture-reviewed pre-commit: GO, no required changes).
- **`AnthropicVisionProvider` refactored** to delegate transport to `AnthropicClient` while keeping all vision concerns (image size guard, mime/readable checks, image+text message, WCAG prompt, `ProviderResult` mapping, error codes). **Alt Text behavior preserved** (proven baseline-identical).

## Key / model resolution
- **Canonical shared (new):** `WPCC_ANTHROPIC_API_KEY` / `wpcc_anthropic_api_key`, `WPCC_ANTHROPIC_MODEL` / `wpcc_anthropic_model`.
- **Legacy preserved (back-compat):** `WPCC_VISION_API_KEY` / `wpcc_alt_text_api_key`, `WPCC_VISION_MODEL` / `wpcc_alt_text_model`.
- Precedence: canonical constant → canonical option → legacy constant → legacy option (→ caller default for model). One BYO Anthropic key now powers all WPCC AI; existing Alt Text installs keep working unchanged.

## Explicitly NOT in Slice 2a
**No** SEO provider · **no** `SeoMetaGenerator` · **no** SEO generate route · **no** SEO generation · **no** proposals · **no** writes · **no** schema change · **no** new operation/capability/MCP tool.

## Invariants (unchanged)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0**.

## Files (5)
New: `includes/Ai/AnthropicClient.php`, `tests/test-anthropic-client.sh`. Modified: `includes/AltText/AnthropicVisionProvider.php` (delegates transport), `tests/test-alt-text.sh` (2 static assertions repointed to the AI transport layer), `tests/regression-map.tsv` (+`ai_transport` group → `test-anthropic-client.sh`).

## Testing
- `test-anthropic-client.sh` **42/0** (not-configured-send path skipped — a key constant is defined on dev)
- `test-alt-text.sh` **baseline-identical: 125/4 vs 125/4** (stash-compare) → behavior unchanged, net-new 0. (The 4 are the chronic "key present on dev" env failures.)
- `tests/run.sh --tier T0 --changed` **96/0 net-new 0** · `--tier T1 --changed` **193/0 net-new 0**

**Next:** Slice 2b (`SeoMetaProvider`/`AnthropicSeoProvider` on `AnthropicClient` + `SeoMetaResult` + `SeoMetaGenerator` → governed drafts via `ProposalStore`, drafts-only, no apply), report-first, on explicit direction. **Slice 2b not started.**

---

# GA#2 SEO Meta Generator — Slice 2b (Governed SEO Draft Generation) — committed locally, NOT pushed

> The second half of Slice 2: AI suggestions → governed DRAFTS on the proposal store. Committed on `main`; **not pushed, not deployed.** Production is at `364011d`.

## What shipped (drafts only)
- **`SeoMetaResult`** — SEO result value object (ok/error + meta_title + meta_description + provenance).
- **`SeoMetaProvider`** (interface) + **`AnthropicSeoProvider`** — consumes the shared `AnthropicClient` (Slice 2a); grounded JSON-only prompt; tolerant `extract_meta()` (bare/fenced/embedded JSON; rejects non-JSON/missing-key — never fabricates).
- **`SeoMetaProviderResolver`** — config-only active-provider selection (non-final test/extension seam).
- **`SeoMetaGenerator`** — provider → `ProposalStore::create` governed draft (`operation_id=seo_manage`, `action=seo_update`, `payload={action:'seo_update', content_id, seo:{title,description}}`, `prior`={current meta}, provenance, batch_id). **`ProposalStore::create` is the only write.**
- **`POST /admin/seo/generate`** (CREATABLE, `check_seo_permission`) + minimal Generate control on the Slice-1 view (per-row select; reports created/skipped/failed).

## Hard boundaries (architecture-verified pre-commit)
- **Explicit `post_ids[]` only.** Handler reads solely `post_ids`; generator's id set = that array, deduped, capped at **`MAX_BATCH=25`**. No criteria/filter/state/"all-matching" generation, **no `SelectionResolver` integration**, no cross-page generation, no audit-filter→server-side expansion. No path creates drafts without explicit ids.
- **Drafts only:** no `seo_update` execution, no `SeoProvider::write`, no `OperationExecutor`/`ProposalApplyService`, no apply, no undo, no approval, no bulk, no site write.
- Two preconditions degrade gracefully (skip): SEO plugin active (`no_seo_plugin`) and AI provider configured (`no_provider`). Per-item failure never aborts the run.

## Invariants (unchanged)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** (no operation/capability/MCP/schema change; drafts are rows in existing `wpcc_proposals`).

## Files (10)
New: `includes/Seo/SeoMetaResult.php`, `includes/Seo/SeoMetaProvider.php`, `includes/Seo/AnthropicSeoProvider.php`, `includes/Seo/SeoMetaProviderResolver.php`, `includes/Seo/SeoMetaGenerator.php`, `tests/test-seo-generate.sh`. Modified: `includes/Admin/AdminRestApi.php` (route+handler), `includes/Admin/views/seo-meta.php` (Generate control), `tests/test-seo-audit.sh` (section-4 guards updated for the drafts control), `tests/regression-map.tsv` (+`seo_generate` group).

## Testing
- `test-seo-generate.sh` **46/0** (stub provider — no real API call) · `test-seo-audit.sh` **55/0**
- `tests/run.sh --tier T0 --changed` **167/0 net-new 0** · `--tier T1 --changed` **264/0 net-new 0**

**Next:** Slice 3 (review/edit/dismiss UI over the existing proposal routes), report-first, on explicit direction. **Slice 3 not started.** Live provider quality (real Anthropic JSON) still needs a manual validation step before relying on real suggestions; prod has no Anthropic key configured.
