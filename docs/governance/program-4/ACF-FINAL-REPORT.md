# PROGRAM-4.9 — ACF Rollback Integrity · Final Report

> **Branch:** `program-4.9-acf-rollback-integrity` (from `program-4.8-bulk-delta-redesign` @ `81afaab`; carries P4C.0a hotfix + P4.7 keystone + P4.8 bulk delta). **No merge / push / deploy.**
> Companion: [Forensic](ACF-FORENSIC-REPORT.md) · [Risk](ACF-RISK-ASSESSMENT.md) · [Design](ACF-ROLLBACK-DESIGN.md) · [Validation](ACF-VALIDATION-REPORT.md) · [Independent Audit](ACF-INDEPENDENT-AUDIT.md).

## 1. Outcome
ACF rollback is now **honest and safe** within a bounded scope, without decomposing any nested ACF structure:
- **value_update (post-bound ACF values):** drift-aware, existence-faithful, **whole-field atomic** delta via `RollbackDelta` + new `AcfValueAccessor`, stored in `PostMetaRollbackStore` (`_wpcc_acf_rb_{id}` — O(1), no FIFO, GC-with-post). Nested values (repeater/flexible/group/clone/gallery/relationship) are captured/compared/restored as one unit; on drift → **skip + conflict** (never clobber); on match → restore prior (clears if prior was absent). Legacy option records still restore.
- **Definition update-in-place (group_update, field_update, location_assign/remove, layout_update):** a whole-definition **fingerprint drift-guard** (refuse-on-drift) — **new records only**; legacy records keep prior behavior; the marker never reaches `acf_update_*`. No decomposition.
- **json_import:** honest **`reversible:false` unsupported** (was a phantom clean-success — no restore branch existed).
- **Create/delete inverse ops:** unchanged.

## 2. Phase A — forensic (source + empirical probe)
All rollback paths were unconditional whole-blob restores (no drift). `value_update` is POST-only (no user/term/option). `json_import` stored a lossy record with **no `rollback()` branch** → false clean-success. Probe confirmed: definition fingerprint **stable** under canonicalization; `update_field(null)` cleanly clears a value (existence-faithful).

## 3. Phases B–C — risk + design
Classified surfaces (safe-flat / complex-atomic / nested-structured / definitions / unsafe). Chose: **field-scoped RollbackDelta whole-field** for values (atomic for nested — no decomposition), **whole-definition fingerprint drift-guard** for definition updates, **honest unsupported** for json_import. Safe-by-construction: refuse-on-drift only adds refusals; never clobbers; legacy untouched.

## 4. Phase D — implementation
`AcfValueAccessor` (new, ACF-scoped: key→name resolution for existence, raw `get_field(...,false)` reads, `update_field` writes/clears, whole-value normalized drift). `ACFRuntimeManager`: value_update capture→store via keystone; `rollback()` value-postmeta path + definition fingerprint guard + json_import honesty; `definition_fingerprint`/`canonicalize_def` helpers; `store_rollback` apply-time fingerprint capture for guarded actions. Reuses `RollbackDelta` + `PostMetaRollbackStore` unchanged.

## 5. Phases E–F — validation + independent audit
- **New ACF suite: 47/0** — flat fidelity, key-vs-name, empty-prior clear, empty-but-existing, sibling preservation, same-field drift conflict, out-of-order (no resurrection), idempotency, legacy record, json_import honesty, nested atomic + drift, `_field` reference preserved, definition guard (drift-refuse + clean-restore), post_object formatted-return raw round-trip.
- **Existing ACF suites green:** runtime 44/0, step92 23/0 (incl. #10), group-delete-f31 15/0, nested-read-f32 18/0.
- **Regression all green:** core 25/0, postmeta-store 30/0, SEO 56/0, Settings 38/0, Media 41/0, Content 30/0, Comments 27/0, User 28/0, Woo step93 19/0, Bulk delta 53/0, Bulk fix 35/0, Bulk runtime 41/0, registry 18/0, capability 61/0, MCP 18/0, change-history 48/0 (standalone). **Net-new attributable failures: 0.**
- **Invariants:** 34 · 23 · 40 · 40 · 2.5.0 — held.
- **Independent audit: GO.** No GO-blocking defects across 14 vectors. One LOW–MEDIUM fidelity concern (formatted-value round-trip) **fixed** (read raw/unformatted); test A13 added; re-ran 47/0.

## 6. Scope / STOP
- Files: `ACFRuntimeManager.php` + new `AcfValueAccessor.php` + new test. `RollbackDelta`/`PostMetaRollbackStore` byte-unchanged.
- **No** decomposition of nested ACF values (atomic). **No** new ACF capability/field type/op. **No** user/term/option support added (runtime post-only — not broadened). **No** schema / DB_VERSION / operation-registry / capability / MCP / REST / UI / security change. New meta keys + record fields additive. **No STOP triggered** — nested behavior made safe via atomic whole-value handling; mutation scope unchanged.

## 7. GO / NO-GO
**GO** — ACF value rollback is drift-aware, existence-faithful, sibling-safe, out-of-order-safe, atomic for nested, honest on partial/conflict/unsupported, legacy-compatible; definition updates are drift-guarded (refuse, never clobber); json_import honest. Invariants frozen; independent audit GO; attributable failures 0. **Committed on `program-4.9-acf-rollback-integrity` only — no merge / push / deploy.**

## 8. Carried forward (out of scope, honestly noted)
- `bulk_value_update` (ACF runtime) remains without its own rollback record — Bulk ACF reversibility is P4.8's `bulk_acf`; per the mission exclusion, not extended here.
- Definition **create/delete** inverse ops keep their existing (drift-tolerant) behavior; only update-in-place got the fingerprint guard.
- True per-row nested-ACF delta (vs atomic whole-field) remains intentionally out — it would require decomposing ACF's row addressing (a STOP-class owner decision). Atomic handling is the safe resolution.
