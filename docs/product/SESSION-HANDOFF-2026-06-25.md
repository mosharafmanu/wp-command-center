# Session Handoff — 2026-06-25 (RC-2 / Concierge Beta staged)

## TL;DR
The Program 5A→10 stack is integrated, acceptance-gated (net-new attributable 0), and **staged on local `main` but NOT pushed**. Production is untouched (still Program-4). Safe to shut down.

## Repository state (refreshed 2026-06-25, planning phase complete)
| Item | Value |
|---|---|
| Current branch | `main` |
| Current HEAD | `90210a0` — `docs(planning): finalize planning phase — canonical architecture & UX blueprints` |
| Local `main` vs `origin/main` | **ahead 22, behind 0** (staged, unpushed) |
| `origin/main` | `94a716c` (unchanged) |
| Production | **Program-4 `2657810`** — nothing pushed, nothing deployed |
| Version on HEAD | `WPCC_VERSION 0.2.0-rc.2` · OPERATION_MAP 34 · DB 2.5.0 |
| Uncommitted tracked code/test | **NONE** |
| Working-tree noise (pre-existing, not mine) | `artifacts/step-36-validation/validation-evidence.json` modified — unrelated to this work; leave as-is or `git checkout --` it |
| RC branch | `rc-2-release-candidate @ 2b447e6` — the validated RC build; `main` has since advanced 6 commits beyond it (connection-wizard UX + planning docs), so it is **no longer identical to the `main` tip** |

> **Since the original handoff (baseline `2b447e6`, ahead 16):** 6 commits were added to `main` — 5 connection-wizard UX commits (`541e42a`→`37cf25c`) and the planning finalization (`90210a0`). All are docs / AI-UX-wizard work; no engine, route, capability, MCP-tool, or schema change (invariants held at 34/23/40/40/2.5.0). Production remains untouched.

## Deliverables (all present, committed)
- `docs/product/rc-2/` — **8 files** (RC-2 release execution: integration, acceptance, defaults, E2E, security, risks, checklist, final).
- `docs/product/concierge-beta/` — **7 files** (deployment readiness, smoke, E2E AI, onboarding review, completion, issues, go/no-go).
- `docs/product/rc-1/` — RC-1 readiness review (committed with this handoff).

## Decisions on record
- **RC-2:** READY WITH MINOR RISKS → blockers cleared (integration, acceptance net-new 0, client-safe default, governed pipeline proven).
- **Concierge Beta:** **READY FOR DESIGN PARTNER DEPLOYMENT** (build GO; execution owner-gated).
- **Owner choice:** stage RC → local `main`, **do not push**.

## Known finding to act on at deploy (I1 — important)
The client-safe default is **unset-only**. Production already has `wpcc_security_mode = developer`, so deploying does **not** auto-flip it. **Set Client mode on production explicitly at deploy**, or it deploys self-approving.

## Resume from here (exact next steps)
1. `git checkout main` (already there) — confirm HEAD `90210a0`, ahead of `origin/main` by 22.
2. When ready to deploy: **`git push origin main`** → pull-deploy updates `mosharafmanu.com` (~1 min).
3. **Immediately set Client mode on production** (finding I1).
4. **Confirm the live AI workflow** on the first keyed site with a real Anthropic key (Phase 3), capturing evidence.
5. Runbook: `docs/product/rc-2/CONCIERGE-BETA-CHECKLIST.md`.

Nothing reaches production until an explicit `git push`. Local `main` being ahead of `origin/main` is expected (staged release) — not "behind", no pull needed.

---

## Planning phase — COMPLETE (2026-06-25)

- **Planning phase is complete.** The canonical planning set is finalized and committed: `PRODUCT-MASTER-PLAN.md` (§0 hierarchy) · `master-architecture/MASTER-AI-PLATFORM-BLUEPRINT.md` (platform: "Three Doors, One Engine") · `master-architecture/FINAL-UX-MASTER-BLUEPRINT.md` (UX).
- **RC build remains staged locally** on `main` (Programs 5A→10 + RC-2 + concierge-beta + wizard UX), ahead of `origin/main`.
- **Nothing has been pushed.** `origin/main` is unchanged.
- **Production remains unchanged** — Program-4 (`2657810`); AI dormant; invariants 34/23/40/40/2.5.0.
- **The next session begins the implementation phase**, aligned to the three canonical documents (build toward the blueprints; UX-blueprint §14 lists the contradictions to resolve forward, never by faking behavior).

