# PROGRAM-4C — Rollback Risk Matrix

> **Type:** risk ranking (no code). Report-only. Synthesizes the Phase-A surface audit into a single prioritized matrix.
> **Scoring:** Risk = **Corruption potential** × **Coverage gap** × **Likelihood of layered/concurrent edits** × **Blast radius**, each 1–5; *Severity* is the holistic verdict that drives ordering.

---

## 1. Master risk matrix (unmigrated surfaces)

| Rank | Surface | Failure family | Corruption | Coverage gap | Likelihood | Blast radius | Drift | **Severity** | Migration complexity |
|---|---|---|---|---|---|---|---|---|---|
| **1** | **Bulk** | **Active corruption + gap + F-1** | 5 (title clobber, status lost) | 5 (media/woo/acf no rollback) | 4 | 5 (N items/op) | 5 (none) | **CRITICAL** | 4 |
| **2** | **ACF** | F-1 corruption + gaps | 4 (serialized clobber; json_import dead) | 3 (option-page values, json_import) | 4 | 4 | 5 | **HIGH** | 4 |
| **3** | **Elementor** | F-1 corruption | 4 (whole-tree clobber) | 1 | 4 (incremental builder edits) | 3 (per page) | 5 | **HIGH** | 3.5 |
| **4** | **Woo variation_update / coupon_update** | Coverage gap (F-3) | 2 | 5 (no rollback at all) | 3 | 3 (price/discount) | 3 | **MED-HIGH** | 2–2.5 |
| **5** | **Plugin / Theme update (G2)** | Silent irreversibility + contract mismatch | 3 (no undo of a breaking update) | 5 (E, no capture) | 3 | 4 (site-wide) | n/a | **MED-HIGH** | 1 (honest) / 3 (artifact) |
| **6** | **Woo orders** | Drift + relational | 3 (status re-email; refund) | 2 | 3 | 3 | 5 (gateways/hooks) | **MED (defer)** | 3 (relational sub-design) |
| **7** | **OptionManager** | Drift-unaware + unbounded growth | 2 (serialized sub-field clobber) | 2 | 2 | 2 | 4 | **MED** | 2 |
| **8** | **Forms (update)** | Coverage gap | 2 (no-op rollback) | 4 (form_update/notification) | 2 | 2 | 3 | **LOW-MED** | 3 |
| **9** | **Menu (item_reorder)** | Coverage gap | 1 | 4 (reorder no rollback) | 2 | 2 | 2 | **LOW-MED** | 2.5 |
| **10** | **Widgets** | Drift / minor | 2 | 1 | 2 | 2 | 3 (admin race) | **LOW** | 2 |
| **11** | **CPT** | Full-blob, low coupling | 2 | 1 | 1 | 2 | 2 | **LOW** | 2 |
| **12** | **SiteBuilder** | Mostly correct | 2 | 1 | 2 | 2 | 2 | **LOW** | 2 |
| **—** | **Settings (residual)** | Edge gap | 1 (permalink rewrite_rules) | 2 | 2 | 2 | 2 | **LOW** | 1 |
| **—** | **Media bytes / Patch** | Pattern C correct | 1 | 1 | 2 | 3 | 1 | **OK (no action)** | — |

---

## 2. The two failure families (carried forward, re-quantified)

### 2.1 Corruption / F-1 (data loss on reversal) — fix by delta or refuse-on-drift
- **Bulk** (active corruption — *and* it is untested), **ACF** values/config, **Elementor** tree, **Woo product single-field actions** (lower, legacy A but minimal-field).
- These destroy or misrestore live data. Highest priority. Closed by field-scoped delta (decomposable) or whole-def+drift-guard (non-decomposable).

### 2.2 Coverage gap (no faithful reversal exists) — fix by capture or honest flag
- **Bulk** media/woo/acf (no rollback), **Woo variation_update/coupon_update** (F-3), **Forms update** (no-op), **Menu reorder** (no rollback), **ACF option-page values** (unsupported), **plugin/theme update** (E).
- These do not corrupt, but the Rollback guarantee is false. Closed by adding capture, or — where capture is infeasible — by `reversible:false` + visible notice (never silent).

### 2.3 Cross-cutting operational risks (affect several surfaces)
- **Shared-option FIFO eviction** (`wpcc_woo_rollbacks` 200-cap shared across product/variation/coupon/order; same shape in ACF/Elementor): a surfaced `rollback_id` can be silently evicted before use. → move hot surfaces to **per-entity postmeta storage**.
- **Uncapped options:** `wpcc_option_rollbacks` (none), `wpcc_cpt_rollbacks` (none) → unbounded growth. → add caps.
- **Untested rollback paths:** Bulk, Woo non-product, ACF rollback, Elementor layered, Menu reorder — gaps that *hid* the Bulk corruption. → mandatory drift/F-1 tests per migration.

---

## 3. Highest-risk unresolved area
**Bulk operations.** It is the only surface with an **active, live, untested corruption bug** (status rollback overwrites titles and fails to restore status) *combined with* the **largest blast radius** (N items per operation) *and* three sub-actions that are silently irreversible. It scores worst on every axis. It is also the surface whose redesign yields the most reusable infrastructure (`PostMetaRollbackStore`).

## 4. Highest-leverage next phase
Two complementary answers:
- **For immediate harm reduction:** the **Bulk correctness hotfix** (P4C.0a) — smallest change that removes live data corruption and silent irreversibility, plus the regression test that should have caught it.
- **For systemic leverage:** the **`PostMetaRollbackStore`** built during the Bulk delta redesign (P4.8) — a per-entity, schema-free, self-GCing store that simultaneously (a) closes Bulk F-1, (b) provides the storage that fixes the shared-option FIFO eviction risk for Woo/ACF/Elementor, and (c) reuses the single-entity accessors already proven in P4.1–P4.6. One store unlocks four surfaces.

## 5. Certification gate (when can "audited reversibility" be claimed platform-wide?)
F-1/Rollback can be marketed as platform-wide only when **all** hold:
1. Bulk corruption fixed **and** Bulk on per-item delta (drift-aware).
2. ACF values on delta + config on whole-def+drift-guard + json_import honest.
3. Elementor on field/widget delta (or whole-blob+drift-guard).
4. Woo F-3 gaps (variation_update, coupon_update) closed.
5. Plugin/theme update **honest** (Tier-1 visibility) at minimum.
6. Forms update + Menu reorder gaps closed (capture or honest flag).
7. Deferred-but-tracked: Woo orders (relational sub-design), A2-1 reaper (schema).
8. Every migrated surface carries an F-1 + drift test; no rollback path remains assertion-free.

Until then: claim "audited reversibility" **only for the migrated set** (SEO, Settings, Media-meta, Content, Comments, User, Woo products) + Pattern-C (Patch, Media bytes).
