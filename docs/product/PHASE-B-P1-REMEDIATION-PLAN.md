# WP Command Center — Phase B P1 Remediation Plan

> **Status:** Report-only remediation plan. **No code, no commits, no deploy.** Implementation begins only on explicit direction, item-by-item.
> **Date:** 2026-06-18 · **Baseline:** `v0.109.0` (`079496a`) = origin = production HEAD; local tree +1 docs-only commit. Phase A complete, read-only.
> **Companion docs:** [`SESSION-HANDOFF-2026-06-18.md`](SESSION-HANDOFF-2026-06-18.md) · [`PRODUCT-MASTER-PLAN.md`](PRODUCT-MASTER-PLAN.md) · [`UX-AUDIT-AND-DESIGN-SYSTEM.md`](UX-AUDIT-AND-DESIGN-SYSTEM.md)
> **Scope:** the P1 dependency chain **W2 → D1 → S1 → S2 → S3 → C1 → C3** only. UX-* and M1 are Phase C; W1/W3/C2 ride along where noted.

---

## 0. Non-negotiables (apply to every item below)

**P0 — The Four Guarantees must never regress:** Approval · Rollback · Audit · Capability Scoping.
**Invariants must hold at every commit:** OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.4.0**. Any item that would move a number is out of scope for Phase B.
**Phase A stays read-only:** no write/POST routes, no `OperationExecutor` dispatch, no second execution path may be introduced by hardening work.
**Test gate, per item:** `--changed` T0/T1 **net-new 0** vs `tests/regression-baseline.tsv` → pristine **serial T2** net-new 0 → deploy on explicit direction (pull-cron, ~1 min live). The chronic 24-suite baseline is matched suite-for-suite; "net-new 0" is the only acceptance bar.

**Grounding (verified against the current tree, 2026-06-18):**
- `includes/Admin/AdminRestApi.php` — **1169 lines**, 26 routes, **6 permission callbacks**. Five are `current_user_can('manage_options') && FeatureGate::allows('<key>')`; `check_permission()` is `manage_options` only. Feature keys in use: `change_history`, `approval_center`, `token_capability_manager`, `operations_explorer`, `dashboard_overview`.
- `includes/Admin/FeatureGate.php` — single static seam `allows(string $feature): bool`, ungated (returns `apply_filters('wpcc_feature_allowed', true, $feature)`).
- `includes/Admin/DashboardAdminQuery.php::overview()` — fans out to `ApprovalAdminQuery::summary()`, `OperationExplorerAdminQuery::summary()`, `TokenCapabilityAdminQuery::tokens()/capabilities()`, `ChangeHistoryAdminQuery::sessions()`. Gated by `check_dashboard_permission()` (`dashboard_overview` key) **only** — it never re-checks the four sub-surface gates. **← W2.**
- `includes/Operations/OperationRegistry.php::get_operations()` — **1073 lines**, one inline array with embedded `is_plugin_active()` / `class_exists()` probes; **no transient, no static memoization, no cache**. **← W1/S1.**
- `includes/Admin/OperationExplorerAdminQuery.php` — exposes `summary()` only; the view (`operations-explorer.php`, 449 lines) loads the full catalogue client-side and filters in JS. **No `LIMIT`/offset/cursor.** **← S2.**
- `includes/Admin/TokenCapabilityAdminQuery.php` — `tokens()` unbounded; `access_matrix()` iterates `OPERATION_MAP` **per token** → `O(tokens × operations)`. **← S3.**
- Copy-pasted view JS: `escHtml` ×5, `apiFetch` ×5, `setHtml` ×3, `sprintf` ×3, `fmtTime` ×2 across `includes/Admin/views/*.php` (5 surfaces, 2838 lines total). **← D1.**

---

## 1. Why this order (the dependency chain)

The seven items are not independent; sequencing minimizes rework and front-loads the only guarantee-breaching item.

