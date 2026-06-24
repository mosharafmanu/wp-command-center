# PROGRAM-4 — Consolidation Report (Phases A–C)

> **Mode:** certification (verify/consolidate/integrate). No merge to main, no push, no deploy.
> **Verified directly from git/source.** Production `a41a9d7`.

---

## PHASE A — Independent Consolidation Audit

### A.1 Exact commit graph
```
main a41a9d7 (production)
 └─ P4.0 2234dcc
      ├─ P4.1 0788720 ┐
      ├─ P4.2 8982e6c ┤
      ├─ P4.3 dbc7c47 ┼─ octopus 6a8aad0 ─→ P4B 8550a4b
      ├─ P4.4 4ccf18b ┤
      └─ P4.5 6b5d0ef ┘
            P4B 8550a4b ─┬─ P4.6 c8fb602         [STRANDED]
                         └─ P4C.0a 5a57db4 ─→ P4.7 97e9ccd ─→ P4.8 81afaab ─→ P4.9 6fff16c ─→ P4.10 c23fc19 (HEAD)
```
Parent verification: P4.1–P4.5 parent = `2234dcc` (P4.0) ✓; P4B parent = octopus `6a8aad0` (folds P4.0–P4.5) ✓; **P4.6 parent = `8550a4b` (P4B)**; P4C.0a parent = `8550a4b`; P4.7→P4.10 linear off P4C.0a.

### A.2 Ancestry of HEAD (`c23fc19`)
| Phase | Commit | Ancestor of HEAD? |
|---|---|---|
| P4.0 | 2234dcc | ✓ |
| P4.1 | 0788720 | ✓ |
| P4.2 | 8982e6c | ✓ |
| P4.3 | dbc7c47 | ✓ |
| P4.4 | 4ccf18b | ✓ |
| P4.5 | 6b5d0ef | ✓ |
| P4B | 8550a4b | ✓ |
| **P4.6** | **c8fb602** | **✗ STRANDED** |
| P4C.0a | 5a57db4 | ✓ |
| P4.7 | 97e9ccd | ✓ |
| P4.8 | 81afaab | ✓ |
| P4.9 | 6fff16c | ✓ |
| P4.10 | c23fc19 | ✓ (HEAD) |

`merge-base(P4.6, HEAD) = 8550a4b` (= P4B), confirming P4.6 forked from P4B in parallel with P4C.0a and was never merged forward.

### A.3 Stranded work
- **P4.6 (Woo Products)** — `WooProductAccessor.php`, the field-scoped `product_update` delta, and `test-woo-product-rollback-delta.sh` exist only on `c8fb602`. At HEAD, `WooCommerceRuntimeManager::product_update` still uses `snapshot_product` (F-1).

### A.4 Duplicate / divergent work
- **ACF accessors:** `BulkAcfAccessor` (P4.8) and `AcfValueAccessor` (P4.9) — both in HEAD; the bulk one was not back-ported with P4.9's key→name + raw-read fixes (**AR-MED-1**).
- **Woo accessors:** `BulkWooAccessor` (P4.8, in HEAD; bulk price/status) vs `WooProductAccessor` (P4.6, stranded; full product field set) — related but distinct functions; **not a merge conflict**.

### A.5 Missing integrations
- **Exactly one: P4.6.** Everything else is already in the HEAD lineage.

