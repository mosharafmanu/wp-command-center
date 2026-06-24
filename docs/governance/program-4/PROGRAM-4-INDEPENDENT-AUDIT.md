# PROGRAM-4 — Independent Adversarial Audit of the Consolidated Branch (Phase F)

> **Branch audited:** `program-4-certification`. **Auditor:** fresh agent, read-only, verifying independently (prior reports not trusted). **Post-audit state:** the one blocking finding (D1) has been resolved by committing the validated remediations (`af6500d`); re-stated below with that resolution.

---

## VERDICT: **GO** for "merge & deploy-review ready" (after the D1 commit, now done).

The auditor's verdict was **"NO-GO as-committed / GO after one commit"** — the sole blocker (D1) was that the Phase-D remediations existed only in the working tree, not in HEAD. They have since been committed (`af6500d`), which the audit explicitly stated would clear the blocker. All other questions passed.

## The 7 deployment questions

| # | Question | Verdict | Evidence |
|---|---|---|---|
| 1 | Any Program-4 work still stranded? | **PASS** | All 8 tips are ancestors of HEAD; clean 2-parent merge `parents = c23fc19 c8fb602`. P4.6 no longer stranded. |
| 2 | Any migrated surface still F-1 vulnerable? | **PASS** | `snapshot_product` = 0 hits in `includes/`; Woo `product_update` uses `RollbackDelta::capture`/`product_touched_fields` + restores via `RollbackDelta::restore(new WooProductAccessor)` (WC public CRUD only); all 10 restore only touched fields. *Scope note:* ACF **definition** ops remain legacy full-object behind a fingerprint drift-guard (pre-existing boundary, not a regression). |
| 3 | Can rollback history lie? | **PASS** | "mark applied" gated on `complete` only in every migrated runtime; conflict/partial return `error:true` (`RollbackDelta::result` or equivalent hand-rolled envelope). No success-while-restoring-nothing path. |
| 4 | Can rollback silently corrupt data? | **PASS (1 accepted caveat)** | Drift-aware whole-value compares prevent clobber; ACF/Woo write via their APIs. Caveat by design: Elementor `key_set` writes raw `_elementor_data` (+`wp_slash`) with runtime `clear_cache()` on complete — symmetric with its own save; documented. |
| 5 | Can rollback silently disappear? | **PASS (honest enumeration)** | **No-FIFO postmeta (eviction-safe):** SEO, Bulk, ACF-value, Elementor (`_wpcc_*_rb_`); Content (keyed, uncapped). **FIFO-capped autoloaded options (rollback_id can be evicted):** Settings(200), Media(100), Comments(100), User(100), ACF-definition(200), Woo(200). Pre-existing behavior, not introduced here. → tracked as MEDIUM debt, not a blocker. |
| 6 | Plugin/theme update honestly represented? | **PASS** | `plugin_update`/`theme_update` return `reversible:false`+note, capture no rollback (no false promise). Additive fields only — no registry/capability/catalogue/MCP/DB_VERSION change. |
| 7 | Certification blockers closed? | **PASS** | BLK-1 closed (P4.6 integrated, Woo F-1 closed at tip). BLK-3 + AR-MED-1 closed (committed `af6500d`). BLK-2's **prod token-gated functional verify is deploy-coupled** → explicitly out of this program's no-deploy rule; not failed, deferred to the deploy decision. |

## Defects
- **D1 — BLOCKER (release-process) → RESOLVED.** Remediations were uncommitted at audit time; committed in `af6500d`. HEAD now contains the BulkAcfAccessor name-resolution (verified 3 lines) and plugin/theme `reversible:false`. No uncommitted code remains.
- **D2 — LOW (pre-existing, P4.3) → REPORTED, NOT FIXED (bounded scope).** `ContentManager.php:281` undefined `$before` in the `content.update` audit array → PHP notice + `old_status=null` per content_update. Apply-path audit only; does NOT affect Content rollback (delta 30/0). Recommended one-line follow-up; out of the two-fix certification bound.

## Independent attack results (held up)
- P4.6 merge: no drop/double-apply; HEAD Woo == P4.6 tip; `snapshot_product` absent; legacy `restore_product` reachable only for pre-migration `before_state` records.
- `BulkAcfAccessor` now mirrors `AcfValueAccessor` (name resolution + raw read); consistent.
- BLK-3 additive fields: no contract break (no registry/schema/MCP/catalogue touch).
- `php -l` clean on all changed files; invariants 34/23/40/40/2.5.0 held.

## Certified vs uncertified (auditor's independent classification — matches the Certification Report)
- **Certified F-1-closed:** SEO, Settings, Media-metadata, Content, Comments, Users, Woo Products, Bulk, Elementor, ACF **value_update**.
- **Uncertified / out of F-1 scope (by design, not regressions):** ACF definition ops (fingerprint-guarded), Woo orders/variation/coupon updates, CPT/Forms/Menu/Widgets/SiteBuilder/OptionManager, plugin/theme update (now honestly `reversible:false`), and non-field reversals.

**Audit conclusion:** the consolidated branch is internally consistent, free of F-1 corruption on the certified surfaces, honest on history and on irreversible surfaces, and invariant-preserving. **GO** for merge & deploy-review (deploy-coupled prod verify remains the final gate, outside this program).
