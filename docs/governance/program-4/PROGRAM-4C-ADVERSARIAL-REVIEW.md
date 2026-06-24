# PROGRAM-4C — Adversarial Review

> **Type:** self-attack on the PROGRAM-4C recommendations (no code). Report-only.
> **Method:** assume the worst — corrupted records, concurrent edits, out-of-order restores, stale snapshots, huge Woo/ACF stores, partial failures — and find where the proposed architecture breaks. Each finding ends with a **revision** folded back into the design docs.

---

## A. Attack: corrupted / malformed rollback records
**Scenario:** a `wpcc_*_rollbacks` record (or postmeta record) is truncated, hand-edited, or has a non-array `fields`/`keys`.
- **Where it breaks:** `RollbackDelta::restore` iterates `$fields` and `$spec['keys']`; a malformed `keys` (string instead of array) is cast `(array)` (safe), but a record missing `fields` entirely would fall through a runtime's v2 branch guard. The Woo/ACF/Bulk runtimes must guard `isset($rec['fields']) && is_array(...)` **before** treating a record as v2 (P4.6 already does this; the pattern must be mandatory).
- **Worse case:** `OptionListRollbackStore::resolve` returns the first record whose `id` matches; a duplicate/old id collision (UUID, negligible) — not a real risk.
- **Revision (R-1):** make **record-integrity a precondition** in every migrated runtime: a record failing shape validation is treated as **`rollback_not_found`/`reversible:false`**, never as a partial restore, and never throws. Add a malformed-record test to each suite. *(Folded into ROADMAP §5 validation + ACF/Bulk docs.)*

## B. Attack: concurrent edits during apply (capture races the write)
**Scenario:** between `capture()` (pre-write prior) and the post-write `after` read, another process mutates the same field.
- **Where it breaks:** the recorded `after` would be the *other* process's value, so a later rollback compares live==after, sees no drift, and restores `prior` — silently overwriting the concurrent change. This is a real, narrow window.
- **Reality check:** all mutations route through the single `OperationExecutor::run` chokepoint with the atomic claim (A-1), so two *governed* writes cannot interleave. The window is only against **non-governed** external writers (WP admin, cron, other plugins) landing in the millisecond between capture and after-read.
- **Revision (R-2):** accept the residual (it is the same window SEO/P4.x already ship with) but **document it explicitly** and prefer reading `after` immediately after the write within the same handler call (already the pattern). For bulk, capture+write+after per item are adjacent — keep them adjacent (no batch-wide capture-then-write-all). *(Folded into BULK §4.)*

## C. Attack: out-of-order restores / resurrection
**Scenario:** edits A then B on the same field; rollback A first (should conflict), then B; or rollback B then A.
- **Where it holds:** the delta core already handles this — rollback A while B is live ⇒ drift ⇒ conflict (no clobber); rollback B (live==B.after) ⇒ restores B.prior (==A.after); then rollback A (live now==A.after) ⇒ restores A.prior. **No resurrection.** Proven for SEO/Content/Woo-products (S4/S5).
- **Where the NEW work could break it:** the **whole-def + drift-guard (A′)** mode for ACF/Elementor uses a *fingerprint of the whole blob*, not per-field. Out-of-order on a blob: rollback A (blob changed by B ⇒ fingerprint mismatch ⇒ refuse) — good. But rollback B then A: after B's restore, the blob == A's apply-time state, so A's fingerprint matches ⇒ A restores. Correct **only if** B's restore reproduces A's exact post-state blob. If B's `before_blob` was captured *before* A (impossible — B is later) it's fine; if canonicalization drops a field that A changed, the fingerprint could false-match.
- **Revision (R-3):** the A′ fingerprint must cover **every field A or B can touch** (canonicalize structurally, drop only provably-volatile keys like `ID`). Add an explicit **out-of-order blob test** (A then B on the same def, both orders) to the ACF/Elementor suites. If a clean fingerprint cannot be guaranteed for a given blob type, **fall back to refuse-on-any-fingerprint-change** (safe: refuses more, never clobbers). *(Folded into ACF §3.2/§8 and ROADMAP P4.10.)*

