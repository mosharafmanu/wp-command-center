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

---

# GA#2 SEO Meta Generator — Slice 3 (SEO Suggestions: Review / Edit / Dismiss) — committed locally, NOT pushed

> UI-only review workflow over the existing proposal platform. Committed on `main`; **not pushed, not deployed.** Production is at `a600432`.

## What shipped (UI-only)
- **Suggestions tab** added to `seo-meta.php` (now tabbed Review | Suggestions). Lists `seo_manage` draft proposals; per row shows post title/type/edit-link · **Current** (`prior`) vs **Suggested** (editable title + description) · live char counts (≤60 / 120–160, advisory) · provider/model attribution · **Edited** indicator.
- **Reuses ONLY the existing proposal routes** — no backend, no new class, no new route:
  - `GET /admin/proposals?operation_id=seo_manage&status=draft` (list)
  - `PUT /admin/proposals/{id}` (edit `final_payload`; draft stays draft)
  - `POST /admin/proposals/{id}/dismiss` (dismiss; terminal)
- **WP core REST** (`/wp/v2/posts` + `/wp/v2/pages` by `include`) used **only** for post-context enrichment (client-side); edit links built client-side. CPT-without-`show_in_rest` degrades to `#id` (edit link still works).
- **`final_payload`-first rendering verified**: `suggested()` prefers `final_payload.seo`, falls back to `payload.seo` only when absent — an edit never reverts to the original AI suggestion after reload.

## Boundaries (architecture-verified, grep counts = 0)
No Apply / Approval-Center / Change-History / Undo / rollback / `/history/` / `OperationExecutor` / `ProposalApplyService` / `SeoProvider::write` / SelectionResolver / bulk apply / bulk dismiss. The single `seo_update` string is the edit's `final_payload.action` **data** (not execution). Save updates `final_payload` only; dismiss terminates the draft only; no SEO meta write. **`ProposalAdminQuery` untouched.**

## Four Guarantees & invariants
Pre-decision / proposal-only / draft-only → Approval · Rollback · Audit · Capability scoping all unchanged (enforced later at apply, Slice 4). Invariants frozen: OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** (no new route/operation/capability/MCP tool/schema).

