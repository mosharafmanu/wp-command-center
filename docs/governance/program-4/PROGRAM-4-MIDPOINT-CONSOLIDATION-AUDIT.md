# PROGRAM-4 — Midpoint Consolidation Audit

> **Date:** 2026-06-23 · **Posture:** adversarial, read-only. Verified against the actual branch commits + live test runs on each branch and on a **synthetic merged stack** assembled in the working tree (then discarded — **nothing committed/pushed/deployed; no integration branch created**).
> **Scope audited:** `main a41a9d7` → P4.0 `2234dcc` → siblings P4.1 `0788720` (Settings), P4.2 `8982e6c` (Media-meta), P4.3 `dbc7c47` (Content), P4.4 `4ccf18b` (Comments), P4.5 `6b5d0ef` (User).

---

## 1. Per-branch verification — ALL **PASS**

| Branch | Base = `2234dcc`? | Files changed (code) | Invariant/forbidden file touched? | Other-runtime leak? | Focused test |
|---|---|---|---|---|---|
| P4.1 Settings | ✅ | `SettingsRuntimeManager` + `OptionAccessor` | none | none | **35/0** |
| P4.2 Media-meta | ✅ | `MediaRuntimeManager` + `MediaFieldAccessor` | none | none | **38/0** |
| P4.3 Content | ✅ | `ContentManager` + `ContentFieldAccessor` | none | none | **27/0** |
| P4.4 Comments | ✅ | `CommentsRuntimeManager` + `CommentFieldAccessor` | none | none | **24/0** |
| P4.5 User | ✅ | `UserManager` + `UserFieldAccessor` | none | none | **25/0** |

- **Base:** every branch's parent is exactly P4.0 `2234dcc` (`git rev-parse <branch>^`).
- **Scope:** each branch's `git diff 2234dcc` touches **only** its one runtime + its one accessor (+ test/docs). No sibling-runtime leakage.
- **Invariants 34/23/40/40/2.5.0:** **trivially preserved** — no branch touches `CapabilityRegistry`, `OperationRegistry`, `Schema.php`, `McpServerRuntime`, or REST. Confirmed live per branch (`operations-registry` 18/0, `capability-runtime` 61/0, `mcp-error-surface` 18/0) and on the merged stack.
- **Rollback delta behaviour preserved:** each focused suite exercises empty/value/empty-but-existing fidelity, sibling preservation, same-field drift→conflict, out-of-order (no resurrection), legacy compatibility, repeated-rollback idempotency, partial/conflict≠clean-success. All green.

**Verdict: 5/5 branches PASS.**

---

## 2. Cross-phase consistency

| Dimension | Finding | Consistent? |
|---|---|---|
| **FieldAccessor pattern** | All 5 implement the same 7-method interface (`backing_keys/read_field/key_exists/key_get/key_set/key_delete/equals`); all use scalar-string `equals` (the P4.0 `SeoFieldAccessor` robots set-compare is the one documented override). Column vs meta vs option vs comment/user dispatch is encapsulated inside each accessor. | **YES** |
| **v2 record format** | All 5 store `{ id, <entity_id>, action, version:2, fields:{ field:{ after, keys:{ key:{ existed, prior } } } }, rollback_applied, created_at, session_id, task_id }`. Shape identical; only the entity-id **key name** (post_id/media_id/content_id/comment_id/user_id) and the backing **option** differ. | **YES (shape)** |
| **Drift behaviour** | All 5 route restore through the single `RollbackDelta::restore` core (drift = `!equals(current, after)` → skip + conflict). No re-implementation. | **YES** |
| **Legacy behaviour** | All 5 branch on `isset($record['fields'])`: v2 → core delta; else → the runtime's original full-object/legacy restore, unchanged. Forward-only, no destructive migration. | **YES** |
| **Partial/conflict** | All emit `wpcc_rollback_conflict`; all **except Comments** emit `wpcc_rollback_partial`. Comments is single-field (status) so `partial` is structurally impossible → conflict-only. | **YES (1 documented variation)** |
| **Audit / change-history** | Each emits a rollback audit event with `status/restored_fields/skipped_fields`; change-history dispatch (`OperationExecutor`, unchanged on all branches) routes via the public `rollback()` method or `ACTION_ROLLBACKS`. **Event names vary** (`seo.restored`, `settings.restored`, `media.rollback.applied`, `content.rollback`, `comment.rollback.applied`, `user.rollback.applied`). | **Shape YES; event-name NO (minor)** |

