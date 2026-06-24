# PROGRAM-4C — ACF Rollback Architecture Review

> **Type:** architecture design (no code). Report-only. **Target runtime:** `includes/Operations/ACFRuntimeManager.php` (option `wpcc_acf_rollbacks`).
> **Goal:** the safest path to make ACF rollback field-scoped, drift-aware, and F-1-free where decomposable, and *honestly safe* (drift-guarded, never silently clobbering) where not.

---

## 1. The ACF storage reality (why one pattern will not do)
ACF state lives in **three different stores**, and the right rollback primitive differs per store:

| ACF concept | Where it physically lives | Rollback primitive that keeps integrity |
|---|---|---|
| Field **value** on a post | postmeta (key = field name; `_field_name` = field-key ref) | `update_field()` / meta write |
| Field value on an **option page** | `wp_options` (`options_{name}` / `_options_{name}`) | `update_field($k,$v,"option")` |
| Field **definition** (incl. sub_fields) | `acf-field` **posts** (post_parent chain) + acf-json files | `acf_update_field()` — re-parents children |
| Field **group** config | `acf-field-group` post + serialized `acf` meta | `acf_update_field_group()` |

The current code flattens all of these into one whole-`before_state` snapshot restored by a single `acf_update_*` call — which is why drift, sibling clobber, and child-post orphaning all appear. The architecture below **routes each ACF action to the store-appropriate primitive**.

---

## 2. Decomposability classification
A mutation can use the field-scoped `RollbackDelta` core **iff** it is expressible as *named fields on a stably-identified entity*, each independently readable/writable with a drift comparator. Classifying every ACF action:

| ACF sub-surface | Decomposable to named fields? | Target pattern |
|---|---|---|
| **value_update — scalar** (text, number, select, true_false, date, url, email) | YES — one field, one meta key | **B (delta)** — `ACFValueAccessor` |
| **value_update — relationship / post_object / taxonomy / user** (id lists) | YES — value is an id-set | **B (delta)**, set-comparator (reuse the WooProductAccessor id-set `equals`) |
| **value_update — gallery** (attachment id list) | YES — id-set | **B (delta)**, set-comparator |
| **value_update — repeater / flexible / group (nested)** | PARTIAL — value is one serialized meta blob; rows not independently keyed | **B at whole-field granularity** + **drift-guard**; not row-level |
| **value_update — clone** | PARTIAL — expands to underlying fields; depends on display/seamless mode | **B whole-field** + drift-guard (treat as opaque value) |
| **value_update — option page** | YES — same as scalar but option store | **B (delta)**, `ACFOptionValueAccessor` |
| **location_assign / location_remove** | YES — `location` is one isolable field on the group | **B (delta)**, order-insensitive rule-set comparator |
| **group_update** (title, active, style, label_placement, location) | MOSTLY — scalar config fields on the group post | **B (delta) for scalars** + **whole-def + drift-guard** for the serialized `acf` blob |
| **field_create / field_update / field_delete** (definition + sub_fields) | NO — sub_fields are post-parented child posts, not flat meta | **Whole-definition + drift-guard + child-reconciliation** (Pattern A′) |
| **layout_create / layout_update** (flexible layouts) | NO — layouts inline in def; sub-fields are child posts | **Whole-definition + drift-guard**; do NOT auto-delete orphaned sub-field posts |
| **json_import** | NO — import semantics + currently lossy `summarize_group` | **Delist rollback OR capture full group tree** (see §6) |

---

## 3. The two core capabilities ACF needs (both schema-free)

### 3.1 `ACFValueAccessor` (new `FieldAccessor`) — for value_update
Drives the existing `RollbackDelta` core over ACF **values**, via ACF's public API only (never raw meta behind ACF's back), so the `_field_name` key-reference stays consistent:
- `backing_keys(field)` → `[field_name]` (single logical key; ACF maintains the paired `_field_name` ref on write).
- `read_field(id, field)` → `get_field($field_key, $id)` (unified value; works for post id and `"option"`).
- `key_get/key_set` → `get_field` / `update_field` (honoring existed-vs-empty).
- `key_exists` → `metadata_exists` for post-bound; `get_option` presence for option-page.
- `equals(field, …)` → per field-type comparator: scalar string; id-set order-insensitive (relationship/gallery/taxonomy/user); **normalized-serialize** for repeater/flexible/clone (whole-field).
- Identity: post_id (scalar/nested) or the literal `"option"` (option pages) or `"{blog}_option"` — supplied by the runtime.

This single accessor covers every **value_update** case. Nested values (repeater/flex/clone) use whole-field capture with a serialize-normalized `equals` — drift-aware at field granularity (if anything in the structure changed externally, skip+report rather than clobber). This is the **honest** middle ground: it does not pretend to row-level precision it cannot guarantee, but it never silently destroys a concurrent edit.

### 3.2 `RollbackDelta` whole-definition + drift-guard mode (Pattern A′) — for definitions/config
For non-decomposable blobs (field/group config, flexible layouts), add a **drift-guarded whole-definition** record shape (no core rewrite — a thin wrapper around the existing record):
```
{ version: 2, mode: "whole_def", entity, id,
  fingerprint: sha1(canonical(after_definition)),   // apply-time identity of the live def
  before_blob: <full prior definition>,             // what to restore
  applied, meta }
```
Restore algorithm:
1. Read the **current** live definition; compute `sha1(canonical(current))`.
2. If it **≠** the stored apply-time `fingerprint` → the definition drifted (an external/later edit) → **skip + report conflict** (do NOT overwrite). This is the F-1 fix for blobs: it converts "silently clobber the newer edit" into "refuse and report."
3. If it **==** the fingerprint → restore `before_blob` via the store-appropriate `acf_update_field*` call.
4. For definition restores that recreate/replace a field or group, run **child reconciliation**: after restore, detect sub-field/child posts whose `post_parent` no longer matches and **report** them (never auto-delete) so the operator/agent sees orphans instead of silent loss.