```
W2  ── defines per-surface gate semantics (introduces a gate-resolution helper)
 │       and closes the one latent scoping breach FIRST.
 ▼
D1  ── extracts the shared view substrate (JS) BEFORE any surface churn, so S2's
 │       view changes don't re-duplicate helpers across files.
 ▼
S1  ── caches the catalogue build. Foundational: S2 reads the catalogue and S3
 │       iterates OPERATION_MAP/catalogue — both get cheap once S1 lands.
 ▼
S2  ── server-side pagination for Operations Explorer + Tokens list. Needs the
 │       shared data-grid/fetch (D1) and the cheap catalogue (S1).
 ▼
S3  ── token access-matrix scaling. Needs cheap OPERATION_MAP (S1) and a bounded
 │       token list (S2); matrix moves to per-detail, list stays bounded.
 ▼
C1  ── consolidates the 6 permission callbacks into one parametrized resolver,
 │       absorbing the W2 gate-resolution helper as the canonical seam.
 ▼
C3  ── consolidates the duplicated security-mode presenter; smallest, rides last
         after the AdminQuery classes have settled (S2/S3 touched them).
```

**Ride-alongs:** **W1** (catalogue-as-registry refactor) is the structural sibling of **S1** — S1 ships the caching layer; W1's per-op registration is staged behind it without changing the public `get_operations()` contract. **C2** (dead `OperationRegistry` instantiation in `ApprovalAdminQuery`) is deleted opportunistically during the S1 work. **W3** (least-privilege viewer role) is acknowledged here but is a capability with product surface — it is **deferred to its own Phase B sub-step** after C3, not folded into this chain.

---

## 2. W2 — FeatureGate coherence gap (aggregator does not re-check sub-surface gates)

**Problem statement.** `DashboardAdminQuery::overview()` fans out to four owning AdminQuery classes (Approvals, Operations, Tokens, Change History) and composes their summaries. The route guard `check_dashboard_permission()` only verifies `FeatureGate::allows('dashboard_overview')`. It never re-checks `approval_center`, `change_history`, `token_capability_manager`, or `operations_explorer`. Today every gate returns `true`, so nothing leaks. The moment licensing (Phase F) turns a sub-surface gate **off** while `dashboard_overview` stays **on**, the Dashboard will surface counts/activity from a feature the edition has disabled.

**Product impact.** This is the seam the entire Free/Pro story rides on. If the aggregator can leak a gated feature's data, the gating model is not trustworthy — and "trust" is the product's whole position. Fixing it now makes the FeatureGate seam *coherent by construction* so Phase F can flip gates without auditing every aggregator.

**Four Guarantee impact.** **Capability Scoping** — this is the one item in the chain that is a *latent breach of a guarantee*, not merely perf/maintainability. A gated-off sub-surface is, in least-privilege terms, out of scope; surfacing its data through an un-re-checked aggregator violates scoping. Approval / Rollback / Audit: untouched (read-only path, no execution, no records written).

**Blast radius.** Small and admin-only. `DashboardAdminQuery::overview()` + `check_dashboard_permission()` (and a small gate-resolution helper). No engine, no `OperationExecutor`, no MCP, no DB schema, no runtime REST. Invariants untouched. The behavioral change is **only observable when a sub-surface gate is false** — i.e., zero observable change today (all gates open).

**Implementation strategy.** Per-section gate-awareness in the aggregator: before composing each sub-surface's block, check that sub-surface's own feature key; when a gate is closed, omit that block (or return it as an explicit `{gated:true}` marker the view renders as "unavailable in your edition") rather than its data. Introduce a single **gate-resolution helper** (feature-key → bool, plus the surface→key map) so the aggregator and the route guards share one source of gate truth — this helper is the artifact C1 later consolidates around. The Dashboard's own invariant strip (catalogue/op-map/caps counts) is platform metadata, not sub-surface data, and stays.

**Migration strategy.** None for data/schema. Because all gates are open, the deployed behavior is byte-identical today; the fix is a *latent-correctness* change. Add the gate-coherence contract to the doc seam in `FeatureGate.php` so future surfaces inherit the rule. No redirects, no slug changes.

**Test strategy.** New cases asserting the aggregator with each sub-surface gate forced **off** via the `wpcc_feature_allowed` filter: the gated block is omitted/marked and no gated data appears in the envelope; with all gates on, the envelope is unchanged from baseline. Extend `test-dashboard.sh`. Gate: `--changed` T0/T1 net-new 0 → serial T2 net-new 0.

