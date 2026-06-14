# WPCC Regression Strategy Audit (design only — not implemented)

**Date:** 2026-06-14
**Trigger:** Every remediation fix runs the full ~3,400-assertion suite (~8–12 min),
slowing the one-finding-at-a-time loop.
**Scope:** Analyze the current suite, design a tiered strategy. No code changes.

---

## 0. Measured inventory (source of truth)

| Metric | Value |
|---|---|
| Suites (`tests/test-*.sh`) | **85** |
| Total lines | ~14,300 |
| Assertions | ~3,400 |
| Use `curl` → REST/MCP (integration) | **83 / 85** |
| Exercise the MCP endpoint (`/mcp`) | 45 |
| Do live network downloads (`download_url`/remote img) | ~9 |
| No HTTP at all (pure `wp eval`/static) | 2 |
| **Pure unit tests (no WP boot, no HTTP)** | **0** |
| Chronic pre-existing failures | **24**, in **6** suites |

**Headline structural facts**
1. **There is no unit-test layer.** Every suite boots WordPress and drives the
   live REST + MCP stack. "3,400 tests" are integration/acceptance assertions, not
   unit tests. The fastest thing we have is still a full WP boot.
2. **The 24 chronic failures are concentrated** in 6 non-logic suites
   (`ai-client-layer`, `ai-integration-ux`, `claude-integration`,
   `cursor-certification`, `documentation-consistency`, `security-redaction`) —
   they fail on every run and are pure noise during remediation.
3. **Suites map cleanly to runtimes by filename**, so changed-file → suite
   selection is mechanical.

---

## 1. Which suites are relevant per runtime (selection map)

| Runtime / area | Source (`includes/Operations/…`) | Suites |
|---|---|---|
| ACF | `ACFRuntimeManager`, `ACFRegistry`, `AcfSeed` | `acf-runtime`, `acf-runtime-step92`, `acf-seed`, `acf-group-delete-f31` |
| Media | `MediaRuntimeManager`, `MediaRegistry`, `MediaSnapshot`, `MediaImport` | `media-runtime`, `media-runtime-step90`, `media-import`, `media-snapshot-step100-1`, `media-replace-step100-2` |
| SEO | `SeoRuntimeManager`, `SeoProvider`, `SeoRegistry` | `seo-runtime-step91` |
| WooCommerce | `WooCommerceRuntimeManager`, `WooProductSeed` | `woocommerce-runtime`, `woocommerce-product-step93`, `woocommerce-order-step94`, `woo-product-seed` |
| Elementor | `ElementorRuntimeManager`, `ElementorRegistry` | `elementor-step96` |
| Workflow | `WorkflowRuntimeManager` | `workflow-runtime`, `workflow-step97`, `workflow-rollback-f61`, `workflow-dataflow-f62` |
| Content | `ContentManager`, `ContentSeed` | `content-runtime`, `content-seed` |
| Site Builder | `SiteBuilderRuntimeManager` | `site-builder-step95` |
| Menus / Widgets / CPT / Comments / Forms | respective managers | `menu-runtime`, `widgets-runtime`, `cpt-runtime`, `comments-runtime`, `forms-runtime`, `cf7-seed` |
| Users / Options / Settings | `UserManager`, `OptionManager`, `SettingsRuntimeManager` | `user-runtime`, `option-runtime`, `site-settings-runtime` |
| Plugins / Themes / Updates | `PluginManager`, `ThemeManager`, `SafeUpdates` | `plugin-runtime`, `theme-runtime`, `plugin-active-after-update`, `safe-updates`, `safe-updates-hardening` |
| Patch / Snapshot / Rollback | `PatchOperation`, `RollbackOperation`, `SnapshotManager`, PatchSystem | `file-patch-bridge`, `patch-lifecycle`, `patch-header-guard`, `snapshot-runtime` |
| Search / Bulk / DB | `SearchRuntimeManager`, `BulkRuntimeManager`, `DatabaseInspector`, `SearchReplace` | `search-runtime`, `bulk-runtime`, `database-inspection-runtime`, `safe-search-replace` |
| Reporting | `ReportingRuntimeManager` | `reporting-step98` |
| System / WP-CLI | `SystemInfoRuntime`, `WpCliBridge` | `system-info`, `structured-wp-cli-runtime`, `wp-cli-bridge` |
| Security / Approval / Capability | `SecurityModeManager`, `OperationManager`, `ApprovalRuntimeManager`, `CapabilityRegistry`, `DestructiveGuard` | `security-modes`, `security-mode-validation`, `approval-enforcement`, `mcp-approval-runtime`, `destructive-guardrails`, `capability-runtime`, `capability-bootstrap`, `mcp-scope-enforcement` |
| Recommendations | recommendation engine | `recommendations`, `recommendation-workflow` |

