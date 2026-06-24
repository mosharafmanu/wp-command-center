# PROGRAM-4.3 — Content Rollback · Independent Diff Audit

> **Posture:** adversarial; verified against `git diff 2234dcc` + live runs.

## Scope
**Modified:** `includes/Operations/ContentManager.php` (+76/−7). **New:** `includes/Rollback/ContentFieldAccessor.php`, test, reports.
**Forbidden surfaces UNTOUCHED:** no SEO/Settings/Media/Woo/ACF/User/Comments/Bulk runtime; no Plugin/Theme; no OperationRegistry/CapabilityRegistry/Mcp/Schema/REST/UI. P4.0 core unchanged. (The `docs/product/*.md` in `git diff` are pre-existing working-tree edits, not P4.3.) P4.1/P4.2 not in branch history; their accessors absent.

## Behaviour-drift audit
- Only the `update` capture (`update_content`) and the `content_rollback` restore changed; `create`/`delete`/`publish`/`unpublish`/`schedule`/`taxonomy` logic **untouched** (diff removes no create/delete/publish code).
- `rollback_content` branches on `isset($record['fields'])`: v2 → core delta; else → the **original** `wp_update_post($before)` path (legacy update **and** delete records) — byte-identical. S6 (legacy) + S7 (delete) confirm.
- Capture is before-write (was already); the store moved to after the success check (no rollback record persisted on a failed `wp_update_post` — a minor improvement, not a behaviour regression for the success path).
- `content_runtime` 98/0 unchanged ⇒ no regression in the broader content surface.

## Correctness (diff + tests)
Field-scoped (S10) · drift skip/conflict (S3/S4) · sibling preservation (S3) · out-of-order (S5) · partial/conflict ≠ clean success, surfaced as failed rollback via the `ACTION_ROLLBACKS` dispatcher (`empty($res['error'])` ⇒ false) · idempotent (S8) · legacy+delete compatible (S6/S7). Columns always exist ⇒ restore always writes prior (existence fidelity N/A for columns, correctly).

## Residual risks (non-blocking)
- Per-field column writes → up to 4 `wp_update_post` (extra `post_modified` bumps); cold path.
- `delete`-record rollback retains its pre-existing whole-`before_state` behaviour (out of scope; unchanged).
- Pre-deploy gates deploy-coupled.

## Verdict
**PASS.** Scope exactly P4.3 (Content `update` + pure-column accessor); no forbidden surface; other actions byte-identical; all behaviours proven; zero attributable failures. Clears for GO.