**Rollback strategy.** Pure revert of the aggregator + helper commit; no data or schema migration to unwind, so rollback is a `git revert` + pull-cron. Because today's behavior is unchanged, rollback risk is effectively nil.

**Commercial impact.** **Direct enabler of Phase F.** Removes the one defect that would otherwise let a Pro-gated surface leak through the Free dashboard — protecting both revenue (no free access to gated value) and the trust narrative (gating is honest). Highest commercial leverage per line changed in the chain.

---

## 3. D1 — Shared view substrate (duplicated view-layer JS)

**Problem statement.** Each Phase A view re-implements the same client helpers — `escHtml` (5 files), `apiFetch` (5), `setHtml` (3), `sprintf` (3), `fmtTime` (2) — plus badge/risk/actor renderers. Any a11y, i18n, or security fix (e.g. an escaping correction) is an N-place edit, and the surfaces already drift. This is the largest duplication in Phase A (M2 is the same root).

**Product impact.** Blocks *consistent* a11y/i18n and is the precondition for the CDS component kit (Phase C). Until there is one substrate, "certification-ready, consistent behavior across surfaces" is not achievable.

**Four Guarantee impact.** Indirect but real on **Audit/Capability** *presentation*: actor chips, risk pills, and escaping are how provenance and scope are shown. Centralizing them removes the risk that one surface escapes/labels incorrectly. No engine path touched.

**Blast radius.** All five Phase A view files + a new shared admin JS module (enqueued via `includes/Admin/Assets.php`). Presentation-only; no PHP query, REST, engine, schema, or invariant change. Risk is *visual/behavioral regression per surface*, mitigated by migrating one surface at a time.

**Implementation strategy.** Extract one versioned shared module (escaping, fetch-with-nonce, DOM set, `sprintf`/format, time, and the badge/risk/actor renderers) as the **CDS v0 precursor**. Keep the public per-view entry points identical; the views consume the shared helpers instead of private copies. No framework introduced — same vanilla approach, deduplicated.

**Migration strategy.** Strangler, **one surface per commit**: migrate a view to the shared module, delete its local copies, verify parity, move on. Order from simplest (dashboard-overview) to most complex (approval-center). Each commit independently revertable; the module is additive until the last local copy is removed.

**Test strategy.** Per-surface admin suites (`test-dashboard.sh`, `test-operations-explorer.sh`, `test-approval-center.sh`, `test-change-history-admin.sh`, `test-token-capability-*.sh`) must stay green after each migration; add assertions that escaping/format helpers behave identically (XSS-escaping cases especially). `--changed` per surface, then full admin-suite serial pass before the final dedup commit.

**Rollback strategy.** Per-surface revert restores that view's local helpers (kept until its migration commit). Because migration is incremental, a regression on surface N never blocks surfaces 1..N-1.

**Commercial impact.** Foundational for the CDS (Phase C) and therefore for the multi-plugin family identity (the "do-not-fork" design language). Reduces per-feature build cost going forward. No direct revenue, high platform-leverage.

---

## 4. S1 — Catalogue caching (stop rebuilding + re-probing every request)

**Problem statement.** `OperationRegistry::get_operations()` rebuilds a 1073-line inline array and re-runs availability probes (`is_plugin_active`, `class_exists`, WP-CLI presence) on **every** request — including the admin hot path, where `DashboardAdminQuery` and `OperationExplorerAdminQuery` both trigger a build. Uncached. This is the root scalability blocker for the 40→200+ operation roadmap.

**Product impact.** The platform's stated future is 200+ governed operations. At that size, an uncached per-request rebuild + probe is a latency and cost problem on the most-visited surfaces. Caching is the prerequisite for the catalogue to scale without UX degradation.

**Four Guarantee impact.** **None directly** — but availability probing feeds what is *offered*; caching must never cause a stale "available" to let an op run that should be gated. The cache stores catalogue *shape*; capability/approval checks remain live at execution time (engine path unchanged), so scoping/approval are unaffected by definition.

