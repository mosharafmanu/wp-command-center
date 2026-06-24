# PROGRAM-4.7 — Rollback Storage Audit (Phase A)

> **Type:** source-verified storage audit (no code changes in this phase). Report-only.
> **Branch context:** `program-4c.0a-bulk-rollback-fix` @ `5a57db4` (base for this phase). Production `a41a9d7` unchanged.
> **Purpose:** inventory every current rollback storage pattern and confirm/reject the PROGRAM-4C findings that motivate the `PostMetaRollbackStore` keystone.

---

## 1. Storage inventory (verified from source)

| Runtime | Store mechanism | Key / option | Cap | Resolution lookup | Autoloaded? | Eviction | GC |
|---|---|---|---|---|---|---|---|
| **SEO** | inline **postmeta-per-record** (`add_post_meta($post_id,'_wpcc_seo_rb_{id}',…,true)` `SeoRuntimeManager.php:483`) | meta_key `_wpcc_seo_rb_{rollback_id}` | **none** | **O(1) indexed** `SELECT post_id … WHERE meta_key=%s LIMIT 1` (`:201`) → `get_post_meta` | **No** | **none** | with the post |
| **Settings** | `OptionListRollbackStore('wpcc_settings_rollbacks',200)` (`:73,:120`) | option (list) | **200** | O(n) list scan | option default (yes) | **FIFO** | manual cap |
| **Media** | `OptionListRollbackStore('wpcc_media_rollbacks',100)` persist (`:662`) + inline scans (`:438+`) | option (list) | **100** | O(n) list scan | option default (yes) | **FIFO** | manual cap |
| **Content** | `OptionKeyedRollbackStore('wpcc_content_rollbacks')` (`:76,:551`) | option (keyed by rollback_id) | **none (unbounded)** | O(1) array-key, but loads whole option | option default (yes) | none → **unbounded growth** | none |
| **Comments** | `OptionListRollbackStore('wpcc_comments_rollbacks',100)` persist (`:328`) + inline scans | option (list) | **100** | O(n) list scan | option default (yes) | **FIFO** | manual cap |
| **User** | `OptionListRollbackStore('wpcc_user_rollbacks',100)` persist (`:438`) + inline scans | option (list) | **100** | O(n) list scan | option default (yes) | **FIFO** | manual cap |
| **Woo Products** | `OptionListRollbackStore('wpcc_woo_rollbacks',200)` + inline scans (`:589,:643`) | option (list, **shared** product/variation/coupon/order) | **200** | O(n) list scan | option default (yes) | **FIFO (shared)** | manual cap |
| **Bulk** | inline option list `wpcc_bulk_rollbacks` (`:128–130`) | option (list) | **200** | O(n) list scan | option default (yes) | **FIFO** | manual cap |

> Note: Media/Comments/User/Woo *persist* via `OptionListRollbackStore` but still perform their resolve/mark_applied via inline option scans (they share one option across multiple actions). Content/Settings use the store closer to end-to-end. None of this changes in P4.7.

---

## 2. Per-dimension findings

### 2.1 Storage location
- **SEO** is the only runtime on **postmeta-per-record** (one protected meta row per rollback). Every other migrated runtime is on a **single `wp_option` blob** (list or keyed map).

### 2.2 Lookup complexity
- **SEO:** **O(1)** — the rollback_id is encoded in the `meta_key`, resolved by one indexed `WHERE meta_key=%s` query (`wp_postmeta` has the core `meta_key` index). No row carries more than one rollback.
- **Option-list stores (Settings/Media/Comments/User/Woo/Bulk):** **O(n)** — `get_option` deserializes the whole list, then a linear scan for `$r['id']===$rid`.
- **Option-keyed store (Content):** O(1) array-key access, but still `get_option` of the entire growing map on every call.

### 2.3 Retention / eviction behavior
- **SEO:** records persist until the post is deleted; no cap, no eviction.
- **Option-list:** **FIFO cap** (`array_slice(-cap)`) — Settings/Woo/Bulk 200, Media/Comments/User 100. When the cap is exceeded, **the oldest records are silently dropped**.
- **Content (keyed):** **no cap** → the option grows without bound.

### 2.4 Scalability characteristics
- **Autoload:** the `wpcc_*_rollbacks` options are created via `update_option()` without an explicit `autoload=no`, so WP defaults them to **autoloaded** — they are loaded into memory on **every request**, and grow to the cap size. SEO explicitly moved **off** the "capped, autoloaded `wpcc_seo_rollbacks` option" to postmeta for exactly this reason (`SeoRuntimeManager.php:31,:451`).
- **Shared-option contention:** `wpcc_woo_rollbacks` is shared across product/variation/coupon/order; a busy store fills the 200-cap quickly. Concurrent writers also risk last-writer-wins clobber on the whole-option update.
- **SEO/postmeta:** not autoloaded, one small row per rollback, no whole-blob rewrite, no cross-record contention.

### 2.5 rollback_id lifecycle
- **SEO:** UUID generated at capture → encoded into the meta_key → resolvable by id alone forever (until post delete). **Stable, addressable, never evicted.**
- **Option stores:** UUID generated at capture → appended to the shared option → **evictable** once the cap is exceeded (FIFO) or, for Content, retained but in an ever-growing blob. A surfaced rollback_id can therefore become **silently unresolvable** on a busy store.

---

## 3. Confirm / reject PROGRAM-4C findings

| PROGRAM-4C finding | Verdict | Evidence |
|---|---|---|
| Shared-option **FIFO eviction** silently drops rollback_ids (Woo/ACF/Elementor/etc.) | **CONFIRMED** | `OptionListRollbackStore::persist` `array_slice(-cap)`; caps 100–200; Woo option shared |
| Option-list lookup is **O(n)** | **CONFIRMED** | linear scan in store + inline runtime scans |
| Option stores are **autoloaded** (per-request cost) | **CONFIRMED** | `update_option` default autoload; SEO's own comments cite escaping the "autoloaded" option |
| `OptionKeyedRollbackStore` (Content) is **uncapped/unbounded** | **CONFIRMED** | no cap logic in keyed store |
| **SEO postmeta-per-record** is the scalable reference (O(1), no eviction, not autoloaded, GC'd with post) | **CONFIRMED** | `SeoRuntimeManager.php:201–206,:483` |
| Bulk shares the same FIFO-capped option weakness | **CONFIRMED** | `BulkRuntimeManager.php:128–130` |

**No PROGRAM-4C finding was rejected.** The audit also surfaces one nuance worth recording: the weakness is twofold — **eviction** (option-list FIFO) *and* **autoload cost / whole-blob rewrite** (all option stores). The postmeta keystone addresses both.

---

## 4. Keystone requirement (derived)
A `PostMetaRollbackStore` that **generalizes the SEO inline pattern** into a reusable `RollbackStore` implementation gives every post-bound runtime: O(1) indexed resolution by rollback_id, no FIFO eviction, no autoload cost, natural GC with the entity, and no whole-option contention — **schema-free** (uses the existing `wp_postmeta` table and its core `meta_key` index; no new table, no column, no DB_VERSION bump). It must be **runtime-neutral** (configurable meta-key prefix), implement the **existing** `RollbackStore` interface unchanged, and **not** migrate any runtime in this phase (introduction only).

Proceeding to Phase B (design).
