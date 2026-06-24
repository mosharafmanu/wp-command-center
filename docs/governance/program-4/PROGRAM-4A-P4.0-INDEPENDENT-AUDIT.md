# PROGRAM-4A / P4.0 — Independent Diff Audit

> **Date:** 2026-06-23 · **Posture:** adversarial self-audit against the P4.0 scope contract and the hard compatibility requirements. **Question asked:** did anything change that shouldn't have, and did any behaviour drift?

## 1. Changed-files audit — only P4.0 surfaces

**Tracked code changes (this work):**
- `includes/Operations/SeoRuntimeManager.php` — delegation extraction (net −26 lines).
- `tests/test-seo-rollback-delta.sh` — static-guard re-points + new wiring guards.

**New files (this work):**
- `includes/Rollback/FieldAccessor.php`, `PostMetaAccessor.php`, `SeoFieldAccessor.php`, `RollbackDelta.php`.
- `tests/test-rollback-delta-core.sh`.
- `docs/governance/program-4/PROGRAM-4A-P4.0-*.md` (reports).

**Forbidden surfaces — confirmed UNTOUCHED** (grep over `git status`):
- OperationRegistry, CapabilityRegistry, McpServerRuntime, `Schema.php` → **none changed** ⇒ no op/cap/MCP/schema/DB_VERSION drift.
- REST routes, admin/UI, `*.css`, `*.js` → **none changed**.
- `SeoProvider.php` → **unchanged** (the accessor wraps it; no new public surface).

**P4.1+ slip — confirmed NONE:** no `Settings`/`Media`/`Woo`/`ACF`/`Bulk`/`Content`/`User`/`Comments`/`Forms`/`Plugin`/`Theme` runtime file changed. Only SEO consumes the new core, exactly as scoped.

> Note: `docs/product/PHASE-B-P1-REMEDIATION-PLAN.md` and `SESSION-HANDOFF-2026-06-18.md` show as modified, but they were **already modified at session start** (pre-existing working-tree edits, unrelated to P4.0) — not touched by this work.

## 2. Behaviour-drift audit (the SeoRuntimeManager diff)
Reviewed line-by-line:
- `restore_delta`: the inline loop was replaced by `RollbackDelta::restore(new SeoFieldAccessor($provider), $post_id, $record['fields'])`. The **core loop is a 1:1 lift** — same `read_field` LHS, same `equals` drift test, same `existed ? key_set(prior) : (key_exists ? key_delete)` existence fidelity, same `empty(skipped)?complete:(empty(restored)?conflict:partial)` status formula. The runtime **retains** mark-applied-on-complete, the `seo.restored` audit, and both success/conflict/partial envelopes verbatim.
- `values_equal` removed → `SeoFieldAccessor::equals` carries the **identical** robots sorted-set / scalar-string logic.
- `capture_prior`: inline loop replaced by `RollbackDelta::capture(...)` — the core does the **same** `backing_keys → {existed:key_exists, prior:key_get}` capture.
- `store_rollback`: **not in the diff** — the v2 record literal (`version=>2`, `fields`, `post_id`, `provider`, …) is byte-identical ⇒ on-disk shape frozen.
- `seo_update`, `seo_restore`, `restore_legacy_meta`, `seo_restore_legacy`: **not in the diff** — unchanged.

**Conclusion:** the moved code is byte-equivalent in behaviour to the removed code; the runtime retains all WP/provenance-specific responsibilities. No semantic drift.

## 3. Test-change audit (re-points — not gaming)
The 5 static structural guards failed only because their target code **moved**; behaviour is independently proven by the **34 unchanged functional round-trips** + the **25 core-unit assertions**. Re-points (old→new), all verified present:

| Guard | Old location | New location / string |
|---|---|---|
| existence flag in capture | `$SRC` `metadata_exists( 'post', $post_id, $key )` | `PostMetaAccessor.php` `metadata_exists( 'post', (int) $entity_id, $key )` |
| drift comparator | `$SRC` `function values_equal` | `SeoFieldAccessor.php` `public function equals` + `sort( $c )` |
| drift conflict record | `$SRC` `'reason' => 'drift'` | `RollbackDelta.php` `'reason' => 'drift'` |
| existed=true restores prior | `$SRC` `update_post_meta( $post_id, $key, $meta['prior'] )` | `RollbackDelta.php` `$accessor->key_set( $entity_id, $key, $meta['prior'] )` |
| existed=false deletes | `$SRC` `delete_post_meta( $post_id, $key )` | `RollbackDelta.php` `$accessor->key_delete(...)` + `PostMetaAccessor.php` `delete_post_meta( (int) $entity_id, $key )` |

**Added (not removed) guards:** "SEO delegates capture to core", "SEO delegates restore to core", "robots set-compare retained", "accessor key_delete = delete_post_meta". No functional assertion was weakened or removed; net assertion count rose 52 → 56.

## 4. Hard compatibility requirements — audited
| # | Requirement | Evidence | Verdict |
|---|---|---|---|
| 1 | 7aa7e84 v2 records readable/restorable | `store_rollback` unchanged; functional suite writes/restores v2 via new core | ✅ |
| 2 | Persisted record shape unchanged | `store_rollback` not in diff | ✅ |
| 3 | Legacy `before_state` records work | dispatch + legacy methods unchanged; S7 green | ✅ |
| 4 | Empty-prior fidelity (absent→delete / empty→restore-empty / value→exact) | delta S1/S2 + core S1/S2/S2b | ✅ |
| 5 | Drift → skip + conflict, never clobber | delta S4 + core S4 | ✅ |
| 6 | Partial/conflict not clean success | delta S9 + core S6 | ✅ |
| 7 | Repeated rollback idempotent | delta S8 (runtime mark-applied unchanged) | ✅ |
| 8 | Rank Math robots fidelity | delta S6 + core S7 | ✅ |
| 9 | Yoast structural compatibility | delta S10 (3-key shape) | ✅ |
| 10 | Invariants 34/23/40/40/2.5.0 | re-verified static + parity suites | ✅ |

## 5. Audit verdict
**PASS.** Scope is exactly P4.0 (SEO-only consumer of a new shared core); no forbidden surface touched; no P4.1 slip; no behaviour drift; test changes are faithful relocations with added coverage; all 10 hard compatibility requirements satisfied. Clears for the FINAL report's GO decision.
