# PROGRAM-4.9 — ACF Rollback Design (Phase C)

> **Type:** design (no code in this phase). Report-only. **Scope: `includes/Operations/ACFRuntimeManager.php` + one new ACF-scoped accessor.**
> **Principle:** make ACF rollback honest and safe. Treat nested values **atomically** (no decomposition). Refuse-on-drift, never clobber. Preserve all current behavior + legacy records.

---

## 1. Approach comparison (per the mission)

| Approach | Where it fits | Verdict |
|---|---|---|
| **1. Field-scoped RollbackDelta** | a single ACF field's value as one "field" | **CHOSEN for value_update** — the value is the unit; drift+existence via the proven core |
| **2. Whole-field value rollback** | nested values (repeater/flex/group/clone/gallery/relationship) | **CHOSEN (atomic)** — captured/restored whole; whole-value drift compare; **no decomposition** |
| **3. Whole-definition + fingerprint drift guard** | definition update-in-place (group/field/location/layout) | **CHOSEN (conservative)** — refuse-on-drift, new-records-only |
| **4. Snapshot-only** | — | rejected (values/defs already restorable; snapshot adds no safety here) |
| **5. Irreversible / unsupported visibility** | `json_import` | **CHOSEN** — fixes the false clean-success |

Approaches 1+2 are the **same mechanism** (RollbackDelta over a single `value` field whose value may be scalar or a whole nested array) — the distinction is only the drift comparator (normalized whole-value), so nested values are handled without decomposition.

