# PROGRAM-4A — RollbackDelta Core · Design Report (Phase P4.0)

> **Type:** engineering design report (no code, no implementation). Autonomous mode.
> **Date:** 2026-06-23 · **Phase:** P4.0 (keystone) of PROGRAM-4 Rollback Integrity Expansion.
> **Parent design:** [`PROGRAM-4-DESIGN.md`](PROGRAM-4-DESIGN.md). **Goal of P4.0:** extract SEO's proven field-scoped, drift-aware delta machinery into a **shared, runtime-agnostic core** (`RollbackDelta` + `FieldAccessor` + `RollbackStore`), and **re-seat SEO onto it behavior-preservingly** — so later phases add a small accessor per runtime instead of re-deriving drift/idempotency/conflict correctness nine times.
> **Hard guarantee of this phase:** zero functional change. **SEO must re-score 52/52**, and **already-deployed v2 SEO records (created by `7aa7e84`) must restore byte-identically.**
> **Constraints (Rule 8):** no commit / push / deploy / AI-enable / mode change / **schema change** / baseline refresh without explicit authorization. P4.0 is schema-free by construction (it only refactors existing postmeta storage).

---

## 1. What we are extracting (grounded in the SEO source)

The current SEO delta lives across two files. Verified seams:

**`SeoRuntimeManager.php`**
- `seo_update()` (`:80`) — orchestrates: `capture_prior` → `SeoProvider::write` → `store_rollback`.
- `capture_prior()` (`:471`) — per touched field, per `SeoProvider::backing_keys`, record `{existed: metadata_exists, prior: get_post_meta}`.
- `store_rollback()` (`:500`) — builds the **v2 record** (`version:2`, `fields:{name:{after,keys}}`, `post_id`, `provider`, `rollback_applied`, timestamps, session/task) and persists it as **postmeta** `_wpcc_seo_rb_{id}` (`:524`).
- `seo_restore()` (`:187`) — resolves record by `rollback_id` via indexed `SELECT post_id FROM postmeta WHERE meta_key` (`:199`); guards `rollback_applied`; **dispatches**: `fields` present → `restore_delta`; else postmeta full-snapshot → `restore_legacy_meta`; else option → `seo_restore_legacy`.
- `restore_delta()` (`:288`) — the correctness core: per field, `read_field(current)` vs `after`; on drift → skip + conflict; on match → per backing key `existed ? update_post_meta(prior) : (exists ? delete_post_meta)`; compute `complete|partial|conflict`; mark `rollback_applied` only on `complete`; audit `restored/skipped/conflicts`; return envelope (success or `wpcc_rollback_conflict|partial`).
- `values_equal()` (`:377`) — drift comparator; **robots** = order-insensitive set compare, scalars = string compare.
- Legacy: `restore_legacy_meta()` (`:264`), `seo_restore_legacy()` (`:232`).

**`SeoProvider.php`** (the de-facto field accessor today)
- `backing_keys(field, provider)` (`:125`) — unified field → backing meta key(s); robots fans out (Rank Math 1 key, Yoast 3).
- `read_field(post_id, field, provider)` (`:151`) — current unified value (scalar string or normalized robots array).
- `write(post_id, fields, provider)` (`:183`) — writes touched fields (`'' ⇒ delete`), returns post-write read.

**The split is already latent in the code.** P4.0 makes it explicit and reusable.

### 1.1 Generic vs SEO-specific
| Concern | Generic (→ core) | SEO-specific (→ accessor/runtime) |
|---|---|---|
| v2 record shape, version flag, timestamps, session/task | ✅ | — |
| capture loop (touched → keys → {existed, prior}) | ✅ (calls accessor for keys/exists/get) | the *keys* and *exists/get primitives* |
| restore loop (drift-skip, key write/delete, status, idempotency, audit/result envelope, error codes) | ✅ | the *read_field*, *equals*, *key write/delete primitives* |
| `after` = post-write per-field value | ✅ (stored generically) | produced by the runtime's write |
| backing_keys / read_field / robots fan-out / provider | — | ✅ `SeoFieldAccessor` (wraps `SeoProvider`) |
| record resolution by id (which meta table) | ✅ via `RollbackStore` | the *table* (postmeta) = `PostMetaRollbackStore` |
| dispatch (v2 vs legacy-meta vs legacy-option) | ✅ thin helper | the *legacy record shapes* (full-object) per runtime |

---

## 2. Target architecture (three collaborators)

All new classes live in `includes/Rollback/` under `WPCommandCenter\Rollback` (joining `RollbackManager`, `SnapshotManager`; PSR-4 already configured).

