# PROGRAM-4.9 — ACF Independent Audit (Phase F)

> **Type:** independent adversarial audit (read-only; no code changes). Report-only.
> **Mandate:** attack nested repeater overwrite, flexible order changes, clone/group ambiguity, relationship/gallery id arrays, ACF key-reference loss, option/user/term scope mismatch, malformed records, same-field drift, out-of-order rollback, partial-success honesty. **Auditor:** fresh agent, separate from the implementer.

---

## VERDICT: **GO**

No path decomposes nested values, clobbers a drifted value/definition, orphans the ACF `_field` key-reference, returns a phantom clean-success, or goes fatal. Scope respected. One LOW–MEDIUM fidelity concern (formatted-value round-trip) was reported and **fixed**.

## Checks

| # | Attack | Result | Evidence |
|---|---|---|---|
| 1 | Nested repeater/flex atomic; concurrent-row clobber | **PASS** | single `value` field; `equals()` whole-array `wp_json_encode` compare; any nested change → drift → `restore` skips |
| 2 | Flex order / clone/group structure drift | **PASS** | JSON-encode order-sensitive → structural change refused |
| 3 | Relationship/gallery id-array atomic | **PASS** | whole-value path; A10/A13 |
| 4 | `_field` key-reference preserved | **PASS** | restore via `update_field`/`update_field(null)`, never raw `delete_post_meta`; A11 |
| 5 | key-vs-name existence; virtual field | **PASS** | name resolved via `acf_get_field(selector)['name']`, safe fallback to selector; existence on resolved name; A1b |
| 6 | Non-post entity mishandling | **PASS** | value_update post-only (post_id guard); accessor casts `(int)`; not reachable for user/term |
| 7 | Malformed records | **PASS** | `resolve` null on non-array; `(array)before_state`; corrupt `__after_fp` ⇒ drift ⇒ refuse; empty fields not reachable via build_record |
| 8 | Same-field drift | **PASS** | compares recorded `after` vs live; A5 → conflict, value kept |
| 9 | Out-of-order no resurrection | **PASS** | per-field `after` snapshot; A6 |
| 10 | Fingerprint determinism / no marker leak / new-only | **PASS** | recursive ksort + drop volatile keys + sha1 (stable per Phase-A probe); `unset(__after_fp)` before any `acf_update_*`; guard gated on `isset(__after_fp)` ⇒ legacy untouched; unreadable def → `''` → safe refusal; A12/A12b |
| 11 | Partial/conflict & json_import honesty | **PASS** | conflict/unsupported set `error:true` ⇒ executor `success=false`; json_import → `reversible:false` (phantom success removed); A9 |
| 12 | Scope / invariants | **PASS** | only `ACFRuntimeManager.php` + new `AcfValueAccessor.php`; RollbackDelta/PostMetaRollbackStore byte-identical; no DB_VERSION/registry/capability/MCP/REST/security; no ACF op/field-type/capability added; post-only; FIFO-200 option retained for legacy+definition records, value records on no-FIFO postmeta |
| 13 | Existing ACF rollback intact | **PASS** | create→delete, group_delete→recreate, field_delete, layout paths unchanged; legacy value/json route via option path; step92 #10 green |
| 14 | Test rigor | **PASS (post-fix)** | proves flat fidelity, empty-clear, empty-existing, sibling, drift, out-of-order, idempotency, legacy, json honesty, def-guard drift+clean; **nested/formatted now covered by A13 (post_object raw round-trip) + A10 (atomic array drift)** after the fix; A12b title asserted |

## Defects
**None GO-blocking.**
- **LOW–MEDIUM (fidelity) → FIXED.** `AcfValueAccessor::read_field` read with `get_field()` default-formatted; for formatted-return fields (relationship/post_object→objects, image→array) the captured/restored value was the non-storable formatted form. Drift stayed self-consistent (could not clobber), but restore could store a coerced value. **Fix:** read raw via `get_field($selector,$id,false)` — capture↔after↔restore symmetric with `update_field`. New test **A13** (post_object) proves the raw-id round-trip; re-ran ACF 47/0.
- **NOTE (test) → addressed.** Added the missing A12b restored-title assertion and the A13 formatted-return case.

## Conclusion
PROGRAM-4.9 makes ACF rollback honest and safe within a bounded scope: post-bound values get drift-aware, existence-faithful, atomic (no decomposition) whole-field rollback on `PostMetaRollbackStore`; definition update-in-place gets a refuse-on-drift fingerprint guard (legacy untouched); json_import is honestly irreversible. No nested structure is decomposed, no contract/schema changes, runtime mutation scope unchanged. **GO for branch commit (Phase G).**