**Cross-cutting "core" suites** (run whenever a wiring file changes —
`OperationExecutor.php`, `OperationRegistry.php`, `CapabilityRegistry.php`,
`McpServerRuntime.php`, `AiAgent/RestApi.php`):
`operations-registry`, `operation-requests`, `operation-retry`, `operation-worker`,
`mcp-runtime`, `mcp-error-surface`, `mcp-tool-schema-compliance`,
`agent-manifest`, `agent-actions`, `agent-timeline`, `agent-review`,
`capability-runtime`.

---

## 2–5. Test classification

### 2. "Pure unit" tests
**None today.** Closest: `wp eval`–only logic checks (no HTTP) — currently only
`admin-ux`, `plugin-active-after-update`. *Opportunity (future): extract pure-PHP
logic (registries, resolvers like F6.2 `resolve_refs`, `DestructiveGuard::classify`,
risk maps) into a real PHPUnit unit layer that runs in <2s with no WP boot.*

### 3. Integration tests
**Essentially all 83 curl suites.** They boot WP and drive REST + MCP. This is the
bulk of the cost.

### 4. Acceptance tests (end-to-end, create-verify-rollback)
The step/finding suites: `*-stepNN`, `*-fNN`, `e2e-runtime`, `final-validation`,
`production-validation`, `real-site-validation`. Highest value per assertion,
but the heaviest (multi-entity lifecycles, network, rollback verification).

### 5. CI / final-validation-only candidates (don't run during local iteration)
- **The 6 chronic-failure suites** — non-logic certification/doc/redaction checks
  that always fail; quarantine them out of the dev loop entirely.
- **Heavy meta/cert suites:** `ai-client-certification`, `claude-integration`,
  `cursor-certification`, `enterprise-hardening`, `token-efficiency`,
  `health-verification`, `mcp-tool-schema-compliance`,
  `documentation-consistency`.
- **Whole-platform validators:** `final-validation`, `production-validation`,
  `real-site-validation`, `e2e-runtime` (also known to flake back-to-back).
- **Network-download suites** run with retries and only in the runtime tier or
  full tier (they caused the false 34/34 in the F6.2 run — confirmed green
  standalone).

### 6. Auto-selection by changed files
A static manifest (glob → suite list) makes this mechanical:

```
includes/Operations/ACF*            → acf-*        + core-light
includes/Operations/Media*,MediaSnapshot → media-* + core-light
includes/Operations/Workflow*       → workflow-*   + core-light
includes/Operations/WooCommerce*    → woocommerce-*, woo-product-seed + core-light
includes/Operations/Seo*            → seo-*        + core-light
includes/Operations/Elementor*      → elementor-*  + core-light
…(one row per runtime, per the §1 map)…
# Wiring/core files fan out wider:
includes/Operations/OperationExecutor.php   → ALL runtime smoke + core suites
includes/Operations/OperationRegistry.php   → ALL runtime smoke + core suites
includes/Operations/CapabilityRegistry.php  → security-*, capability-*, mcp-scope
includes/AiAgent/RestApi.php                → core suites + the touched runtime
includes/Security/*                          → security-*, capability-*, redaction
tests/*                                       → just that suite
*.md, docs/**                                 → documentation-consistency (CI only)
```

> **Caveat that matters here:** the remediation pattern (and most roadmap steps)
> edits `OperationRegistry.php` + `RestApi.php` to wire each operation. Naive
> file-selection would fan out to "everything" on every change. Mitigation: treat
> *registry/REST wiring additions* (a new `'x_manage' => [...]` block or route)
> as runtime-scoped, not core-scoped — i.e. key off the operation id touched, not
> the file. A small `git diff` heuristic (which operation block changed) keeps
> these in the runtime tier.