**Blast radius.** `OperationRegistry` internals + cache invalidation hooks (plugin activate/deactivate, switch_theme, upgrader complete). Read by admin AdminQuery classes, the MCP runtime, and the engine. **The public `get_operations()` contract and the catalogue count (40) must not change** — this is a transparent memoization, not a re-shape. Higher inherent risk than W2 because many readers depend on it; contained by keeping the return value identical.

**Implementation strategy.** Memoize within the request (static) and across requests (transient or object cache) keyed on a fingerprint of the environment that the probes read (active plugins set, theme, WP-CLI availability, plugin version). Invalidate on the activation/deactivation/upgrade hooks. **W1 ride-along:** stage per-operation registration *behind* the unchanged `get_operations()` facade so a later step can split the inline array without a second migration — but Phase B ships only the caching layer, not the W1 re-architecture. Delete the C2 dead `OperationRegistry` instantiation in `ApprovalAdminQuery` while here.

**Migration strategy.** No data/schema migration. Ship caching dark (cache populated, but a kill-switch filter forces a fresh build) so production can validate parity before relying on the cache. Cache auto-warms on first request; no manual step.

**Test strategy.** Assert `get_operations()` is byte-identical cached vs uncached across environment permutations (plugin active/inactive, Woo present/absent, WP-CLI present/absent); assert invalidation fires on the activation/upgrade hooks (stale-availability test is the critical one); assert catalogue count stays 40 and MCP tool count stays 40 (1:1). Extend `test-operations-explorer.sh` + a focused registry/cache suite. `--changed` then **full serial T2** (many readers) before deploy.

