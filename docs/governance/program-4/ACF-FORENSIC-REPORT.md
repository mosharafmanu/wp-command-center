# PROGRAM-4.9 — ACF Forensic Report (Phase A)

> **Type:** source-verified forensic audit (no code changes in this phase). Report-only.
> **Method:** read `ACFRegistry.php` + `ACFRuntimeManager.php` (764 lines) directly + an empirical ACF runtime probe. ACF does **not** behave like flat postmeta — findings reflect real ACF storage.
> **Base:** `program-4.8-bulk-delta-redesign` @ `81afaab`. Production `a41a9d7` unchanged.

---

## 1. Rollback-capable ACF operations (from `ACFRegistry::supports_rollback`)
`group_create, group_update, group_delete, field_create, field_update, field_delete, location_assign, location_remove, value_update, json_import, layout_create, layout_update`. (Plus `group_duplicate` which stores a `group_create` inverse record.)

## 2. Per-operation forensics

For each: **object type · storage written · rollback record captured · full-object/field-scoped/drift-aware/legacy-compatible · field types**.

### 2.1 `value_update` (`:559–570`) — the core in-scope surface
- **Object:** **POST only** (`$post_id=(int)$p['post_id']; if($post_id<=0) error` `:560–561`). **No user/term/option-page support** in the runtime.
- **Storage:** postmeta — ACF stores the value under `meta_key = field_name` plus a key-reference under `_field_name` (confirmed by probe: after `update_field('V')` both `probe_field` and `_probe_field` exist).
- **Captured:** `before = get_field($key,$post_id)` — the **whole resolved value** (scalar for text/number; **array** for repeater/flexible/group/gallery/relationship). Record `{post_id, key, value:before}` in option `wpcc_acf_rollbacks` (`:567`).
- **Restore (`:624–625`):** `update_field($before['key'],$before['value'],$before['post_id'])` — **unconditional whole-field write, no drift check.**
- **Classification:** **whole-field (atomic), NOT field-scoped across the post's other ACF fields (but only this field is touched → siblings safe), NOT drift-aware.** Legacy-compatible (it is the only path).
- **Field types:** ANY (passes value straight to `update_field`); nested values are stored/restored **whole** (never decomposed).

### 2.2 `bulk_value_update` (`:572–584`)
- POST only; loops `update_field` per field. **Captures NO rollback record** (no `store_rollback` call) → **irreversible**. (Bulk ACF reversibility is covered by P4.8's `bulk_acf` op, not here; this ACF-runtime bulk op is out of P4.9 scope per the mission's "Bulk ACF beyond what P4.8 already covers" exclusion.)

