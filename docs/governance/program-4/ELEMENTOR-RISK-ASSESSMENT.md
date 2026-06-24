# PROGRAM-4.10 — Elementor Risk Assessment (Phase B)

> **Type:** risk classification (no code). Report-only. Predicated on the Phase-A forensic.

---

## 1. Surface classification

| # | Surface | Mutated by runtime? | Corruption risk (today) | Drift risk | Sibling-overwrite risk | Rollback feasibility | Recommended model |
|---|---|---|---|---|---|---|---|
| **1** | **`_elementor_data` JSON** | **YES** (sole write target) | MEDIUM — whole-document restore wipes a concurrently/later-edited **different** widget on the same page | **HIGH** (unconditional restore) | **intra-document** (other widgets in the same JSON) | **HIGH** (atomic) | **whole-document value + drift guard (refuse-on-drift), `PostMetaRollbackStore`** |
| **2** | **`_elementor_page_settings`** | **NO** | n/a | n/a | n/a | n/a (nothing to roll back) | **untouched / out of scope (honest)** |
| **3** | **`_elementor_css`** | only **deleted** (cache-bust) | n/a (regenerable) | n/a | n/a | n/a | **not a rollback target** (Elementor regenerates) |
| **4** | **template data** | **NO** | n/a | n/a | n/a | n/a | **untouched / out of scope** |
| **5** | **post title/content/status** | **NO** | n/a | n/a | n/a | n/a | **untouched / out of scope** |
| **6** | **widget-level edits** | edits mutate one widget *within* the JSON | — | — | other widgets | **NOT clearly safe** to roll back per-widget under concurrent edits | **whole-document atomic — do NOT decompose** |
| **7** | **clone / import** | **NO** (no such ops) | n/a | n/a | n/a | n/a | **N/A** |
| **8** | **unsupported / unsafe** | — | — | — | — | — | malformed/missing → **honest reporting** |

## 2. Why whole-document, not widget-level (the safety argument)
`_elementor_data` is a nested ordered tree (sections → columns → widgets). A faithful **per-widget** rollback would have to splice the prior widget subtree back into a tree that may have changed elsewhere (other widgets added/removed/reordered) — i.e. re-implement Elementor's element addressing and merge semantics. That is explicitly out of scope ("redesign Elementor JSON structure", "implement granular widget-level rollback unless already clearly safe") and a STOP condition ("Elementor JSON behavior cannot be made safe without owner decision").

The safe resolution mirrors ACF P4.9 nested values: treat the **entire `_elementor_data` document as one atomic unit**. Capture the whole prior JSON; on rollback compare the **whole** current document to the recorded apply-time JSON (normalized). Match → restore the whole prior document. Any difference (a later edit to **any** widget, a reorder, an add/remove) → **refuse + report conflict** (never clobber). This closes F-1 (no sibling-widget clobber, drift-aware, honest) **without any JSON decomposition**.

## 3. Drift-guard safety argument
- **Refuse-on-drift**: only ever *adds* a refusal; never changes a clean (no-external-change) restore; never clobbers.
- **New-records-only**: legacy `wpcc_elementor_rollbacks` option records (no v2 delta) restore exactly as today → zero behavior change for existing data/tests.
- **Stable compare**: both the recorded apply-time `after` and the live `current` are read via the same `get_post_meta($id,'_elementor_data')` path, and compared **normalized** (json_decode→re-encode, order-preserving) so encoding-only noise does not false-drift while any structural/content/order change does. Decode failure → raw-string compare (still safe).
- **Worst case** (false-drift): a safe **false refusal** (`reversible:false`) — never corruption.

## 4. False-clean-success elimination
- Today rollback always returns `restored:true`. A drift conflict must return an **error envelope** (`error:true`) so `OperationExecutor::rollback`'s `success = empty(result['error'])` is truthful, matching `RollbackDelta::result()` and the ACF P4.9 convention.
- A malformed/missing record must report honestly (not-found / unsupported), never a phantom success.

## 5. Feasibility verdict
A **safe, bounded** design exists and is the same shape as ACF P4.9:
- **Surface 1 (`_elementor_data`):** whole-document, drift-aware, atomic delta via `RollbackDelta` + a new `ElementorDataAccessor` + `PostMetaRollbackStore`. **IMPLEMENT.**
- **Surfaces 2–5, 7:** **not mutated by the runtime → nothing to roll back** (honest, untouched).
- **Surface 6:** whole-document atomic — **no decomposition** (do not implement widget-level).
- **Surface 8:** honest conflict / not-found / malformed reporting.

No surface requires decomposing Elementor JSON, no schema/contract change, no broadening of supported mutation. **No STOP condition triggered.** Proceeding to Phase C (design).