### Implementation readiness (as of this refresh)
- **First milestone:** Phase 1 — Narrative + IA migration (Platform Blueprint §15.1; UX Blueprint §2/§3/§4/§7/§8). Pure UX/navigation: regroup into the door-aware sections, apply the rename set, add the onboarding door-fork.
- **Why first:** explicitly the first phase of the blueprint's phased rollout; lowest-risk, invariant-preserving, builds on the existing `AppShell` + `AdminMenu` strangler + legacy-slug redirects.
- **Out of scope for Phase 1:** engine / `OperationExecutor` / approval / audit / rollback; REST & MCP namespaces; the 40 ops · 23 caps · 40 catalogue · 40 MCP tools · DB 2.5.0 invariants; Generation Adapters (Anthropic-only execution stays honestly marked, not closed); capability-scoped tokens; enabling AI feature flags; any push/deploy (owner-gated).
- **Phase 4 Design-Partner Readiness — COMPLETE (2026-06-26):** the #1 demo blocker is removed — built-in AI tools (SEO/Alt Text/Content) can now be **enabled from the UI** (Built-in AI › Providers), governed (nonce + `manage_options` + audited) and honest: a defined constant/filter still wins (shown "Locked / set in configuration"), otherwise a per-tool **option** governs (default off; existing installs unchanged); a tool with no provider reads `requires_provider`. New `BuiltinAiSettings` + `DesignPartnerReadiness` (live 8-item "can I run the first governed AI change?" checklist, real state only) + a Home **first-value panel** (one next action, details collapsed). Provider honesty surfaced ("generation runs on Anthropic today"). **No** schema/DB_VERSION change (enablement is a WP option), no provider-execution/REST/MCP/capability/IA change. `test-phase-4-readiness` 58/0; affected suites green; invariants 34/23/40/40/2.5.0; net-new 0. Docs: [`phase-4-design-partner-readiness/`](phase-4-design-partner-readiness/) (report + readiness checklist + first-workflow + validation). The first governed AI demo now runs end-to-end without code editing (founder still pastes a real Anthropic key). Production untouched (Program-4).

- **Phase 2 Runtime Migration — COMPLETE (2026-06-25):** the legacy "Agent Runtime Dashboard" (`dashboard.php`) is retired. **2A** (additive) built the new homes — Settings › Tools (Safe Search & Replace) + Settings › Diagnostics › Recommendations (+ Home/Approvals signals on real data) — keeping Runtime working (`cf20e50`). **2B** (cutover) deleted `dashboard.php`, removed the Runtime tab, grouped Settings **8/10 → 5 tabs** (Security & Approvals · Access · Tools · Diagnostics ▾ · Advanced ▾ via hub wrappers), and added pane-precise backward-compatible redirects (every old Runtime/Settings URL → Diagnostics/Advanced, 0 loops). No engine/REST/MCP/capability/schema change; invariants 34/23/40/40/2.5.0 held; ~801 assertions green across 12 suites; net-new 0. Engine Inspector (raw internals) deferred (available via REST/MCP). Docs: [`phase-2-runtime-migration/`](phase-2-runtime-migration/). Production untouched (Program-4).

- **Status:** **Phase 1 IMPLEMENTED + POLISHED + validation-green, staged on local `main` (not pushed).** The admin IA migrated 5-C → six product-language sections (Home · Built-in AI · Connect · Activity · History · Settings) with tab-aware legacy redirects, the first-run door fork, the new honest API & Integrations landing, and the rename set. **Beta-readiness pass:** fixed a Settings redirect loop (live section slug `wpcc-settings` was self-mapped in `legacy_map` → `resolve_legacy` now short-circuits live sections; whole-section navigation verified — 25 render variants, 139 path checks, 0 loops); Home cards relabeled to the new IA ("At a glance"; Approvals/Capabilities/Access/History); honest Built-in AI note when generation tools are flag-off. Invariants held (34/23/40/40/2.5.0); suites green (0 net-new failures; 2 pre-existing SEO classifier fails proven unrelated). Implementation documentation: [`phase-1-ia/`](phase-1-ia/) (9 deliverables; see §9 Polish & Fix). Production untouched (Program-4 `2657810`). **Next:** owner review → next blueprint phase; pre-GA schedule the formal AT/axe a11y pass.