### A.6 Certification blockers (re-verified at HEAD)
| ID | Blocker | Verified state |
|---|---|---|
| **BLK-1** | P4.6 Woo Products not integrated | CONFIRMED — stranded; `product_update` = `snapshot_product` (F-1) |
| **BLK-2** | Acceptance gate not completed | PARTIAL-CLOSABLE — local serial battery runnable here; **prod token-gated functional verify is deploy-coupled** (out of this program's no-deploy rule) |
| **BLK-3** | Plugin/theme update false reversibility | CONFIRMED — `plugin_update`/`theme_update` return no `rollback_id`/`reversible`; honest fix = additive `reversible:false` flag |
| **AR-MED-1** | `BulkAcfAccessor` residual defect | CONFIRMED — existence via `metadata_exists($field_key)` (no key→name); `get_field()` formatted (no `false`) |

---

## PHASE B — Consolidation Design

### B.1 Certification branch
`program-4-certification` from **HEAD `c23fc19`** (already contains P4.0–P4.5, P4B, P4C.0a, P4.7–P4.10).

### B.2 Merge order
Single merge: **merge P4.6 (`c8fb602`) into the certification branch.** No octopus needed — only P4.6 is missing.

### B.3 Conflict risk — LOW (verified)
- `WooCommerceRuntimeManager.php` was **not touched** in the HEAD lineage (`diff 8550a4b..HEAD` = empty). P4.6 modified it from the same `8550a4b` base → three-way merge takes P4.6's delta version cleanly.
- `WooProductAccessor.php` + `test-woo-product-rollback-delta.sh` are new files → no conflict.
- `merge-tree` showed **no conflict markers**. Doc files are additive (P4.6's `PROGRAM-4.6-*.md` are new).

### B.4 Remediation (Phase D) — bounded
- **AR-MED-1:** back-port into `BulkAcfAccessor` — resolve field key→name for existence; read raw `get_field(...,false)`. No refactor, no de-dup.
- **BLK-3:** add `reversible => false` (+ short note) to `plugin_update`/`theme_update` responses. Additive only — **no** registry/capability/MCP/REST/schema change.

### B.5 Validation requirements (Phase E)
- Full Program-4 battery (all 10 migrated suites incl. Woo product delta) + core + keystone.
- Regression: registry/capability/MCP guards + change-history (standalone) + existing runtime suites (woo, acf, elementor, bulk).
- Invariants: OPERATION_MAP 34 · capabilities 23 · catalogue 40 · MCP 40 · DB_VERSION 2.5.0.
- **Net-new attributable failures = 0.**

### B.6 Acceptance criteria
1. P4.6 present: `WooProductAccessor.php` exists; `product_update` uses `RollbackDelta`; `snapshot_product` gone; woo product delta suite 47/0.
2. Keystone, Bulk delta, ACF, Elementor all present and green.
3. `BulkAcfAccessor` fixed (+ test proving key-selector existence).
4. `plugin_update`/`theme_update` return `reversible:false`.
5. Invariants held; net-new attributable 0.
6. No STOP condition triggered.

---

## PHASE C — Consolidation Implementation (executed)

**Branch:** `program-4-certification` (from `c23fc19`). **No merge to main / push / deploy.**

| Step | Result |
|---|---|
| Merge P4.6 (`c8fb602`) | **clean — 0 conflicts** (`ort` strategy). Merge commit `89852d5`. |
| Remediation commit | `af6500d` (BulkAcfAccessor key→name + raw read; plugin/theme `reversible:false`). |

**Consolidated contents verified at HEAD:**
- Ancestry: **all** phases now ancestors — P4.0 `2234dcc`, P4.1–P4.5, P4B `8550a4b`, **P4.6 `c8fb602` ✓ (no longer stranded)**, P4C.0a `5a57db4`, P4.7 `97e9ccd`, P4.8 `81afaab`, P4.9 `6fff16c`, P4.10 `c23fc19`.
- **Woo F-1 closed at the tip:** `snapshot_product` = **0 refs**; `product_update` uses `RollbackDelta` + `WooProductAccessor`; woo product delta suite **47/0**.
- Present: `WooProductAccessor`, `PostMetaRollbackStore`, `BulkWooAccessor`, `BulkAcfAccessor` (fixed), `AcfValueAccessor`, `ElementorDataAccessor`. All `php -l` clean.
- No uncommitted code; `main` unchanged (`a41a9d7`).

**Acceptance criteria (B.6):** 1 ✓ (P4.6 present, F-1 closed) · 2 ✓ (keystone/Bulk/ACF/Elementor present+green) · 3 ✓ (BulkAcfAccessor fixed + D11b key-selector test) · 4 ✓ (plugin/theme `reversible:false`) · 5 ✓ (invariants held, net-new 0) · 6 ✓ (no STOP triggered). **Consolidation: COMPLETE.**
