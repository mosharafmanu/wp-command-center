# PROGRAM-4C.0a — Bulk Independent Audit (Phase F)

> **Type:** independent adversarial audit (read-only; no code changes). Report-only.
> **Mandate:** assume the implementation is wrong; attempt to break rollback, history, drift handling, idempotency, partial restore, repeated restore. **Auditor:** fresh agent, separate from the implementer.

---

## VERDICT: **GO**

The corruption is genuinely eliminated. The auditor attacked all 11 required dimensions plus 6 additional edge cases and found **no** data-corruption, mis-restore, silent-failure, idempotency, or scope defect. All 35 functional assertions pass with non-tautological checks; Woo and ACF branches ran live.

## Checks

| # | Dimension | Result | Evidence |
|---|---|---|---|
| 1 | Corruption gone — no status→title write on any path | **PASS** | old `wp_update_post(['ID'=>…,'post_title'=>$old_title])` deleted; restore writes each captured key to its own field via `array_key_exists`; B2 title stays `ORIG_T1` |
| 2 | Action dispatch includes `bulk_draft` (unpublish) | **PASS** | unpublish stores action `"bulk_draft"`; dispatch `in_array(…,['bulk_content','bulk_publish','bulk_draft','bulk_media'])` includes it; B1 restores `draft` |
| 3 | Legacy scalar back-compat | **PASS** | `normalize_snap` maps scalar→`post_status` for publish/draft, else `post_title`; B7 |
| 4 | Field-scoping / sibling preservation | **PASS** | `array_key_exists` (not `isset`) restores `''`/null faithfully; B3b sibling content survives a title-only rollback |
| 5 | Idempotency | **PASS** | `!empty(rollback_applied)` checked before, set after; B8 second call → `done` |
| 6 | Partial / missing entities / no uncaught throw | **PASS** | deleted ids skipped; `wc_get_product` false / `update_field` bad id do not throw; restored count + mark applied (intended skip-and-mark) |
| 7 | Dependency-gated retryability | **PASS** | Woo/ACF inactive or missing field_key → `unsupported` returned **before** the mark line ⇒ `rollback_applied=false`, retryable |
| 8 | History honesty | **PASS** | success envelope only after real restore; unknown action → structured `wpcc_bulk_rollback_unsupported`, never false success |
| 9 | Empty-record guard / rid surfacing | **PASS** | record stored only when `$before` non-empty; B10 rid surfaced for publish/media/content |
| 10 | Scope & invariants | **PASS** | only `BulkRuntimeManager.php` changed (+ new test); `BulkRegistry::ACTIONS` + constants unchanged; new fields additive; no registry/schema/cap/MCP/REST/security change |
| 11 | Test rigor | **PASS** | B2 asserts title ∉ {draft,publish} AND B1 asserts status restored — non-tautological; Woo + ACF ran live |

## Defects
**None GO-blocking.** Two low-severity observations:
- **OBS-1 (process):** at audit time the fix was uncommitted (working-tree only). → resolved in Phase G (commit on the dedicated branch).
- **OBS-2 (LOW, defense-in-depth):** `set_status()` on the woo restore branch writes the stored status without re-validation; a corrupted record could set an invalid status. Not reachable in normal flow (the stored value always originates from `get_status()`), and consistent with sibling-runtime behavior. **Acceptable for a hotfix; noted for the P4.8 delta redesign** (which adds drift/record-integrity guards).

## Adversarial edge cases tried (beyond the suite)
- Unpublish record action mismatch (`bulk_draft`) → handled.
- NULL prior value restore (existence fidelity) → restored via `array_key_exists`.
- No-field woo op → no phantom record (rid '').
- Invalid stored status on restore → accepted (OBS-2), no throw, no corruption of other fields.
- Unknown record type → structured unsupported, not success.
- Double rollback → guarded.

## Conclusion
The remediation closes the confirmed corruption and all Phase-A coverage gaps within Bulk scope, is backward-compatible (and *corrects* legacy status records), preserves siblings, is idempotent, honest, and retryable on dependency-gated paths. **GO for branch commit (Phase G).** OBS-2 carried forward to P4.8.
