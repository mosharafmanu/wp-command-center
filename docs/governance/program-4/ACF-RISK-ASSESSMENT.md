# PROGRAM-4.9 — ACF Risk Assessment (Phase B)

> **Type:** risk classification (no code). Report-only. Predicated on the Phase-A forensic + empirical probe.

---

## 1. Surface classification

| # | Surface | ACF ops | Corruption risk (today) | Drift risk | Sibling-overwrite risk | Rollback feasibility | Recommended model |
|---|---|---|---|---|---|---|---|
| **1** | **Safe flat values** | `value_update` on text/number/email/url/textarea/select/true_false/date/color | LOW (only this field touched) | **HIGH** (unconditional restore clobbers later edit) | none (single field) | **HIGH** | **field-scoped RollbackDelta-style whole-field delta + drift guard** |
| **2** | **Complex but atomic values** | `value_update` on image/file/oembed/link/post_object/page_link/taxonomy/user (scalar-ish ids) | LOW | HIGH | none | **HIGH** | **whole-field value + drift guard** (atomic) |
| **3** | **Nested structured values** | `value_update` on repeater/flexible_content/group/clone/gallery/relationship (arrays) | MEDIUM (whole-field restore wipes a concurrently-edited row) | **HIGH** | none (single field) — but **intra-field** row loss on drift | **MEDIUM — only if treated ATOMICALLY** | **whole-field value, normalized whole-value drift compare → refuse-on-drift (NO decomposition)** |
| **4** | **Field-group / field / location / layout definitions (update-in-place)** | `group_update, field_update, location_assign, location_remove, layout_update` | MEDIUM (whole-blob restore clobbers a newer external def edit) | **HIGH** | n/a (whole def) | **MEDIUM** | **whole-definition + fingerprint drift-guard (refuse-on-drift), new-records-only** |
| **5a** | **Definition create/delete inverse** | `group_create, group_delete, field_create, field_delete, group_duplicate, layout_create` | LOW (inverse is drift-tolerant) | LOW | n/a | OK (leave as-is) | **unchanged** (test #10 depends on these) |
| **5b** | **Unsafe / unsupported** | `json_import` (lossy summary, no restore branch) | **HIGH (false clean-success)** | n/a | n/a | **NOT feasible** | **honest irreversible / unsupported (reversible:false)** |
| **5c** | **Out of runtime scope** | user/term/option-page values, `bulk_value_update` | n/a | n/a | n/a | n/a | **untouched** (not supported by runtime / P4.8 territory) |

## 2. Why nested values must be ATOMIC (not decomposed)
ACF stores a repeater/flexible value as a set of postmeta rows (`field_0_subfield`, `field_1_subfield`, a count row, plus `_`-ref rows). `get_field` reassembles them into a nested array; `update_field` rewrites the whole set. There is **no safe, general way** to merge "restore row 2 of the prior value while keeping a concurrently-added row 4" without re-implementing ACF's row addressing — which is explicitly out of scope and a STOP condition ("ACF nested behavior cannot be made safe without owner decision"). 

The safe resolution: treat the **entire field value as one atomic unit**. Capture the whole prior value; on rollback compare the **whole** current value to the recorded apply-time value (normalized). If they match → restore the whole prior value. If they differ (ANY change, including one row) → **refuse + report conflict** (never clobber). This delivers F-1 safety (no sibling/row clobber, drift-aware, honest) **without any decomposition**.

## 3. Drift-guard safety argument (definitions, surface 4)
- The guard is **refuse-on-drift**: it only ever *adds* a refusal; it never changes what a clean (no-external-change) restore does, and never clobbers.
- It is **new-records-only**: legacy `wpcc_acf_rollbacks` records (no stored fingerprint) restore exactly as today → zero behavior change for existing data/tests.
- The fingerprint is **empirically stable** for unchanged definitions (Phase-A probe: `group fp stable=YES`), so the common immediate-rollback case still succeeds.
- Worst case if the fingerprint is ever unstable: a **false refusal** (reversible:false) — annoying but **safe and honest**; it can never corrupt or clobber.

## 4. False-clean-success elimination (the truthfulness requirement)
- `json_import` rollback today returns `{action:acf_rollback, rollback_id}` (success) while doing nothing → must become an explicit `reversible:false` / unsupported envelope.
- value_update + definition drift conflicts must return an **error envelope** (so `OperationExecutor::rollback`'s `success = empty(result['error'])` is truthful), matching `RollbackDelta::result()` — never a silent clean success when nothing (or only part) was restored.

## 5. Feasibility verdict
A **safe, bounded** design exists:
- **Surfaces 1–3 (value_update):** drift-aware whole-field delta on `PostMetaRollbackStore` (post-bound). Atomic for nested. **IMPLEMENT.**
- **Surface 4 (definition update-in-place):** whole-definition fingerprint drift-guard, new-records-only, no decomposition. **IMPLEMENT (conservative).**
- **Surface 5b (json_import):** honest unsupported/irreversible. **IMPLEMENT (honesty).**
- **Surfaces 5a / 5c:** **leave unchanged** (safe by construction / out of scope).

No surface requires decomposing nested ACF structures, no schema/contract change, no broadening of supported mutation. **No STOP condition triggered.** Proceeding to Phase C (design).
