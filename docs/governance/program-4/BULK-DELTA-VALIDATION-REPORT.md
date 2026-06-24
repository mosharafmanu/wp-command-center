# PROGRAM-4.8 — Bulk Delta Validation Report (Phase D)

> **Type:** validation results (no code changes in this phase beyond the audit-driven D-1 fix). Report-only.
> **Branch:** `program-4.8-bulk-delta-redesign` (base `97e9ccd`). **Code:** `BulkRuntimeManager.php` rewritten internals + new `BulkWooAccessor.php`, `BulkAcfAccessor.php`; new `tests/test-bulk-delta-rollback.sh`; 2 obsolete static checks in `test-bulk-rollback-fix.sh` retargeted.

---

## 1. New delta suite — `test-bulk-delta-rollback.sh`: **53 / 0**
Manager-level, PHP-bootstrapped, WooCommerce + ACF active (D10/D11 ran live).

| Mission point | Scenario(s) | Result |
|---|---|---|
| 1 status restored not title | D1 (publish→rollback: status draft, title T1, not a status word) | PASS |
| 2 unpublish/draft restores status | D2 | PASS |
| 3 sibling preservation | D3 (title-only rollback; drifted sibling content preserved) | PASS |
| 4 bulk_media per-item | D9 | PASS |
| 5 bulk_woocommerce per-item | D10 (price 99→10, live) | PASS |
| 6 bulk_acf per-item | D11 (value→prior empty, live) | PASS |
| 7 one failed item ≠ corrupt others | D7 (m1 restores while m2 record missing) | PASS |
| 8 partial reports partial honestly | D6 (restored 1 / skipped 1 / status partial) | PASS |
| 9 repeated rollback safe | D8 (second → `done`) | PASS |
| 10 drift conflict skips/reports | D4 (conflict, skipped 1, drifted title NT2 not clobbered) | PASS |
| 11 legacy P4C.0a record restores | D13 (option record → legacy path, status restored) | PASS |
| 12 batch index resolves item records | D12 (membership rows = item count; each resolves) | PASS |
| 13 missing item handled honestly | D7 (dangling membership → `missing` 1) | PASS |
| 14 rollback_id surfaced | D15 (+ D1/D9 etc.) | PASS |
| 15 audit/history truthful | envelope reports restored/skipped/missing/already/errored + status (D4/D6/D7) | PASS |
| 16 no FIFO eviction | D14 (early batch survives later batches); static `lacks array_slice` | PASS |
| out-of-order safety | D5 (rollback B then retry A → no resurrection, back to OT) | PASS |
| 17 invariants unchanged | §3 | PASS |

Static (15): per-item `PostMetaRollbackStore`, `RollbackDelta::capture/build_record/restore`, batch membership meta index, indexed `meta_key` resolution, `ContentFieldAccessor`/`BulkWooAccessor`/`BulkAcfAccessor` usage, per-item `try/catch`, legacy path retained, idempotent `done`, **no `array_slice` FIFO**, woo decimal-normalized drift, acf `metadata_exists`.

## 2. Backward-compatibility — `test-bulk-rollback-fix.sh`: **35 / 0**
The P4C.0a hotfix suite passes **fully** against the P4.8 implementation: all 31 functional checks (B1–B10) green (status-not-title, sibling preservation, media/woo/acf reversibility, legacy scalar record, idempotency, honesty, id surfacing). Two static checks that asserted hotfix-internal code shapes (now superseded by the delta architecture) were retargeted to the equivalent new guarantees (`PostMetaRollbackStore`, `RollbackDelta::capture`); the functional B1–B10 remain the behavior oracle.

## 3. Full regression battery
| Suite | Tally |
|---|---|
| **bulk delta (new)** | **53 / 0** |
| bulk rollback-fix (hotfix compat) | **35 / 0** |
| bulk runtime (REST) | **41 / 0** |
| rollback-delta-core (unchanged) | **25 / 0** |
| PostMetaRollbackStore (unchanged) | **30 / 0** |
| SEO delta | **56 / 0** |
| Settings delta | **38 / 0** |
| Media metadata delta | **41 / 0** |
| Content delta | **30 / 0** |
| Comments delta | **27 / 0** |
| User delta | **28 / 0** |
| Woo runtime | **117 / 0** |
| operations-registry (catalogue 40) | **18 / 0** |
| capability-runtime (caps 23) | **61 / 0** |
| mcp-error-surface (MCP 40) | **18 / 0** |
| change-history-rollback (standalone) | **48 / 0** |

**Net-new attributable failures: 0.**

## 4. Invariants
`OPERATION_MAP=34 · capabilities=23 · DB_VERSION=2.5.0` probed live; `catalogue=40` / `MCP=40` via the passing operations-registry + mcp-error-surface guards. **All held — unchanged.**

## 5. Audit-driven fix (D-1)
The independent audit flagged that a non-`complete` batch returned no `error` flag, so the executor's `success` boolean read true on a pure-conflict (zero-restored) rollback — diverging from `RollbackDelta::result()`. Fixed: a `partial`/`conflict` batch now returns `error:true` + `code` (`wpcc_rollback_partial`/`wpcc_rollback_conflict`) + `reversible:false`, while the rich `status`/`restored`/`skipped`/`per_item` fields stay honest and restored items remain applied (skipped/missing items retryable). Re-ran: bulk-delta 53/0, hotfix 35/0 — no regression.

## 6. Scope / STOP
- Files: `BulkRuntimeManager.php` (internals) + 2 new accessors + 1 new test + 2 retargeted static checks. `RollbackDelta`, `PostMetaRollbackStore`, `ContentFieldAccessor` **byte-unchanged**.
- **No** schema / DB_VERSION / operation-registry / capability / MCP / REST-contract / security change. Action set, routes, capability, MCP tool unchanged; new response fields additive; new meta keys do not bump DB_VERSION. **No STOP triggered.**

## 7. Verdict
All suites green; net-new attributable failures 0; invariants unchanged; corruption stays fixed; F-1 closed (drift-aware, per-item, sibling-preserving, out-of-order-safe, no FIFO). **Ready for the Phase E independent audit record + commit.**