### 2.1 `FieldAccessor` (interface) — the per-runtime adapter
Read/write primitives over one entity's storage. Designed against SEO (the hardest case: multi-key fields + provider + set-valued robots) so it generalizes.
```
interface FieldAccessor {
    // unified field → its backing storage key(s)
    public function backing_keys(string $field): array;
    // current unified value of a field (drift LHS) — scalar or structured
    public function read_field($entity_id, string $field): mixed;
    // raw key primitives (entity-storage specific: post/user/comment meta, option, WC setter)
    public function key_exists($entity_id, string $key): bool;
    public function key_get($entity_id, string $key): mixed;
    public function key_set($entity_id, string $key, $value): void;
    public function key_delete($entity_id, string $key): void;
    // drift comparator (default string compare; override per field type, e.g. robots set)
    public function equals(string $field, $current, $after): bool;
}
```
- **`PostMetaAccessor`** — base implementing `key_*` via `metadata_exists/get_post_meta/update_post_meta/delete_post_meta` and `equals` = string compare. Most runtimes subclass this with a `backing_keys`/`read_field` where field == key (1:1).
- **`SeoFieldAccessor extends PostMetaAccessor`** — `backing_keys`/`read_field` delegate to `SeoProvider` (carrying `provider`); overrides `equals('robots', …)` for the sorted-set compare. This is the *only* SEO-aware class.
- Future: `UserMetaAccessor`, `CommentMetaAccessor`, `OptionAccessor`, `WooProductAccessor` (later phases — not P4.0).

### 2.2 `RollbackStore` (interface) — record persistence + resolution
Abstracts *which* meta table holds the record and how it's resolved by `rollback_id`.
```
interface RollbackStore {
    public function persist($entity_id, string $rollback_id, array $record): void;
    public function resolve(string $rollback_id): ?array;     // ['entity_id'=>…, 'record'=>…] or null
    public function update($entity_id, string $rollback_id, array $record): void;  // mark applied
}
```
- **`PostMetaRollbackStore`** — prefix `_wpcc_seo_rb_` (configurable per namespace); `persist` = `add_post_meta(…, true)`; `resolve` = the indexed `SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=…` then `get_post_meta`; `update` = `update_post_meta`. **Byte-identical to SEO's current storage** when constructed with SEO's prefix.
- Future stores: `UserMetaRollbackStore`, `CommentMetaRollbackStore`, `OptionRollbackStore` (later).