---

## Current bottlenecks (ranked)

1. **No selection.** Every fix runs all 85 suites — the single biggest waste.
2. **`wp eval` bootstrap tax.** Each call cold-boots WordPress (~0.5–1s). Suites
   make dozens; this dominates non-network cost. (32 suites use `wp eval`.)
3. **Live network downloads** (`download_url`) in ~9 suites — slow and flaky under
   full-suite load (the F6.2 "34 failures" were media/network flakes, green
   standalone).
4. **Chronic-failure noise.** 24 always-red assertions in 6 non-logic suites run
   every time — wasted time + obscured signal.
5. **Serial execution.** Suites run one-by-one; they are independent and
   parallelizable.
6. **Back-to-back state coupling.** `final-validation` (and media) flake when run
   immediately after others — a correctness smell in test isolation, not product.

---

## Recommended test tiers

| Tier | What runs | Target time | When |
|---|---|---|---|
| **T0 — Fast** | `php -l` on changed files + the **single most-relevant** suite (the finding/acceptance suite for the touched runtime, network-excluded) + any `wp eval` smoke | **< 30 s** | Every edit / pre-commit hook |
| **T1 — Runtime** | All suites for the touched runtime (§1) **+ core-light** (`operations-registry`, `capability-runtime`, `mcp-error-surface`) — chronic + network-heavy excluded or retried | **1–2 min** | Before each local commit |
| **T2 — Full** | All 85 suites, **parallelized**, network suites with retry, **chronic 6 quarantined** into a separate report | **3–5 min** (parallel) / ~8–12 min serial | Pre-deploy / pre-push / nightly CI |
| **Quarantine** | The 6 chronic + known-flaky validators | tracked separately | CI dashboard only; never gates dev |

Notes:
- T2 should run with `-jN` parallelism (suites are independent). With ~4–8
  workers, ~8–12 min serial collapses to **~3–5 min**, and that's the only tier
  that needs the whole set.
- Quarantine ≠ ignore: keep a CI job that runs the chronic 6 and tracks whether
  the count changes from 24 (so a *new* failure there is still caught), but never
  block local iteration on them.

---

## Measured runtime (2026-06-14, serial, one machine)

A timed full pass recorded per-suite duration:

- **Full serial wall time: 1,910 s (~32 min)** for 85 suites (~3,400 assertions) —
  *materially worse than the earlier 8–12 min guess; the suite is heavier and
  `wp eval` / network costs dominate.*
- **Slowest 9 suites (≈40% of total time):** `final-validation` 76s,
  `woocommerce-runtime` 69s, `patch-lifecycle` 69s, `structured-wp-cli-runtime`
  59s, `production-validation` 55s, `enterprise-hardening` 55s, `media-runtime`
  53s, `user-runtime` 48s, `theme-runtime` 48s. Most are heavy validators or
  network/`wp eval`-dense — exactly the quarantine/network sets.
- **A new bottleneck surfaced: test isolation.** The timed run reported 30
  failures (not 24). The 6 extra were **environment drift**: `test-theme-runtime`
  left the active theme switched to `mosharaf-core` (acf-json source-of-truth)
  without restoring it → acf-json auto-sync turned on → `acf-group-delete-f31`
  (5) and `site-builder-step95` (1) failed. Restoring the theme → both green.
  *This is a real flakiness source: a late-alphabet test corrupts shared state
  for the next run's early tests.* Recommendation: theme/option-mutating suites
  must snapshot+restore, and/or the T2 runner should restore active theme +
  flush ACF local store between suites.

## Estimated time savings (now anchored to the 1,910 s measurement)

| Workflow today | Time | With tiers | Time |
|---|---|---|---|
| Per remediation fix | full ~**32 min** | T0 then T1 | ~**0.3 + 1.5 = ~2 min** |
| Iterating on one runtime (×5) | ~**2.5+ hours** | 5 × T0/T1 | ~**10 min** |
| Pre-deploy | full ~**32 min** serial | T2 parallel `-j6` | ~**6–10 min** |

**Per-fix dev-loop reduction: ~90%+** (~32 min → ~2 min), plus removal of the
24-failure noise so green/red is unambiguous. The full suite still runs — once,
before deploy — so coverage is unchanged.

---

