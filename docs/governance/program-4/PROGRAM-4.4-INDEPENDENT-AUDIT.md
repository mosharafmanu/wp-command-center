# PROGRAM-4.4 — Comments Rollback · Independent Diff Audit

> Verified against `git diff 2234dcc` + live runs.

## Scope
**Modified:** `includes/Operations/CommentsRuntimeManager.php` (+68/−3). **New:** `includes/Rollback/CommentFieldAccessor.php`, test, reports.
**UNTOUCHED:** `CommentsRegistry` (confirmed — no registry change); no SEO/Settings/Media/Content/Woo/ACF/User/Bulk runtime; no Plugin/Theme; no OperationRegistry/CapabilityRegistry/Mcp/Schema/REST/UI. P4.0 core unchanged. P4.1/2/3 not in branch history; their accessors absent.

## Nature of the change (honest finding)
Comments does **not** exhibit the F-1 full-object over-reach: `approve`/`unapprove`/`spam` captured **nothing** and were **not reversible**; only `trash`/`delete` stored records. P4.4 is therefore **additive** — it closes the status-reversibility gap using the field-scoped delta. This is a reversibility *improvement*, not a like-for-like snapshot replacement; it is the faithful Program-4 outcome for this runtime.

## Behaviour audit
- `approve/unapprove/spam`: capture status **before** the change, store a v2 status delta, surface `rollback_id` (additive — like Settings/Media). The status change itself (`wp_set_comment_status`/`wp_spam_comment`) is unchanged.
- `rollback()`: new early branch for v2 records → core drift-aware restore (complete-only terminal, conflict otherwise). `trash` (untrash) and `delete` (unsupported) switch cases **unchanged**.
- `store_status_delta` is a dedicated path → **no `CommentsRegistry::supports_rollback` change**.
- `comments-runtime` 44/0 ⇒ adding `rollback_id` to responses did not break the suite.

## Contract check
No new operation/capability/MCP tool. `comments_manage` already dispatched rollback via the public `rollback()` method; P4.4 only adds a record type it can handle. `rollback_id` in responses is additive. **No op/cap/MCP/REST/schema contract change.**

## Residual risks (non-blocking)
- Status restore via `wp_update_comment(comment_approved)` proven for hold/approved/spam transitions (S1–S5). Restoring to `trash` raw value is not exercised (trash is reversed structurally via untrash; the status delta only targets approve/unapprove/spam). 
- Single field ⇒ outcome is complete or conflict (no partial) — expected.
- Pre-deploy gates deploy-coupled.

## Verdict
**PASS.** Scope exactly P4.4 (comment status reversibility via a single-field accessor); no forbidden surface; trash/delete byte-identical; gap closed and proven; zero attributable failures. Clears for GO.