## D. Attack: stale / oversize snapshots (Pattern C + G2 artifacts)
**Scenario:** a media original > 10 MB or read > 10 s; a plugin ZIP larger than disk headroom.
- **Where it breaks:** `MediaSnapshot` aborts capture on the bound (safe — the *operation* aborts, so no irreversible write happens). But the **G2 plugin artifact** path I proposed could, if naively coded, *skip* capture on oversize and then proceed with the update ⇒ a silent irreversible update mislabeled reversible.
- **Revision (R-4):** for G2 artifact capture, **capture-or-abort-or-flag**: if the pre-update ZIP cannot be made (size/permissions/disk), either abort the update or proceed **explicitly flagged `reversible:false` with a visible `log`** — never silently "reversible." Add a prune policy (cap count + bytes) so the artifact dir cannot grow unbounded. *(Folded into G2 §5 Tier-2 + §8.)*

## E. Attack: large Woo / ACF stores — shared-option FIFO eviction
**Scenario:** a busy store performs 500 product/variation/coupon/order writes; `wpcc_woo_rollbacks` is one option capped at 200.
- **Where it breaks:** the oldest 300 `rollback_id`s are **silently evicted**. An agent that received `rollback_id` X an hour ago calls rollback ⇒ `rollback_not_found`, with no indication it *used to* exist. P4.6 product records live in this same capped option — so even migrated Woo products inherit this. This is a genuine correctness weakness the original program under-weighted.
- **Revision (R-5):** **move hot per-entity surfaces (Woo, ACF values, Elementor) to per-entity postmeta storage** (the P4.7 `PostMetaRollbackStore`), where each record lives with its entity and is GC'd with it — no global cap, no cross-entity eviction. For records that must stay in an option (option-page values, global settings), keep the cap but **make eviction observable** (audit a `rollback.evicted` event) so a vanished id is explainable. *(Promotes P4.7 from "Bulk helper" to "shared keystone"; folded into INVENTORY §0.6, RISK §2.3, ROADMAP P4.7.)*

## F. Attack: massive ACF structures — option/postmeta bloat + autoload
**Scenario:** a flexible-content group with hundreds of nested sub-fields; whole-def blob is hundreds of KB.
- **Where it breaks:** stored in an **autoloaded** option this would tax every page load; even non-autoloaded, 200 such records bloat the option and slow `get_option`.
- **Revision (R-6):** ACF whole-def records go to **postmeta on the group/field post** (not an option), never autoloaded, GC'd with the post; cap per-entity record count. Add a **size guard**: if a single def blob exceeds a sane bound, store it but `log` the size (observability), and consider compressing. *(Folded into ACF §4.)*