### 2.3 Definition operations (act on `acf-field-group` / `acf-field` **posts**, addressed by key)
| Op | Capture (`store_rollback`) | Restore (`rollback()`) | Class |
|---|---|---|---|
| `group_create` (`:84`), `group_duplicate` (`:172`) | `before=[]` | `acf_delete_field_group($eid)` (`:612`) | inverse-delete |
| `group_update` (`:103`) | `before=$g` (FULL original group, F-4 fix `:95–100`) | `acf_update_field_group($before)` (`:619`) | whole-def, unconditional |
| `group_delete` (`:119`) | `before=$g` minus `ID` (FULL def, F3.1) | `acf_update_field_group($before)` w/ active (`:615`) | recreate-by-key |
| `field_create` (`:296`) | `before=[]` | `acf_delete_field($eid)` (`:613`) | inverse-delete |
| `field_update` (`:320`) | `before=$f` (FULL original field) | `acf_update_field($before)` (`:620`) | whole-def, unconditional |
| `field_delete` (`:330`) | `before=$f` (FULL def) | `acf_update_field($before)` (`:617`) | recreate |
| `location_assign` (`:472`) | `before=['location'=>$g.location]` | merge live group + `before.location` (`:622–623`) | whole-location, unconditional |
| `location_remove` (`:485`) | same | same | same |
| `layout_create` (`:389`) | `before` (field's layouts) | set `$f.layouts=before.layouts`; `acf_update_field` (`:627–628`) | whole-field-layouts inverse |
| `layout_update` (`:431`) | same | same | whole-field-layouts, unconditional |
| `json_import` (`:514`) | `before=summarize_group($existing)` (**LOSSY**) | **NO branch in `rollback()`** | **dead / false clean-success** |

### 2.4 Storage / lifecycle (all definition + value records)
- **Option `wpcc_acf_rollbacks`**, **FIFO cap 200** (`:652`), **autoloaded** (default), **O(n) scan** to resolve (`:605`). Record `{id, entity_id, action, before_state, rollback_applied, created_at, session, task}`. `rollback()` marks applied + returns `{action:'acf_rollback', rollback_id}` (`:632`).

## 3. Answers to the Phase-A questions
1. **Object types:** value_update → **post**; group/field/location/layout/json_import → **acf-field-group / acf-field posts (definitions)**. No user/term/option-page mutation exists.
2. **Storage:** value → postmeta (+ `_field_name` ref); definitions → the definition posts; rollback records → option `wpcc_acf_rollbacks`.
3. **Record captured:** whole-value (value_update), whole-definition (group/field/location/layout), lossy summary (json_import), empty (create inverse).
4. **Full-object?** Yes for definitions and values (whole-blob).
5. **Field-scoped?** No (whole-blob); value_update touches only its one field so post-siblings are safe, but the value itself is whole.
6. **Drift-aware?** **No** — all restores are unconditional.
7. **Legacy-compatible?** The single option path is the only path (no versioning yet).
8. **Field types handled:** value_update accepts ANY (incl. nested); definitions handle the full `ACFRegistry::FIELD_TYPES` set.
9. **Repeater/flexible/clone/group/gallery/relationship:** in `value_update` these are captured/restored **whole** (atomic) — never decomposed, so no row-merge corruption, **but** a later edit is clobbered (no drift). In definition ops their config is whole-blob.
10. **Tests:** `test-acf-runtime.sh` (route exists, bogus-id rollback 404), `test-acf-runtime-step92.sh` (#10: field_create→rollback removes field — an inverse-delete test), `test-acf-group-delete-f31.sh`, `test-acf-nested-read-f32.sh`, `test-acf-seed.sh`. **No drift / no value-restore-fidelity / no sibling / no out-of-order rollback tests.**

## 4. Empirical probe (real ACF behavior — not assumed)
- ACF active; **45 field groups**.
- **Definition fingerprint STABLE** across two reads under canonicalization (ksort + drop `ID/menu_order/modified/_valid`) ⇒ a refuse-on-drift guard will not false-drift on unchanged definitions.
- **Value existence:** absent field → `get_field`=NULL, `metadata_exists`=0; `update_field('V')` → meta + `_ref` present; **`update_field(null)` removes the meta** (existence-faithful clear is available).

## 5. Residual gaps P4.9 must address (honestly + safely)
- **value_update:** no drift detection (clobbers later edits); no existence fidelity on rollback (restores prior value but never re-clears a field that was absent before); FIFO-evictable option storage. → drift-aware whole-field delta on PostMetaRollbackStore.
- **json_import:** **false clean-success** (record stored, `rollback()` does nothing, returns success). → honest irreversible/unsupported.
- **Definition update-in-place (group_update, field_update, location_assign/remove, layout_update):** unconditional whole-blob restore can clobber a newer external edit. → conservative whole-definition **fingerprint drift-guard** (refuse-on-drift), new-records-only so legacy behavior is untouched, **no decomposition**.
- **Out of scope (left as-is):** create/delete inverse actions (drift-tolerant by construction; test #10 depends on them), bulk_value_update (P4.8 territory), user/term/option (unsupported by the runtime — staying bounded; no STOP since not broader than documented).

Proceeding to Phase B (risk classification).
