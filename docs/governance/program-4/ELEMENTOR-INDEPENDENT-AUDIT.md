# PROGRAM-4.10 — Elementor Independent Audit (Phase F)

> **Type:** independent adversarial audit (read-only; no code changes). Report-only.
> **Mandate:** attack Elementor JSON order changes, widget reorder, concurrent edit, partial JSON mutation, malformed record, same-field drift, out-of-order rollback, legacy record, generated-CSS mismatch, unsupported reporting. **Auditor:** fresh agent, separate from the implementer.

---

## VERDICT: **GO**

The implementation is honest, atomic, drift-aware, and tightly scoped. No path decomposes the JSON, clobbers a drifted/concurrent document, returns false clean-success, corrupts JSON via slashing, goes fatal, or violates scope. The drift comparator was empirically confirmed order-sensitive.

## Checks

| # | Attack | Result | Evidence |
|---|---|---|---|
| 1 | Reorder/add/remove → drift; order-SENSITIVE (no ksort) | **PASS** | `ElementorDataAccessor::normalize` json_decode→`wp_json_encode` (insertion-order preserved, no sort); empirically reorder→drift; new test Ereorder |
| 2 | Concurrent edit to a DIFFERENT widget → refuse, no clobber | **PASS** | whole-doc `equals`; sibling change → conflict; Esib (`B_EXTERNAL` preserved) |
| 3 | No partial/merged write | **PASS** | single `data`→`_elementor_data` key; `key_set` writes whole string; no per-widget keys |
| 4 | Malformed record / malformed live JSON → not-found/refuse, no fatal | **PASS** | non-array record → resolve null → legacy → not-found; malformed live → normalize null → raw compare → drift → conflict; Emalformed (`NOTJSON{` not clobbered) |
| 5 | Same-field drift | **PASS** | Edrift (conflict, `DB` kept) |
| 6 | Out-of-order rollback | **PASS** | Eooo (B→DA, A→D0, no resurrection) |
| 7 | Legacy record | **PASS** | legacy option path unchanged; Elegacy (`LEG`, path 'legacy') |
| 8 | Generated-CSS mismatch | **PASS** | `clear_cache` on complete restore; `_elementor_css` deleted not restored (regenerable) |
| 9 | wp_slash fidelity | **PASS** | capture reads raw `get_post_meta`; `key_set` wp_slashes once; both drift sides unslashed-consistent; byte-faithful round-trip |
| 10 | False clean-success | **PASS** | conflict → `error:true` ⇒ executor `success=false`; complete → `reversible:true`; missing → not_found |
| 11 | Scope / invariants | **PASS** | 1 file changed + 1 new accessor; RollbackDelta/PostMetaRollbackStore/ElementorRegistry byte-identical; no op/capability/schema/DB_VERSION/MCP/REST/security; `_elementor_data` only; atomic (no decomposition); dead `store_rollback` removed; new path no-FIFO postmeta |
| 12 | Existing step96 button-rollback via new path | **PASS** | all 3 ops route through edit_widget→capture/build/persist; rollback resolves postmeta→delta; step96 26/0 |
| 13 | Test rigor | **PASS** | value-based assertions (exact values, error flags, codes); reorder case added post-audit |

## Defects
**None GO-blocking.** Two low-severity observations:
- **OBS-1 (test) → addressed.** No dedicated widget-reorder functional case at audit time (order-sensitivity was empirically verified). Added **Ereorder**; re-ran 34/0.
- **OBS-2 (cosmetic).** The delta conflict envelope is hand-rolled rather than via `RollbackDelta::result()` (omits `conflicts`/`message`); behavior is correct (`error:true`, `code`, `status`, `restored:false`) and consistent with the ACF P4.9 convention. No functional impact; left as-is for cross-runtime consistency.

## Conclusion
PROGRAM-4.10 makes Elementor rollback honest and safe by treating `_elementor_data` as one atomic whole-document field: drift-aware (order-sensitive), refuse-on-drift (never clobbers a concurrent/reordered edit), existence-faithful, out-of-order-safe, honest on conflict/malformed/missing, and legacy-compatible. No JSON decomposition, no widget-level rollback, no contract/schema change, mutation scope unchanged. **GO for branch commit (Phase G).**
