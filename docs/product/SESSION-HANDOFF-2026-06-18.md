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
