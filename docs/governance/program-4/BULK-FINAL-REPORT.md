# PROGRAM-4C.0a — Bulk Rollback Corruption Remediation · Final Report

> **Branch:** `program-4c.0a-bulk-rollback-fix` (from `program-4b-integration-core-hardening` @ `8550a4b`). **No merge / push / deploy.**
> Companion: [Forensic](BULK-FORENSIC-REPORT.md) · [Risk](BULK-RISK-ASSESSMENT.md) · [Design](BULK-REMEDIATION-DESIGN.md) · [Validation](BULK-VALIDATION-REPORT.md) · [Independent Audit](BULK-INDEPENDENT-AUDIT.md).

## 1. Outcome
The confirmed Bulk rollback corruption is remediated. Status rollbacks now restore `post_status` (and never touch `post_title`); `bulk_media`/`bulk_woocommerce`/`bulk_acf` are now reversible; `bulk_content` captures `post_content`; `rollback_id` is surfaced; the rollback response is honest. **Hotfix scope only** — legacy snapshot corrected; no `RollbackDelta`/per-item store/drift (deferred to P4.8).

## 2. Defect (verified from source, base `8550a4b`)
`bulk_status` captured `{id→post_status}` but `rollback()` wrote that status string into `post_title` and never restored status — active title corruption + silent non-restoration on up to 200 posts/op. `bulk_media`/`woo`/`acf` had no rollback record; `rollback_id` was never surfaced for any bulk op; the rollback envelope returned success unconditionally. Two of four guarantees (Rollback, Audit) were breached on this surface.

## 3. Fix (one file: `includes/Operations/BulkRuntimeManager.php`, +103/−24)
- **Self-describing capture:** each op records a per-id field map + a `fields` list (`bulk_content`→title/content; status→post_status; media→title; woo→regular_price/status; acf→prior value + `field_key`).
- **Action-dispatched restore:** `rollback()` branches on the record `action` (incl. the `bulk_draft` action used by unpublish) and restores **only the captured fields** via the correct primitive (`wp_update_post` / WC setters+save / `update_field`) — no cross-field clobber.
- **Backward-compatible reader:** a legacy bare-scalar `before[id]` is normalized to the action's primary field — so even a pre-existing `bulk_publish`/`bulk_draft` legacy record now restores **status** correctly instead of clobbering the title.
- **Reversibility + honesty:** `store_rollback` returns the id; ops surface `rollback_id`; rollback returns `{type,restored,fields,reversible}` + a `bulk.rollback` audit event; dependency-gated (woo/acf) and unknown-type records return a structured `reversible:false` error **without** marking applied (retryable). Idempotency guard preserved.

## 4. Validation
- **`test-bulk-rollback-fix.sh` (new): 35/0** — corruption reproduced-as-fixed (B1/B2), content + sibling preservation (B3/B3b), media/woo/acf reversibility (B4/B5/B6, Woo+ACF live), legacy compat (B7), idempotency (B8), honesty (B9), id surfacing (B10).
- **Guards:** bulk-runtime **41/0**, rollback-delta-core **25/0**, operations-registry **18/0**, capability-runtime **61/0**, mcp-error-surface **18/0**, change-history-rollback **48/0** (standalone). **Attributable failures: 0.**
- **Invariants:** OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0 — held.

## 5. Independent audit
**GO.** No GO-blocking defects across 11 attacked dimensions + 6 extra edge cases. Two low-severity observations: OBS-1 (commit the fix — done here, Phase G); OBS-2 (woo restore writes stored status without re-validation — unreachable in normal flow, matches sibling runtimes, carried to P4.8).

## 6. Scope / STOP
- Exactly one runtime file changed + one new test. Added response fields are **additive** (existing REST suite green at 41/0).
- **No** schema / DB_VERSION / capability / operation-registry / MCP / REST-contract / security-model change. **No STOP condition triggered.**
- No adjacent cleanup, no architecture expansion, no Program-4 spillover (branch based pre-P4.6).

## 7. GO / NO-GO
**GO** — corruption eliminated, coverage gaps closed, backward-compatible, idempotent, honest; invariants frozen; independent audit GO; attributable failures 0. **Committed on `program-4c.0a-bulk-rollback-fix` only — no merge / push / deploy.**

## 8. Carried forward (out of this hotfix)
P4.8 Bulk delta redesign (`PostMetaRollbackStore` + per-item field-scoped `RollbackDelta` + drift-awareness + record-integrity guard for OBS-2) per the PROGRAM-4C roadmap. This hotfix stabilizes the surface; P4.8 closes F-1 fully.