**Rollback strategy.** Kill-switch filter disables the cache instantly (forces fresh build = today's behavior) without a deploy; full revert removes the caching layer. Because the cached value equals the fresh value, rollback cannot change correctness.

**Commercial impact.** Unblocks the 200+-op roadmap that underpins Phase D's expanded governed-action catalogue — the operational surface area customers pay for. Indirect but strategically large.

---

## 5. S2 — Pagination consistency (Operations Explorer + Tokens unbounded)

**Problem statement.** `OperationExplorerAdminQuery` exposes only `summary()`; the view loads the entire catalogue client-side and filters in JS. The token list is likewise unbounded. Both diverge from the platform's own cursor-pagination contract (already used by `change_history`). At 200+ ops / many tokens, this is an unbounded payload + client-side filter that doesn't scale and is inconsistent with the rest of the platform.

**Product impact.** Consistency and scale: every list surface should page the same way (the CDS data-grid contract). Inconsistent pagination is both a scale risk and a certification/UX-coherence problem.

**Four Guarantee impact.** None — read-only list shaping. Capability scoping on *who can list* is unchanged (route guards intact).

**Blast radius.** `OperationExplorerAdminQuery` (+ its REST route in `AdminRestApi`), the token-list query/route, and their two views (now on the D1 shared substrate). No engine/schema/invariant change. Depends on **D1** (shared data-grid + fetch) and **S1** (cheap catalogue so server-side paging is inexpensive).

**Implementation strategy.** Add server-side `list()` endpoints with the existing cursor/limit/offset contract (mirror `ChangeHistoryAdminQuery`'s envelope: rows + cursor + total). Move filtering server-side. The view switches from "load all + filter in JS" to "fetch a page." Reuse the shared data-grid's loading/empty/error/no-match states (from D1) so behavior matches other surfaces.

**Migration strategy.** Add the paginated route alongside the existing `summary()` (which Dashboard still uses for counts); migrate the view to the paginated endpoint; retire the client-side load-all only after parity is verified. Bookmarks unaffected (same admin slug). Default page size chosen so existing small installs see no functional difference.

**Test strategy.** Assert page/cursor/limit semantics, total counts match `summary()`, filter parity (server results == old client filter for the same query), and empty/no-match/error states. Extend `test-operations-explorer.sh` + token suite. `--changed` T0/T1 → serial T2.

**Rollback strategy.** Revert the view to the load-all path (the `summary()`/full-list source remained available through migration); paginated routes are additive and can be left dormant or reverted independently.

**Commercial impact.** Scale-readiness for large agency/enterprise installs (many ops, many tokens) — directly supports the agency/enterprise pricing tiers in Phase F. Consistency lowers Phase C CDS migration cost.

---

## 6. S3 — Token-surface scaling (access matrix O(tokens × operations))

**Problem statement.** `TokenCapabilityAdminQuery::access_matrix()` computes a per-operation effective-access row over `OPERATION_MAP` for **every** token, and `tokens()` is unbounded. The cost is `O(tokens × operations)` — super-linear as both grow toward many tokens × 200+ ops.

**Product impact.** The access matrix is a signature trust/transparency view ("what can this token actually do"). It must stay truthful *and* affordable at scale, or it becomes the surface that makes the Tokens page slow for the exact customers (agencies, enterprises) who have the most tokens.

**Four Guarantee impact.** **Capability Scoping — presentation of.** The matrix *renders* effective scope; it must remain byte-truthful after optimization (it reproduces, never re-derives, the platform's real scoping rule). No engine change; the live scoping decision at execution time is untouched.

**Blast radius.** `TokenCapabilityAdminQuery` (matrix + token list) and its route/view. Depends on **S1** (cheap `OPERATION_MAP`/catalogue) and **S2** (bounded token list — the full matrix moves to per-token *detail*, the list carries only a compact allowed/total count).

**Implementation strategy.** Keep the heavy per-operation matrix on the **detail** endpoint (one token at a time) where it's already structured; ensure the **list** endpoint (now paginated via S2) carries only the compact access summary, not a full matrix per row. Memoize the per-scope capability→operation reverse map once per request (it's identical across tokens with the same scope) instead of recomputing per token.

**Migration strategy.** No data/schema change. The list already exposes a compact "allowed/total" count; this step guarantees the expensive matrix is never computed in the list path. Detail view unchanged in output.

**Test strategy.** Assert detail `access_matrix` output is byte-identical before/after; assert list path does **not** compute a full matrix (cost/shape assertion); truthfulness cases across scopes (read-only, system.admin unlock, partial caps). Extend the token suite. `--changed` → serial T2.

**Rollback strategy.** Revert to per-token matrix computation; output parity means no correctness risk on rollback.

**Commercial impact.** Keeps the most access-heavy surface fast for high-token agency/enterprise/multisite installs — the Phase F premium tiers. Protects the "transparent access" trust feature from becoming a performance liability.

---

## 7. C1 — Consolidate the six permission callbacks

**Problem statement.** `AdminRestApi` has six permission callbacks; five are the identical shape `current_user_can('manage_options') && FeatureGate::allows('<key>')`, differing only by the feature-key string, and one (`check_permission`) is `manage_options` only. This is duplication that drifts and obscures the gating model.

**Product impact.** One parametrized gate resolver = one place where the access rule lives, making the gating model auditable and ready for W3 (least-privilege roles) to slot in a capability other than `manage_options` per surface without touching six methods.

**Four Guarantee impact.** **Capability Scoping** — consolidation must be *behavior-preserving*: each route keeps its exact `(capability, feature-key)` pair. This step encodes the W2 gate-resolution helper as the canonical seam, so the aggregator and the routes share one gate-truth source. No widening of access.

**Blast radius.** `AdminRestApi` permission-callback layer only (the same 26 routes, same effective gates). No engine/schema/invariant change. Risk is "a route's gate silently changes" — eliminated by a route→(cap,key) table test asserting parity with today.

**Implementation strategy.** Replace the six callbacks with one resolver parametrized by the surface's `(capability, feature-key)`, fed from the surface→key map introduced in W2. `check_permission`'s `manage_options`-only routes are expressed as `(manage_options, null-gate)` so they remain ungated-by-feature but still capability-checked. This is a precursor seam for M1 (controller split, Phase C) — it does not split the controller.

**Migration strategy.** Pure internal refactor; no route URLs, no client changes. Land after W2 so the resolver encodes final gate semantics.

**Test strategy.** A table-driven test asserting every route's effective `(capability, feature-key)` matches the pre-refactor mapping; gate-off cases per surface (reusing the W2 filter harness) confirm identical deny behavior. `--changed` → serial T2.

**Rollback strategy.** Revert restores the six explicit callbacks; because the mapping is asserted identical, rollback is behavior-neutral.

**Commercial impact.** Indirect: makes W3 (viewer role) and Phase F per-tier gating cheap and low-risk. Maintainability, not revenue.

---

## 8. C3 — Consolidate the duplicated security-mode presenter

**Problem statement.** The security-mode presentation wrapper (`{mode, label}` from `SecurityModeManager`) is duplicated identically in `DashboardAdminQuery::security()` and `OperationExplorerAdminQuery`. Two copies of the same trivial presenter.

**Product impact.** Smallest item; tidies the AdminQuery layer so the security-posture pill is sourced from one presenter (consistent labeling everywhere, ready for the CDS posture-pill component in Phase C).

**Four Guarantee impact.** None (read-only presentation of the existing security mode). Approval semantics live in the engine, not here.

**Blast radius.** Two AdminQuery classes + one shared presenter helper. Trivial; no route/schema/invariant change. Rides last because S2/S3 already touched `OperationExplorerAdminQuery`/`TokenCapabilityAdminQuery`, so the classes are settled.

**Implementation strategy.** Extract a single security-mode presenter (mode key + localized label) and have both AdminQuery classes call it. Output identical to today.

**Migration strategy.** Pure internal dedup; no migration.

**Test strategy.** Assert both surfaces emit identical `{mode,label}` before/after across the three security modes (developer/client/enterprise). Covered by existing dashboard + operations-explorer suites; add a parity assertion. `--changed` → serial T2.

**Rollback strategy.** Revert restores the two inline presenters; output-identical, zero risk.

**Commercial impact.** None directly; minor maintainability + CDS-readiness.

---

## 9. Sequencing & exit criteria

| # | Item | Depends on | Primary risk | Net-new gate |
|---|---|---|---|---|
| 1 | **W2** gate coherence | — | latent scoping (none today) | `test-dashboard.sh` + gate-off filter cases |
| 2 | **D1** shared view substrate | — | per-surface visual regression | all 5 admin suites, one surface per commit |
| 3 | **S1** catalogue caching | (D1 helpful) | stale availability; many readers | full serial T2 (registry/MCP/engine readers) |
| 4 | **S2** pagination | D1, S1 | filter parity | explorer + token suites, parity vs old filter |
| 5 | **S3** token matrix scaling | S1, S2 | matrix truthfulness | token suite, byte-identical detail |
| 6 | **C1** permission resolver | W2 | silent gate change | route→(cap,key) parity table |
| 7 | **C3** security presenter | (S2/S3 settle) | none | dashboard+explorer parity |

**Ride-alongs:** W1 staged behind S1's facade (no Phase-B re-architecture); C2 deleted during S1.
**Deferred to its own Phase B sub-step (not this chain):** **W3** least-privilege viewer role — it is a product capability with its own surface and approval/scoping design, sequenced after C3.

**Phase B P1 exit criteria (all must hold):**
1. Four Guarantees intact — demonstrably (gate-off scoping test passes; no new execution path; audit/rollback untouched).
2. Invariants unchanged: **34 / 23 / 40 / 40 / 2.4.0**.
3. Catalogue caching live with verified parity + working invalidation; 200-op readiness demonstrable.
4. Operations Explorer + Tokens paginate on the platform cursor contract; token matrix bounded.
5. One shared view substrate; per-surface a11y/i18n/escaping behavior consistent (D1 closed at the JS layer; full CDS is Phase C).
6. One gate-resolution seam (W2+C1) and one security-mode presenter (C3).
7. **Net-new 0** vs `tests/regression-baseline.tsv` on pristine serial T2 at the end of the chain.
8. Clean security review pass (the certification stamp gate from the master plan).

---

## 10. What this plan does **not** do

- **No code, no commits, no deploy** — report only; each item builds on explicit per-item direction.
- **No invariant movement** (no new ops/caps/tools/schema) — those belong to Phase D, gated by their own steps.
- **No UX/identity work** (UX-1..8), **no controller split (M1)**, **no CDS component kit** — Phase C.
- **No write/execution path** added to any Phase A surface — they remain read-only through Phase B.
- **No W1 re-architecture beyond the caching facade**, and **W3 is acknowledged but separated** from this chain.

*Report-only. No source files were modified by this plan.*