### 2.3 `RollbackDelta` (the pure logic core)
Stateless given an accessor + store. Owns the correctness-critical algorithm.
```
final class RollbackDelta {
    // capture prior state of the touched fields' backing keys (pre-write)
    public static function capture(FieldAccessor $a, $entity_id, array $touched): array;
    // build the v2 record (after-values supplied from the runtime's post-write read)
    public static function build_record($entity_id, array $touched, array $prior,
                                        array $after, array $context, array $meta): array;
    // field-scoped, drift-aware restore → ['status'=>complete|partial|conflict,
    //   'restored'=>[], 'skipped'=>[], 'conflicts'=>[]]
    public static function restore(FieldAccessor $a, array $record): array;
}
```
- `restore()` is a 1:1 lift of `restore_delta()`'s loop (`:294–314`) with `update_post_meta/delete_post_meta/read_field/values_equal` replaced by `$a->key_set/key_delete/read_field/equals`. **Status computation, idempotency decision, and result/conflict shaping are preserved verbatim.**
- `capture()` is a 1:1 lift of `capture_prior()` (`:471`) via the accessor.
- The audit emission + the `mark applied` write stay in the **runtime** (they need the runtime's `AuditLog` instance and the store), invoked around `RollbackDelta::restore()`. This keeps the core free of WP/audit singletons and easy to unit-test with a fake accessor.

### 2.4 Dispatch + legacy stay in the runtime (thin)
A small shared helper MAY centralize the resolve→guard→dispatch skeleton, but **legacy restore is per-runtime** (each runtime's pre-v2 `before_state` is a different full-object shape). SEO keeps `restore_legacy_meta`/`seo_restore_legacy` unchanged. The dispatch becomes:
```
$resolved = $store->resolve($rollback_id);
if ($resolved) {
    $record = $resolved['record'];
    if (!empty($record['rollback_applied']))  return already_applied_error;
    if (isset($record['fields']))             // v2 delta
         $r = RollbackDelta::restore($seoAccessor($record['provider']), $record);
         …mark applied on complete, audit, return envelope…
    else return $this->restore_legacy_meta(...);   // unchanged
} else return $this->seo_restore_legacy($rollback_id);  // unchanged option fallback
```

---

## 3. The back-compatibility constraint (non-negotiable)

`7aa7e84` is **live in production**; v2 SEO rollback records already exist on disk with this exact top-level shape:
```php
[ 'id', 'version'=>2, 'post_id', 'provider', 'fields'=>{f:{after,keys:{k:{existed,prior}}}},
  'rollback_applied', 'created_at', 'session_id', 'task_id' ]
```
**P4.0 must not change the on-disk record shape for SEO.** Therefore:
- `PostMetaRollbackStore` keeps `post_id` as the entity-id field and the `_wpcc_seo_rb_` prefix; it does **not** rename `post_id`→`entity_id` on disk.
- `SeoFieldAccessor` reads `provider` from the record at restore.
- The generic core treats the entity id and `meta` (provider) opaquely; the **SEO store/accessor map the on-disk names**. New runtimes may use cleaner names in their own namespaces, but **SEO's persisted shape is frozen**.
- A dedicated test asserts a **hand-built record in the exact `7aa7e84` shape restores identically** post-extraction (regression fixture, not just round-trip).

This makes "behavior-preserving" concrete: same on-disk format, same restore output, same audit events, same error codes.

---

## 4. Generality proof (validate the interface against a second, dissimilar runtime — on paper)

To avoid baking SEO assumptions into the "generic" core, the interface is checked against **Settings** (the most dissimilar near-term target: global, option-stored, field == key 1:1, no provider, no multi-key fan-out):

| Accessor method | SEO (`SeoFieldAccessor`) | Settings (`OptionAccessor`, future) |
|---|---|---|
| `backing_keys(field)` | `SeoProvider::backing_keys` (1 or 3 keys) | `[ $field ]` (the option name itself) |
| `read_field(id, field)` | unified read (scalar/robots) | `get_option($field)` |
| `key_exists/get/set/delete` | `*_post_meta` | option exists / `get_option` / `update_option` / `delete_option` |
| `equals(field,a,b)` | robots set-compare; else string | string compare |
| identity (`entity_id`) | `post_id` | none → fixed sentinel (global), record keyed by `rollback_id` |
| `RollbackStore` | `PostMetaRollbackStore` | `OptionRollbackStore` (record in an option keyed by id) |

The interface absorbs both without change → it is genuinely generic, not SEO-shaped. (Settings is **not** implemented in P4.0; this is a design check only.) The one place the abstraction must flex is **global vs entity-bound storage** — handled entirely inside `RollbackStore`, not the core algorithm.

---

## 5. Risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| **R1** | Refactor changes SEO behavior (subtle drift in status/ordering/empty-string handling) | HIGH | 1:1 lift, not rewrite; **`test-seo-rollback-delta.sh` must stay 52/52**; plus the full SEO suite set (store 28/0, undo 33/0, apply 76/0, workflow-rollback 16/0). Any delta = stop + fix (Rule 4). |
| **R2** | On-disk v2 record shape drift breaks already-deployed records | HIGH | §3 freeze; SEO store/accessor map on-disk `post_id`/`provider`; **fixture test** restoring a `7aa7e84`-shape record. |
| **R3** | Over-abstraction bakes in SEO assumptions | MED | §4 paper-validation against Settings (dissimilar); interface designed from the hardest case (multi-key + provider + set field). |
| **R4** | Core couples to WP globals / audit singleton → untestable, heavier | MED | core is pure (accessor + record in, result out); audit + `mark applied` stay in runtime; new `test-rollback-delta-core.sh` drives the core with a **fake in-memory accessor** (no WP). |
| **R5** | Class loading / autoload miss for new `WPCommandCenter\Rollback\*` | LOW | PSR-4 already maps the namespace; mirror `SnapshotManager` placement; `php -l` + suite load proves wiring. |
| **R6** | Scope creep — migrating a second runtime inside P4.0 | MED | **P4.0 wires ONLY SEO.** Settings/others are separate phases. Accessor for a second runtime is design-checked, not coded. |
| **R7** | `robots`/structured-field comparator regressions | MED | `equals` override carried verbatim from `values_equal`; S6/S10 robots scenarios in the gate. |
| **R8** | Hidden callers of the SEO private methods | LOW | `store_rollback`/`restore_delta`/`capture_prior` are `private`; grep confirms no external callers; the public surface (`seo_manage` actions, `rollback_id`) is unchanged. |

No security-model, capability, MCP-contract, or schema change is introduced. Invariants **34·23·40·40·2.5.0** are untouched (no op/cap/tool/DB_VERSION change) and re-verified in the gate.

---

## 6. Affected files

**New (`includes/Rollback/`, namespace `WPCommandCenter\Rollback`):**
- `FieldAccessor.php` — interface.
- `PostMetaAccessor.php` — base (post-meta primitives + default string `equals`).
- `RollbackStore.php` — interface.
- `PostMetaRollbackStore.php` — postmeta persistence + indexed resolve (SEO-prefix configurable).
- `RollbackDelta.php` — `capture` / `build_record` / `restore` (the lifted correctness core).

**New (SEO accessor — `includes/Operations/` or `includes/Rollback/`):**
- `SeoFieldAccessor.php` — `extends PostMetaAccessor`, wraps `SeoProvider`, robots `equals` override.

**Modified:**
- `includes/Operations/SeoRuntimeManager.php` — `seo_update` delegates capture/build/persist to the core; `seo_restore` dispatch delegates the v2 branch to `RollbackDelta::restore` (legacy branches unchanged); delete now-inlined `capture_prior`/`store_rollback`/`restore_delta`/`values_equal` (moved to core/accessor) or make them thin delegators. **Public behavior unchanged.**
- `includes/Operations/SeoProvider.php` — **no change expected** (the accessor wraps it). If anything, no new public surface.

**New tests:**
- `tests/test-rollback-delta-core.sh` — unit-level: drives `RollbackDelta` with a **fake accessor** (in-memory key store) to prove drift-skip, existence fidelity, partial/conflict/complete, idempotency — **independent of SEO/WP**.
- Reuse unchanged: `tests/test-seo-rollback-delta.sh` (the behavior-preservation oracle).

**Not touched:** any other runtime, OperationRegistry, CapabilityRegistry, McpServerRuntime, Schema, ACTION_ROLLBACKS map (SEO already dispatches via `seo_restore`).

---

## 7. Validation plan

**Gate = behavior preservation + core unit coverage + invariants. All must pass; any failure → stop, fix, re-validate, update report (Rules 4–5).**

1. **Lint:** `php -l` on every new + modified file.
2. **Behavior-preservation oracle (the decisive test):** `tests/test-seo-rollback-delta.sh` → **must be 52/52**, identical scenario-by-scenario (S1–S10), including the static source assertions (some will be re-pointed at the new class/method names — those assertion *targets* may move, but the asserted *behaviors* stay).
3. **Back-compat fixture (R2):** a test that writes a record in the exact `7aa7e84` on-disk shape (`post_id`/`provider`/`fields`, no new keys) and asserts `seo_restore` restores it identically and marks it applied. Plus the legacy full-object (`before_state`) postmeta and option records still restore (`restore_legacy_meta`, `seo_restore_legacy` unchanged).
4. **Core unit suite (R4):** `tests/test-rollback-delta-core.sh` with a fake accessor — empty-prior delete, value-prior restore, disjoint sibling survival, same-field drift→conflict, out-of-order, idempotency, complete/partial/conflict status, robots-style set comparator via an override. Proves the core *without* SEO.
5. **SEO regression breadth:** `test-seo-rollback-store.sh` (28/0), `test-seo-undo.sh` (33/0), `test-seo-apply.sh` (76/0), `test-workflow-rollback-f61.sh` (16/0), `test-change-history-runtime.sh` (57/0) — all stay at current tallies.
6. **Dispatcher path:** `test-change-history-rollback.sh` (run **standalone** to avoid the known concurrency-contended backfill flake; expect 48/0) — proves `OperationExecutor::rollback` → `seo_restore` still works end-to-end.
7. **Invariant guard:** `OPERATION_MAP=34 · capabilities=23 · catalogue=40 · MCP=40 · DB_VERSION=2.5.0` re-verified (static + `operations-registry`/`capability-runtime`/`mcp-error-surface` green).
8. **Pre-deploy (deferred, deploy-coupled):** full serial T2 + prod token-gated SEO verify — only at an authorized deploy decision (Rule 8), not in P4.0.

**Self-audit checklist before declaring P4.0 done:** (a) no on-disk record shape change for SEO; (b) no new op/cap/tool/schema; (c) core has zero WP/audit coupling; (d) every moved method is a 1:1 behavior lift (diff-reviewed); (e) 52/52 + core unit green + invariants held; (f) failure classification applied to any red (attributable vs environmental).

---

## 8. Exit criteria → next phase

P4.0 is complete when: SEO is fully re-seated on `RollbackDelta`/`FieldAccessor`/`RollbackStore`, scores **52/52** with identical behavior and frozen on-disk shape, the **core unit suite** passes against a fake accessor, and invariants hold. At that point the shared core is proven and the cheapest path forward is **P4.1 — Settings** (`OptionAccessor` + `OptionRollbackStore`), the first *new* runtime to consume the core — its design report follows on approval.

> No code was written, committed, or deployed. This is a design report only (Rule 8).
