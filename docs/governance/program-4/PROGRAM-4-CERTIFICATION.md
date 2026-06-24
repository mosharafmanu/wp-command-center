# PROGRAM-4 — Certification Readiness (Phase D)

> **Type:** audit-only (no code changes). Verified at HEAD `c23fc19`; production `a41a9d7`.
> **"Certification" = an honest, shippable claim of audited reversibility for a defined surface set.**

---

## 1. Certification BLOCKERS (must clear before any reversibility certification)

| ID | Blocker | Evidence | Effort | Depends on |
|---|---|---|---|---|
| **BLK-1** | **No consolidated branch.** P4.6 (Woo Products) is not in the P4.10 lineage; the tip is missing the Woo product delta (still F-1). The completed program cannot be shipped because it does not exist as one artifact. | `git merge-base --is-ancestor program-4.6 HEAD` = false; `WooProductAccessor.php` absent at HEAD; `product_update` uses `snapshot_product`. | **S** (integration merge of P4.6 + P4.10 lineage; disjoint files — P4.6 touched `WooCommerceRuntimeManager`/`WooProductAccessor`, the P4.10 chain did not — likely conflict-free) + full re-validation | — |
| **BLK-2** | **Nothing deployed + acceptance gate not run.** Production has zero Program-4 commits; the Phase-3 precedent gate (**serial T2 + Stage-A + prod token-gated functional verify**) is still open (per SESSION-HANDOFF-PHASE-3). | production = `a41a9d7`; handoff §5 outstanding. | **M** (serial T2 run + prod functional verify + Rule-7 deploy decision) | BLK-1 |
| **BLK-3** | **Honesty of the irreversible surfaces.** Plugin/theme **update** are approved as high-risk (implying reversibility) but capture nothing → a **false reversibility contract** ships if deployed as-is. At minimum they must return `reversible:false`. | `PluginManager`/`ThemeManager` update paths have no `store_rollback`; `wpcc_plugin_rollbacks`/`wpcc_theme_rollbacks` only cover activate/deactivate/delete. | **S** (visibility-only flag) | — |

> BLK-1/BLK-2 block a claim for the **certified set** (the 9 + Woo products). BLK-3 blocks a claim that the platform is honest about what it *cannot* reverse.

## 2. HIGH (cert-scoping, not strict blockers if scope is stated)
- **H-1 — Woo F-3 irreversibility:** `variation_update`/`coupon_update` capture no rollback (verified 0 `store_rollback`). Price/discount edits are silently irreversible. Either fix or exclude-and-flag.
- **H-2 — ~7 legacy surfaces non-drift-aware:** Woo orders, CPT, Forms, Menu, Widgets, SiteBuilder, OptionManager. F-1/coverage exposure persists. Must be **out of the certified scope and honestly marked**, or migrated.

## 3. MEDIUM (debt — should be tracked, not necessarily pre-cert)
- **M-1 — Storage-tier inconsistency:** Settings/Media-meta/Comments/User/Content are drift-aware but still on FIFO-capped autoloaded options (eviction can silently drop a `rollback_id`). Migrate to `PostMetaRollbackStore` for parity with Bulk/ACF/Elementor/SEO.
- **M-2 — Accessor duplication (ACF/Woo):** `BulkAcfAccessor` vs `AcfValueAccessor`; `BulkWooAccessor` vs `WooProductAccessor` — behavioral-drift risk.
- **M-3 — OptionManager/CPT uncapped option growth.**
- **M-4 — Woo orders relational rollback** (status-rollback re-emails; needs its own sub-design) — explicitly deferred by the program.

## 4. LOW / Non-blocking debt
- **L-1 — Conflict-envelope inconsistency** (ACF/Elementor hand-rolled vs `RollbackDelta::result`).
- **L-2 — SEO storage duplicates `PostMetaRollbackStore`** (equivalent; unify opportunistically).
- **L-3 — Settings permalink `rewrite_rules` not captured.**
- **L-4 — "mark-applied-only-on-complete" is a per-runtime convention**, not a core guardrail.

## 5. Future enhancements / nice-to-have
- Unify all post-bound migrated runtimes onto `PostMetaRollbackStore` (closes M-1, L-2).
- Consolidate ACF/Woo accessors (closes M-2).
- True per-row nested-ACF / per-widget Elementor rollback (explicitly out — atomic handling is the safe ceiling without an owner decision).
- Plugin-update artifact capture (true reversibility beyond the BLK-3 honesty flag).
- A core-enforced terminality guard.

## 6. Can Program-4 be considered complete?

**Not as "platform-wide audited reversibility." Yes as "F-1 closed on the major mutation surfaces" — pending consolidation + deploy.**

- **Achieved:** the program's central goal (eliminate F-1 full-object over-reach on the highest-risk surfaces) is met for **9 surfaces + Woo products (on-branch)**, all drift-aware, honest, validated, invariant-frozen, schema-free. The Bulk active-corruption defect (P4C.0a) was found and fixed. The keystone (`PostMetaRollbackStore`) and core (`RollbackDelta`) are sound.
- **Not complete:** (a) **not consolidated** (BLK-1), (b) **not deployed / not acceptance-gated** (BLK-2), (c) the platform still has ~10 non-certified surfaces + 2 false-reversibility surfaces (BLK-3, H-2).

## 7. Dependency chain to certification
```
BLK-1 Consolidate P4.6 + P4.10 lineage  (one branch, conflict-check, re-validate full battery)
   └─► BLK-2 Acceptance gate (serial T2 + prod functional verify) → Rule-7 deploy
BLK-3 Plugin/Theme update reversible:false (independent; cheap)
   ─► then: certify the defined set {9 + Woo products + Pattern-C} as "audited reversibility";
            mark all other surfaces honestly as not-yet-covered.
H-1/H-2/M-* → a follow-on program (do NOT expand Program-4 scope here).
```

## 8. Certification verdict
**NOT YET CERTIFIABLE.** Three blockers (consolidation, deploy+gate, irreversible-honesty). All are **small-to-medium effort** and **none require new architecture** — they are integration, validation, and one visibility flag. Once BLK-1/2/3 clear, a **scoped** certification (the 9 surfaces + Woo products + Pattern-C) is honest and defensible; a platform-wide claim is not (H-2 surfaces remain).
