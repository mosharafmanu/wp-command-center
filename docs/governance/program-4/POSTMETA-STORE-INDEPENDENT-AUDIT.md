# PROGRAM-4.7 — PostMetaRollbackStore Independent Audit (Phase F)

> **Type:** independent adversarial audit (read-only; no code changes). Report-only.
> **Mandate:** assume the implementation is wrong; attempt to break lookup, retrieval, rollback resolution, legacy records, id collisions, orphaned records, backward compatibility. **Auditor:** fresh agent, separate from the implementer.

---

## VERDICT: **GO**

The keystone is safe. The auditor attacked all 10 required vectors and found **no defect** that breaks resolution, corrupts/collides records, touches existing data, leaks unprotected meta, or violates additive-only scope. One LOW observational concern and one INFO test nit — both addressed/non-blocking.

## Checks

| # | Attack | Result | Evidence |
|---|---|---|---|
| 1 | Interface conformance | **PASS** | byte-exact `persist`/`resolve`/`mark_applied` signatures vs `RollbackStore.php`; `php -l` clean |
| 2 | Lookup / injection / prefix collision | **PASS** | `$wpdb->prepare(... meta_key = %s ...)` parameterized; meta_key built via one private `meta_key()`; **exact-equality** match (not LIKE) ⇒ `_wpcc_a_`+id never matches `_wpcc_ab_`+id |
| 3 | Resolution semantics | **PASS** | returns `{entity_id:int, record:array}`; `null` (never throws) on empty/absent id, post_id≤0, non-array value |
| 4 | Legacy / coexistence | **PASS** | only touches rows under its own constructor prefix; never reads/writes any `wpcc_*_rollbacks` option or the SEO `_wpcc_seo_rb_` key; T9 proves no option cross-talk |
| 5 | id collisions | **PASS** (LOW noted) | `add_post_meta(unique=true)` rejects a duplicate `(post,key)`, original preserved (T6). LOW: same id on a *different* post would create a second same-key row; `resolve` LIMIT 1 picks one. UUIDv4 + post-bound ⇒ practically unreachable; behavior defined, not fatal; identical to the SEO reference |
| 6 | Orphaned records | **PASS** | meta cascades on post delete ⇒ resolve null (T7); no dangling-row fatal path |
| 7 | Protected meta | **PASS** | constructor forces leading `_` (handles `''`→`'_'` and already-underscored); T2 asserts `is_protected_meta`; no REST/Custom-Fields leak |
| 8 | Blast radius / additive-only | **PASS** | `git diff 5a57db4` shows zero tracked change; only the new class + new test (untracked); interface/RollbackDelta/option stores/runtimes byte-unchanged |
| 9 | Test rigor | **PASS** | 11 functional cases prove resolve-by-id, no-FIFO independence, unique rejection, GC, malformed-safety, coexistence, prefix-forcing, guards. INFO nit (a tautological T1 assertion) — **fixed**: T1 now asserts `entity_id == post id` |
| 10 | STOP conditions | **PASS** | no schema/DB_VERSION/capability/OperationRegistry/MCP/REST/Security change; uses `wp_postmeta` + core `meta_key` index; zero references outside the class (no runtime migrated) |

## Defects
**None GO-blocking.**
- **LOW (documented, not code-changed):** `unique=true` is per-post, so a rollback_id reused across *different* posts yields two same-key rows and a nondeterministic `LIMIT 1` resolve. This is inherent to id-addressable-without-post-id resolution and is the **same assumption the SEO reference makes**; UUIDv4 ids make it practically unreachable, and the result is always a valid record (never a fatal). Consumers must use globally-unique ids (UUIDs) — documented as a usage contract in the final report. Adding a cross-post uniqueness check would require a table scan and defeat the O(1) goal; intentionally not done.
- **INFO (fixed):** the implementer strengthened the previously-tautological T1 assertion to verify `entity_id == post id`; suite re-ran 30/0.

## Conclusion
`PostMetaRollbackStore` is interface-correct, injection-safe, id-addressable with O(1) indexed resolution, free of FIFO eviction, GC-clean, malformed-safe, and provably non-interfering with existing option/SEO storage. It is a pure additive leaf class with zero blast radius. **GO for branch commit (Phase G).** The LOW concern is recorded as a UUID-uniqueness usage contract for the consuming phases (P4.8/P4.9/P4.10).