**Overall: strongly consistent.** Two minor variations (Comments omits `partial`; audit event names differ) — both benign.

---

## 3. Duplication & abstraction issues

| ID | Issue | Severity | Recommendation |
|---|---|---|---|
| **D1 — record builder** | The v2 record construction (`fields[f] = {after, keys: prior[f].keys}` loop + the record envelope) is hand-rolled in all 5 `store_*_delta`. ~12 near-identical lines × 5. | Medium (maintainability) | Extract `RollbackDelta::build_record(entityKey, entityId, action, touched, prior, after, context)` → the record array. |
| **D2 — response envelope** | The complete/partial/conflict response (code selection `wpcc_rollback_conflict|partial`, message, `restored/skipped/conflicts`, complete-only mark-applied) is hand-rolled 5× with **message drift** between runtimes. | Medium (consistency) | Extract `RollbackDelta::result(actionLabel, ids, rollbackId, outcome)` → success/error envelope; standardise messages. |
| **D3 — record storage/resolution** | **Three different schemes:** postmeta-per-record + indexed SQL (SEO); **keyed**-option `$records[$id]` (Content); **list**-option + `foreach` scan (Settings/Media/Comments/User). This is the real abstraction gap — it surfaced concretely in the merged-stack run (§4): a fixed-id legacy fixture **overwrites** under keyed storage (Content passed) but **shadows** under list storage (Media/User hit `already_applied`). List scan is O(n) and the option grows unbounded. | **High (scalability) before Bulk/Woo** | Introduce the deferred **`RollbackStore`** abstraction (P4.0 design §2.2): `persist/resolve/markApplied`, with postmeta / keyed-meta / option implementations. Unifies resolution and bounds growth. |
| Adapter API gaps | **None.** The 7-method `FieldAccessor` cleanly modelled post columns, post meta, WP options, comment columns, and user columns+usermeta. No interface change was needed across 5 dissimilar runtimes. | — | Keep the interface frozen. |
| Runtime-specific hacks | (a) Settings passes `entity_id = 0` (global) — clean. (b) Media has **two** restore entry points (`rollback()` + `media_restore`) sharing one helper — clean. (c) **List-option scan resolution** (4 runtimes) — does **not** scale to Bulk's per-item records or Woo's product volume. | Low except (c) | (c) resolved by D3 (`RollbackStore`). |

**The FieldAccessor abstraction scales; the record store does not.** D1/D2 are maintainability; **D3 is the one that matters before the hard runtimes.**

---

## 4. Merged-stack regression — **GREEN** (branches compose cleanly)

Assembled P4.0 + all 5 runtimes + all 5 accessors in the working tree (disjoint files → **zero conflict**), lint-clean, then ran once:
- Focused: Settings **35/0**, Media **38/0**, Content **27/0**, Comments **24/0**, User **25/0**.
- Common: `rollback-delta-core` **25/0**, `seo-rollback-delta` **56/0**, `operations-registry` **18/0**, `capability-runtime` **61/0**, `mcp-error-surface` **18/0**.
- Invariants: **34/23/40/40/2.5.0**.
- `change-history-rollback`: confirmed **48/0** standalone on P4.3, P4.4, **and P4.5**; `OperationExecutor` is unchanged on every branch, so the merged dispatcher is identical (not re-run on the merged stack).

**Transient artifact (NON-ATTRIBUTABLE):** on first merged-stack pass, Media S7 + User S6 (the **legacy** fixtures) failed because fixed-id records (`legacy-media-1`, `legacy-user-1`) from the earlier phase runs persisted in the **list**-storage options as `rollback_applied=true`, shadowing the fresh record. After clearing the stale fixtures both re-passed (38/0, 25/0). This is a **test-fixture idempotency** issue (fixed ids + list storage), not a composition or product-code regression — and a concrete data point for **D3**.

