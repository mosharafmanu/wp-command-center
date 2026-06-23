# PROGRAM-4.5 — User Rollback Integrity · Design Report

> **Branch:** `program-4.5-user` (from P4.0 `2234dcc`; P4.1–P4.4 excluded — confirmed). **No other runtime; no op/cap/MCP/REST/UI/schema; no merge/push/deploy.**

## 1. Audit (verified in source)
`includes/Operations/UserManager.php` (523 lines); public `rollback()` (method-path dispatch). Rollback-supporting actions: create/delete/update/assign_role/remove_role/suspend (records in option `wpcc_user_rollbacks`).
- **`update_user()` (`:176`):** captures `$before = {email, display_name, first_name, last_name}` (from `get_userdata` read **before** the write — no DEF-1) then `wp_update_user($updates)`; over-reaches (captures/restores all four even when one changed → **F-1**).
- **`rollback()` `update` case (`:459`):** `$before['ID']=$user_id; wp_update_user($before)`.
  - **DEF-U1 (pre-existing bug):** `$before` uses key **`email`**, but `wp_update_user` expects **`user_email`** → **email is silently NOT restored** on update rollback (display_name/first_name/last_name are). The field-scoped delta fixes this by mapping `email`→`user_email`.
- `create`/`delete`/`assign_role`/`remove_role`/`suspend` cases reverse structurally / via role-set restore — **out of scope, unchanged**.
- Field storage: email→`user_email` (users column), display_name→`display_name` (column), first_name/last_name→usermeta. All readable via `get_userdata`, writable via `wp_update_user`.

## 2. Scope
**In:** the `update` action → field-scoped, drift-aware delta (email/display_name/first_name/last_name), which also fixes DEF-U1. **Out (unchanged):** create/delete; the role actions (assign_role/remove_role/suspend) — roles are a separate set-valued concern already reversed by `set_role('')`+`add_role`, not part of the update field-set.

## 3. Design (reuse P4.0 core)
**New `includes/Rollback/UserFieldAccessor.php` (`FieldAccessor`):** unified fields → `wp_update_user` field names:
`email→user_email, display_name→display_name, first_name→first_name, last_name→last_name`.
`read_field/key_get = (string) get_userdata(id)->{key}` (WP_User magic props cover column + usermeta); `key_set = wp_update_user(['ID'=>id, key=>v])`; `key_exists = user exists ⇒ true` (user fields always conceptually present; restore writes prior, even ''); `equals = string`.

**`UserManager` changes (update only):**
- `update_user()`: `$touched` = fields present in payload among the four; `RollbackDelta::capture(new UserFieldAccessor(), $id, $touched)` **before** `wp_update_user`; existing write unchanged; read `$after`; persist a **v2 delta** record (same option, adds `version:2`+`fields`) via new `store_user_delta()`; surface `rollback_id` in the response (was absent).
- `rollback()`: add an early branch for v2 records (`isset($record['fields'])`) → `RollbackDelta::restore(new UserFieldAccessor(), …)`, mark applied **only on complete**, truthful audit, success or `wpcc_rollback_conflict|partial` envelope. The existing switch (`create/delete/update-legacy/assign_role/remove_role/suspend`) is **unchanged**.

## 4. Affected files
**New:** `includes/Rollback/UserFieldAccessor.php`, `tests/test-user-rollback-delta.sh`. **Modified:** `includes/Operations/UserManager.php`. **Unchanged:** P4.0 core, `UserRegistry`, all other runtimes, OperationExecutor, registries, Schema, REST, UI.

## 5. Risks
R1 DEF-U1 fix changes behaviour (email now restored — the intended fix; legacy records keep old behaviour) · R2 column+usermeta via `wp_update_user` (proven API) · R3 roles untouched (separate actions) · R4 SEO/core regression (additive) · R5 user suite must stay green · R6 invariants frozen · R7 usermeta existence fidelity (first/last name restored as '' if absent — matches prior `wp_update_user($before)` behaviour, minor residual).

## 6. Validation plan
New `tests/test-user-rollback-delta.sh`: S1 value-prior restore (email — **proves DEF-U1 fixed**) · S2 display_name/first/last restore · S3 sibling preservation + drift (update email+display_name; later display_name change; rollback → email restored, display_name (later) survives, partial) · S4 same-field conflict · S5 out-of-order · S6 legacy `before_state` update record (incl. the old email-not-restored behaviour preserved) · S7 repeated rollback guarded · S8 untouched field not in record · S9 roles action rollback unaffected · static guards. **Regression:** `test-user-runtime`/`test-user-management` (whichever exists), core (25/0), seo-delta (56/0), change-history-rollback (standalone), registry/cap/MCP parity, invariants 34/23/40/40/2.5.0. **Stop if** an attributable failure can't be resolved in scope or schema is needed.
