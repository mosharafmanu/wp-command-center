# PROGRAM-4.7 — PostMetaRollbackStore Design (Phase B)

> **Type:** design (no runtime behavior change). Report-only.
> **Deliverable of Phase C:** `includes/Rollback/PostMetaRollbackStore.php` — a new `RollbackStore` implementation. No runtime is migrated in this phase.

---

## 1. Goal
Generalize SEO's proven inline postmeta-per-record storage into a **reusable, runtime-neutral** `RollbackStore` so future phases (P4.8 Bulk per-item, P4.9 ACF, P4.10 Elementor) can persist rollback records with O(1) addressable resolution, no FIFO eviction, no autoload cost, and natural GC — **schema-free**.

## 2. Requirements ↔ design

| Requirement | How the design meets it |
|---|---|
| **schema-free** | uses existing `wp_postmeta` table + its core `meta_key` index; no table/column/DB_VERSION change |
| **DB_VERSION unchanged** | no schema touched; remains 2.5.0 |
| **postmeta-backed** | one protected meta row per rollback (`add_post_meta($post_id, $prefix.$rollback_id, $record, true)`) |
| **rollback-id addressable** | the rollback_id is **encoded into the meta_key** (`$prefix . $rollback_id`); resolve by id alone |
| **O(1) retrieval** | one indexed `SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s LIMIT 1` (meta_key is indexed) → `get_post_meta` |
| **no FIFO eviction** | each record is its own row; nothing is capped or sliced |
| **runtime-neutral** | meta-key **prefix is a constructor argument** (e.g. `_wpcc_bulk_rb_`, `_wpcc_acf_rb_`, `_wpcc_elementor_rb_`) |
| **legacy-compatible** | implements the **existing** `RollbackStore` interface unchanged; coexists with option stores; does not read/alter their data |
| existing `RollbackStore` interface | implements `persist` / `resolve` / `mark_applied` with identical signatures |
| future Bulk per-item / ACF / Elementor | post-bound entities (post_id) → each gets its own prefix; per-item records are independent rows sharing a `batch_id` carried *inside* the record (store stays shape-agnostic) |

## 3. Interface conformance (no contract change)
The store implements `RollbackStore` exactly:
```php
persist( $entity_id /*post_id*/, string $rollback_id, array $record ): void
resolve( string $rollback_id ): ?array   // { entity_id: post_id, record } | null
mark_applied( $entity_id /*post_id*/, string $rollback_id, array $record ): void
```
`entity_id` is the WordPress post id the record is attached to. The store is **shape-agnostic** about the record (legacy full-object or v2 delta), exactly like the option stores.

## 4. Mechanics (mirrors SEO `:201–206,:483`, generalized)
- **Construction:** `new PostMetaRollbackStore( string $prefix )`. The prefix MUST start with `_` (protected meta — hidden from the Custom Fields UI and from REST `meta` exposure) and identifies the consuming runtime. Length budget: `meta_key` is `VARCHAR(255)`; prefix (~16) + UUID (36) ≈ 52 — comfortably within bounds.
- **persist:** `add_post_meta( (int)$entity_id, $prefix.$rollback_id, $record, /*unique*/ true )`. `unique=true` guarantees a single row per rollback_id on that post; a UUID collision (astronomically unlikely) is rejected rather than duplicated.
- **resolve:** indexed `meta_key` lookup → `post_id`; then `get_post_meta($post_id, $meta_key, true)`; return `['entity_id'=>$post_id,'record'=>$record]` or `null` when absent / not an array (defensive: a malformed/non-array value resolves to `null`, never a fatal).
- **mark_applied:** `update_post_meta( (int)$entity_id, $prefix.$rollback_id, $record )` — caller has already set `rollback_applied=true` on the record (identical contract to the option stores).

## 5. Why postmeta over the option stores (comparison)

| Property | OptionListRollbackStore | OptionKeyedRollbackStore | **PostMetaRollbackStore** |
|---|---|---|---|
| Resolution | O(n) list scan | O(1) array-key (loads whole blob) | **O(1) indexed meta_key** |
| Eviction | FIFO cap (silent drop) | none → unbounded blob | **none, per-row** |
| Autoload cost | whole option per request | whole option per request | **none (postmeta not autoloaded)** |
| Write contention | whole-option rewrite (last-writer-wins) | whole-option rewrite | **single-row insert/update** |
| GC | manual cap | never | **with the post (delete cascades meta)** |
| Per-item (Bulk) | one record holds N items | one record holds N items | **one row per item, shared batch_id** |
| Schema impact | none | none | **none** |

The option stores remain correct and are **retained** for global/non-post-bound runtimes (Settings has no entity; Content/keyed is fine at its scale). The postmeta store is the right tool for **post-bound, high-volume, per-entity** records — exactly Bulk/ACF/Elementor.

## 6. Scope guards (what this phase does NOT do)
- **No runtime migration.** SEO keeps its inline implementation (behavior identical; the new store is a *generalization*, not a replacement, this phase). Settings/Media/Content/Comments/User/Woo/Bulk are untouched.
- **No `RollbackStore` interface change**, no `RollbackDelta` change.
- **No** registry / capability / MCP / REST / schema / DB_VERSION / security change.
- The store is a leaf class, autoloaded by the existing PSR-4 `Autoloader` (`WPCommandCenter\Rollback\PostMetaRollbackStore` → `includes/Rollback/PostMetaRollbackStore.php`); **no registration anywhere**.

## 7. Compatibility surface
Because nothing constructs `PostMetaRollbackStore` yet, introducing the class **cannot change any runtime behavior** — it is dead-but-tested code until P4.8 wires it in. Validation therefore proves (a) the class is interface-correct and behaves per spec against real postmeta, and (b) every existing rollback path is byte-for-byte unchanged (full regression). The risk profile is minimal: additive leaf class.

## 8. Future-fit notes (for P4.8/P4.9/P4.10, not implemented here)
- **Bulk per-item:** persist one record per affected post under `_wpcc_bulk_rb_{id}` with a shared `batch_id` field; a thin batch index (or a `meta_query` on `batch_id`) reverses the batch item-by-item. The store handles the per-row persistence; batch orchestration lives in the runtime.
- **ACF values / Elementor:** post-bound → `_wpcc_acf_rb_{id}` / `_wpcc_elementor_rb_{id}`; whole-def/drift-guard records are just another record shape the store persists opaquely.
- **Non-post entities** (option pages, settings) stay on option/keyed stores — the postmeta store is intentionally post-bound.

## 9. Validation plan (Phase D/E preview)
- **Unit (new `test-postmeta-rollback-store.sh`):** persist→resolve round-trip; O(1) resolve by id alone; mark_applied flips the flag; absent id → null; malformed/non-array meta → null (not fatal); id-collision (unique) rejected, original intact; two records on the same post are independent; record on a deleted post → resolve null (GC); coexistence with an option-store record of the same id (no cross-talk); interface-compliance static asserts.
- **Regression (Phase E):** rollback-delta-core, SEO, Settings, Media, Content, Comments, User, Woo Products, Bulk, plus registry/capability/MCP parity and change-history-rollback — all must hold at current tallies (net-new attributable = 0), proving the additive class changed nothing.
- **Invariants:** 34 · 23 · 40 · 40 · 2.5.0 re-verified.
