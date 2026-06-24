# PROGRAM-4.7 — PostMetaRollbackStore Keystone · Final Report

> **Branch:** `program-4.7-postmeta-rollback-store` (from `program-4c.0a-bulk-rollback-fix` @ `5a57db4`). **No merge / push / deploy.**
> Companion: [Audit](POSTMETA-STORE-AUDIT.md) · [Design](POSTMETA-STORE-DESIGN.md) · [Validation](POSTMETA-STORE-VALIDATION.md) · [Independent Audit](POSTMETA-STORE-INDEPENDENT-AUDIT.md).

## 1. Outcome
Introduced `PostMetaRollbackStore` — a reusable, runtime-neutral `RollbackStore` that generalizes SEO's proven inline postmeta-per-record pattern. It gives post-bound runtimes **O(1) indexed resolution by rollback_id, no FIFO eviction, no autoload cost, single-row writes, and GC-with-the-post** — **schema-free**. This is the storage foundation for P4.8 (Bulk per-item), P4.9 (ACF), P4.10 (Elementor). **No runtime was migrated; no rollback behavior changed.**

## 2. Phase A — audit (confirmed PROGRAM-4C)
Every option-backed store (Settings/Media/Comments/User/Woo/Bulk) is FIFO-capped (100–200), O(n)-scanned, and autoloaded; Content's keyed option is uncapped/unbounded; SEO alone is postmeta-per-record (O(1), no eviction, not autoloaded, GC'd). All PROGRAM-4C findings **confirmed**, none rejected; added nuance: the weakness is both eviction *and* autoload/whole-blob rewrite.

## 3. Phase B/C — design + implementation
New leaf class `includes/Rollback/PostMetaRollbackStore.php`:
- `persist($post_id,$id,$record)` → `add_post_meta($post_id, $prefix.$id, $record, unique:true)`.
- `resolve($id)` → indexed `SELECT post_id … WHERE meta_key=%s LIMIT 1` → `get_post_meta`; returns `{entity_id,record}` or `null` (defensive on absent/malformed).
- `mark_applied($post_id,$id,$record)` → `update_post_meta`.
- Constructor takes the meta-key **prefix** (runtime-neutral) and forces a protected leading `_`.
Implements the **existing** `RollbackStore` interface verbatim; autoloaded by the existing PSR-4 `Autoloader` (no registration). Zero modification to any existing file.

## 4. Phase D/E — compatibility + validation
- **Keystone unit suite (new): 30/0** — resolve-by-id, no-FIFO independence, unique rejection, GC, malformed-safety, option coexistence (no cross-talk), protected-prefix forcing, guard no-ops.
- **Regression (all green):** rollback-delta-core 25/0 · SEO 56/0 + store 28/0 · Settings 38/0 · Media 41/0 · Content 30/0 · Comments 27/0 · User 28/0 · Woo runtime 117/0 + STEP93 19/0 · Bulk fix 35/0 + runtime 41/0 · operations-registry 18/0 · capability-runtime 61/0 · mcp-error-surface 18/0 · change-history-rollback 48/0 (standalone). **Net-new attributable failures: 0.**
- **Invariants:** OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0 — held.

## 5. Phase F — independent audit
**GO.** No GO-blocking defects across 10 attacked vectors. Two non-blocking notes: a LOW usage-contract concern (rollback_ids must be globally unique — UUIDv4 satisfies this; inherent to id-addressable resolution and identical to the SEO reference) and an INFO test nit (a tautological assertion, **fixed** to verify `entity_id == post id`; suite re-ran 30/0).

## 6. Scope / STOP
- Exactly one new code file + one new test. **No existing file modified** (`git diff 5a57db4 -- includes/` clean).
- **No** schema / DB_VERSION / capability / operation-registry / MCP / REST-contract / security-model change. Uses `wp_postmeta` + its core `meta_key` index. **No STOP condition triggered.**
- No runtime migrated; no rollback semantics or contract changed.

## 7. Usage contract for consumers (P4.8/P4.9/P4.10)
- Use a **globally-unique** rollback_id (UUIDv4, as all runtimes already do) — resolution is by id alone, so ids must not be reused across posts.
- Choose a **distinct protected meta-key prefix** per runtime (`_wpcc_bulk_rb_`, `_wpcc_acf_rb_`, `_wpcc_elementor_rb_`).
- The store is **post-bound**; non-post entities keep the option/keyed stores.

## 8. GO / NO-GO
**GO** — keystone introduced safely; additive-only; O(1) addressable, eviction-free, GC-clean, malformed-safe, coexistent with existing stores; invariants frozen; independent audit GO; attributable failures 0. **Committed on `program-4.7-postmeta-rollback-store` only — no merge / push / deploy.**

## 9. Next
P4.8 Bulk delta redesign consumes this keystone (per-item postmeta records + a batch index) to close Bulk F-1; P4.9 ACF and P4.10 Elementor follow.
