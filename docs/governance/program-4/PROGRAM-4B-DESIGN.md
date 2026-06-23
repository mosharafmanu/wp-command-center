# PROGRAM-4B — Integration + Rollback Core Hardening · Design Report

> **Branch:** `program-4b-integration-core-hardening` (octopus-merge of P4.0 + P4.1–P4.5; 0 conflicts; merge `6a8aad0`). **Scope:** integration + core hardening only. **No Woo/ACF/Bulk/G2; no schema/DB_VERSION/op/cap/MCP/REST/UI; no merge/push/deploy.**
> **Driver:** `PROGRAM-4-MIDPOINT-CONSOLIDATION-AUDIT.md` findings D1/D2/D3.

## 1. Integration confirmation
All six commits are ancestors of HEAD (`is-ancestor` ✓: 2234dcc, 0788720, 8982e6c, dbc7c47, 4ccf18b, 6b5d0ef); 0 unmerged paths; no invariant/forbidden file in `git diff 2234dcc..HEAD`; invariants **34/23/40/40/2.5.0**; clean baseline on integration — Settings 35/0, Media 38/0, Content 27/0, Comments 24/0, User 25/0, core 25/0, SEO 56/0, parity 18/61/18.

## 2. What is being hardened (from the audit)
- **D1 — record-builder duplication** (×5): the v2 record construction (`fields[f]={after,keys}` loop + scaffolding) is hand-rolled in every `store_*_delta`.
- **D2 — response-envelope duplication** (×5, with message drift): the complete/partial/conflict envelope + `wpcc_rollback_conflict|partial` selection.
- **D3 — three record-storage schemes:** postmeta+SQL (SEO), keyed-option (Content), list-option scan (Settings/Media/Comments/User). The list scan is O(n) + unbounded → won't scale to Woo/Bulk.

## 3. Design

### D1 — `RollbackDelta::build_record()`
```
public static function build_record( array $touched, array $prior, array $after, array $context, array $head = [] ): array
```
Builds the **fields** map (`f => ['after'=>$after[f]??'', 'keys'=>$prior[f]['keys']??[]]`) + the common scaffolding (`version:2, rollback_applied:false, created_at, session_id, task_id`), merged under `$head` (runtime identity: `id`, the entity-id key, `action`). **Byte-identical** to the current inline records (same keys/values) ⇒ on-disk format unchanged.

### D2 — `RollbackDelta::result()`
```
public static function result( array $base, array $outcome ): array
```
`$base` = runtime identity (`action`, entity-id key, `rollback_id`). On `complete` → `$base + {restored:true, status:complete, restored_fields, skipped_fields:[]}`. On `partial|conflict` → `$base + {error:true, code:wpcc_rollback_partial|conflict, message, restored:false, status, restored_fields, skipped_fields, conflicts}`. **Standardises the (previously drifting) messages**; preserves every field the focused tests assert (`status`, `code`, `restored`, `skipped_fields`).

### D3 — `RollbackStore` (consistent storage API)
```
interface RollbackStore {
    public function persist( $entity_id, string $rollback_id, array $record ): void;
    public function resolve( string $rollback_id ): ?array;   // ['entity_id'=>…, 'record'=>…] | null
    public function mark_applied( $entity_id, string $rollback_id, array $record ): void;
}
```
Two implementations matching the **existing on-disk formats** (backward-compatible — no migration):
- **`OptionListRollbackStore`** (option holds a list; resolve by scanning `$r['id']`; FIFO cap) → Settings(`wpcc_settings_rollbacks`,200), Media(`wpcc_media_rollbacks`,100), Comments(`wpcc_comments_rollbacks`,100), User(`wpcc_user_rollbacks`,100).
- **`OptionKeyedRollbackStore`** (option keyed by rollback_id; `$records[$id]`) → Content(`wpcc_content_rollbacks`).
- **SEO is intentionally NOT migrated** (its postmeta-per-record + indexed-SELECT store is already the most scalable, is the reference the helpers were extracted from, and carries the most-tested legacy paths — migrating it adds risk for no Woo/ACF/Bulk benefit). Documented exception; SEO record format frozen.

## 4. Backward compatibility (hard requirement)
- **Record format unchanged:** `build_record` emits the identical v2 record; stores write to the same option in the same shape/cap. Existing v2 records (Settings/Media/Content/Comments/User) resolve and restore unchanged.
- **Legacy records:** `resolve()` returns the raw record; the runtime keeps its `isset($record['fields'])` branch (v2 → core delta; else → its existing legacy/full-object restore). Legacy `before_state` records, Content's keyed legacy records, Media's `media_restore` path, and the non-`update` actions (trash/delete/roles/etc.) are untouched.
- **No DB_VERSION / schema change** (same options, same keys).

## 5. Migration plan (incremental, test after each)
For each of Settings → Media → Content → Comments → User:
1. `store_*_delta` → `RollbackDelta::build_record(...)` + `$store->persist(...)`.
2. rollback v2 branch → `$store->resolve(...)` (replacing the inline find) → `RollbackDelta::restore(...)` → on complete `$store->mark_applied(...)` → `RollbackDelta::result(...)`.
3. Keep each runtime's legacy/other-action branches as-is.
4. Run that runtime's focused suite (**must hold:** 35/38/27/24/25). Stop+fix on any attributable failure.
Media migrates **both** restore paths (`rollback()` + `media_restore`) via the shared helper.

## 6. Affected files
**New:** `includes/Rollback/RollbackStore.php`, `OptionListRollbackStore.php`, `OptionKeyedRollbackStore.php`. **Modified:** `includes/Rollback/RollbackDelta.php` (+build_record, +result); the 5 runtime managers (Settings/Media/Content/Comments/User); the 5 focused tests (re-point static guards to the core + idempotent legacy fixtures). **Unchanged:** SEO runtime + `SeoProvider`, `FieldAccessor`/`PostMetaAccessor`/the 5 accessors, OperationExecutor, registries, Schema, REST, UI.

## 7. Validation plan
Per-runtime focused suite after each migration; then full set: core, SEO, Settings, Media, Content, Comments, User, change-history-rollback (standalone), operations-registry/capability-runtime/mcp-error-surface, invariants 34/23/40/40/2.5.0. Also: a **backward-compat fixture test** — restore a pre-hardening v2 record (and a legacy record) through the new store. Fix fixed-id legacy fixtures to unique ids (idempotency). **Stop** for schema need / contract change / unrecoverable conflict / unfixable attributable regression.

## 8. Decision
Proceed. D1+D2+D3 implemented on the 5 option-stored runtimes; SEO documented as the frozen reference. Behaviour-preserving, backward-compatible, invariant-frozen; gives Woo/ACF a reusable core + store (Bulk will add a per-item store on this API).
