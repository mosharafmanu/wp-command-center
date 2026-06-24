# PROGRAM-4C.0a — Bulk Validation Report (Phase E)

> **Type:** validation results (no code changes in this phase). Report-only.
> **Branch:** `program-4c.0a-bulk-rollback-fix` (base `8550a4b`). **Files changed:** `includes/Operations/BulkRuntimeManager.php` (+103/−24), new `tests/test-bulk-rollback-fix.sh`.

---

## 1. Dedicated remediation suite — `test-bulk-rollback-fix.sh`: **35 / 0**
Exercises `BulkRuntimeManager` directly (manager-level, mirrors the SEO/Woo delta suites). WooCommerce and ACF were **active**, so B5/B6 ran (not skipped).

| Group | Assertion | Result |
|---|---|---|
| **Static (10)** | store_rollback returns id; status capture is a field map; action-dispatched restore; woo/acf reversible branches; legacy scalar normalized; status-legacy→post_status; unsupported is reversible:false; honest envelope; **no status-into-title write remains** | PASS |
| **B1 corruption fix** | status applied=publish; **status restored=draft** | PASS |
| **B2 corruption prevention** | **title NOT corrupted (ORIG_T1)**; title is not a status word (0) | PASS |
| **B3 content correctness** | title + content restored | PASS |
| **B3b sibling preservation** | title restored; **externally-drifted sibling content preserved** | PASS |
| **B4 media reversible** | media title applied then restored | PASS |
| **B5 woo reversible** | regular_price applied=99 then restored=10 | PASS |
| **B6 acf reversible** | value applied=ACF_NEW then restored to prior (empty) | PASS |
| **B7 legacy compatibility** | legacy **scalar** `bulk_publish` record restores **status**, leaves title intact | PASS |
| **B8 idempotency** | first restored=1; second guarded (`done`) | PASS |
| **B9 history honesty** | envelope reports restored count + reversible:true; unknown type → `wpcc_bulk_rollback_unsupported` + reversible:false | PASS |
| **B10 rollback_id surfaced** | publish / media / content ops return a non-empty rollback_id | PASS |

**The corruption is reproduced-as-fixed:** before this change a `bulk_publish` rollback wrote `"draft"` into `post_title` and never restored status; B1/B2 now assert status restored **and** title untouched.

## 2. Required guard suites
| Suite | Purpose | Result |
|---|---|---|
| `test-bulk-runtime.sh` | focused bulk suite (REST/MCP, existing) | **41 / 0** |
| `test-bulk-rollback-fix.sh` | dedicated remediation (new) | **35 / 0** |
| `test-rollback-delta-core.sh` | rollback core (untouched) | **25 / 0** |
| `test-operations-registry.sh` | registry parity (catalogue 40) | **18 / 0** |
| `test-capability-runtime.sh` | capability parity (caps 23) | **61 / 0** |
| `test-mcp-error-surface.sh` | MCP parity (tools 40) | **18 / 0** |
| `test-change-history-rollback.sh` | change-history rollback (standalone) | **48 / 0** |

**Attributable failures: 0.** (`change-history-rollback` was run standalone per the documented heavy-backfill flake guidance; 48/0 matches its clean baseline.)

## 3. Invariants
`OPERATION_MAP=34 · capabilities=23 · DB_VERSION=2.5.0` probed live; `catalogue=40` and `MCP=40` confirmed via the passing operations-registry (18/0) and mcp-error-surface (18/0) guards. **All held — unchanged.**

## 4. Scope / contract
- **Files changed:** exactly one runtime (`BulkRuntimeManager.php`) + one new test. No other code touched.
- **No** schema / DB_VERSION / capability / operation-registry / MCP / REST-route / security change. The action set (`BulkRegistry::ACTIONS`), routes, capability, and MCP tool are unchanged. Added response fields (`rollback_id`, and the rollback envelope's `type`/`restored`/`fields`/`reversible`) are **additive** — the existing `test-bulk-runtime.sh` REST suite stayed green at 41/0, confirming no consumer contract broke.

## 5. Coverage of Phase-A findings
| Finding | Closed by | Proof |
|---|---|---|
| status rollback corrupts title | action-dispatched field-scoped restore | B1/B2 |
| status never restored | post_status restore branch | B1 |
| media/woo/acf irreversible | capture + restore added | B4/B5/B6 |
| bulk_content incomplete (post_content) | content captured + restored | B3 |
| rollback_id never surfaced | store_rollback returns id; ops return it | B10 |
| dishonest history | honest envelope + `bulk.rollback` audit + structured unsupported | B9 |

## 6. Verdict
All required suites green; attributable failures 0; invariants unchanged; corruption reproduced-as-fixed and all coverage gaps closed within Bulk scope. **Ready for independent audit (Phase F).**
