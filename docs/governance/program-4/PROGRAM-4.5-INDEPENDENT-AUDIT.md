# PROGRAM-4.5 — User Rollback · Independent Diff Audit

> Verified against `git diff 2234dcc` + live runs.

## Scope
**Modified:** `includes/Operations/UserManager.php` (+77/−9). **New:** `includes/Rollback/UserFieldAccessor.php`, test, reports.
**UNTOUCHED:** `UserRegistry`; no SEO/Settings/Media/Content/Comments/Woo/ACF/Bulk runtime; no Plugin/Theme; no OperationRegistry/CapabilityRegistry/Mcp/Schema/REST/UI. P4.0 core unchanged. P4.1–P4.4 not in branch history; sibling accessors absent.

## Behaviour audit
- `update_user`: capture only payload-present fields (email/display_name/first_name/last_name) **before** `wp_update_user` (capture was already pre-write; the snapshot just moved to the accessor); existing write unchanged; store a v2 delta; surface `rollback_id` (additive).
- `rollback()`: new early branch for `update` v2 records → core drift-aware restore (complete-only terminal, conflict/partial otherwise). The switch (`create`/`delete`/`update`-legacy/`assign_role`/`remove_role`/`suspend`) is **unchanged** — diff removes no create/delete/role logic; `$before` gained a `?? []` guard only.
- `user-runtime` 75/0 ⇒ no regression to update, roles, create, or delete.

## DEF-U1 (pre-existing bug) — fixed correctly
Legacy `update` rollback did `wp_update_user($before)` with key `email` (not `user_email`) → email silently not restored. The `UserFieldAccessor` maps `email→user_email`, so v2 records restore email correctly (S1 proves it). Legacy records keep their original behaviour (switch unchanged) — no destructive migration.

## Contract check
No new op/cap/MCP tool; `user_manage` already dispatched rollback via the public `rollback()` method. `rollback_id` in the update response is additive. **No op/cap/MCP/REST/schema contract change.**

## Residual risks (non-blocking)
- Roles remain a separate set-valued concern (assign/remove/suspend), already reversible via `set_role('')`+`add_role` — out of scope, unchanged.
- usermeta existence fidelity: first/last name restored as '' if previously absent (matches the prior `wp_update_user($before)` behaviour; minor).
- Pre-deploy gates deploy-coupled.

## Verdict
**PASS.** Scope exactly P4.5 (User `update` field-set via a column+usermeta accessor); no forbidden surface; non-update actions byte-identical; F-1 over-reach closed and DEF-U1 fixed; zero attributable failures. Clears for GO.
