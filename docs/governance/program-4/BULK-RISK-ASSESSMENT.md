# PROGRAM-4C.0a — Bulk Rollback Risk Assessment (Phase B)

> **Type:** impact analysis (no code). Report-only. Predicated on the Phase-A forensic confirmation.
> **Bug confirmed:** proceeding with impact + Four-Guarantees analysis.

---

## 1. Severity

| Defect | Class | Severity | Rationale |
|---|---|---|---|
| `bulk_status` rollback writes status into `post_title` | active data corruption | **CRITICAL** | destroys a field the user never edited; unrecoverable without external backup |
| `bulk_status` rollback never restores `post_status` | silent non-restoration | **CRITICAL** | the rollback's stated purpose fails while reporting success |
| `bulk_media` / `bulk_woo` / `bulk_acf` no rollback record | coverage gap (irreversible) | **HIGH** | governed, approval-gated writes with zero reversibility; Woo price edits are commercially sensitive |
| `bulk_content` omits `post_content` from capture | incomplete record | **MEDIUM** | content edits half-reverse (title yes, body no) |
| `rollback_id` never surfaced for any bulk op | contract / usability gap | **MEDIUM** | the rollback endpoint is effectively unreachable through the normal response |
| Success envelope returned regardless of outcome | dishonest history | **MEDIUM** | violates audit/observability expectations |

**Overall surface severity: CRITICAL** — it is the only rollback surface that *actively corrupts* data, and it does so on an approved, high-risk, multi-item operation (largest blast radius in the platform).

## 2. Affected operations
- **Corrupting:** `bulk_publish`, `bulk_unpublish` (both route through `bulk_status`).
- **Irreversible:** `bulk_media`, `bulk_woo`, `bulk_acf`.
- **Incomplete:** `bulk_content` (post_content).
- **Unaffected/correct:** `batch_execute` (delegates to per-operation handlers; reversibility is the inner op's responsibility — out of scope here).

## 3. Production risk
- **Blast radius:** up to `MAX_ITEMS = 200` posts per operation; a single `bulk_unpublish`-then-rollback corrupts up to 200 titles at once.
- **Detectability:** **low** — the rollback returns `success`, the timeline shows a completed rollback, and nothing flags that titles were overwritten or status left unreverted. The corruption is silent.
- **Likelihood (today):** the deploy state mitigates *current* exposure — Bulk is **branch-only / not deployed** (production `a41a9d7` contains this code but the bulk runtime is reachable only by a token-holder via the governed REST/MCP path, and the rollback_id is unsurfaced so reversal is rarely invoked). The risk is **latent**: it activates the moment a bulk rollback is wired into the UI/agent flow (which surfacing the rollback_id — part of this fix — would do). Fixing the corruption *before* the id is surfaced is the correct order.
- **Reversibility of the damage:** title corruption is **not** self-recoverable (the original title is lost once overwritten and the record is marked applied).

## 4. Audit-integrity impact
- The audit event `bulk.<action>` (`:40`) records only a count; the **rollback** path emits no dedicated audit event and returns a success envelope irrespective of correctness. An operator auditing the trail would see "bulk rollback applied" and reasonably conclude the prior state was restored — **the audit record is misleading**. This undermines the Audit guarantee's purpose (a faithful, reviewable history).

## 5. Four Guarantees impact

| Guarantee | Status on Bulk | Impact |
|---|---|---|
| **Approval** | intact | all bulk actions `requires_approval=true` (`BulkRegistry::requires_approval`); gating unaffected |
| **Rollback** | **BREACHED** | status rollback corrupts + fails; media/woo/acf have no rollback; content rollback incomplete; ids unsurfaced. The guarantee is materially false for Bulk. |
| **Audit** | **DEGRADED** | rollback returns success regardless of outcome; no faithful per-action rollback record |
| **Capability Scoping** | intact | `bulk.manage` capability + `SelectionResolver` bounds unaffected |

Two of the four guarantees (Rollback, Audit) are compromised on this surface. This is precisely the class of defect Program-4 exists to close.

## 6. Containment & ordering rationale
- **Do not surface rollback_id until corruption is fixed.** Surfacing the id (a usability fix) without fixing the restore would *increase* exposure by making the corrupting path easy to invoke. This remediation fixes the restore **and** surfaces the id in the same change, so reversibility becomes both reachable and correct simultaneously.
- **Scope boundary:** this is the P4C.0a *correctness hotfix* (legacy snapshot, corrected) — **not** the P4.8 delta redesign. It restores the right fields and closes the coverage gaps; it does **not** introduce per-item postmeta storage, the `RollbackDelta` core for bulk, or drift-awareness (those are deferred to P4.7/P4.8). Keeping the hotfix minimal limits regression risk and respects "no architecture expansion."

## 7. Constraints confirmed (no STOP)
The remediation is achievable entirely within `BulkRuntimeManager.php`, reusing the existing `wpcc_bulk_rollbacks` option and the existing action set. **No** schema / DB_VERSION / capability / operation-registry / MCP / REST-contract / security-model change is required. Adding `rollback_id` to bulk responses is **additive** (no field removed/renamed) and aligns Bulk with the platform-wide convention already used by Content/Woo/SEO. Invariants 34 · 23 · 40 · 40 · 2.5.0 are expected to hold unchanged.
