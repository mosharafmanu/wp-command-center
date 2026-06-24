# PROGRAM-4.10 — Elementor Rollback Design (Phase C)

> **Type:** design (no code in this phase). Report-only. **Scope: `includes/Operations/ElementorRuntimeManager.php` + one new Elementor-scoped accessor.**
> **Principle:** treat `_elementor_data` as one **atomic whole-document JSON field** (no decomposition, no widget-level rollback). Refuse-on-drift, never clobber. Preserve current behavior + legacy records.

---

## 1. Approach comparison (per the mission)

| Approach | Fit | Verdict |
|---|---|---|
| **1. Field-scoped RollbackDelta** | the single `data` field = the whole `_elementor_data` document | **CHOSEN** — drift+existence via the proven core, atomically |
| **2. Whole-field JSON rollback** | `_elementor_data` JSON string | **CHOSEN (this IS the field)** — whole-document, never decomposed |
| **3. Whole-document fingerprint drift guard** | drift detection on the document | **CHOSEN (as the drift comparator)** — normalized whole-JSON compare |
| **4. Snapshot-only** | — | rejected (data already restorable from the captured JSON; snapshot adds nothing) |
| **5. Irreversible / unsupported visibility** | malformed/missing/drift | **CHOSEN** — honest conflict / not-found |

Approaches 1–3 are the **same mechanism**: `RollbackDelta` over a single `data` field whose value is the whole `_elementor_data` JSON, with a normalized whole-document drift comparator. This is identical in shape to ACF P4.9's atomic value path.

## 2. New code: `ElementorDataAccessor` (necessary)
A `FieldAccessor` over the single Elementor document field `data` → meta key `_elementor_data`:
- `backing_keys('data')` → `['_elementor_data']`.
- `read_field($id,'data')` / `key_get` → `get_post_meta($id,'_elementor_data',true)` (raw JSON string).
- `key_exists($id,'_elementor_data')` → `metadata_exists('post',$id,'_elementor_data')`.
- `key_set($id,'_elementor_data',$json)` → `update_post_meta($id,'_elementor_data',wp_slash($json))` (the stored prior was unslashed; `update_post_meta` strips one slash level, so `wp_slash` keeps it byte-faithful — mirrors the legacy `rollback()`).
- `key_delete` → `delete_post_meta($id,'_elementor_data')` (restore prior absence; unreachable in practice — `edit_widget` rejects non-Elementor pages, so `existed` is always true).
- `equals('data',$cur,$after)` → **normalized whole-document compare**: `json_decode` both then order-preserving `wp_json_encode`; equal ⇒ no drift. Decode failure ⇒ raw-string compare. Detects any structural/content/**order** change (a later edit to any widget, a reorder, an add/remove) while ignoring pure encoding noise.

The accessor is Elementor-data-scoped and does **not** clear the Elementor cache (kept in the runtime — see §4) so it stays a thin meta adapter.

## 3. Apply path (`edit_widget`)
```
$acc   = new ElementorDataAccessor();
$prior = RollbackDelta::capture($acc, $id, ['data']);   // existed + prior whole JSON, BEFORE mutate
... existing decode → mutate_widget → save_data (writes _elementor_data + clears cache) ...
$after = ['data' => $acc->read_field($id, 'data')];      // post-edit whole JSON
$rid   = uuid;
$rec   = RollbackDelta::build_record(['data'], $prior, $after, $cx,
           head{ id:$rid, page_id:$id, action });
(new PostMetaRollbackStore('_wpcc_elementor_rb_'))->persist($id, $rid, $rec);
return { ..., rollback_id:$rid }
```
New records live in **postmeta** (`_wpcc_elementor_rb_{rid}` on the page) — O(1) by id, no FIFO eviction, not autoloaded, GC with the page. The legacy `wpcc_elementor_rollbacks` option still holds pre-P4.10 records (drained by the legacy path); new edits never write it.

## 4. Rollback path
`rollback()` first tries `PostMetaRollbackStore('_wpcc_elementor_rb_')->resolve($rid)`:
- **Found (v2 delta):** `o = RollbackDelta::restore(new ElementorDataAccessor(), $page_id, $rec.fields)`. Drift compare live whole document vs recorded `after`; **match → restore prior whole JSON** (existence-faithful), then `clear_cache($page_id)`; mark applied; return `complete` success. **Drift → skip + conflict** (`error:true`, `code:wpcc_rollback_conflict`, `reversible:false`, **not** applied → retryable, never clobbers the newer edit).
- **Not found → legacy option scan** (unchanged): whole-document `update_post_meta(wp_slash)` + `clear_cache`, mark applied. Preserves the existing behavior for pre-P4.10 records.

Cache clear (`delete _elementor_css` + `files_manager->clear_cache()`) stays in the runtime, invoked once after a complete delta restore (mirrors the legacy path) — the accessor stays Elementor-Plugin-free.

## 5. Honesty conventions (avoid false clean-success)
- **complete:** `{action:'elementor_rollback', rollback_id, page_id, status:'complete', restored:true, reversible:true}`.
- **drift conflict:** `{action:'elementor_rollback', rollback_id, page_id, error:true, code:'wpcc_rollback_conflict', status:'conflict', restored:false, reversible:false}`.
- **already applied / not found / missing id:** unchanged errors.
- **malformed record** (non-array / missing fields): `resolve` returns null on non-array ⇒ falls to legacy scan ⇒ not-found (honest), never fatal.

## 6. Idempotency / legacy / GC
- v2 records: per-record `rollback_applied` (in the postmeta record) — repeat → already-applied error. Legacy option records keep their option flag.
- GC: deleting the page removes its `_wpcc_elementor_rb_*` records (postmeta cascade) → resolve null → legacy fallback → not-found (honest).

## 7. Scope / STOP
- Files: `ElementorRuntimeManager.php` (capture in `edit_widget`; `rollback()` delta-path + legacy fallback; small helpers) + new `includes/Rollback/ElementorDataAccessor.php`. Reuses `RollbackDelta`, `PostMetaRollbackStore`.
- **No** JSON decomposition (atomic whole-document). **No** widget-level rollback. **No** new Elementor capability / op / field. **No** page-settings/template/post-field rollback (not mutated by the runtime). **No** schema / DB_VERSION / operation-registry / capability / MCP / REST / UI / security change. New meta keys + record fields additive.
- **No STOP triggered:** JSON made safe via atomic whole-document handling; runtime mutation scope unchanged (the 3 widget edits on `_elementor_data`).

## 8. Validation plan (Phase E preview)
New `test-elementor-rollback-delta.sh` (manager-level, real seeded Elementor page): `_elementor_data` whole-document restore; empty-prior (n/a — always exists; assert existence path); sibling-widget preservation (edit widget A, externally edit widget B, rollback A → refuses on drift so B's change kept — proves no clobber); same-field drift skip/report; out-of-order no resurrection; repeated rollback safety; legacy option record restore; partial/conflict not clean-success; malformed JSON handled honestly; unsupported/missing reported honestly. Plus the full regression battery + guards + change-history.