## G. Attack: partial failures mid-restore (multi-write handlers)
**Scenario:** a Woo product rollback restores field 1 (save ok), field 2's `save()` throws (e.g., invalid SKU now duplicated by a concurrent product).
- **Where it breaks:** the delta core marks applied only on `complete`; but per-key `key_set` does `wc_get_product→set→save` per field — a throw mid-iteration leaves some fields restored, some not, and (in the current Woo runtime) is uncaught. The record is **not** marked applied (good — retryable), but the entity is in a mixed state and the caller sees an exception, not a structured partial.
- **Revision (R-7):** every migrated runtime must **wrap restore in try/catch(\Throwable)** and convert a mid-restore failure into a structured `partial`/error envelope listing which fields were restored, leaving the record retryable (mirrors A2-1's exception-safe finalize). For **bulk**, wrap **per item** so one item's failure cannot abort the batch or strand its record. *(Folded into BULK §6 and ROADMAP §5; generalizes the A2-2 residual mitigation.)*

## H. Attack: drift comparator false-equality (type coercion)
**Scenario:** WC `get_regular_price()` returns `"10.00"` at apply, `"10"` after a normalization pass; or an id-set returns ints vs numeric strings; or ACF returns `null` vs `''`.
- **Where it breaks:** a string `equals` could mark live≠after (false drift ⇒ over-refuse, safe but annoying) or, worse, a loose compare could mark live==after when truly different (false-equal ⇒ clobber).
- **Revision (R-8):** comparators must be **type-precise and normalization-stable**: numeric fields compare as normalized decimals; id-sets as sorted ints; null/'' distinguished (existence-fidelity); structured fields via canonical serialize. The WooProductAccessor already does sorted-int + nullable-numeric + normalized-attributes — adopt the same discipline in `ACFValueAccessor` and the bulk accessors. Prefer **false-refuse over false-equal** (refusing is safe; clobbering is not). Add comparator unit tests per field type. *(Folded into ACF §3.1 and core validation.)*

## I. Attack: json_import "dead" rollback record
**Scenario:** an agent calls rollback on a `json_import` record expecting reversal.
- **Where it breaks:** today the record is lossy (`summarize_group`) and has no faithful restore ⇒ it returns success-ish while reversing nothing (or partially) — a *silent* false reversal, the exact anti-pattern.
- **Revision (R-9):** **delist json_import from `ROLLBACKABLE` and return `reversible:false` + notice** as the immediate fix (honest), with full-tree capture as an optional later upgrade. Never leave a record that claims reversibility it cannot deliver. *(Folded into ACF §5/§7d.)*

## J. Attack: bulk batch index lost / desynced
**Scenario:** the new `wpcc_bulk_batches` index (batch_id→ids) is evicted/capped while per-item postmeta records survive (or vice-versa).
- **Where it breaks:** rollback by batch_id can't find the id list ⇒ cannot reverse, even though item records exist.
- **Revision (R-10):** make the batch index **derivable**: tag each item's postmeta record with `batch_id`, and resolve a batch by querying items carrying that `batch_id` (a bounded meta_query, N≤100) **if** the index option is missing — index is an optimization, not the source of truth. Cap the index generously and audit eviction. *(Folded into BULK §3.1.)*

## K. Attack: G2 honest-flag bypass via queued/MCP path
**Scenario:** the `reversible:false` flag is added at the runtime response, but a worker/MCP path constructs the approval differently and the flag never reaches the operator card.
- **Where it breaks:** the false-reversibility contract reappears on a non-admin path (echoes the B2-1 class of "execution truth not unified across paths").
- **Revision (R-11):** set `reversible:false` at the **classification layer (DestructiveGuard/registry), not just the response**, so every path (admin, worker, MCP, change_history) sees the same truth — and assert it on all three entry paths in tests. *(Folded into G2 §7.)*

---

## Net effect of the adversarial pass on the architecture
| Revision | Change to the plan |
|---|---|
| **R-5 (FIFO eviction)** | **Promotes `PostMetaRollbackStore` to the program keystone (P4.7)** and extends its use to Woo/ACF/Elementor, not just Bulk — the single most consequential revision. |
| R-3 (blob out-of-order) | A′ fingerprint must cover all touchable fields; fall back to refuse-on-any-change; out-of-order blob tests mandatory. |
| R-7 (partial restore) | Mandatory try/catch→structured-partial in every migrated runtime; per-item for bulk. |
| R-4 (oversize artifact) | G2 capture-or-abort-or-visibly-flag; prune policy. |
| R-1/R-9 (bad/dead records) | Record-integrity precondition; json_import delisted to honest. |
| R-8 (comparators) | Type-precise comparators, prefer false-refuse; per-type comparator tests. |
| R-6 (ACF bloat) | ACF whole-def → postmeta, size-guarded, never autoloaded. |
| R-2/R-10/R-11 | Document capture window; derivable batch index; honesty at classification layer across all paths. |

**Conclusion:** the core delta approach survives the attack. The material changes are (1) **storage** — per-entity postmeta becomes the default for hot surfaces to kill FIFO eviction (R-5), (2) **failure handling** — structured partials and record-integrity guards everywhere (R-1, R-7), and (3) **honesty hardening** — refuse-over-clobber for blobs and reversible:false at the classification layer (R-3, R-9, R-11). None of these introduce a schema/DB_VERSION/capability/operation/MCP/security change; all remain within postmeta/option storage and existing interfaces.