## Files (5) — UI + tests only
Modified: `includes/Admin/views/seo-meta.php` (Suggestions tab + JS), `tests/test-seo-audit.sh` + `tests/test-seo-generate.sh` (2 stale view guards repointed to `/apply`+`/history/` since the shared view's scope grew), `tests/regression-map.tsv` (+`seo_review` group). New: `tests/test-seo-review.sh`.

## Testing
- `test-seo-review.sh` **39/0** · `test-seo-audit.sh` **55/0** · `test-seo-generate.sh` **46/0**
- `tests/run.sh --tier T0 --changed` **182/0 net-new 0** · `--tier T1 --changed` **424/0 net-new 0**

**Next:** Slice 4 (approve/apply + undo), report-first, on explicit direction. **Slice 4 not started.** Apply → `ProposalApplyService` → `seo_manage`/`seo_update`; undo → existing change-history rollback (`change_id`→`rollback_id`→`seo_restore`, verified). Slice 5 (bulk) needs `wpcc_seo_rollbacks` store hardening first.

---

# GA#2 SEO Meta Generator — Slice 4a (Apply + Applied) — committed locally, NOT pushed

> Approve/Apply + a read-only Applied tab over the existing governed apply path. UI-only. Committed on `main`; **not pushed, not deployed.** Production is at `5158b47`. (Undo = Slice 4b, NOT started.)

## What shipped (UI-only)
- **Apply** on the Suggestions tab → reuses the existing `POST /admin/proposals/{id}/apply` → `ProposalApplyService::apply()` → `OperationExecutor::run('seo_manage')` → `SeoRuntimeManager::seo_update` → `ChangeRecorder` → `ProposalStore::mark_applied(change_id)`. No SEO-specific apply, no new route/executor/approval path; the UI never calls `seo_manage`/`OperationExecutor` directly.
- **Mode-aware** label from server-rendered `SecurityModeManager::current()`: developer → "Approve & Apply" (immediate); client/enterprise → "Submit for approval" (`pending_approval`). Outcome read from the API response, never assumed from the label.
- **Applied tab** (read-only) reuses the existing proposal list query: three disjoint reads (`status=applied|pending_approval|failed`, `operation_id=seo_manage`), merged + WP-core-REST post enrichment; rollback-aware status via the deployed `ProposalAdminQuery.change_status` ("Applied / Awaiting approval / Failed / Reverted"). No row actions.

## Verified (Final Applied-State Verification: GO)
Real developer apply → status `applied`, `change_id` recorded, change record `rollback_kind=runtime_option` + `rollback_id` present + `reversible=1`, and `seo_update` actually wrote the post meta. Applied/pending/failed all persist across reload + re-query; the 3-read merge is drop-safe (status-disjoint). Provider/model + Current-vs-Suggested render after reload.

## Boundaries (grep-verified 0)
No Undo (`wpcc-seo-undo`), no `/history/` rollback route, no Approval-Center link, no Change-History link, no rollback button, no bulk apply, no SelectionResolver, no direct `seo_manage`/`OperationExecutor` execution, no `SeoProvider::write`. The single `action: 'seo_update'` is the edit's `final_payload` data.

## Four Guarantees & invariants
Approval (per-mode gating via the existing executor) · Rollback (untouched; `rollback_id` preserved for 4b) · Audit (`ChangeRecorder`) · Capability scoping (`seo_manage → content.manage`) — all unchanged; no second path. Invariants frozen: OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** (no new route/operation/capability/MCP tool/schema).

## Files (6) — UI + tests only
Modified: `includes/Admin/views/seo-meta.php` (Apply button + Applied tab + handlers), `tests/test-seo-review.sh` + `test-seo-audit.sh` + `test-seo-generate.sh` (stale `/apply` "no-apply" guards removed — apply now belongs to 4a; `/history/` undo guards retained), `tests/regression-map.tsv` (+`seo_apply` group). New: `tests/test-seo-apply.sh`.

## Testing
- `test-seo-apply.sh` **39/0** · `test-seo-review.sh` **37/0** · `test-seo-audit.sh` **54/0** · `test-seo-generate.sh` **45/0**
- `tests/run.sh --tier T0 --changed` **227/0 net-new 0** · `--tier T1 --changed` **553/0 net-new 0**

**Next:** Slice 4b (per-item Undo via the existing `/admin/history/{change_id}/rollback` → `seo_restore`), report-first, on explicit direction. **Slice 4b not started.** Slice 5 (bulk) still needs the `wpcc_seo_rollbacks` store hardening first.

---

# GA#2 Slice 4a — DEPLOYED to production (2026-06-20)

> Deployment record for the Slice 4a section above. **Supersedes its "committed locally, NOT pushed" status:** Slice 4a is now **live in production**.

## Deployment
- **Commit:** `f7c1ca8` — *feat(seo): add governed proposal apply workflow*
- **Date:** 2026-06-20 · **Model:** Hostinger pull-cron
- **Deploy log:** `DEPLOYED 5158b47 -> f7c1ca8 active=yes` @ 2026-06-20T03:55:09Z
- **Pre-deploy gate:** `tests/test-seo-apply.sh` **39 / 0** (live DB, AMPPS env).

## Production status
- **Production HEAD = `f7c1ca8`** (`git describe` = `v0.109.0-15-gf7c1ca8`); **origin == prod == local**, working tree clean.
- Plugin **active** · homepage **200** · **no `debug.log` / no PHP fatals**.

## Verification (all PASS)
- **Routes (anon):** `/admin/dashboard` 401 · `/admin/proposals` 401 · `/admin/seo/audit` 401 · POST `/admin/seo/generate` 401 (`rest_forbidden`, auth-gated — not 404).
- **Slice 4a view (deployed `seo-meta.php`):** Apply control (`wpcc-seo-apply`) present · Applied tab (`wpcc-seo-tab-applied`) present · NO Undo (`wpcc-seo-undo` 0) · no `/history/` (0) · no Approval-Center (0) · no Change-History (0) · no `SelectionResolver` (0) · no `OperationExecutor` (0) · no bulk apply.
- **Invariants (live `wp eval` on prod):** OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** (`wpcc_db_version` option = 2.5.0).
- **Schema:** **14** `wpcc_*` tables (none added) · no migration ran (`Schema.php` untouched; option matched code → no `dbDelta`).
- **Build flags:** `WPCC_SEO_META_UI` / `WPCC_ALT_TEXT_UI` / `WPCC_PROPOSALS_DEV_UI` all **UNDEFINED → Builder UIs HIDDEN** (SEO Meta / AI Alt Text / Governed Drafts).

## GA#2 progress
Slice 1 (audit) · 2a (AnthropicClient) · 2b (drafts) · 3 (review/edit/dismiss) · **4a (approve & apply + Applied tab)** — all DEPLOYED, Builder UI build-flag OFF throughout. **Next = Slice 4b (per-item Undo), NOT started.** Slice 5 (bulk) still gated on `wpcc_seo_rollbacks` store hardening.

---

# GA#2 Slice 4b — Per-item Undo — DEPLOYED to production (2026-06-20)

Per-item Undo on the SEO Applied tab. **UI-only** (one production file: `seo-meta.php`): an Undo control on applied + reversible (not yet rolled back) rows carrying a `change_id`, reusing the EXISTING governed route `POST /admin/history/{change_id}/rollback` (`change_history → seo_restore`). Developer reverts immediately; client/enterprise route to `pending_approval`. No new route/operation/capability/MCP tool/schema; no backend PHP change. Committed `14a1999` (*feat(seo): add governed per-item undo workflow*), pushed, pull-cron **DEPLOYED to production**; `git describe` = `v0.109.0-17-g14a1999`, plugin active, all admin routes 401, invariants **34/23/40/40/2.5.0**, 14 tables, Builder UIs build-flag OFF. Tests: `test-seo-undo.sh` 33/0 · `test-seo-apply.sh` 39/0 · T0/T1 `--changed` net-new 0.

---

# GA#2 Slice 4c — SEO Rollback Store Hardening — committed locally, NOT pushed

> Backend-only prerequisite for Slice 5 bulk. Committed on `main` as **`<slice-4c-commit>`** (*feat(seo): harden rollback storage for bulk readiness*); **not pushed, not deployed.** Production remains at **`14a1999`**.

## What shipped (Option B — per-post protected meta snapshots)
- **New rollback store:** each SEO rollback snapshot is a dedicated **protected post-meta row** keyed `_wpcc_seo_rb_{rollback_id}` (one row per rollback). Replaces the capped, autoloaded `wpcc_seo_rollbacks` option for new writes — **no global 100-cap, no FIFO eviction, not autoloaded, no shared-blob lost-update race.**
- **`rollback_id`-only restore:** `seo_restore` resolves the snapshot by `rollback_id` alone via one **indexed** `meta_key` lookup → `get_post_meta()` → restore `before_state` → mark `rollback_applied=true` (record kept). The dispatch contract is unchanged (no post_id needed from the caller).
- **Legacy fallback:** if no meta row is found, `seo_restore_legacy()` reads the old `wpcc_seo_rollbacks` option and restores pre-4c records, marking them `rollback_applied=true`. **No migration; the legacy option is left in place** (a draining set; new writes never touch it).

## Preserved / unchanged
Change History route (`/admin/history/{change_id}/rollback`), `ChangeHistoryRuntimeManager`, `OperationExecutor`, `ACTION_ROLLBACKS`, `seo_restore`, `ChangeRecorder`, `ProposalApplyService` — all untouched. **Four Guarantees:** Approval/Audit/Capability scoping unchanged; **Rollback strengthened** (durable, bulk-safe). **No schema change, no DB_VERSION change.**

## Invariants (unchanged, live-verified)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** · **14** `wpcc_*` tables (no migration).

## Files (4) — one production file only
Modified: `includes/Operations/SeoRuntimeManager.php` (the only production change). Tests: `tests/test-seo-rollback-store.sh` (new), `tests/test-seo-audit.sh` (read-only sentinel now store-agnostic), `tests/regression-map.tsv` (+`seo_rollback_store` group). Confirmed UNCHANGED: AdminRestApi · ChangeHistoryRuntimeManager · OperationExecutor · ProposalApplyService · ProposalAdminQuery · Schema · CapabilityRegistry · OperationRegistry · McpServerRuntime.

## Testing
- `test-seo-rollback-store.sh` **28/0** (write/restore-by-id/idempotency/legacy-fallback/no-evict-103/no-option-growth) · `test-seo-apply.sh` **39/0** · `test-seo-undo.sh` **33/0** · `test-seo-audit.sh` **54/0**.
- `T0 --changed` **245/0 net-new 0** · `T1 --changed` **599/0 net-new 0** (a first-run transient flake in `test-change-history-rollback.sh` passed 48/0 standalone + after the store suite; re-run clean — cross-suite env pollution, not a regression).

**Slice 5 prerequisite satisfied:** the rollback store is now bulk-safe (no cap, no eviction, no lost-update, not autoloaded). **Next = deploy decision for 4c, then GA#2 Slice 5 (bulk), NOT started.**

> **Deploy update:** Slice 4c was subsequently **released to production** — prod HEAD = **`529023d`** (`git describe` v0.109.0-18-g529023d), pull-cron verified, invariants 34/23/40/40/2.5.0, 14 tables, Builder UIs build-flag OFF. The protected `_wpcc_seo_rb_` meta store + legacy fallback are live.

---

# GA#2 Slice 5a — Bulk Apply + Bulk Dismiss — committed locally, NOT pushed

> First increment of GA#2 Slice 5 (bulk). UI-only. Committed on `main`; **not pushed, not deployed.** Production remains at **`529023d`**.

## What shipped (UI-only)
- **Page-scoped bulk action bar on the SEO Suggestions tab:** per-row checkboxes (`wpcc-seo-sg-cb`), *Select all on this page* (`wpcc-seo-sg-selectall`), **Apply selected** (`wpcc-seo-sg-apply`), **Dismiss selected** (`wpcc-seo-sg-dismiss`), and a `role=status`/`aria-live=polite` progress region (`wpcc-seo-sg-progress`).
- **Bulk Apply/Dismiss are SEQUENTIAL loops over the EXISTING per-proposal routes** (`POST /admin/proposals/{id}/apply`, `/dismiss`) — they act only on **checked rows of the current page**. Each item is governed individually (own approval gate, `change_id`, audit, independent Slice-4c rollback snapshot). Developer → applied; client/enterprise → `pending_approval`. Mode-aware confirm; outcome read from each API response, never the mode. **Per-item failure never aborts** (failed rows kept with a message; successful rows removed).

## Boundaries (grep-verified absent in the view)
NO cross-page selection · NO `SelectionResolver` · NO `matchall` / select-all-matching · NO `/admin/seo/selection` · NO bulk **Undo** · NO batch approval · NO batch rollback · NO async/background job · NO direct `OperationExecutor`/`seo_manage`/`SeoProvider::write` · NO new REST route.

## Invariants (unchanged, live-verified)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** (no new route/operation/capability/MCP tool/schema).

## Files (4) — one production file only
Modified: `includes/Admin/views/seo-meta.php` (the only production change). Tests: `tests/test-seo-bulk.sh` (new), `tests/test-seo-apply.sh` (stale "no bulk apply" guard flipped — bulk apply now lives on the Suggestions tab), `tests/regression-map.tsv` (+`seo_bulk` group). `test-seo-review.sh` unchanged (its `lacks SelectionResolver`/`matchall` boundaries remain valid — 5a is page-scoped). Confirmed UNCHANGED: AdminRestApi · ChangeHistoryRuntimeManager · OperationExecutor · ProposalApplyService · ProposalAdminQuery · Schema · CapabilityRegistry · OperationRegistry · McpServerRuntime · SeoRuntimeManager.

## Testing
- `test-seo-bulk.sh` **36/0** (incl. live 3-item apply → 3 distinct `change_id`s + 3 independent `_wpcc_seo_rb_*` snapshots; 2-item dismiss; partial-failure isolation) · `test-seo-apply.sh` **38/0** · `test-seo-undo.sh` **33/0** · `test-seo-audit.sh` **54/0** · `test-seo-review.sh` **36/0**.
- `T0 --changed` **325/0 net-new 0** · `T1 --changed` **727/0 net-new 0** (19 suites; no flake).

**Next = deploy decision for 5a; Slice 5b (cross-page select-all-matching, needs a READABLE `/admin/seo/selection` route) and 5c (bulk Undo) deferred — NOT started.**

> **Deploy update:** Slice 5a was **released to production** — prod HEAD = **`02682dc`** (`git describe` v0.109.0-19-g02682dc), pull-cron verified, invariants 34/23/40/40/2.5.0, 14 tables, Builder UIs build-flag OFF, all routes 401.

---

# GA#2 UX Polish — Workflow Guidance, Tab Counts, Action Dashboard — committed locally, NOT pushed

> Must-Have UX polish (U1.1/U1.2/U1.3/U1.4/U3) on the SEO Meta Builder. UI-only. Committed on `main`; **not pushed, not deployed.** Production remains at **`02682dc`**.

## What shipped (UI-only)
- **U1.1 intro copy fix:** removed the false "Read-only — this page does not change anything"; the intro now states reviewable / approval-aware / **reversible**.
- **U1.2 Generate → Suggestions handoff:** on successful generation (`created > 0`) the view **auto-switches** to the Suggestions tab; the dashboard footer is the persistent CTA. No more dead-end.
- **U1.4 no-provider notice:** when generation returns `no_provider` (no AI key), a clear inline notice links to **AI Integrations** (reads the existing `skipped[].reason`; zero backend).
- **U1.3 tab count badges:** Review / Suggestions / Applied carry live counts (reuse the existing proposal list route, `limit=1`).
- **U3 action-first dashboard:** progress bar (optimized %) + **Needs you** (clickable **Missing** / **Needs work** → set the audit filter) + **Healthy** (Optimized) + footer **N suggestions ready** / **N applied (reversible)** deep-linking to those tabs.

## Visual QA (real Chrome via Playwright, authenticated dev session)
All states captured and verified: Review, Suggestions, Applied, dashboard card, tab badges, no-provider notice, Generate→Suggestions handoff, mobile (390px — no overflow). Dashboard actions functionally verified (filter switch + tab deep-links). **One double-percent bug found and fixed** ("0%% optimized" → "0% optimized"; `dashPct` `%%`→`%`).

## Boundaries / invariants
No new route/operation/capability/MCP tool/schema; **no backend PHP changed** (only `seo-meta.php`). The new count reads reuse the existing `/admin/proposals` route. Four Guarantees untouched (presentation-only). Invariants frozen: OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0**.

## Files (3) — one production file only
Modified: `includes/Admin/views/seo-meta.php` (only production change), `tests/test-seo-audit.sh` (+14 UX assertions: intro/badges/dashboard), `tests/test-seo-generate.sh` (+5 UX assertions: handoff/no-provider).

## Testing
- `test-seo-audit.sh` **68/0** · `test-seo-generate.sh` **49/0** · siblings green (`test-seo-review.sh` 36/0 · `test-seo-apply.sh` 38/0 · `test-seo-bulk.sh` 36/0 · `test-seo-undo.sh` 33/0).
- `T0 --changed` **330/0 net-new 0** · `T1 --changed` **801/0 net-new 0** (22 suites; no flake).

**Scope = Must-Have only.** Deferred (separate slices, NOT started): U2 audit-table badge relabels + score `/100` + "Suggestion ready" state; **Applied-tab pagination** (the remaining Public-Beta scalability blocker, visually confirmed unbounded in QA); Slice 5b / 5c.

> **Deploy update:** UX Polish was **released to production** — prod HEAD = **`af9d314`** (`git describe` v0.109.0-20-gaf9d314), pull-cron verified, all UX-polish markers live (intro/tab badges/dashboard/no-provider + corrected `%d% optimized`), invariants 34/23/40/40/2.5.0, 14 tables, Builder UIs build-flag OFF, routes 401.

---

# SEO Meta Applied Tab Pagination — committed locally, NOT pushed

> Fixes the unbounded/truncated Applied tab. UI-only. Committed on `main`; **not pushed, not deployed.** Production remains at **`af9d314`**.

## What shipped (UI-only)
- **Segmented Applied statuses:** the Applied tab now has a 3-segment control — **Applied** (default) · **Awaiting approval** · **Failed** — instead of the old 3-read merge (each capped at `limit=50`, silently truncating).
- **Pagination envelope reuse:** each segment is **one paginated read** over the EXISTING `GET /admin/proposals?operation_id=seo_manage&status={apSeg}&limit=20&offset=N`, consuming the canonical envelope (`total_count/returned/has_more/offset`). Offset-based **Prev/Next** + "**Showing X–Y of N**" (reuses `STR.pageInfo`). Default segment = Applied; switching a segment or re-entering the Applied tab resets offset to 0. WP-core post/page enrichment now per-page (≤20 ids).
- **Preserved:** Undo on reversible Applied rows (Slice 4b), Reverted badge, Applied/Awaiting/Failed rendering — `renderApplied` unchanged; `loadApplied()` kept its name so the Undo handler + tab switch reload the current segment/page transparently.

## No backend / no invariant changes
Only `seo-meta.php` changed in production. **No** new route / multi-status / `status IN` / store change / schema / DB_VERSION / operation / capability / MCP tool. All 9 named backend files byte-identical. Four Guarantees untouched (read-only presentation). Invariants: OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0**.

## Files (3)
Modified: `includes/Admin/views/seo-meta.php` (only production change), `tests/test-seo-apply.sh` (section 2 → segmented + paginated assertions; +4c functional pagination test: 22 applied → page 1 returns 20 + has_more + total≥22, page 2 reachable). `docs/product/SESSION-HANDOFF-2026-06-18.md` (this section).

## Testing
- `test-seo-apply.sh` **56/0** (incl. live >20-record pagination) · `test-seo-undo.sh` **33/0** · `test-seo-bulk.sh` **36/0** · siblings green (audit 68/0, generate 49/0, review 36/0).
- `T0 --changed` **281/0 net-new 0** · `T1 --changed` **607/0 net-new 0** (16 suites; no flake). Live visual smoke (Playwright, authed dev session): default Applied segment, "Showing 1–20 of 133", Next → "21–40 of 133", switch to Awaiting → "1–20 of 37" with 0 Undo (offset reset, no Undo on non-applied).

**Not deployed yet.** Next = deploy decision for Applied-tab pagination. Slice 5b / 5c and other surfaces NOT started.

---

# GA#2 — SEO Meta Applied Tab Pagination — DEPLOYED to production

- **Commit:** `75e1631` — *feat(seo): paginate applied proposal history*
- **Deployment verified:** pull-cron released; **Production HEAD = `75e1631`** (`git describe` v0.109.0-21-g75e1631), plugin active, homepage/namespace **200**, admin routes **401**, no PHP fatals.
- **What shipped (UI-only):** the Applied tab is now a **segmented single-status paginated list** — **Applied** (default) · **Awaiting approval** · **Failed**. Each segment is one paginated read over the EXISTING `GET /admin/proposals?operation_id=seo_manage&status={seg}&limit=20&offset=N`, **reusing the canonical pagination envelope** (`total_count/returned/has_more/offset`) with offset-based Prev/Next and "Showing X–Y of N". Default = Applied; segment switch / tab entry resets offset to 0.
- **Silent truncation removed:** the old 3-read merge (each `limit=50`) is gone; >20 records in any status now page fully (live-verified "Showing 1–20 of 133" → Next "21–40 of 133").
- **Preserved:** Undo on reversible Applied rows, Reverted badge, all status rendering (`renderApplied` unchanged; `loadApplied` kept its name).
- **No backend / route / schema changes:** only `seo-meta.php` (production); all 9 named backend files byte-identical on prod (`git diff af9d314..75e1631` = view + tests + docs only). **Four Guarantees preserved** (read-only presentation). **Invariants unchanged: 34 / 23 / 40 / 40 / 2.5.0**; **14** `wpcc_*` tables (no migration).
- **Tests:** `test-seo-apply.sh` 56/0 (incl. live >20-record pagination) · siblings green · `T0 --changed` 281/0 net-new 0 · `T1 --changed` 607/0 net-new 0.

**Production baseline is now `75e1631`.** Builder UIs remain build-flag OFF on prod. Next = contextual SEO AI entry points (architecture review) / Slice 5b / 5c — NOT started.

---

# Contextual SEO Entry Points (Sprint A) — committed locally, NOT pushed

> Posts/Pages/Products list **row action** "Generate SEO Suggestion" → governed draft → SEO Meta → Suggestions. Thin admin wiring only. Committed on `main`; **not pushed, not deployed.** Production remains at **`75e1631`**.

## What shipped (propose-only)
- **New `includes/Admin/SeoRowActions.php`** — registers a **"Generate SEO Suggestion"** row action on `post_row_actions` (Posts + WooCommerce Products) and `page_row_actions` (Pages), plus one `admin_post_wpcc_seo_generate` handler. The handler verifies a **nonce**, checks **`manage_options` + `FeatureGate('seo_meta_generator')` + the SEO Meta UI build flag**, then creates a **governed DRAFT** via the EXISTING `SeoMetaGenerator::generate([id])` and **redirects to SEO Meta → Suggestions** (`?page=wpcc-seo&tab=suggestions&wpcc_seo_gen={code}`).
- **`seo-meta.php`** (UI): reads `?tab=suggestions` (auto-switch) + `?wpcc_seo_gen={code}` → shows a result notice (created / already-exists / no-provider+AI-Integrations link / no-plugin / not-published / failed).
- **`Plugin.php`**: wires `SeoRowActions` in the `is_admin()` bootstrap block (gated, so it only appears when the Builder is enabled).
- **Products only when WooCommerce is active** (`class_exists('WooCommerce')`); action only on **published** content (mirrors the generator's `not_published` skip). Reuses the generator's existing skip handling (`has_open_proposal`/`no_provider`/`no_seo_plugin`).

## Propose ≠ Apply (Four Guarantees intact)
The row action **only creates drafts** — it NEVER applies, NEVER writes SEO meta, NEVER calls `ProposalApplyService`/`OperationExecutor`/`SeoProvider::write`, NEVER bypasses approval/rollback/audit/capability scoping. Review + apply + undo stay in the governed Builder chokepoint. **No new route/operation/capability/MCP tool/schema/DB table/DB_VERSION; no batch primitive.** All 9 contract-backend files byte-identical. Invariants: OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0**.

## Files (5)
New: `includes/Admin/SeoRowActions.php`, `tests/test-seo-row-actions.sh`. Modified: `includes/Core/Plugin.php` (admin wiring), `includes/Admin/views/seo-meta.php` (entry notice + Suggestions landing), `tests/regression-map.tsv` (+`seo_row_actions` group).

## Testing + visual validation
- `test-seo-row-actions.sh` **39/0** — visibility matrix (admin+flag+published → present; non-published / unsupported type / FeatureGate-off / subscriber / flag-off → absent), nonce/capability/FeatureGate/flag gates, propose-only round-trip (draft created, **no SEO meta written**, duplicate prevented), invariants. Siblings green.
- `T0 --changed` **345/0 net-new 0** · `T1 --changed` **671/0 net-new 0** (17 suites; no flake).
- **Playwright (authed dev session):** row action present on Posts (20/20 published), Pages (5/5), **Products (7/7, Woo active)**; redirect lands on Suggestions with the green "SEO suggestion created" notice; duplicate + no-provider notices render; no layout regressions. (Drafts correctly show no action.)

**Not deployed yet.** Gated in practice on enabling the SEO Meta Builder UI in production (build-flag OFF). Next = deploy decision; Slice 5b / 5c and editor/Yoast/RankMath metabox integrations NOT started.

> **Deploy update:** Sprint A row actions were **released to production** — prod HEAD = **`a69a2d3`** (`git describe` v0.109.0-23-ga69a2d3), pull-cron verified, `SeoRowActions.php` live + propose-only, invariants 34/23/40/40/2.5.0, 14 tables, no fatals; row action hidden on prod (build-flag OFF, expected).

---

# Contextual SEO Entry Points (Sprint B) — Bulk Actions — committed locally, NOT pushed

> Native WordPress **Bulk Actions** "Generate SEO Suggestions" on Posts/Pages/Products. Extends the deployed `SeoRowActions`; propose-only. Committed on `main`; **not pushed, not deployed.** Production remains at **`a69a2d3`**.

## What shipped (propose-only)
- **`SeoRowActions` extended** with `bulk_actions-edit-{post|page|product}` (dropdown) + `handle_bulk_actions-edit-{type}` (handler), registered for the same supported types (Products only when Woo active). The handler verifies **capability + FeatureGate + build flag**, sanitizes ids, **caps to `MAX_BATCH ≤ 25`**, calls the EXISTING `SeoMetaGenerator::generate()` (drafts only), and **redirects to SEO Meta → Suggestions** with aggregate counts (`?wpcc_seo_bulk=1&c=&s=&f=&r=`). WP core verifies the bulk nonce before dispatch.
- **`seo-meta.php`**: reads `wpcc_seo_bulk` → shows an aggregate notice ("X created · Y skipped · Z failed"), or the dominant skip reason when nothing was created (no-provider + AI-Integrations link / all-already-exist / no-plugin / not-published).
- **Test seam:** `SeoRowActions` gained a `protected make_generator()` factory (class no longer `final`) so the bulk handler can be tested with a stub provider — no production behavior change.

## Propose ≠ Apply (Four Guarantees intact)
Bulk action **only creates drafts** — never applies/writes meta/calls `ProposalApplyService`/`OperationExecutor`/`SeoProvider::write`/rollback; review+apply+undo stay in the governed Builder. **No new REST route / AJAX / operation / capability / MCP tool / schema / DB table / DB_VERSION; no batch-apply primitive.** `ProposalStore`/`ProposalAdminQuery`/`AdminRestApi` contracts byte-identical. Invariants **34/23/40/40/2.5.0**.

## Files (4)
Modified: `includes/Admin/SeoRowActions.php` (bulk hooks + handler + factory seam; only production logic file), `includes/Admin/views/seo-meta.php` (bulk summary notice), `tests/test-seo-row-actions.sh` (+bulk section), `tests/regression-map.tsv` (trigger extended).

## Testing + visual validation
- `test-seo-row-actions.sh` **64/0** — bulk registration, dropdown gating (admin+flag present; subscriber/flag-off/FeatureGate-off absent or pass-through), **MAX_BATCH (27 → 25 created, overflow dropped)**, **drafts created via bulk (stub provider) with NO SEO meta written**, duplicate bulk → all `has_open_proposal`, redirect to Suggestions. Siblings green.
- `T0 --changed` **366/0 net-new 0** · `T1 --changed` **463/0 net-new 0** (11 suites; no flake).
- **Playwright (authed dev session):** "Generate SEO Suggestions" present in Posts/Pages/**Products** bulk dropdowns; bulk-result landing shows "8 created · 2 skipped · 0 failed" on the Suggestions tab; all-exist + no-provider notices render; no admin regressions.

**Not deployed yet.** Next = deploy decision; editor metabox / Yoast / Rank Math integrations and Slice 5b/5c NOT started.

> **Deploy update:** Sprint B was **released to production** — prod HEAD = **`15bcd6d`** (`git describe` v0.109.0-24-g15bcd6d), pull-cron verified, bulk-action code live + propose-only, invariants 34/23/40/40/2.5.0, 14 tables, no fatals; hidden on prod (build-flag OFF, expected).

---

# GA#2 Contextual SEO Quick Panel (Option B) — committed this session

> In-context AJAX modal over the EXISTING governed routes; progressive enhancement of the deployed Sprint A row action. Committed on `main` this session and released via pull-cron (production stamp at the end of this section). Production baseline before this work: **`15bcd6d`**.

## Architecture decision — Option B (AJAX modal using existing REST routes)
Progressive enhancement. The "Generate SEO Suggestion" row action stays a working nonce-signed `<a href="admin-post.php?action=wpcc_seo_generate&post=ID">` (no-JS fallback = the existing redirect handler) and gains a `wpcc-seo-quickgen` class + `data-id`/`data-type`. A small enqueued asset intercepts the click, opens an accessible modal, calls `POST /admin/seo/generate {post_ids:[id]}`, then `GET /admin/proposals/{created_id}`, and renders **Current vs Suggested** (title + description). Modal actions only **navigate** (Open in Suggestions / Close). **Drafts only — never applies.** The asset is enqueued ONLY on `edit.php` for supported types (post / page / product-when-Woo), gated by the same `manage_options` + `FeatureGate('seo_meta_generator')` + `WPCC_SEO_META_UI` build flag as the row action.

## Files changed (5)
- **Modified** `includes/Admin/SeoRowActions.php` — anchor gains `wpcc-seo-quickgen` + `data-id`/`data-type` (href fallback intact); new `enqueue_assets()` on `admin_enqueue_scripts` (scoped to `edit.php` + supported type + the row-action gate; localizes REST base + a fresh `wp_rest` nonce + `suggestUrl` + i18n).
- **New** `assets/js/seo-quick-panel.js` — modal logic (generate → fetch created proposal → Current vs Suggested; all skip/failure branches; navigate-only actions; a11y: dialog role, focus trap, ESC, focus restore, `role=status`). Self-aborts if unconfigured (fallback preserved).
- **New** `assets/css/seo-quick-panel.css` — neutral modal; semantic color; `prefers-reduced-motion`; responsive; the `role=status` live region rendered screen-reader-only.
- **New** `tests/test-seo-quick-panel.sh` — 50 assertions.
- **Modified** `tests/regression-map.tsv` — `seo_quick_panel` group (triggers on `SeoRowActions` too, so a shared-file edit runs both the row-action and Quick Panel suites).
- *Asset-path note:* placed under the existing top-level `assets/` root (matches `WPCC_PLUGIN_URL`/`Assets.php`), not `includes/Admin/assets/` as the original plan sketched — convention consistency, single asset root.

## Four Guarantees — verified
- **Approval** — modal only *proposes*; apply stays in the governed Builder (gated modes still route apply → `pending_approval` there).
- **Rollback** — unaffected (no apply/rollback in the modal).
- **Audit** — generation recorded via the existing generator/store chokepoint (the REST route).
- **Capability scoping** — `check_seo_permission` (`manage_options` + FeatureGate) enforced **server-side** on both reused routes; the client gate is convenience only.
- **Propose-only**, same `ProposalStore::create`, **no second proposal system**; admin_post redirect kept as fallback. The JS calls ONLY `POST /admin/seo/generate` + `GET /admin/proposals/{id}` — no `/apply`, `/dismiss`, `/history/`, rollback, `OperationExecutor`, or admin-ajax.

## Invariants — verified (live)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** (live `tools/list`) · DB_VERSION **2.5.0** (14 tables). No new route / operation / capability / MCP tool / schema. All 10 contract-backend files byte-identical to HEAD.

## T0 / T1 / T2 results
- Focused: `test-seo-quick-panel.sh` **50/0** (fallback preserved · enhancement hooks · enqueue gating matrix, 6 cases · drafts-only PHP+JS guards · a11y hooks · invariants).
- **T0 `--changed` 231/0 net-new 0** · **T1 `--changed` 328/0 net-new 0**.
- **T2 (full serial, 129 suites): 5284 passed, 31 failed; run.sh net-new 7 — ALL triaged to 0 attributable.** Of the 7: `test-alt-text` 4 + `test-proposal-admin` 1 = chronic key-present env (reproduced standalone **125/4**, **24/1**); `test-change-history-rollback` 1 + `test-safe-search-replace` 1 = cross-suite pollution (clean standalone **48/0**, **11/0**). Zero failing suites reference the Quick Panel surface. **Attributable net-new = 0.**

## Visual validation summary (Playwright + Chrome, authed dev session, provider = Yoast + live key)
Modal opens (`role=dialog` / `aria-modal=true`); loading → real `claude-sonnet-4-6` **Current vs Suggested** comparison with provenance + a "saved as a draft — nothing has been applied" note; footer = **Open in Suggestions** + **Close** (no apply/undo/approve controls or text); "already exists" state; **ESC** closes and restores focus to the trigger; mobile 390px no overflow. Live enqueue scoping: **present** on post/page/product edit screens, **absent** on dashboard/media/comments/shop_order. **No-JS fallback** (JS disabled): href → `admin-post.php` → redirect to SEO Meta → Suggestions (`wpcc_seo_gen=created`). One QA nit fixed (the `role=status` live region visually duplicated the message → made screen-reader-only).

## Production readiness assessment
- **Engineering: production-ready** — all gates green, net-new 0, no fatals, no drift.
- **Exposure on prod: none until enablement** — the SEO Builder is build-flag OFF, so the row action AND the Quick Panel asset are both hidden until `WPCC_SEO_META_UI` is flipped. This ships dormant.
- Enablement remains gated on (1) an AI key on prod, (2) live-key/prod-provider validation, (3) a security-mode posture choice — config/validation, not code.

## Known limitations
- Hidden on prod (build-flag OFF) — ships dormant; flip the flag + add an AI key to surface the value.
- Modal acts on a single id (the first created proposal); bulk / cross-page generation stays in the WP bulk action and the Builder.
- No in-modal edit/apply by design — review/edit/apply/undo remain in the governed Builder chokepoint.
- On prod (no AI key) the modal would render the `no_provider` state (graceful, with an AI-Integrations link).

## Why Option B over the alternatives
- **vs admin-ajax wrapper (C):** would add a parallel `admin-ajax` action duplicating logic the governed REST routes already expose — more surface, a second code path, zero benefit. Rejected.
- **vs new REST endpoint (D):** would add a route (a contract/invariant surface) for a capability `generate` + `proposals/{id}` already cover 1:1 — violates "no new route," more to certify, no gain. Rejected.
- **vs redirect-only UX (A):** the deployed behavior — functional but dated (navigates away from the list on every click). Option B keeps A as the **no-JS fallback layer** while adding the in-context modal for JS users — best of both, zero backend change.

> **Deploy update:** released to production via pull-cron this session — prod HEAD = **`343d720`** (`git describe` v0.109.0-26-g343d720), plugin active, invariants 34/23/40/40/2.5.0, 14 tables, no fatals; SEO Builder build-flag OFF → row action **and** Quick Panel asset hidden on prod (expected); homepage 200, SEO routes 401. The `343d720` feature commit is the new production baseline (this docs-stamp commit advances git HEAD only).

---

# GA#2 SEO Suggestions for Draft / Pending / Scheduled / Private content — committed this session

> Product decision: SEO suggestions should be available **before publishing**. Replaces the published-only gate across the whole contextual path with a shared editable-status allow-list. Committed on `main` this session; production stamp below. Baseline before this work: **`343d720`**.

## What changed (behavior)
The row action, Quick Panel, WP bulk action, and REST `generate` path now offer/accept SEO suggestion generation for **editable** statuses — **publish · draft · pending · future · private** — instead of published-only. Disallowed statuses (**trash · auto-draft · inherit** [revisions/attachments] · anything else) are skipped with reason **`unsupported_status`** (renamed from `not_published`). Still **propose-only** (drafts); no apply/undo/rollback/SEO-write changes.

## Architecture decision — one shared allow-list (no UI/generator drift)
Single source of truth on the generator: `SeoMetaGenerator::SUPPORTED_STATUSES` + `SeoMetaGenerator::is_supported_status()`. `SeoRowActions` (row action visibility) and `SeoMetaGenerator` (skip logic) both consult it, so the UI never offers an action the generator would then skip. The REST `generate` and WP bulk paths inherit it automatically (both call the same generator) — **no AdminRestApi change**.

## Files changed (7)
- `includes/Seo/SeoMetaGenerator.php` — `SUPPORTED_STATUSES` const + `is_supported_status()`; skip `unsupported_status` (was `'publish' !== status` → `not_published`).
- `includes/Admin/SeoRowActions.php` — row-action gate uses `SeoMetaGenerator::is_supported_status()`; `outcome_code()` maps `unsupported_status`; localized i18n `unsupportedStatus` (was `notPublished`); doc comment.
- `includes/Admin/views/seo-meta.php` — entry-notice + bulk-notice map `unsupported_status`; copy `genUnsupported` (clarifies draft/pending/scheduled/private supported).
- `assets/js/seo-quick-panel.js` — `skipMessage()` maps `unsupported_status` → `unsupportedStatus`.
- `tests/test-seo-row-actions.sh` — status allow-list matrix (allowed present / disallowed absent) + mixed-status bulk (draft created, trash skipped).
- `tests/test-seo-generate.sh` — generator status matrix (draft/pending/future/private create a draft; trashed → `unsupported_status`).
- `tests/test-seo-quick-panel.sh` — rename guards (`unsupportedStatus`/`unsupported_status`; no stale `not_published`/published-only gate).

## Four Guarantees & invariants — intact
Propose-only (same `ProposalStore::create`); no direct SEO write / apply / rollback; gated apply unchanged in the Builder. Approval/Rollback/Audit/Capability-scoping all unchanged. **No new route / operation / capability / MCP tool / schema. DB_VERSION 2.5.0.** Invariants **34 / 23 / 40 / 40 / 2.5.0**. The 10 contract-backend files (incl. `AdminRestApi`) byte-identical.

## Tests
- Focused: `test-seo-row-actions.sh` **67/0** · `test-seo-generate.sh` **51/0** · `test-seo-quick-panel.sh` **55/0** · `test-seo-bulk.sh` **36/0**.
- **T0 `--changed` 426/0 net-new 0** · **T1 `--changed` 523/0 net-new 0**. Full serial **T2** before deploy.

## Visual validation (Playwright, dev: Yoast + live key)
Draft Posts list → **20** row actions present; Quick Panel opens on a **draft** and renders a real `claude-sonnet-4-6` Current-vs-Suggested comparison with the "saved as a draft — nothing applied" note (no apply/undo/approve). Trash list → **0** row actions (disallowed status correctly hidden).

> **Deploy update:** released to production via pull-cron — prod HEAD = **`c87ce08`** (`git describe` v0.109.0-28-gc87ce08), feat(seo) draft/pending/scheduled/private support. Live-verified: plugin active · `SeoMetaGenerator::SUPPORTED_STATUSES = publish,draft,pending,future,private` · `is_supported_status('draft')=yes` / `('trash')=no` · invariants 34/23/40/40/2.5.0 · 14 tables · no fatals · build-flag OFF (entry points hidden on prod) · homepage 200 / SEO routes 401. Pre-deploy clean serial T2 = 5295/30, **net-new attributable 0** (24 chronic baseline · 5 environment-specific key-present [alt-text 125/4, proposal-admin 24/1, reproduced standalone] · 1 cross-suite pollution [safe-search-replace, clean 11/0 standalone]).

---

# Quick-Panel-First Safe SEO v1 (Trust Polish + Quick Panel Apply/Undo Option B) — DEPLOYED to production (2026-06-21)

> First two commits of the approved **Quick-Panel-First Safe SEO v1** execution plan. Pushed and released via pull-cron. **Ships dormant** (`WPCC_SEO_META_UI` OFF) — no customer-facing exposure. Production baseline before this work: **`c87ce08`**.

## What shipped (two commits)
- **`d934859` — feat(seo): trust polish — persist-before-apply + reversibility toast.** Apply on the SEO Suggestions tab now persists the visible (possibly unsaved) field values via the existing `PUT /admin/proposals/{id}` route BEFORE applying, so an edit can never be silently discarded for the stale AI suggestion. Post-apply toast ("Applied successfully · Reversible · Audited") with one-click Undo (developer); gated modes show "Submitted for approval · Audited". Undo reuses the existing change_history rollback route via a shared `rollbackChange()` helper (the Applied-tab Undo was refactored onto it — **one rollback path**). View + test only (`includes/Admin/views/seo-meta.php`, `tests/test-seo-apply.sh`).
- **`5db577a` — feat(seo): Quick Panel becomes a complete governed action (Apply + Undo).** The contextual SEO Quick Panel (row-action modal) now carries one item through its full governed lifecycle in-context — **Generate → Review → Edit → Apply → Undo** — instead of being a read-only preview. Suggested fields are editable; Apply persists the visible values (PUT) BEFORE applying (POST `/apply`); mode-aware (developer applies immediately + one-click Undo via `/admin/history/{id}/rollback`; client/enterprise show an "approval required" pre-signal and submit → `pending_approval` with no Undo and no live write). Outcome read from the API response, never assumed from the label. Reuses existing routes only; `SeoRowActions` adds only additive CFG localization (security mode + apply/undo i18n). Files: `assets/js/seo-quick-panel.js`, `assets/css/seo-quick-panel.css`, `includes/Admin/SeoRowActions.php`, `tests/test-seo-quick-panel.sh`.

## Four Guarantees & invariants — intact
One execution path, one rollback path; no executor/provider-write in the modal; no-JS redirect fallback preserved. **No new route / operation / capability / MCP tool / schema.** Approval (gated modes → real `pending_approval`), Rollback (shared change_history route), Audit (`ChangeRecorder`), Capability scoping (`check_seo_permission` server-side) all unchanged. Invariants **34 / 23 / 40 / 40 / 2.5.0**; backend contract files byte-identical.

## Validation
- Focused: `test-seo-apply.sh` **76/0** (incl. functional edited-value-applied proof) · `test-seo-quick-panel.sh` **74/0** · all SEO siblings green.
- `T0 --changed` (7 suites) **390/0 net-new 0** · `T1 --changed` (10 suites) **487/0 net-new 0** · pre-commit `T0` **542/0** / `T1` **1270/0** net-new 0.
- Pristine serial **T2** (129 suites) **5335/29, net-new 5 — all 5 environmental** (`test-alt-text` 4 + `test-proposal-admin` 1 = dev "key present" failures; pass standalone). **0 net-new attributable** to these commits.
- Playwright (real Chrome, dev): developer Generate→Edit→Apply→Undo **12/12** + server-truth (edited-in-modal value applied, stale avoided; Undo restored prior); client/pending-approval **6/6** ("Submit for approval" + pre-signal → "Submitted · Audited", no Undo, live meta unchanged).

## Production verification (2026-06-21) — all green
- **Push:** `4499d87..5db577a main -> main`. **origin/main = `5db577a`** (both commits present).
- **Deploy:** pull-cron advanced prod `4499d87 → 5db577a`. **Prod HEAD = `5db577a`** (`git describe` = **`v0.109.0-31-g5db577a`**); origin == prod == local.
- Plugin **active** · homepage **200** · namespace `wp-json/wp-command-center/v1` **200** · admin routes **401** unauth (`/admin/seo/audit`, `/admin/proposals`, `/admin/dashboard` GET; `POST /admin/seo/generate`, `POST /admin/proposals/{id}/apply`) · **no `debug.log` / no fatals**.
- **Invariants (live `wp eval` on prod):** OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** (live `tools/list`) · DB_VERSION **2.5.0**.
- **Schema:** exactly **14** `wp_wpcc_*` tables, **no migration** (`wpcc_db_version` option = code = 2.5.0).
- **Dormant:** `WPCC_SEO_META_UI` **OFF** (const undefined, `wpcc_seo_meta_ui` filter OFF) → Builder page + Quick Panel asset hidden; Option B markers present in deployed files (`applyAction`, `persistRow`, `applyDev`). Prod `mode=developer`, `provider=rankmath`, **AI key unset**. **No enablement performed; no customer-facing exposure.**

**Production baseline is now `5db577a`.** Next = **Controlled Production Enablement** (Execution Plan Step 5) — config/validation, on explicit direction.

---

# AI Content Platform (Title/Excerpt generation · generalized Governed Action Panel · Alt Text contextual · AI Content Builder) — DEPLOYED to production (2026-06-21)

> The AI Content Platform initiative. Fast-forward merged to `main` (four logical commits preserved, no squash/rebase) and released via pull-cron. **Ships dormant** (all Builder build flags OFF) — no customer-facing exposure. Production baseline before this work: **`5db577a`**.

## What shipped (four commits)
- **`39a678f` — feat(content): governed Title/Excerpt generation.** `includes/Content/*` AI chain (ContentFieldResult/Provider/AnthropicContentProvider/Resolver/Generator) → `ProposalStore::create` governed DRAFTS for `post_title`/`post_excerpt` (`content_manage`/`content_update`; per-kind dedup via `target_type` content_title|content_excerpt). Additive `generate:{kind,post_id}` branch on the EXISTING `POST /admin/proposals` (Option 1 — **no new route**), FeatureGate-aware per kind. `ContentManager` excerpt rollback-snapshot fix (excerpt reversible; legacy records backward-compatible).
- **`21ebb45` — refactor(ui): generalized Governed Action Panel + shared runtime + tokens.** `wpcc-tokens.css` (thin 3-tier tokens) + `wpcc-admin-runtime.js` (shared helpers; closes D1) + `wpcc-action-panel.js/.css` (the SEO Quick Panel generalized into a config-driven panel; persist-before-apply, mode-aware, mobile bottom-sheet, a11y). `seo-quick-panel.*` removed (no fork); SEO migrated onto it via `data-wpcc-action="seo"`.
- **`dcc9dd1` — feat(ui): AI content contextual entry + Builder.** `ActionPanelAssets` (single central enqueuer — one localized config for all workflows) + `ContentRowActions` (Title/Excerpt row+bulk on Posts/Pages/Products) + `MediaRowActions` (Alt Text row+bulk on the Media Library) + `views/ai-content.php` (AI Content Builder) + AdminMenu/Plugin wiring. Build flag `WPCC_AI_CONTENT_UI`; per-kind FeatureGate `title_generator`/`excerpt_generator`.
- **`fc70b2d` — test: AI Content Platform suites + regression map.**

## Four Guarantees & invariants — intact
One execution path (apply → `ProposalApplyService` → `OperationExecutor`), one rollback path (`/admin/history/{change_id}/rollback`); no provider write / no second execution path in any client surface. **No new route / operation / capability / MCP tool / schema.** Server-truth verified all four workflows (SEO/Alt/Title/Excerpt) × developer (applied + reversible) and client (`pending_approval`, no site write, no undo).

## Validation
- Focused green: `test-ai-content` 32/0 · `test-ai-content-builder` 81/0 · `test-contextual-entry` 39/0 · `test-seo-quick-panel` 70/0. `T0 --changed` 488/0 net-new 0 · `T1 --changed` net-new 0.
- Pristine serial **T2** (132 suites) **5482/30, net-new attributable 0** (24 chronic baseline · 5 dev "key-present" env [alt-text 125/4, proposal-admin 24/1, standalone-identical] · 1 cross-suite pollution [safe-search-replace, clean 11/0 standalone]).
- Real-browser (Playwright chromium-1223, real Anthropic): full in-context Generate→Review→Edit→Apply→Undo (SEO developer) with the exact 5-route governed trace; all four entry points open the modal + route-traced (SEO→`/admin/seo/generate`, Title/Excerpt→`/admin/proposals`, Alt→`/admin/alt-text/generate`); client mode; mobile 390px bottom-sheet; a11y (focus-in-dialog, trap, ESC, restore). Minor a11y follow-up: move focus into the dialog on open (currently placed after generation renders).

## Production verification (2026-06-21) — all green
- **Push:** `5db577a..fc70b2d main -> main`; **origin/main = `fc70b2d`**.
- **Deploy:** pull-cron `5db577a -> fc70b2d active=yes` (2026-06-21T12:13:08Z). **Prod HEAD = `fc70b2d`** (`git describe` = **`v0.109.0-35-gfc70b2d`**); origin == prod == local.
- Plugin **active** · homepage **200** · namespace **200** · admin routes **401** (`/admin/dashboard`, `/admin/proposals`, `/admin/seo/audit`; `POST /admin/seo/generate`, `POST /admin/alt-text/generate`) · **no `debug.log` / no fatals**.
- **Invariants (live on prod):** OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** · **14** `wp_wpcc_*` tables (no migration).
- **Dormant:** `WPCC_SEO_META_UI` / `WPCC_AI_CONTENT_UI` / `WPCC_ALT_TEXT_UI` all **undefined**; filters off → SEO Builder + AI Content Builder + all contextual entry points (Title/Excerpt/Alt) + Quick Panel asset **hidden**. Prod `mode=developer`, **AI key unset**. **No enablement; no customer-facing exposure.**

**Production baseline is now `fc70b2d`.** Next = **Controlled Production Enablement** (Execution Plan Step 5) — config/validation, on explicit direction (roadmap priorities unchanged).

---
---

# ✅ CONSOLIDATED PRODUCTION STATE (authoritative — read this first)

> This block supersedes the per-slice history above for "what is live today." The sections above are the chronological build log.

## Current production
- **Production baseline = `fc70b2d`** (`git describe` = `v0.109.0-35-gfc70b2d`) — the **AI Content Platform** (Title/Excerpt governed generation · generalized Governed Action Panel · Alt Text contextual entry · AI Content Builder; commits `39a678f` → `21ebb45` → `dcc9dd1` → `fc70b2d`), fast-forward merged onto **Quick-Panel-First Safe SEO v1** (`5db577a`). **origin/main == local == prod**; working tree clean except this handoff doc. (Lineage: `c87ce08` → `343d720` → `5db577a` Quick Panel Apply/Undo → `fc70b2d` AI Content Platform. A docs-stamp commit may advance git HEAD past `fc70b2d`; the feature baseline is `fc70b2d`.)
- **Deploy model:** Hostinger pull-cron on `mosharafmanu.com` — `git push origin main` → live ~1 min. Manual deploy: `ssh -p 65002 u916998506@72.62.68.183` then `bash ~/wpcc-deploy.sh`. Runbook: `.ai/DEPLOY.md`. Prod REST namespace = **`wp-command-center/v1`** (not `wpcc/v1`). Prod plugin path: `~/domains/mosharafmanu.com/public_html/wp-content/plugins/wp-command-center`.
- **Plugin active · homepage/namespace 200 · admin routes 401 · no PHP fatals.**

## Invariants (live-verified on prod at `fc70b2d`, FROZEN)
- **OPERATION_MAP = 34**
- **capabilities = 23**
- **catalogue = 40**
- **MCP tools = 40** (live `tools/list`)
- **DB_VERSION = 2.5.0** (14 `wp_wpcc_*` tables; no migration since 2.5.0)

## Deployed AI Content Platform — live (dormant) at `fc70b2d`
Four governed AI content workflows, all on one config-driven **Governed Action Panel** (Generate → Review → Edit → Apply → Undo, mode-aware, persist-before-apply) over EXISTING routes only:
1. **SEO** (`/admin/seo/generate` → `seo_manage`) — migrated onto the generalized panel; behavior preserved.
2. **Alt Text** (`/admin/alt-text/generate` → `media_manage`) — Media-Library row/bulk contextual entry (`MediaRowActions`).
3. **Title** (`POST /admin/proposals` generate branch → `content_manage`/`content_update`) — Posts/Pages/Products row/bulk (`ContentRowActions`).
4. **Excerpt** (same generate branch → `content_manage`/`content_update`).
Plus the **AI Content Builder** (`views/ai-content.php`, menu `wpcc-ai-content`) and the shared substrate (`wpcc-tokens.css`, `wpcc-admin-runtime.js`). One execution path (`ProposalApplyService`), one rollback path (`/admin/history/{id}/rollback`). No new route/operation/capability/MCP tool/schema.

## Deployed GA#2 SEO Meta Generator — full feature set live at `5db577a`
1. **Slice 1** — read-only SEO audit (Rank Math/Yoast via `SeoProvider`).
2. **Slice 2a/2b** — shared `AnthropicClient` transport + governed AI **draft generation** (`SeoMetaGenerator` → `ProposalStore::create`, drafts only, `MAX_BATCH=25`).
3. **Slice 3** — Suggestions review / edit (`final_payload`) / dismiss.
4. **Slice 4a** — Approve & Apply (mode-aware) + Applied tab.
5. **Slice 4b** — per-item **Undo** (reuses `/admin/history/{change_id}/rollback` → `seo_restore`).
6. **Slice 4c** — **rollback-store hardening**: per-post protected meta `_wpcc_seo_rb_{rollback_id}` + legacy-option fallback (no cap/eviction/lost-update; not autoloaded).
7. **Slice 5a** — page-scoped **Bulk Apply / Bulk Dismiss** (sequential, per-item governed).
8. **UX Polish** — corrected intro, Generate→Suggestions handoff + auto-switch, no-provider notice, tab count badges, action-first dashboard (progress bar + clickable Missing/Needs-work + deep links).
9. **Applied Tab Pagination** — segmented single-status (Applied/Awaiting/Failed) paginated list (canonical envelope; replaced the unbounded 3-read merge).
10. **Sprint A — Contextual Row Actions** — "Generate SEO Suggestion" on Posts/Pages/Products rows → governed draft → redirect to Suggestions. (`includes/Admin/SeoRowActions.php`.)
11. **Sprint B — Bulk Actions** — "Generate SEO Suggestions" in the WP Bulk Actions dropdown on Posts/Pages/Products → governed drafts (≤25) → redirect with aggregate counts. (Same `SeoRowActions` class.)
12. **Contextual SEO Quick Panel (Option B)** — in-context AJAX modal on the row action (progressive enhancement; admin_post redirect = no-JS fallback). *(Superseded by #14: originally a navigate-only preview; the modal now carries the full governed lifecycle.)*
13. **Trust Polish** (`d934859`) — Suggestions-tab Apply **persists the visible edited values before applying** (no silent stale-apply); post-apply "Applied · Reversible · Audited" toast with one-click Undo; **single shared rollback path** (Applied-tab Undo refactored onto the same `rollbackChange()` helper). View + test only.
14. **Quick Panel Apply + Undo (Option B → complete governed action)** (`5db577a`) — the Quick Panel modal now does **Generate → Review → Edit → Apply → Undo** in-context: editable Suggested fields, persist-before-apply (`PUT` then `/apply`), mode-aware (developer applies + one-click Undo via `/admin/history/{id}/rollback`; client/enterprise show "approval required" pre-signal → `pending_approval`, no Undo). Reuses existing routes only; `SeoRowActions` adds only additive CFG localization. (`assets/js|css/seo-quick-panel.*` + `includes/Admin/SeoRowActions.php`.)

All of the above are **propose-or-governed** — every mutation flows through the single `OperationExecutor`/`ProposalApplyService`/`change_history` chokepoint. Contextual *generation* entry points (Sprint A/B) are **propose-only** (drafts); the Quick Panel's Apply/Undo (#14) and the Builder are **governed** (mode-gated apply + reversible).

## Build-flag status (production)
- `WPCC_SEO_META_UI` — **OFF** → SEO Meta Builder page, its row/bulk entry points, AND the Quick Panel asset are all **hidden/dormant** on prod (the Quick Panel is now the generalized Governed Action Panel, enqueued by `ActionPanelAssets`).
- `WPCC_AI_CONTENT_UI` — **OFF** (undefined) → AI Content Builder (`wpcc-ai-content`) + Title/Excerpt row/bulk contextual entry points hidden. The `fc70b2d` AI Content Platform code is deployed but inert until this flag is flipped.
- `WPCC_ALT_TEXT_UI` — **OFF** → AI Alt Text Builder + the Media-Library "Generate Alt Text" row/bulk entry hidden. `WPCC_PROPOSALS_DEV_UI` — OFF.
- Local dev enables these via `wp-content/mu-plugins/wpcc-dev-*.php` (outside the repo, never committed/deployed): `wpcc-dev-seo-meta-ui.php` and `wpcc-dev-alt-text-ui.php` exist on dev; AI Content was enabled only transiently for browser validation and removed.

## Quick-Panel-First Safe SEO v1 — status
- **v1 core = DEPLOYED but DORMANT** at `5db577a`: Trust Polish (persist-before-apply + reversibility toast) + Quick Panel **Apply-in-modal + Undo + approval-required signaling**. The loved surface (Quick Panel) is now a complete governed action; the Builder remains the management/scale surface.
- **Remaining v1 scope** (per the approved Execution Plan; NOT started, do not start without direction):
  - **Step 3 (residual):** Quick Panel trust-signal *visual* polish / chip consistency (functional approval-required signal already shipped in `5db577a`).
  - **Step 4:** mobile bottom-sheet adaptation for the modal.
  - **Step 5 — Controlled Production Enablement** *(next major initiative — see below)*.
  - **Step 6:** minimal Client Activity Report (read-only "what AI did, all reversible" over existing Applied/audit data).

## Known limitations (production)
- **No AI provider key on prod** (`WPCC_ANTHROPIC_API_KEY` unset) → generation returns `no_provider`; the value path (generate→apply) cannot produce suggestions until a key is configured. **Hard prerequisite for enablement.**
- **Security mode = `developer`** on prod → apply is **immediate** (no approval gate; still reversible + audited). For agency/client use, switch to `client`/`enterprise` so apply → `pending_approval`.
- **No live-key, prod-provider (Rank Math) generation-quality validation** has been run on prod.
- **Deferred UX (cosmetic):** U2 — audit-table badge relabels, score as `/100`, cross-tab "Suggestion ready" indicator; Applied segment-label counts.
- **Scale gap:** cross-page bulk selection (5b) not built — bulk is page-/selection-scoped (≤25 / current page). No unbounded surfaces remain.
- **Supportability:** no usage/cost metering, no error telemetry (relies on UI notices + append-only audit log).

## Completed (SEO Meta Generator — UX feature-complete)
- ✅ **Contextual SEO Quick Panel (Option B)** — COMPLETE & deployed this session (in-context AJAX modal; propose-only; no backend/route/invariant change).
- ✅ **Sprint A — Contextual Row Actions** — COMPLETE & deployed (`a69a2d3`).
- ✅ **Sprint B — Bulk Actions** — COMPLETE & deployed (`15bcd6d`).
- ✅ **Editable-status support** — COMPLETE & deployed. The row action / Quick Panel / bulk / REST generate path now offer suggestions for **publish · draft · pending · future · private** (shared `SeoMetaGenerator::is_supported_status()` allow-list); trash/auto-draft/revisions skipped as `unsupported_status`. (This fixed the "missing on Posts/Products" report — those lists were draft-heavy.)
- ✅ **Trust Polish** (`d934859`) — COMPLETE & deployed (2026-06-21): persist-before-apply + reversibility toast + single shared rollback path. Dormant (flag OFF).
- ✅ **Quick Panel Apply + Undo (Option B)** (`5db577a`) — COMPLETE & deployed (2026-06-21): Quick Panel is now a complete governed action (Generate→Review→Edit→Apply→Undo), mode-aware, existing routes only. Dormant (flag OFF).

With the Quick Panel, the SEO Meta Generator is **UX feature-complete**: audit → generate (publish/draft/pending/future/private) → review/edit → approve & apply → undo → page-scoped bulk → contextual row/bulk entry → in-context Quick Panel. Every surface preserves the Four Guarantees.

## Remaining roadmap (ranked by business impact)
1. **Production Enablement** (configure AI key + flip `WPCC_SEO_META_UI`) — unlocks all shipped value; **gated on the key + validation**.
2. **Real-world Validation** (prod Rank Math + real key; approval + rollback round-trips) — prerequisite to public beta.
3. **Cross-page Selection (5b)** — agency-scale bulk (server-resolved select-all-matching → existing per-item governed apply).
4. **Design System / Modern UI** — identity/trust polish (Phase C foundations).
5. **Bulk Undo (5c)** — convenience.
6. **Editor Metabox / Rank Math / Yoast** in-metabox — deferred (complexity / provider coupling).

## Recommended next step
Both the Quick-Panel-First Safe SEO v1 (`5db577a`) **and** the AI Content Platform (`fc70b2d`) are now **deployed but dormant**. The recommended next step is unchanged: the **next major initiative: Controlled Production Enablement** (Execution Plan Step 5 = Roadmap #1–#2 Production Enablement + Real-world Validation) — a **product/config call** (AI key on prod + live-key/prod-provider [Rank Math] validation + security-mode posture), not an engineering task. Enablement would now surface the full governed AI content family (SEO · Alt Text · Title · Excerpt) under the build flags. The enablement plan is ready to produce as a report on request (no enablement actions without explicit direction). **Roadmap priorities are unchanged.** **Do not start a new feature (incl. Experience Layer / Initiative 3) or perform enablement without explicit direction.**

---

# 🚀 NEXT SESSION START HERE

## Current architecture state
- **Production baseline = `fc70b2d`** (`v0.109.0-35-gfc70b2d`); origin == prod == local, working tree clean except this handoff doc.
- The **AI Content Platform is DEPLOYED but DORMANT** (`fc70b2d`): four governed AI content workflows (SEO · Alt Text · Title · Excerpt) on one generalized **Governed Action Panel** + the **AI Content Builder**, built on top of the still-dormant **Quick-Panel-First Safe SEO v1** (`5db577a`). All Builder build flags OFF (`WPCC_SEO_META_UI` / `WPCC_AI_CONTENT_UI` / `WPCC_ALT_TEXT_UI` undefined) → no customer-facing exposure. The governed pipeline (Propose → capability → approval → execute → audit → reversible) is intact across every surface; one execution path, one rollback path. No new route/operation/capability/MCP tool/schema; invariants 34/23/40/40/2.5.0.
- **Contextual entry points:** the row action is a nonce-signed `<a href="admin-post.php?action=wpcc_seo_generate&post=ID">` (no-JS fallback) handled by `SeoRowActions::handle()` → `SeoMetaGenerator::generate([id])` (drafts only) → `wp_safe_redirect` to SEO Meta → Suggestions; when JS is present the **Quick Panel** intercepts the click and shows Current vs Suggested in an in-context modal (same generator, drafts only). Bulk uses `bulk_actions-edit-{type}` / `handle_bulk_actions-edit-{type}` → same generator → redirect with counts.
- **Reusable governed routes available to admin JS** (cookie + `wp_rest` nonce, gated by `check_seo_permission` = `manage_options` + `FeatureGate('seo_meta_generator')`):
  - `POST /wp-command-center/v1/admin/seo/generate` — body `{post_ids:[…]}` → `{created:[proposal_id…], skipped:[{post_id,reason}], failed:[{post_id,code,message}], provider, model, batch_id}`.
  - `GET /wp-command-center/v1/admin/proposals/{id}` (36-char uuid) → shaped proposal incl. `payload`, `final_payload`, `prior`, `provider`, `model` (the suggested vs current text).
  - `GET /wp-command-center/v1/admin/proposals?status=draft&operation_id=seo_manage&…` — list.

## Current production readiness assessment
- **Engineering: production-ready and deployed** (v1 core live + dormant at `5db577a`; all suites green; T0/T1 `--changed` net-new 0; serial T2 net-new 0 attributable; Playwright dev+client verified; no prod fatals).
- **Enablement: NOT yet** — blocked on (1) **AI key on prod** (`WPCC_ANTHROPIC_API_KEY` unset), (2) **live-key/prod-provider (Rank Math) validation**, plus a **security-mode** posture choice (prod = `developer`). These are config/validation, not code defects.
- **Recommendation:** validate first, then enable. No UX work remains before enablement.

## Next recommended task — Controlled Production Enablement (next major initiative; product/config call)
The Quick-Panel-First Safe SEO v1 **core is deployed but dormant**. Per **Roadmap v3** (below, priorities unchanged), the next step is a **product/config decision**, not engineering — the **Controlled Production Enablement** initiative (Execution Plan Step 5):
1. **Production Enablement** — configure an AI key on prod (`WPCC_ANTHROPIC_API_KEY`) and flip `WPCC_SEO_META_UI` (controlled, scoped — not a public beta) to surface the already-deployed dormant Builder + row/bulk/Quick Panel (now Apply/Undo-capable) entry points. The flag is the kill switch.
2. **Real-world Validation** — with the prod provider (Rank Math) + a real key, exercise generate → approve/apply → undo round-trips; pick a security-mode posture (`developer` immediate vs `client`/`enterprise` → `pending_approval`).

A report-only **Controlled Production Enablement plan** is ready to produce on request. After validation+enablement, Roadmap v3 sequences: **onboarding/activation → thin premium Experience Layer → agency white-label "AI activity" report → enable Alt Text → WooCommerce governed product workflow → Free/Pro seam.** See the Roadmap v3 and Experience Layer Vision sections below.

> **EXPLICIT HOLD (per product owner):** do **NOT** start new feature work without direction — specifically **NOT** the remaining v1 polish steps (mobile bottom-sheet, Client Activity Report), WooCommerce workflow, Experience Layer implementation, Design System work, or Alt Text expansion, and **NOT Initiative 3**. Do **NOT** enable the SEO UI or set the AI key without explicit direction. The Quick Panel Apply/Undo deploy (`5db577a`) is the last shipped item.

## Guardrails that still apply to any next SEO work
- **Reusable governed routes for admin JS** (cookie + `wp_rest` nonce, gated by `check_seo_permission` = `manage_options` + `FeatureGate('seo_meta_generator')`): `POST /admin/seo/generate {post_ids:[…]}`; `GET /admin/proposals/{id}` (shaped: `payload`/`final_payload`/`prior`/`provider`/`model`); `GET /admin/proposals?status=draft&operation_id=seo_manage`.
- **Four Guarantees are non-negotiable** — every mutation flows through `ProposalApplyService`/`OperationExecutor`/`change_history`; Propose ≠ Apply.
- **Invariants frozen: 34 / 23 / 40 / 40 / 2.5.0.** No new route / admin-ajax / operation / capability / MCP tool / schema / DB_VERSION without an explicit, reviewed decision.
- **Backend contract files that stay byte-identical for UI-only work:** `AdminRestApi`, `ProposalStore`, `ProposalAdminQuery`, `OperationExecutor`, `ChangeHistoryRuntimeManager`, `SeoRuntimeManager`, `Schema`, `CapabilityRegistry`, `OperationRegistry`, `McpServerRuntime`.
- **Test discipline:** `tests/run.sh --tier T0|T1 --changed` net-new 0 → pristine serial **T2** before deploy. Note: run.sh's auto net-new over-reports on dev-with-key (alt-text / proposal-admin) and under serial pollution (change-history-rollback / safe-search-replace) — triage standalone before treating as a regression. **Run only ONE T2 at a time** — concurrent runs share the dev DB and mutually pollute results.

---

# 🧭 PRODUCT ROADMAP v3 (strategy — adopted this session)

> Supersedes Roadmap v2. Optimized for adoption, differentiation, commercial viability, premium perception, and long-term category leadership — not engineering purity.

## Positioning
- **Category (architecture):** Safe AI Operations / governance layer for WordPress. **Category (buyer language):** *"The safe way to run AI on WordPress — especially on client sites."* Governance is the **proof/mechanism**, not the headline. Do not market "governance platform" to SMB/agencies.
- **Headline:** *Run AI on WordPress without breaking your site* — review, approve, undo, and audit everything AI does (for you and the agents you connect).
- **SEO is the wedge, not the product.** The product is the safe multi-domain AI execution layer (SEO → media → Woo → ops), all on one trust spine, plus the **MCP agent-governance gateway**.

## The real moat (ranked)
1. **Governed single chokepoint** — every mutation reversible + audited; hard to retrofit onto direct-apply competitors (Rank Math AI / Yoast AI / AI Engine).
2. **MCP agent-governance gateway** — the agentic-era position; most future-proof, currently most undervalued.
3. **Data gravity** — audit log + change history + rollback snapshots become an agency system-of-record (switching cost).
Generation quality is **not** a moat (BYO-Anthropic). Compete on trust + breadth + agent-readiness, never on raw generation.

## Feature priority ranking (1–20)
1. SEO real-world validation on prod *(blocker)* · 2. SEO enablement (controlled) · 3. Onboarding / time-to-first-governed-action · 4. **Thin premium Experience Layer** (shell/dashboard/trust chips/Builder mode — NOT a full CDS) · 5. **Agency white-label "AI activity" report** · 6. Client approval workflow polish · 7. Reversibility-as-hero (visible one-click undo) · 8. Alt Text enablement (accessibility/compliance) · 9. **WooCommerce governed product content workflow** *(next major build)* · 10. Free/Pro seam + licensing groundwork · 11. Command palette + in-context premium interactions (absorbs "Quick Panel V2") · 12. Scheduling / recurring governed ops (Pro) · 13. MCP agent-governance UX + dev docs · 14. Audit export / compliance reporting · 15. Cross-page bulk 5b *(demand-gated)* · 16. Bulk Undo 5c *(demand-gated)* · 17. Site Intelligence / issue detection · 18. Multi-site / fleet · 19. Additional AI providers (post-validation) · 20. Full versioned CDS (last).

## Reject / avoid (now)
Generic blog writer · chatbot · "AI buttons everywhere" · any direct-apply/ungoverned path · multi-provider before validation · MCP tool-count as a metric · full CDS rewrite now · multisite fleet/marketplace now · standalone "Quick Panel V2" project · ungated bulk-scale before demand.

## Free vs Pro
- **Free (trust + adoption):** governance core (approval/rollback/audit/capability scoping/change history) · read-only audits (SEO/alt-text/site-intel) · single-item governed actions · MCP gateway (rate-limited) · security modes · reversibility-as-hero.
- **Pro (scale + agency + automation):** bulk/cross-page (5b/5c) · scheduling/recurring ops · WooCommerce workflow (or gate at volume) · **agency white-label client reports, roles/delegation, multi-site** · audit export/compliance · policy templates · AI usage metering · white-label · priority support. Rides the existing FeatureGate seam. Keep the safety promise free.

## Roadmap horizons
- **1 week:** finish + deploy Draft Support *(DONE this session — `c87ce08`)*; prod AI key + real-world validation; security-mode posture decision; enable SEO Builder (controlled).
- **2 weeks:** onboarding/activation v1; thin premium Experience Layer pass on SEO surfaces; agency white-label report v1 + client-approval polish.
- **30 days:** enable Alt Text; begin WooCommerce governed product workflow (report-first); wire Free/Pro seam.
- **90 days / later:** Woo v1; scheduling; command palette; demand-gated bulk; MCP agent-governance UX; audit export; then Site Intelligence, fleet, providers, full CDS.

## Public beta criteria
Real-key prod validation passed (quality + apply→undo in a gated mode; rollback proven on prod) · ≥2 governed workflows live (SEO + Alt Text) · onboarding in place, time-to-first-governed-action minimal · ≥1 premium "wow" · no ungoverned mutation path; invariants stable; T2 net-new 0 · plain-language errors + a feedback/telemetry channel · build flags flipped intentionally with a rollback plan.

---

# 🎨 EXPERIENCE LAYER VISION (premium perception — NOT yet implemented)

> The lever that moves WPCC from "$49 admin plugin" to a premium SaaS agencies pay for. **Vision only — do not implement without direction.** Deliberately a *thin* layer first, NOT the full versioned CDS (#20).

What makes WPCC feel like premium SaaS *inside* WordPress:
- **Branded app shell + one home** — retire the ~12-menu sprawl into a single Command Center entry with the 5-C IA (Overview · Operate · Audit · Access · Connect); kill the two-dashboard split.
- **Onboarding / readiness** with progress (connect agent → token → security mode → run a governed action → undo it).
- **Action-first dashboard** — a "Needs you" approval/queue hero + recent-activity timeline; **reversibility as a first-class, visible affordance** (one-click Undo column), not buried in Change History.
- **In-context, no-reload interactions** — modals (the Quick Panel pattern), optimistic UI, toasts, live status dots; **command palette (⌘K)**.
- **Builder vs Engineer mode** — plain-language outcomes vs system detail over shared data; density + disclosure toggle persisted per user; maps to security modes.
- **Trust signals everywhere** — "reversible · audited · provenance" chips, actor identity (human/system/agent), security-posture pill.
- **Agency white-label client report** — shareable "what AI did this month, all reversible" summary; turns the audit log into a billable deliverable.
- **Polished voice + states** — empty/loading/skeleton states, no raw error codes to Builder users.

Sequencing: ship a **thin** Experience Layer on the SEO surfaces first (priority #4), prove premium perception + pricing power, then generalize. The **full versioned Command Design System (CDS)** remains the *last* item (#20) — do not start it early.

---

# 📋 NEW CHAT BOOTSTRAP PROMPT (copy-paste to resume)

```
WP Command Center — resume from SESSION-HANDOFF-2026-06-18.md

Read docs/product/SESSION-HANDOFF-2026-06-18.md in full, especially the
"CONSOLIDATED PRODUCTION STATE" and "NEXT SESSION START HERE" sections.

Authoritative state to confirm before any work:
- Production feature baseline = fc70b2d (git describe v0.109.0-35-gfc70b2d);
  git HEAD = 5933b84 (a docs-stamp past the feature baseline). origin/main ==
  local == prod == 5933b84; tree clean. (AI Content Platform 39a678f -> 21ebb45
  -> dcc9dd1 -> fc70b2d, fast-forward merged on top of Quick-Panel-First Safe SEO
  v1 5db577a; lineage c87ce08 -> 343d720 -> 5db577a -> fc70b2d.)
- Invariants FROZEN: OPERATION_MAP 34 · capabilities 23 · catalogue 40 ·
  MCP tools 40 · DB_VERSION 2.5.0 (14 wp_wpcc_* tables, no migration).
- Deploy = Hostinger pull-cron: `git push origin main` -> live ~1 min.
  SSH: ssh -p 65002 u916998506@72.62.68.183 ;
  prod path ~/domains/mosharafmanu.com/public_html/wp-content/plugins/wp-command-center ;
  prod REST namespace = wp-command-center/v1 ; runbook .ai/DEPLOY.md.
- AI Content Platform DEPLOYED but DORMANT: four governed AI content workflows
  (SEO, Alt Text, Title, Excerpt) on ONE config-driven Governed Action Panel
  (assets/js/wpcc-action-panel.js + wpcc-admin-runtime.js + wpcc-tokens.css;
  Generate -> Review -> Edit -> Apply -> Undo, mode-aware, persist-before-apply,
  mobile bottom-sheet, a11y) + the AI Content Builder (views/ai-content.php,
  menu wpcc-ai-content). Title/Excerpt generation rides the EXISTING
  POST /admin/proposals generate branch ({generate:{kind,post_id}}, Option 1, no
  new route); SEO uses /admin/seo/generate, Alt uses /admin/alt-text/generate.
  Contextual entry: ContentRowActions (Title/Excerpt on Posts/Pages/Products),
  MediaRowActions (Alt Text on the Media Library), SeoRowActions (SEO). All gated
  by build flags WPCC_SEO_META_UI / WPCC_AI_CONTENT_UI / WPCC_ALT_TEXT_UI (all
  UNDEFINED on prod -> hidden) + per-surface FeatureGate. Prod has NO AI key and
  security_mode=developer; provider=Rank Math. One execution path
  (ProposalApplyService), one rollback path (/admin/history/{id}/rollback).
- Local dev enables Builders via wp-content/mu-plugins/wpcc-dev-*.php (outside the
  repo): wpcc-dev-seo-meta-ui.php + wpcc-dev-alt-text-ui.php exist; AI Content
  (wpcc_ai_content_ui filter) has NO dev enabler -> add one to surface
  Title/Excerpt. Tests: tests/run.sh --tier T0|T1 --changed ; gate net-new 0 vs
  tests/regression-baseline.tsv ; full serial T2 before deploy. New suites:
  test-ai-content, test-ai-content-builder, test-contextual-entry,
  test-seo-quick-panel (rewritten for the generalized panel).
- Visual checks: Playwright + Chrome are available; authenticate by minting an
  auth+logged_in cookie for the admin via wp eval (no password). Local site:
  http://localhost/ClientProjects/WordPress/2026/plugins-dev .

NEXT STEP (no new feature without direction): both Quick-Panel-First Safe SEO v1
(5db577a) AND the AI Content Platform (fc70b2d) are DEPLOYED but DORMANT. Per
Roadmap v3 (in this doc, priorities unchanged) the next major initiative is
CONTROLLED PRODUCTION ENABLEMENT (Execution Plan Step 5) — a PRODUCT/CONFIG call,
not engineering:
  1. Production Enablement — set WPCC_ANTHROPIC_API_KEY on prod + flip the build
     flag(s) (WPCC_SEO_META_UI / WPCC_AI_CONTENT_UI / WPCC_ALT_TEXT_UI, controlled,
     scoped) to surface the already-deployed (dormant) Builders + row/bulk/Quick
     Panel entry points across SEO/Title/Excerpt/Alt. The flags are the kill switch.
  2. Real-world Validation — prod provider (Rank Math) + real key: generate ->
     approve/apply -> undo round-trips; choose security-mode posture.
A report-only Controlled Production Enablement plan is ready on request.
Non-blocking follow-ups (NOT started): a11y polish (move focus into the dialog on
open), Quick Panel chip polish, Client Activity Report; legacy ai-alt-text.php tab
page not yet re-skinned onto the tokens/runtime (works as-is). Roadmap v3 order
after enable: onboarding -> thin premium Experience Layer -> agency white-label
report -> WooCommerce workflow -> Free/Pro seam. EXPLICIT HOLD: do NOT enable any
UI or set the AI key, and do NOT start the non-blocking follow-ups, WooCommerce
workflow, Experience Layer implementation, Design System work, or Initiative 3
without direction. Preserve the Four Guarantees and frozen invariants
34/23/40/40/2.5.0; no new route/admin-ajax/operation/capability/MCP tool/schema
without a reviewed decision.

Work report-first: architecture verification -> implementation -> tests
(focused + T0/T1 --changed net-new 0) -> Playwright visual -> commit on approval
-> push -> pull-cron deploy -> production verification. Do not change build flags
or enable the Builder UI on prod unless explicitly asked.
```

*Handoff consolidated; documentation only — no code, no commits, no deploy in this update.*

---

# Experience Layer + Performance quick-win — DEPLOYED to production (2026-06-21)

Two workstreams landed as three clean commits on `main`, pushed, and deployed via the
Hostinger pull-cron. **Production HEAD = `df7a806`** (`git describe` = `v0.109.0-40-gdf7a806`);
`origin/main == prod`. Deploy log: `2026-06-21T16:53:07Z DEPLOYED b057385 -> df7a806 active=yes`.

## Commits (in order)
1. **`8dd4cfe`** — `perf(admin): memoize operation catalogue and WP-CLI availability`
   `includes/Operations/OperationRegistry.php`, `includes/Operations/WpCliBridge.php`,
   `includes/Core/Plugin.php`. Per-request static memo of `get_operations()` + O(1)
   `get_operation()` id-index + memoized `WpCliBridge::is_available()`; memo busted on
   `activated_plugin`/`deactivated_plugin`/`switch_theme`. In-memory only (NO transient).
   Behavior-preserving (catalogue byte-identical cold/warm/rebuilt; unknown-op → null).
2. **`445483f`** — `feat(cds): add Command Design System token + component foundation`
   `assets/css/wpcc-tokens.css` (additive tiers: risk/actor/density), `assets/css/wpcc-cds.css`
   (new), `assets/js/wpcc-cds.js` (new). Dormant: unreferenced until enqueued.
3. **`df7a806`** — `feat(admin): Experience Layer — 5-C navigation, unified Home, CDS shell`
   `AppShell.php` (new), `AdminMenu.php`, `Assets.php`, `views/command-home.php` (new),
   `views/dashboard.php` (relocated/trimmed → Operate › Runtime), `views/dashboard-overview.php`
   (deleted), + 11 test/infra files (`test-experience-layer.sh` new; sibling admin suites
   repointed to AppShell; `regression-map.tsv` +experience group; `run.sh` pipefail/grep-q fix).

## What shipped (functional summary)
- **5-C IA**: ~12 flat submenus collapsed to Overview · Operate · Audit · Access · Connect,
  rendered by `AppShell` over the existing views via `?wpcc_tab=`; legacy slugs 302-redirect
  in (args preserved) — redirect runs on **`admin_menu` priority 0** (core 403s unregistered
  slugs before `admin_init`).
- **Unified Command Center Home** (`command-home.php`) replaces the two-dashboard conflict;
  legacy operational dashboard relocated to Operate › Runtime, trimmed of the op-request/queue
  write panels (now owned by the Approval Center).
- **CDS** tokens + components + runtime, enqueued in the HEAD (so body view scripts can use
  `window.WPCC` and never hang on "Loading").
- **Perf**: `get_operation()` ×25 4937ms→199ms (dev); `/admin/approvals/history` 5.91s→0.80s,
  `/admin/dashboard` 1.60s→0.83s (dev HTTP).

## Production verification (SSH wp-cli + anonymous HTTP, 2026-06-21)
- prod HEAD `df7a806` == origin == local; plugin **active**; no fatals / no `debug.log` issues.
- Anonymous: homepage **200**, `/wp-json/` **200**, REST ns `wp-command-center/v1` **200**,
  `/admin/dashboard` + `/admin/approvals/summary` **401**, admin page **302** (login) — no
  customer-facing exposure.
- Invariants **34 / 23 / 40 / 40 / 2.5.0**; DB_VERSION **2.5.0**; **14** `wpcc_*` tables (unchanged).
- **Dormant**: build flags `WPCC_ALT_TEXT_UI`/`WPCC_SEO_META_UI`/`WPCC_AI_CONTENT_UI`/
  `WPCC_PROPOSALS_DEV_UI` all **undefined** → AI surfaces hidden (Operate tabs =
  approvals/operations/runtime only). **AI key NOT set.** **Security mode = developer** (unchanged).
- Experience Layer smoke (server render via wp-cli): all 5 sections render with shell chrome,
  no fatals; File Access renders; legacy redirects fire (`wpcc-tokens&tab=capabilities` →
  `wpcc-access&wpcc_tab=tokens&tab=capabilities`, `wpcc-file-access&path=themes` →
  `wpcc-connect&wpcc_tab=files&path=themes`, `wpcc-change-history` → `wpcc-audit&wpcc_tab=changes`)
  — **no 403** on Capabilities/Operation Map. **`approvals_history(25)` on prod = 7ms** (was ~5s).
- No route/operation/capability/MCP/schema growth.

## Status & known follow-ups (NOT started; no roadmap-priority change)
- **Dormant**: nothing enabled, no AI key, security mode unchanged. Do NOT enable without direction.
- Follow-ups (deferred, report-first when picked up):
  1. **Runtime lazy-load** — Operate › Runtime still renders ~6s (40 synchronous queries +
     `information_schema` size query + registry build on render); lazy-load its metric cards / cache the size query.
  2. **Optional transient WP-CLI availability cache** — would shave the remaining single ~200ms
     probe off dashboard/operations; deliberately NOT added (per-request memo only) — report-first.
  3. **`operation_results` (59MB / 22.7k) + `change_log` (40MB / 69k) retention/rotation**
     (mirror AuditLog rotation); add `LIMIT` to the two `LIMIT`-less SELECTs.
  4. **Minor a11y focus-on-open polish** in the shell/home if still relevant.

*Production baseline now `df7a806`. Documentation update only in this commit.*
