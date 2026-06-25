# Concierge Beta — Phase 1: Deployment Report

> **Scope of what I can do autonomously:** validate that the RC build is deployment-ready. **What I will NOT do autonomously:** push to live production. See "Deployment execution" below.

## Deployment model (project reality)
- Deploy = `git push origin main` → server cron (`~/wpcc-deploy.sh`) pulls `origin/main`, hard-resets, reactivates the plugin, flushes cache (~1 min). Documented in `.ai/DEPLOY.md`.
- **There is no separate staging environment.** `origin` is `github.com/mosharafmanu/wp-command-center` and the deploy target is the **live production site** (`mosharafmanu.com`). Production currently runs **Program-4** (`2657810`); `main` here = `94a716c`.

## Deployment-readiness validation (autonomous, local — PASS)
| Check | Result |
|---|---|
| Full-build PHP lint (233 `includes/*.php` + main) | **0 errors** |
| Plugin boots WordPress without fatal | **PASS** — `PLUGIN LOADS OK · WPCC_VERSION=0.2.0-rc.2` |
| Version upgrade marker | **0.1.0 → 0.2.0-rc.2** present (header, `WPCC_VERSION`, readme) |
| DB schema version | `DB_VERSION = 2.5.0` |
| Telemetry table self-provisions | **YES** (`wp_wpcc_telemetry` created on `ensure_table()`) |
| New subsystems load | EventBus ✓, OperationsCenterQuery ✓ |
| Invariants | OPERATION_MAP 34 · DB 2.5.0 (T2 also confirmed 23/40/40) |
| Acceptance gate (RC-2) | T2 5874 pass; **net-new attributable = 0** |

**The build is structurally and functionally ready to deploy.** No PHP/JS/fatal blocker found. (JS: the admin UIs are server-rendered PHP with inline handlers; no build step / bundler to fail.)

## ⚠️ Material deployment finding — production would NOT be client-safe
- RC-2's client-safe default is **`add_option('wpcc_security_mode','client')` — unset-only**. It only seeds on a **fresh** install.
- **Evidence:** on the existing environment `get_option('wpcc_security_mode') = "developer"` (the option is already set from the original Program-4 activation). `current()` therefore resolves to **developer**.
- **Consequence:** deploying the RC to the **existing production site** leaves it in **developer (self-approving)** mode. Pointing an agent at it post-deploy would allow ungoverned writes. The client-safe default protects *new* design-partner installs, **not** the existing prod site.
- **Required action before/at deploy:** explicitly set production to **Client** mode (Security UI, or update the option). This is a one-line owner action; without it, deploying does not improve production's safety posture.

## Deployment execution — OWNER-GATED (not performed)
Pushing 15 commits + a version + behavior changes to a **live production website** is outward-facing and hard to reverse, the model has **no staging**, and the mode caveat above means a naive deploy is not automatically safe. Per the project's standing process (deploy = owner-authorized; RC-2 explicitly did not push) and basic deployment safety, **I have not pushed/deployed.** The decision and execution are surfaced to the owner (see Go/No-Go).

## Status
**Build: READY TO DEPLOY.** **Act of deploying: awaiting explicit owner authorization + a production mode-set.**