`canonical()` = recursively ksort + drop volatile keys (`ID`, menu_order noise) so cosmetic ordering does not register as drift. `fingerprint` + `before_blob` are plain record fields → **no schema change**.

---

## 4. Storage decision (schema-free)
- **value_update (post-bound):** store the v2 delta record as **postmeta on the target post** (`_wpcc_acf_rb_{id}`), mirroring SEO. This eliminates both option bloat and the shared-option FIFO eviction risk, and gives natural GC (post delete ⇒ record gone).
- **value_update (option page):** no post to attach to → keep an **option record** in v2 shape (a dedicated `wpcc_acf_option_rollbacks` key, capped), or reuse `wpcc_acf_rollbacks`.
- **definition/config/layout/json_import (whole_def):** these are group/field-scoped; store the whole_def record as **postmeta on the `acf-field-group` / `acf-field` post** where one exists, else option. Large serialized defs as postmeta avoid the autoloaded-option bloat.
- **Legacy compatibility:** keep reading existing `wpcc_acf_rollbacks` list records via a legacy branch (exactly as SEO/Content retained legacy restore). No data migration.

No new table, no new column, **DB_VERSION stays 2.5.0**.

---

## 5. What can / cannot use RollbackDelta — verdict
- **Can (Pattern B, clean):** scalar value_update, id-set value_update (relationship/gallery/taxonomy/user/post_object), option-page values, location rules. → `ACFValueAccessor`. **F-1 fully closed.**
- **Can (Pattern B, whole-field + drift-guard):** repeater/flexible/clone **values**. Field-granular drift; row-level precision intentionally out of scope (honest skip on any structural change). **F-1 contained (no clobber).**
- **Cannot decompose — Pattern A′ (whole-def + drift-guard + child reconciliation):** field/group **config**, flexible **layouts**, group_update serialized blob. **F-1 safety achieved without row-delta** (refuse-on-drift, never clobber).
- **Special — json_import:** today it is in `ROLLBACKABLE` but stores a lossy `summarize_group` and lacks a faithful restore ⇒ a record that cannot truly reverse the import. **Recommendation:** either (a) **delist** json_import from `ROLLBACKABLE` and return `reversible:false` + visible notice, or (b) capture the **full pre-import group tree** as a whole_def record. (a) is honest and cheap; (b) is correct but heavy. Ship (a) first.
- **Currently unsupported — option-page field values:** add via `ACFValueAccessor` with `"option"` identity. New capability, low effort, no contract change.

---

## 6. Hybrid requirement summary
ACF is the program's clearest case for a **typed hybrid within one runtime**:
- **values → B** (scalar/id-set clean; nested whole-field+guard),
- **config/layouts → A′** (whole-def + drift-guard + child reconciliation),
- **json_import → E-visible** (delist + reversible:false) or A′,
- **option-page values → B** (new).

No single pattern; the router (action → pattern) is the design's backbone.

---

## 7. Recommended ACF migration phases (within the P4.8 ACF slot)
1. **P4.8a — values (clean):** `ACFValueAccessor`, migrate scalar + id-set value_update + location rules → Pattern B. Highest value, lowest risk; closes the most common F-1 (value clobber). Postmeta storage.
2. **P4.8b — nested values + option-page:** repeater/flexible/clone whole-field + drift-guard; add option-page value support. Medium risk.
3. **P4.8c — definitions/config/layouts:** Pattern A′ (whole-def + fingerprint drift-guard + child-orphan reporting). Replaces the silent-clobber blob restore with refuse-on-drift.
4. **P4.8d — json_import:** delist (reversible:false + notice) now; full-tree capture later if required.

Each sub-phase: design note → implement → S1–S9 suite (adapted) + drift-injection + child-orphan test → self-audit → independent audit → branch-commit. Gate: existing `test-acf-*` stay green; new ACF delta suite green; invariants 34·23·40·40·2.5.0 held.

---

## 8. Validation must-haves (the F-1 proofs)
- **Scalar value:** S1 absent-prior clears, S2 value-prior restores, S3 disjoint layered (field A vs field B → sibling survives), S4 same-field drift (conflict, no clobber), S5 out-of-order (no resurrection), S8 idempotency.
- **Id-set value:** order-insensitive restore; partial external add → drift skip.
- **Nested value:** external row change between apply and rollback → **skip + report** (proves no clobber).
- **Config/layout (A′):** external def edit between apply and rollback → fingerprint mismatch → **refuse + report**; matching fingerprint → exact restore; **child-orphan reporting** asserted.
- **json_import:** returns `reversible:false` (no silent success).
- **Legacy:** a pre-migration `wpcc_acf_rollbacks` whole-`before_state` record still restores.

---

## 9. Risks & mitigations specific to ACF
- **Child-post orphaning** on definition restore → never auto-delete; **report** orphans (operator decides). Mitigation built into A′.
- **acf-json sync** files can re-create defs out of band → fingerprint drift-guard catches it (refuse rather than fight the json sync).
- **Serialized value size** → postmeta storage (not autoloaded option) avoids page-load bloat.
- **Clone/seamless fields** read through to underlying fields → treat as opaque whole-field value; drift-guard prevents partial clobber.
- **No schema/DB_VERSION/capability/operation/MCP/security change** anywhere in this design — all storage is postmeta/option, all records are plain arrays, the action set and registries are untouched. If any ACF sub-surface is found at implementation to need an index for discovery, that single sub-phase **escalates to a Rule-7 schema check-in before code** (not anticipated).
