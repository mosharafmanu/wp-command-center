# PROGRAM-4.10 — Elementor Rollback Integrity · Final Report

> **Branch:** `program-4.10-elementor-rollback-integrity` (from `program-4.9-acf-rollback-integrity` @ `6fff16c`; carries P4C.0a hotfix + P4.7 keystone + P4.8 bulk delta + P4.9 ACF). **No merge / push / deploy.**
> Companion: [Forensic](ELEMENTOR-FORENSIC-REPORT.md) · [Risk](ELEMENTOR-RISK-ASSESSMENT.md) · [Design](ELEMENTOR-ROLLBACK-DESIGN.md) · [Validation](ELEMENTOR-VALIDATION-REPORT.md) · [Independent Audit](ELEMENTOR-INDEPENDENT-AUDIT.md).

## 1. Outcome
Elementor rollback is now **honest and safe**, treating `_elementor_data` as one **atomic whole-document JSON field** (no decomposition, no widget-level rollback):
- The 3 mutating ops (`elementor_update_text/image/button`, all via `edit_widget`) capture the **whole pre-edit `_elementor_data`** via `RollbackDelta` + new `ElementorDataAccessor`, stored in `PostMetaRollbackStore` (`_wpcc_elementor_rb_{id}` — O(1), no FIFO, not autoloaded, GC-with-page).
- `rollback()` is **drift-aware** (order-sensitive whole-document compare): match → restore prior whole JSON + clear Elementor cache; **drift → refuse + conflict** (`error:true`, `reversible:false`, not applied, retryable) — never clobbers a concurrent/reordered edit to any widget. Legacy option records still restore via the unchanged legacy path.

## 2. Phase A — forensic
Only `_elementor_data` is mutated; page settings, templates, post fields are **not** touched; `_elementor_css` is a regenerable cache (cleared, not rolled back). Each edit snapshotted the **whole** `_elementor_data` and restored it **unconditionally** (F-1 whole-blob clobber) in a FIFO-100 autoloaded option.

## 3. Phases B–C — risk + design
Single rollback surface (`_elementor_data`) classified as a complex nested document; widget-level rollback is **not** clearly safe under concurrent edits (STOP-class). Chose **whole-document atomic + drift guard** (refuse-on-drift) — the same shape as ACF P4.9's atomic value path — via `RollbackDelta` + `PostMetaRollbackStore`. No decomposition; safe-by-construction (only adds refusals; never clobbers; legacy untouched).

## 4. Phase D — implementation
`ElementorDataAccessor` (new): single `data` field → `_elementor_data`; raw read/write with `wp_slash` fidelity; normalized order-preserving whole-document drift compare with raw fallback. `ElementorRuntimeManager`: capture in `edit_widget` → store via keystone; `rollback()` postmeta-delta path (drift-aware) + unchanged legacy fallback; dead `store_rollback` removed. Reuses `RollbackDelta` + `PostMetaRollbackStore` unchanged.

## 5. Phases E–F — validation + independent audit
- **New Elementor suite: 34/0** — whole-doc fidelity, sibling-widget preservation (refuse-on-drift), same-field drift, out-of-order (no resurrection), repeated-rollback safety, legacy record, conflict-not-clean-success, malformed-JSON honest, missing-record honest, **widget-reorder structural drift**.
- **Existing Elementor step96: 26/0** (button-rollback now via the delta path, still correct).
- **Regression all green:** core 25, postmeta 30, ACF 47, ACF-step92 23, SEO 56, Settings 38, Media 41, Content 30, Comments 27, User 28, Woo 19, Bulk-delta 53, Bulk-fix 35, registry 18, capability 61, MCP 18, change-history 48 (standalone). **Net-new attributable failures: 0.**
- **Invariants:** 34 · 23 · 40 · 40 · 2.5.0 — held.
- **Independent audit: GO.** No GO-blocking defects across 13 vectors. OBS-1 (no reorder test) **addressed** (Ereorder added; 34/0). OBS-2 (hand-rolled conflict envelope) cosmetic, consistent with ACF P4.9 — left as-is.

## 6. Scope / STOP
- Files: `ElementorRuntimeManager.php` + new `ElementorDataAccessor.php` + new test. `RollbackDelta`/`PostMetaRollbackStore`/`ElementorRegistry` byte-unchanged.
- **No** JSON decomposition (atomic). **No** widget-level rollback. **No** new Elementor op/capability/field. **No** page-settings/template/post-field rollback (not mutated — out of scope, honest). **No** schema / DB_VERSION / operation-registry / capability / MCP / REST / UI / security change. **No STOP triggered** — JSON made safe via atomic whole-document handling; mutation scope unchanged.

## 7. GO / NO-GO
**GO** — `_elementor_data` rollback is drift-aware (order-sensitive), whole-document atomic, sibling-widget-safe, out-of-order-safe, honest on conflict/malformed/missing, legacy-compatible; invariants frozen; independent audit GO; attributable failures 0. **Committed on `program-4.10-elementor-rollback-integrity` only — no merge / push / deploy.**

## 8. Program note
With P4.10, the three highest-risk F-1 surfaces from the PROGRAM-4C risk matrix — **Bulk (P4.8), ACF (P4.9), Elementor (P4.10)** — are now closed on the `PostMetaRollbackStore` keystone (per-item / atomic-whole-field / whole-document, all drift-aware, all honest). Remaining 4C roadmap items (G2 plugin/theme honesty, Forms/Menu/Option/CPT/SiteBuilder/Widgets hardening, Woo orders) are independent of this surface.
