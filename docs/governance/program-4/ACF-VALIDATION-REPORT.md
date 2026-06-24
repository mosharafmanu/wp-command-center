# PROGRAM-4.9 — ACF Validation Report (Phase E)

> **Type:** validation results (no code changes beyond the audit-driven raw-read fix). Report-only.
> **Branch:** `program-4.9-acf-rollback-integrity` (base `81afaab`). **Code:** `ACFRuntimeManager.php` (value_update delta + rollback value-path + definition fingerprint guard + json_import honesty + helpers) + new `includes/Rollback/AcfValueAccessor.php`; new `tests/test-acf-rollback-delta.sh`.

---

## 1. New ACF rollback suite — `test-acf-rollback-delta.sh`: **47 / 0**
Manager-level, PHP-bootstrapped, against a real ACF field group (text + post_object fields) with ACF active.

| # | Coverage point | Scenario | Result |
|---|---|---|---|
| 1 | flat text value restore | A1 (value-prior → exact restore) | PASS |
| — | key-vs-name selector | A1b (field_key selector resolves to name) | PASS |
| 2 | empty-prior restore | A2 (absent before → rollback clears) | PASS |
| 3 | empty-but-existing restore | A3 (`''` restored) | PASS |
| 4 | sibling field preservation | A4 (other field untouched) | PASS |
| 5 | same-field drift skip/report | A5 (conflict, error, value NOT clobbered) | PASS |
| 6 | out-of-order no resurrection | A6 (rollback B then A → back to original) | PASS |
| 7 | repeated rollback safety | A7 (second → already_applied) | PASS |
| 8 | legacy record restore | A8 (option `value_update` record restores) | PASS |
| 9 | partial/conflict NOT clean success | A5 (error:true) + A9 (json_import) | PASS |
| 10 | nested treated ATOMICALLY + drift | A10 (whole-array applied; drift → conflict; drifted value kept) | PASS |
| 11 | ACF `_field` key-reference preserved | A11 (`_p49_text` present after restore) | PASS |
| 12 | user/term/option scope | post-only (runtime guard) — N/A, not added | n/a |
| 13 | unsupported reported honestly | A9 (json_import → `wpcc_rollback_unsupported`, reversible:false) | PASS |
| — | definition fingerprint guard (drift) | A12 (external def edit → refuse conflict, edit NOT clobbered) | PASS |
| — | definition guard (clean) | A12b (no external edit → restores prior title) | PASS |
| — | formatted-return raw round-trip | A13 (post_object → raw id captured/restored) | PASS |

Static (11): RollbackDelta capture/restore, PostMetaRollbackStore, json_import honest-unsupported, definition fingerprint guard, volatile-key drop, new-records-only guard, `__after_fp` never fed to `acf_update_*`, accessor whole-value compare + name-based existence.

## 2. Existing ACF suites (regression)
| Suite | Tally |
|---|---|
| `test-acf-runtime.sh` (REST) | **44 / 0** |
| `test-acf-runtime-step92.sh` (incl. #10 field_create→rollback-delete) | **23 / 0** |
| `test-acf-group-delete-f31.sh` | **15 / 0** |
| `test-acf-nested-read-f32.sh` | **18 / 0** |

The create/delete inverse paths and definition restores are unchanged; #10 (field_create→rollback removes the field) still passes.

## 3. Full regression battery
| Suite | Tally |
|---|---|
| **ACF rollback (new)** | **47 / 0** |
| rollback-delta-core | **25 / 0** |
| PostMetaRollbackStore | **30 / 0** |
| SEO delta | **56 / 0** |
| Settings delta | **38 / 0** |
| Media metadata delta | **41 / 0** |
| Content delta | **30 / 0** |
| Comments delta | **27 / 0** |
| User delta | **28 / 0** |
| Woo product (STEP 93) | **19 / 0** |
| Bulk delta | **53 / 0** |
| Bulk rollback-fix | **35 / 0** |
| Bulk runtime (REST) | **41 / 0** |
| operations-registry (catalogue 40) | **18 / 0** |
| capability-runtime (caps 23) | **61 / 0** |
| mcp-error-surface (MCP 40) | **18 / 0** |
| change-history-rollback (standalone) | **48 / 0** |

**Net-new attributable failures: 0.**

## 4. Invariants
`OPERATION_MAP=34 · capabilities=23 · DB_VERSION=2.5.0` probed live; `catalogue=40` / `MCP=40` via the passing registry + MCP guards. **All held — unchanged.**

## 5. Audit-driven fix
The independent audit flagged that `AcfValueAccessor::read_field` used `get_field()` default-formatted, so for fields with formatted return (relationship/post_object→objects, image→array) the captured/restored value would be the formatted (non-storable) form. **Fixed:** read **raw** via `get_field($selector,$id,false)` so capture↔after↔restore are symmetric with `update_field`'s storable form (drift stays exact). New test **A13** (post_object) proves the raw-id round-trip. Re-ran: ACF 47/0 — no regression.

## 6. Scope / STOP
- Files: `ACFRuntimeManager.php` + new `AcfValueAccessor.php` + new test. `RollbackDelta`/`PostMetaRollbackStore` byte-unchanged.
- **No** decomposition of nested ACF values (atomic). **No** new ACF capability/field type/op. **No** user/term/option support added (post-only). **No** schema / DB_VERSION / operation-registry / capability / MCP / REST / UI / security change. **No STOP triggered.**

## 7. Verdict
All suites green; net-new attributable failures 0; invariants unchanged; ACF value rollback is now drift-aware, existence-faithful, sibling-safe, out-of-order-safe, atomic for nested, honest on partial/conflict/unsupported, and legacy-compatible; definition updates are drift-guarded (refuse, never clobber); json_import is honest. **Ready for the Phase F audit record + commit.**
