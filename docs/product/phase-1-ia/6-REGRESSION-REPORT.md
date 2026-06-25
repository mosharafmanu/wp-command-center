# Phase 1 — Regression Report

> **Discipline:** net-new attributable failures must be 0 vs. the pre-change baseline. Every changed file was linted; every affected suite was run.

## Lint
`php -l` clean on all 10 changed PHP files + the 1 new view:
`AppShell.php · AdminMenu.php · AdoptionStatus.php · command-home.php · api-integrations.php · ai-integrations.php · ai-setup.php · operations-center.php · dashboard.php · ai-content.php · seo-meta.php`.

## Suites run (all affected admin/IA/UX suites)

| Suite | Result |
|---|---|
| `test-ia-phase1.sh` (**new**) | **82 / 0** |
| `test-experience-layer.sh` | **117 / 0** |
| `test-first-value-5c.sh` | **24 / 0** |
| `test-usability-5b.sh` | **36 / 0** |
| `test-adoption-readiness.sh` | **44 / 0** |
| `test-approval-center.sh` | **127 / 0** |
| `test-change-history-admin.sh` | **119 / 0** |
| `test-token-capability-admin.sh` | **155 / 0** (incl. **live wp-cli** redirect check) |
| `test-operations-explorer.sh` | **151 / 0** |
| `test-operations-center-10.sh` | **28 / 0** |
| `test-alt-text-ui.sh` | **76 / 0** |
| `test-proposal-admin.sh` | **25 / 0** |
| `test-seo-audit.sh` | 66 / **2 pre-existing** (see below) |

**Total: 1050 passed · 0 net-new failures · 2 pre-existing (attributable to baseline, not this change).**

## The 2 `test-seo-audit` failures are pre-existing
Assertions *"classify weak (short desc)"* and *"classify ok"* (a SEO-meta classifier unit check, unrelated to navigation). **Proven pre-existing:** with this milestone's only `seo-meta.php` edit (a single URL string) reverted via `git stash`, both still fail identically. They are environmental/baseline, consistent with the RC-2 acceptance note of ~38 baselined T2 failures. **Not attributable to Phase 1.**

## Why the test updates are legitimate (not "fixing the test to pass")
10 existing suites carried **structural assertions that hard-coded the retired 5-C IA** (e.g. `'wpcc-approval-center' => [ 'wpcc-operate', 'approvals' ]`, badge → `page=wpcc-operate`, tab label "AI Alt Text", `sections()['wpcc-operate']`). The blueprints make the 6-section IA the new contract; those guards were updated to assert the **new** reality (and the new `test-ia-phase1.sh` adds authoritative coverage). The **invariant assertions (34/23/40/40/2.5.0) were left untouched and pass**, and all behavioural/functional assertions (live redirect, gating, FeatureGate, drift guards) pass against the real code.

## Invariants (live wp-cli, via DashboardAdminQuery envelope)
`OPERATION_MAP 34 · ALL_CAPABILITIES 23 · catalogue 40 · MCP tools 40 · DB 2.5.0` — **green.**

## Net
Zero net-new attributable regressions. Navigation, backward-compatibility, gating, and drift guards all green; invariants held.
