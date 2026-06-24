# PROGRAM-4 — Certification Report (Phases D–E)

> **Branch:** `program-4-certification` @ `af6500d` (consolidation merge `89852d5` + remediation `af6500d`). **No merge to main / push / deploy.** Production `a41a9d7`.

---

## PHASE D — Certification Remediation (bounded — exactly two fixes)

### AR-MED-1 — `BulkAcfAccessor` residual defect (FIXED)
Back-ported P4.9's two fixes (no refactor, no de-dup):
- Constructor resolves the field **key→name** (`acf_get_field(selector)['name']`); `key_exists` checks `metadata_exists($this->name)`. A field-**key** selector no longer reads existence=false and clears-instead-of-restores.
- `read_field` reads **raw** (`get_field(...,false)`) — symmetric with `update_field` for formatted-return fields.
- Proof: `test-bulk-delta-rollback` **D11b** (real ACF field, KEY selector, prior value `PRIOR` → update `NEWV` → rollback restores **`PRIOR`**, not cleared). Suite **55/0**.

### BLK-3 — Plugin/theme update honesty (FIXED)
`plugin_update` and `theme_update` responses now carry `reversible:false` + `reversible_note`. Additive response fields only — **no** operation-registry / capability / MCP / REST / schema change. The false reversibility contract is closed without implying a rollback that does not exist.

> **Scope discipline:** no other change made. A separately-identified pre-existing LOW defect (D2 below) was **deliberately not fixed** (out of the two-item bound).

---

## PHASE E — Certification Validation (full battery, consolidated+remediated branch)

### Rollback core + migrated surfaces
| Suite | Tally |
|---|---|
| rollback-delta-core | 25/0 |
| PostMetaRollbackStore | 30/0 |
| SEO | 56/0 |
| Settings | 38/0 |
| Media metadata | 41/0 |
| Content | 30/0 |
| Comments | 27/0 |
| Users | 28/0 |
| **Woo Products (now integrated)** | **47/0** |
| Bulk delta (+ D11b AR-MED-1 regression) | **55/0** |
| Bulk rollback-fix | 35/0 |
| ACF | 47/0 |
| Elementor | 34/0 |

### Runtime regression + guards
| Suite | Tally |
|---|---|
| woocommerce-runtime | 117/0 |
| woocommerce-product-step93 | 19/0 |
| elementor-step96 | 26/0 |
| acf-runtime-step92 | 23/0 |
| bulk-runtime | 41/0 |
| operations-registry (catalogue 40) | 18/0 |
| capability-runtime (caps 23) | 61/0 |
| mcp-error-surface (MCP 40) | 18/0 |
| change-history-rollback (standalone) | 48/0 |

### Invariants (verified at HEAD)
OPERATION_MAP **34** · capabilities **23** · catalogue **40** · MCP tools **40** · DB_VERSION **2.5.0** — **held**.

### Net-new attributable failures: **0.**

---

## Certified surfaces (F-1 closed: field-scoped / atomic-whole, drift-aware, honest history, legacy-compatible)
1. **SEO** · 2. **Settings** · 3. **Media metadata** · 4. **Content** · 5. **Comments** · 6. **Users** · 7. **Woo Products** · 8. **Bulk** · 9. **ACF (value_update)** · 10. **Elementor**.
Plus **Pattern C** (byte snapshot+verify): Patch/File, Media bytes, Media Enhancement.

## Surfaces NOT certified (honest scope boundary — not regressions)
- **ACF definition operations** (group/field/location/layout config): whole-definition + fingerprint **drift-guard** (refuse-on-drift) — safe and honest, but **not** field-scoped F-1 closure.
- **Woo orders / variation_update / coupon_update**: not migrated (orders relational; variation/coupon updates still without rollback).
- **CPT, Forms, Menu, Widgets, SiteBuilder, OptionManager**: legacy unconditional/inverse restores, not drift-aware.
- **Plugin/Theme update**: now **honestly `reversible:false`** (irreversible by nature; not certified as reversible).
- **Non-field reversals** (media upload/replace/delete/featured, content delete, comment trash/delete, user create/role/suspend): inverse-action or byte-snapshot; out of F-1 field-delta scope by design.

## Known non-blocking finding (reported, not fixed — bounded scope)
- **D2 (LOW, audit-integrity, pre-existing P4.3):** `ContentManager.php:281` references undefined `$before` in the `content.update` **audit** array (`'old_status' => $before['status']`) — the P4.3 delta migration removed the snapshot var but left this audit reference. Effect: a PHP undefined-variable notice + `old_status` logged null on each content_update. **Apply-path audit only — does NOT affect Content rollback correctness** (content delta 30/0). Recommended one-line follow-up (capture prior status for the audit, or drop the field); **out of this program's two-fix bound.**