## 2. New code: `AcfValueAccessor` (necessary)
A `FieldAccessor` over **one ACF field value on a post**, exposing the unified field `value`:
- Constructed with the selector (`field_key` **or** `field_name`, as `value_update` accepts both).
- Resolves the underlying **field name** once via `acf_get_field(selector)['name']` (falls back to the selector) — needed because **existence** must be checked on the raw meta_key (= field name), not the key. (This is why P4.8's name-based `BulkAcfAccessor` is *not* reused — and `BulkAcfAccessor` stays untouched, avoiding P4.8 retest churn.)
- `read_field`/`key_get` → `get_field(selector,$post_id)` (whole value, scalar or nested array).
- `key_exists` → `metadata_exists('post',$post_id,$resolved_name)` (distinguishes absent from present-but-empty — confirmed by the Phase-A probe).
- `key_set` → `update_field(selector,$value,$post_id)`; `key_delete` → `update_field(selector,null,$post_id)` (probe: removes the meta → existence-faithful clear).
- `equals('value',$cur,$after)` → **normalized whole-value compare** (`wp_json_encode` for arrays; string for scalars) — so ANY change (incl. one nested row) registers as drift.

## 3. value_update — drift-aware whole-field delta (surfaces 1–3)
**Apply (`value_update`):**
```
$acc = new AcfValueAccessor($key);
$prior = RollbackDelta::capture($acc, $post_id, ['value']);   // existed + prior whole value
update_field($key, $value, $post_id);                          // unchanged mutation
$after = ['value' => $acc->read_field($post_id, 'value')];
$rid  = uuid;
$rec  = RollbackDelta::build_record(['value'], $prior, $after, $cx,
          head{ id:$rid, post_id:$post_id, field_key:$key, action:'value_update' });
(new PostMetaRollbackStore('_wpcc_acf_rb_'))->persist($post_id, $rid, $rec);
return { ..., rollback_id:$rid }
```
New value records live in **postmeta** (`_wpcc_acf_rb_{rid}` on the post) — O(1) by id, no FIFO eviction, GC with the post. The legacy `wpcc_acf_rollbacks` option still holds pre-P4.9 value records (drained by the legacy path).

**Rollback (value path):** `rollback()` first tries `PostMetaRollbackStore('_wpcc_acf_rb_')->resolve($rid)`:
- If found (v2 value record): `o = RollbackDelta::restore(new AcfValueAccessor($rec.field_key), $rec.post_id, $rec.fields)`. Drift compare live whole value vs recorded `after`; **match → restore prior whole value** (existence-faithful: prior absent ⇒ clear); **drift → skip + conflict** (error envelope, reversible:false, **not** marked applied → retryable). Mark applied only on `complete`.
- If not found → fall through to the **option scan** (legacy value records + all definition records), unchanged.

## 4. Definition update-in-place — fingerprint drift guard (surface 4)
Applies to `group_update, field_update, location_assign, location_remove, layout_update` only.
- **Capture (in `store_rollback`, which runs right after the mutation):** for these actions, also store `before_state['__after_fp'] = definition_fingerprint($entity_id, $action)` — read the **live (post-write)** definition and canonicalize.
- **`definition_fingerprint($id,$action)`:** read `acf_get_field_group($id)` (group/location actions) or `acf_get_field($id)` (field/layout actions); canonicalize = recursive `ksort` + drop volatile keys (`ID, id, menu_order, modified, _valid`); `sha1(wp_json_encode(...))`. Empirically stable (Phase-A probe).
- **Rollback guard:** for these actions, **if** the record carries `__after_fp` (new): recompute the live fingerprint; if it **differs** → an external edit happened since our op → **refuse + report conflict** (error, reversible:false, not applied). If it **matches** → proceed with the existing `acf_update_*` restore. If the record has **no** `__after_fp` (legacy) → existing unconditional restore (**unchanged**).
- **Safety:** refuse-on-drift only *adds* refusals; never changes a clean restore; never clobbers. New-records-only ⇒ zero legacy behavior change. Worst case (fingerprint instability) = a safe false-refusal, never corruption.
- **Untouched:** `group_create/group_delete/field_create/field_delete/group_duplicate/layout_create` (create/delete inverse — drift-tolerant; test #10 depends on field_create→delete).

## 5. json_import — honest irreversible (surface 5b)
`rollback()` gains an explicit `json_import` branch returning an **unsupported/irreversible** envelope (`error:true, code:wpcc_rollback_unsupported, reversible:false`) instead of falling through all `elseif`s and returning a phantom success. The record is left **not** marked applied (it cannot be applied). Fixes the false clean-success.

## 6. Response & honesty conventions (avoid false clean-success)
- **value complete:** `{action:'acf_rollback', rollback_id, post_id, field_key, status:'complete', restored:true, reversible:true}`.
- **value drift conflict:** `{error:true, code:'wpcc_rollback_conflict', reversible:false, status:'conflict', restored:false, post_id, field_key}`.
- **definition drift refusal:** `{error:true, code:'wpcc_rollback_conflict', reversible:false, action:'acf_rollback', rollback_id}`.
- **json_import:** `{error:true, code:'wpcc_rollback_unsupported', reversible:false}`.
- **clean definition/create/delete restore:** unchanged `{action:'acf_rollback', rollback_id}`.
- **already applied / not found / missing id:** unchanged errors.

## 7. Idempotency / legacy / GC
- value records: per-record `rollback_applied` (in postmeta record) — repeat → already-applied error. Legacy option value/definition records keep their option `rollback_applied` flag.
- GC: a deleted post removes its `_wpcc_acf_rb_*` value records (postmeta cascade) → resolve null → option fallback → not-found (honest).

## 8. Scope / STOP
- Files: `ACFRuntimeManager.php` (value_update capture; rollback value-path + definition guard + json_import honesty; `definition_fingerprint` helper; `store_rollback` fingerprint capture) + new `includes/Rollback/AcfValueAccessor.php`. Reuses `RollbackDelta`, `PostMetaRollbackStore`.
- **No** decomposition of nested ACF values (atomic). **No** new ACF capability / field type / op. **No** user/term/option support added (runtime is post-only). **No** schema / DB_VERSION / operation-registry / capability / MCP / REST / UI / security change. New meta keys + record fields are additive.
- **No STOP triggered:** nested behavior is made safe by atomic whole-value handling; runtime mutation scope is unchanged (post-only values + existing definition ops).

## 9. Validation plan (Phase E preview)
New `test-acf-rollback-delta.sh` (manager-level): flat-value restore, empty-prior restore (clears), empty-but-existing restore, sibling field preservation, same-field drift skip/report, out-of-order no-resurrection, repeated rollback safety, legacy option record restore, partial/conflict not clean-success, nested (repeater/gallery/relationship) treated atomically (whole restore + drift refuse), ACF `_field` key-reference preserved, json_import honest-unsupported, definition update fingerprint drift-refusal + clean restore, unsupported reporting. Plus the full regression battery + guards + change-history.