---

## 5. Branch strategy — recommendation

The five siblings were the right structure for **independent review** of P4.1–P4.5 (each verifiable in isolation; proven). They compose without conflict. But the **hard runtimes (Woo/ACF/Bulk) should build on the extracted core**, which means they need a line that contains it.

**Recommended (for a future authorized step — not executed here):**
1. **Create `program-4-integration`** = P4.0 + merge P4.1–P4.5 (conflict-free; verified). Optionally squash per-runtime.
2. **Core-hardening refactor on the integration branch first:** extract `RollbackDelta::build_record` (D1) + `RollbackDelta::result` (D2); introduce `RollbackStore` (D3). Re-run all 5 focused suites + core + SEO + parity to prove behaviour-preserving (each accessor/runtime then *consumes* the helpers instead of hand-rolling).
3. **Branch P4.6 Woo from the integration branch** (not from bare P4.0), so it reuses the hardened core and a real store.

Rationale: continuing P4.6 as a bare sibling off P4.0 would (a) add a 6th copy of the D1/D2 duplication and (b) inherit the non-scaling list store. Integrating + hardening now stops the duplication from tripling and gives Woo/Bulk a store that scales.

---

## 6. Readiness for the hard runtimes

| Runtime | Readiness | Notes |
|---|---|---|
| **Woo (products)** | **READY** (store recommended) | `WooProductAccessor` over WC_Product get/set fits the 7-method interface. Product **volume** + the list store argue for `RollbackStore` first. **Orders deferred** (relational/custom tables — separate sub-design). |
| **ACF** | **PARTIAL** | Flat `field→key` accessor does **not** model nested serialized config paths (e.g. `sub_fields[n].label`). Ship the **whole-definition + drift-guard** variant first (gets F-1 *safety* without true path-delta); true path-delta needs an accessor extension/design. **Adapter gap for true ACF path-delta.** |
| **Bulk** | **NOT READY without `RollbackStore`** | Per-item delta across N items in a single list-option blows up record size and O(n) scan. **Requires D3 (per-item store) + redesign.** |
| **Plugin/Theme visibility (G2)** | **READY anytime** | Not a delta migration — a visible-`reversible:false` flag + guard. Independent of the core refactor. |

---

## 7. Findings summary

**Blockers:** **None.** All 5 branches pass; invariants held; merged stack green; no forbidden-surface or contract change anywhere.

**Non-blocking findings:**
- D1 record-builder duplication (×5).
- D2 response-envelope duplication + message drift (×5).
- **D3 three record-storage schemes** — the only one with scalability impact; blocks *clean* Bulk and strains Woo volume.
- Audit event-name inconsistency (cosmetic).
- Test-fixture idempotency: Media S7 / User S6 legacy fixtures use fixed ids + list storage (harden to unique ids).

---

## 8. Decisions

- **PASS / FAIL per branch:** P4.1 **PASS** · P4.2 **PASS** · P4.3 **PASS** · P4.4 **PASS** · P4.5 **PASS**.
- **GO / NO-GO for P4.6 Woo:** **GO** — the pattern is proven and Woo-products is feasible. **Conditional recommendation:** do the **core-hardening refactor (D1+D2, and D3 `RollbackStore`) on an integration branch first**, then branch Woo from it. Woo *can* proceed without the refactor, but at the cost of a 6th duplication and a non-scaling store.
- **Recommended next runtime:** **Woo (products)** — cleanest of the three hard ones; aligns with the master design order. **Bulk last** (needs `RollbackStore`); ACF in between with the whole-def-drift-guard fallback. **G2 (Plugin/Theme visibility)** can be slotted anytime (independent).
- **Refactor needed before Woo/ACF/Bulk?** **YES — recommended, and effectively required before Bulk.** Extract `build_record` + `result` (D1/D2) and introduce `RollbackStore` (D3) on an integration branch before the hard runtimes. This is the single highest-leverage move at the midpoint.

> Report only. No code modified, nothing committed/pushed/deployed; the synthetic merged stack was assembled in the working tree for testing and fully discarded (tree restored to P4.5 `6b5d0ef`).
