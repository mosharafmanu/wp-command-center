# PROGRAM-4.7 — PostMetaRollbackStore Validation (Phase D + E)

> **Type:** validation results (no code changes in this phase). Report-only.
> **Branch:** `program-4.7-postmeta-rollback-store` (base `5a57db4` = P4C.0a Bulk hotfix lineage). **Code change:** one new leaf class `includes/Rollback/PostMetaRollbackStore.php` + new `tests/test-postmeta-rollback-store.sh`. No existing file modified.

---

## 1. Keystone unit suite — `test-postmeta-rollback-store.sh`: **30 / 0**
Exercises `PostMetaRollbackStore` directly over real postmeta (mirrors the rollback-delta-core suite).

| Group | Proves | Result |
|---|---|---|
| Static (11) | implements `RollbackStore` with byte-exact signatures; persist=unique `add_post_meta`; resolve=indexed `meta_key` SQL; mark_applied=`update_post_meta`; meta_key encodes id; protected-meta prefix; malformed→null; interface unchanged | PASS |
| T1 round-trip | persist → **resolve by rollback_id alone**; `entity_id == post id`; record id round-trips; not-yet-applied | PASS |
| T2 storage shape | single **protected** postmeta row keyed by the id | PASS |
| T3 mark_applied | flips `rollback_applied` in place (same row) | PASS |
| T4 absent | unknown id → `null` (no throw) | PASS |
| T5 independence | two records on the same post both survive (**no FIFO eviction**) | PASS |
| T6 uniqueness | re-persisting an id does **not** overwrite the original | PASS |
| T7 GC | deleting the post → resolve `null` (meta cascades) | PASS |
| T8 safety | non-array meta value → `null` (no fatal) | PASS |
| T9 coexistence | postmeta record resolves to its own data; a same-id **option** record is untouched (no cross-talk) | PASS |
| T10 protected prefix | a prefix missing the leading `_` is forced protected and still resolves | PASS |
| T11 guards | zero entity id / empty rollback id are safe no-ops | PASS |

## 2. Compatibility (Phase D)
- **Old records still work / existing stores still work:** `git diff 5a57db4 -- includes/` shows **no modification** to `RollbackStore.php`, `OptionListRollbackStore.php`, `OptionKeyedRollbackStore.php`, `RollbackDelta.php`, or any runtime. The keystone is additive; nothing constructs it yet, so no existing rollback path can change.
- **RollbackStore contract unchanged:** the new class implements the existing interface verbatim (static asserts pass).
- **RollbackDelta unchanged:** rollback-delta-core 25/0.
- **Runtime behavior unchanged:** every per-runtime rollback suite holds at its prior tally (below).
- **Coexistence proven:** T9 shows a postmeta record and a same-id option record do not interfere.

## 3. Full regression battery (Phase E)
| Suite | Tally |
|---|---|
| rollback-delta-core | **25 / 0** |
| SEO (delta) | **56 / 0** |
| SEO (store) | **28 / 0** |
| Settings (delta) | **38 / 0** |
| Media metadata (delta) | **41 / 0** |
| Content (delta) | **30 / 0** |
| Comments (delta) | **27 / 0** |
| User (delta) | **28 / 0** |
| Woo Products (runtime) | **117 / 0** |
| Woo Products (STEP 93) | **19 / 0** |
| Bulk (rollback fix) | **35 / 0** |
| Bulk (runtime) | **41 / 0** |
| **PostMetaRollbackStore (new)** | **30 / 0** |
| operations-registry (catalogue 40) | **18 / 0** |
| capability-runtime (caps 23) | **61 / 0** |
| mcp-error-surface (MCP 40) | **18 / 0** |
| change-history-rollback (standalone) | **48 / 0** |

**Net-new attributable failures: 0.** (change-history-rollback run standalone per its documented heavy-backfill flake guidance; 48/0 = its clean baseline.)

> Note on Woo: this branch's base (`5a57db4`) predates P4.6, so the P4.6 Woo product-delta test is not present here; "Woo Products" is validated via the Woo runtime suites that exist at this base. The keystone is additive and runtime-neutral, so it cannot affect any Woo code regardless of which Woo version is present.

## 4. Invariants
`OPERATION_MAP=34 · capabilities=23 · DB_VERSION=2.5.0` probed live; `catalogue=40` and `MCP=40` confirmed via operations-registry (18/0) and mcp-error-surface (18/0). **All held — unchanged.**

## 5. STOP conditions
None triggered. The class uses the existing `wp_postmeta` table and its core `meta_key` index — **schema-free**. No DB_VERSION / capability / operation-registry / MCP / REST / security change. The class is not registered anywhere (autoloaded by the existing PSR-4 `Autoloader`).

## 6. Verdict
All suites green; net-new attributable failures 0; invariants unchanged; compatibility proven; keystone behaves per spec. **Ready for independent audit (Phase F).**
