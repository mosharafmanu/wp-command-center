# PROGRAM-4.1 — Settings Runtime Rollback · Validation Report

> **Date:** 2026-06-23 · **Env:** DEV (AMPPS), PHP 8.2.27, wp-cli live, security mode developer.
> **Verdict:** **GO** — all design goals proven; SEO/core unchanged; invariants held; zero attributable failures.

## 1. Lint — clean
`php -l` → No syntax errors: `includes/Rollback/OptionAccessor.php`, `includes/Operations/SettingsRuntimeManager.php`.

## 2. Settings delta acceptance — `tests/test-settings-rollback-delta.sh` → **35 / 0**
| Scenario | Design goal | Result |
|---|---|---|
| **S0** capture-before-write + value-prior restore | **DEF-1 fix** + goal 1/2 | ✅ rollback restores ORIG, not the post-write value |
| **S1** empty-prior → delete on rollback | goal 5 (existed-vs-empty) | ✅ |
| **S2** empty-but-existing → restore empty row | goal 5 | ✅ |
| **S3** disjoint sibling + drift (partial) | goals 3, 2, 6 | ✅ sibling survives, drifted field skipped, status `partial` |
| **S4** same-field drift → conflict, no clobber | goal 2/6 | ✅ status `conflict`, newer value kept |
| **S5** out-of-order rollback | goal 4 | ✅ no resurrection |
| **S6** repeated rollback guarded | goal (idempotent) | ✅ `wpcc_rb_done` |
| **S7** cross-action field-scoping | goal 3 (DEF-2 fix) | ✅ general rollback leaves reading sibling untouched |
| **S8** legacy `before_state` record restores | goal 7 | ✅ path `legacy` |
| 12 static source guards | structure | ✅ |

All six required behaviour checks satisfied: sibling preservation (S3/S7), drift conflict (S4), empty-value fidelity (S1/S2), repeated-rollback safety (S6), legacy compatibility (S8), out-of-order (S5).

## 3. No-regression — SEO + core (design goal 8)
| Suite | Result | Baseline |
|---|---|---|
| `test-rollback-delta-core.sh` | **25 / 0** | 25/0 — core untouched |
| `test-seo-rollback-delta.sh` | **56 / 0** | 56/0 — no SEO regression |
| `test-seo-rollback-store.sh` | **28 / 0** | 28/0 |
| `test-seo-undo.sh` | **33 / 0** | 33/0 |
| `test-site-settings-runtime.sh` (REST) | **24 / 0** | 24/0 — existing Settings REST suite still green |
| `test-operations-registry.sh` | **18 / 0** | parity |
| `test-capability-runtime.sh` | **61 / 0** | parity |
| `test-mcp-error-surface.sh` | **18 / 0** | parity |
| `test-change-history-rollback.sh` | dispatcher path — see §5 | 48/0 standalone |

## 4. Invariants
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** — **held** (no op/cap/tool/schema change; the v2 record reuses the existing `wpcc_settings_rollbacks` option).

## 5. Failure classification
| Observation | Class | Disposition |
|---|---|---|
| (none attributable) | — | No code fix triggered by validation failures of the rollback path. |
| Initial S1/S7 reds during authoring | **test-fixture** | S1 used `WPLANG` (specially-handled WP option); S7 used `posts_per_page` (hit DEF-3). Both were **test-design issues, not rollback defects** — fixtures corrected (S1→`blogdescription`, S7→capture-and-compare `blog_public`); rollback code unchanged. |
| DEF-3 (`reading_update` null-key) | pre-existing, out-of-scope | update-method bug, not rollback; documented, not fixed (see Impl report). |
| ch-rollback 1 red (`backfill inserts no duplicates: expected 80044, got 80067`) | NON-ATTRIBUTABLE / ENVIRONMENTAL (concurrency) | Section-0 backfill count drifted ~23 rows from foreground suites inserting `change_log` rows mid-count. **Rollback functionality Sections 1–9 all PASS.** Same pattern seen 3× this session (twice cleanly 48/0 standalone). P4.1 touches neither OperationExecutor nor change-history. Clean standalone re-run launched for the record. |

**No ATTRIBUTABLE and no FLAKY rollback failures.**

## 6. Verdict
**GO.** Settings rollback is now field-scoped, drift-aware, sibling-preserving, out-of-order-safe, existence-faithful, partial/conflict-honest, and legacy-compatible — reusing the P4.0 core with no SEO/core regression and no invariant change. Two pre-existing defects (DEF-1 no-op, DEF-2 over-reach) are fixed as a consequence.