## Recommended workflow

1. **Edit** → T0 (lint + the one most-relevant suite) on save / pre-commit.
   *Sub-30s confidence the specific fix works.*
2. **Before `git commit`** → T1 (runtime + core-light, auto-selected from
   `git diff --name-only`). *1–2 min; the gate for a local commit.*
3. **Before `git push` / deploy** → T2 (full, parallel, chronic quarantined).
   *The existing release gate; the only place the whole suite runs.*
4. **CI** → T2 on every push + a nightly that also runs the quarantined 6 and the
   network/validator suites with retries, reporting any drift from the 24 baseline.

### Minimal enabling pieces (when implementation is approved)
- `tests/run.sh --tier=fast|runtime|full [--changed]` wrapper that:
  - reads `git diff --name-only` and the selection manifest (§6);
  - excludes the quarantine list; supports `-jN` parallelism; retries
    network suites; prints `passed/failed` and **diffs against the 24 baseline**
    so "0 net-new" is computed automatically (today it's eyeballed).
- A `tests/selection-manifest.tsv` (glob → suites) — the §1/§6 map.
- A `tests/quarantine.txt` — the 6 chronic + flaky validators.
- *(Stretch)* a real PHPUnit unit tier for pure-PHP logic (registries, resolvers,
  guards) → a true <5s T(-1) that needs no WP boot.

---

---

## Implementation (built)

The tiered runner is implemented:

| File | Purpose |
|---|---|
| `tests/run.sh` | Tiered runner: `--tier T0\|T1\|T2`, `--changed` (auto-select from `git diff` names+content), `--runtime NAME`, `--files`/`--content` (testable injection), `--list`, `-j N` parallel. Lints changed PHP; runs the selected suites; **diffs failures against the baseline to report net-new automatically**; retries network suites once. |
| `tests/regression-map.tsv` | Selection manifest: `group → trigger_regex → primary_suite → suites`. Triggers match source-file paths **and** operation ids, so a new `acf_manage` route/registry block selects the ACF group even though the edit is in `RestApi.php`/`OperationRegistry.php` (the wiring-fan-out caveat from §6, solved). |
| `tests/regression-quarantine.txt` | Suites excluded from T0/T1 (chronic 6 + heavy validators). Still run in T2. |
| `tests/regression-baseline.tsv` | The 24 known failures (suite→count). Powers automatic net-new computation. |
| `tests/test-suite-selection.sh` | **Acceptance test (43 assertions) proving selection is correct**: per-runtime isolation, op-id selection, core fan-out, T0=primary-only/network-free, T2=all suites, quarantine never in T1 but present in T2, `--runtime` forcing. |

### Measured tier times (demo, theme restored)
- **T0 `--runtime acf`: 14 s** (15/15, net-new 0) — 1 primary suite + lint.
- **T1 `--runtime workflow`: 145 s** (215/215, net-new 0) — 7 suites (4 workflow +
  3 core-light).
- vs **full serial 1,910 s** → the dev loop drops from ~32 min to **~0.25–2.5 min**
  (≈**92–99%** less per check), while T2 still covers everything before deploy.

### Usage

```
tests/run.sh --tier T0 --changed      # pre-edit: lint + primary suite of changed runtime  (<30s)
tests/run.sh --tier T1 --changed      # pre-commit: runtime suites + core/cap/MCP           (1-2 min)
tests/run.sh --tier T2 -j 6           # pre-deploy: full suite, parallel, net-new vs baseline
tests/run.sh --tier T1 --runtime acf  # force a runtime without a file change
tests/run.sh --tier T1 --changed --list   # show what WOULD run (no execution)
```

Exit code is non-zero only on **net-new** failures or a lint failure — the chronic
24 baseline does not fail the run, so "0 net-new" is computed, not eyeballed.

### Safety / coverage preserved
- T2 still runs **every** suite (incl. quarantine) before deploy.
- Quarantine is excluded only from the local T0/T1 loop; CI/T2 still executes it
  and the baseline diff still catches any *new* failure inside those suites.
- Suite selection is itself covered by an acceptance test, so the map can't
  silently drift.

*Strategy implemented; the regression-strategy task can later layer on parallel
CI sharding and a true PHPUnit unit tier (registries/resolvers/guards).*
