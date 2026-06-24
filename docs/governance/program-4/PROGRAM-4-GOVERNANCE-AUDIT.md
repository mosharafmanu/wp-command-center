# PROGRAM-4 — Governance Audit: The Four Guarantees (Phase C)

> **Type:** audit-only (no code changes). Verified at HEAD `c23fc19`; production reality = `a41a9d7`.

---

## 1. Approval
- **Status:** **INTACT** (unchanged by Program-4).
- **Evidence:** No phase altered `OPERATION_MAP` (34), `ALL_CAPABILITIES` (23), the operation registry, or `requires_approval` logic; `capability-runtime` 61/0 and `operations-registry` 18/0 at HEAD. All rollback work was additive within runtimes.
- **Gaps / severity:** none. (Note: plugin/theme *update* are approved as high-risk but are not actually reversible — that is a **Rollback** honesty gap, not an Approval gap; see Rollback below.)

## 2. Rollback
- **Status:** **MATERIALLY IMPROVED, NOT PLATFORM-TRUE.**
- **Evidence (engineered + validated, branch-only):** 9 surfaces are drift-aware, sibling-safe, out-of-order-safe, existence-faithful, honest on partial/conflict, and legacy-compatible — SEO 56/0, Settings 38/0, Media-meta 41/0, Content 30/0, Comments 27/0, User 28/0, Bulk 53/0, ACF 47/0, Elementor 34/0; core 25/0; keystone 30/0 (all re-verified at HEAD).
- **Gaps / severity:**
  - **HIGH — not deployed.** Production `a41a9d7` contains **none** of this work. On the live product the F-1 full-object rollbacks still stand everywhere. The guarantee is *built*, not *shipped*.
  - **HIGH — Woo products F-1 open at the tip.** P4.6's fix is stranded; HEAD's `product_update` is still a full-object snapshot.
  - **MED-HIGH — coverage gaps unaddressed:** Woo `variation_update`/`coupon_update` capture **no** rollback (verified 0 `store_rollback`); plugin/theme **update** capture nothing yet are approved as reversible (false contract); Forms `form_update` no-op; Menu `item_reorder` no rollback.
  - **MED — ~7 surfaces not drift-aware:** Woo orders, CPT, Forms, Menu, Widgets, SiteBuilder, OptionManager still unconditional/inverse restores.

## 3. Audit
- **Status:** **INTACT and IMPROVED.**
- **Evidence:** Migrated runtimes emit honest audit events with real outcome (`status`, `restored`, `skipped`/conflict) rather than unconditional "success"; the Bulk corruption fix (P4C.0a) specifically replaced a dishonest success path; `change-history-rollback` 48/0 at HEAD (standalone). Conflict/partial now surface `error:true` so `OperationExecutor::rollback`'s success boolean is truthful.
- **Gaps / severity:** **LOW** — Bulk/ACF/Elementor reversals emit `AuditLog` events but do not write `change_log` rows (no schema change made — acceptable); legacy surfaces still return unconditional success on rollback (rolls up under Rollback gaps).

## 4. Capability Scoping
- **Status:** **INTACT.**
- **Evidence:** No capability added/removed/renamed; `ALL_CAPABILITIES`=23 unchanged; bulk/ACF/Elementor reversals run within the existing operation's capability; `capability-runtime` 61/0. New postmeta keys and response fields are additive (no new REST/MCP surface).
- **Gaps / severity:** none.

---

## 5. Can WP Command Center honestly claim "audited reversibility" today?

**NO — not as a shipped, platform-wide guarantee.** Precisely why:

1. **Nothing is deployed.** Production is `a41a9d7`; **zero** Program-4 commits are live. Today the product's actual behavior is the pre-Program-4 full-object/F-1 rollback on every surface. A reversibility claim about the live product would be **false**.
2. **No consolidated artifact exists.** The completed work is spread across two non-converging lineages (P4.6 vs the P4C.0a→P4.10 chain). There is no branch that contains all of it; the tip is missing Woo products.
3. **Not platform-wide even if consolidated + deployed.** ~10 mutation surfaces (Woo orders/variations/coupons, Forms, Menu, CPT, Widgets, SiteBuilder, OptionManager, Plugin/Theme update) remain non-drift-aware or irreversible.
4. **Phase-3 SEO acceptance gate still open.** Per `SESSION-HANDOFF-PHASE-3`, the SEO delta fix was deployed but the **serial T2 + Stage-A + prod token-gated functional verify** were never closed; that gate was the precedent acceptance bar and remains outstanding.

## 6. What CAN be claimed honestly (today)
> "Field-scoped, drift-aware, audited reversibility has been **engineered and validated (branch-only, not yet deployed)** for 9 major mutation surfaces — SEO, Settings, Media metadata, Content, Comments, Users, Bulk, ACF, and Elementor — plus WooCommerce products on a separate validated branch. It is **not yet consolidated, deployed, or platform-wide.**"

## 7. What remains for an honest platform-wide claim
1. **Consolidate** P4.6 + the P4.10 lineage into one branch; re-validate.
2. **Deploy** the consolidated branch (Rule-7 decision) and run the outstanding **acceptance gate** (serial T2 + prod functional verify).
3. **Close coverage gaps**: Woo variation/coupon F-3, plugin/theme update honesty (reversible:false at minimum), Forms/Menu gaps.
4. **Extend drift-awareness** to the remaining legacy surfaces (Woo orders, CPT, Widgets, SiteBuilder, OptionManager) OR scope the claim explicitly to the certified set and mark the rest honestly.
