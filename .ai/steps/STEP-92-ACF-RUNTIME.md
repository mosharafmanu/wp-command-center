# STEP 92 — ACF Runtime

## Goal

Let AI agents build and manage ACF structures — field groups, fields of every
required type, repeaters, flexible content + layouts — over REST and MCP.

## Audit (do not rebuild what exists)

`ACFRuntimeManager` already had group + field + location + JSON + value CRUD (28
actions). STEP 92 added the missing capabilities and fixed two latent bugs the
acceptance test surfaced.

## Added

1. **All field types** — `field_create`/`field_update` validate against
   `ACFRegistry::FIELD_TYPES` and accept a `config` blob (choices, return_format,
   post_type, button_label, …) plus common settings (instructions, required,
   default_value). Covers text, textarea, image, gallery, relationship, select,
   group, clone, and more.
2. **Nested sub-fields** — `field_create` for a `repeater`/`group` accepts inline
   `sub_fields[]`, each created as a child (parent = the new field). Flexible
   layout fields use `parent = flexible_field` + `parent_layout = layout_key`.
3. **`acf_layout_create` / `acf_layout_update`** — manage flexible-content
   layouts (name, label, display, inline `sub_fields`), rollback-capable.

## Two pre-existing bugs fixed (found by the acceptance test)

1. **Orphaned fields.** `acf_update_field()` only links a field when `parent` is
   the parent's **numeric post ID** — a KEY string left the field orphaned
   (`post_parent = 0`). Top-level fields (parent = group key) were never linked
   to their group, and orphaned `acf-field` posts accumulated until ACF's
   compatibility migration fataled (`Cannot access offset of type string on
   string`). Fixed: `field_create` resolves a group/field key to its post ID.
   (147 orphans from prior runs were purged from the dev DB.)
2. **Malformed `location_assign`.** It appended a single rule where ACF expects a
   location OR-group (a list of AND-rules), producing a string-where-array that
   corrupted the group and fataled every later group operation. Rewritten to
   accept `{ param, operator, value }` (or an array of rules) and build a valid
   OR-group, sanitizing each field.
3. **Rollback was silently disabled** — `store_rollback()` keyed support on the
   `acf_*` operation names while being called with short internal names, so no
   ACF rollback was ever recorded. Fixed with an internal `ROLLBACKABLE` set;
   every write now returns a usable `rollback_id`.

## Operations (REST `/operations/acf_manage/run` + `/rollback`, MCP `acf_manage`)

New/changed: `acf_field_create` (types + config + `sub_fields` + `parent`/
`parent_layout`), `acf_layout_create`, `acf_layout_update`. All `medium` risk,
rollback-capable, audited (`acf.field.created`, `acf.layout.created/updated`).

Structured errors: `wpcc_acf_missing_parent`, `wpcc_acf_parent_not_found`,
`wpcc_acf_unsupported_field_type`, `wpcc_acf_not_flexible`,
`wpcc_acf_missing_layout_name`, `wpcc_acf_layout_not_found`,
`wpcc_acf_invalid_location`, `wpcc_acf_field_not_found`.

## Acceptance tests — `tests/test-acf-runtime-step92.sh` (23/23)

Workflow: create field group → add fields (text/select-with-choices/image-with-
config) → add a repeater with sub-fields → add flexible content + a layout with
sub-fields → update a layout → attach the group to another post type via
`location_assign` → verify the structure via `acf_group_get` (admin-UI data).
Plus structured errors, MCP parity, and field-create rollback. Verified no
orphaned fields are created. `test-acf-runtime` (existing) made deterministic
and green.

## Files changed

- `includes/Operations/ACFRegistry.php` — `FIELD_TYPES`, layout actions, risk/rollback.
- `includes/Operations/ACFRuntimeManager.php` — enhanced `field_create`,
  `layout_create`/`layout_update`, parent resolution, fixed `location_assign`,
  rollback fix + ids, dispatch + rollback restore.
- `includes/Operations/OperationRegistry.php` — `acf_manage` action_risks + params.
- `tests/test-acf-runtime.sh` — deterministic inventory seed.

## Test-environment note

ACF Pro 6.4.2 is active on the dev site. Field-group/field changes were exercised
against it; 147 orphaned `acf-field` posts left by the pre-fix bug were purged.

## Preserved guarantees

Backward compatible (additive; legacy actions unchanged). Security modes (writes
gated medium), approval, rollback, audit, and REST/MCP parity all intact.
