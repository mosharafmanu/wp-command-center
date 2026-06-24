# PROGRAM-4 — Rollback Integrity Expansion · Design Report

> **Type:** design report (no code, no implementation). Autonomous mode.
> **Date:** 2026-06-23 · **Baseline:** prod HEAD `a41a9d7` (code-effective `7aa7e84`); Phase 1/2/3 deployed; **Phase 3 SEO F-1 DEV-acceptance-gated** (52/52 + clean regressions + invariants 34·23·40·40·2.5.0).
> **Authority:** `SESSION-HANDOFF-PHASE-3.md`, `docs/governance/validation/VALIDATION-SUMMARY.md`, `RISKS-AND-GAPS.md`, `NEXT-RECOMMENDED-PROGRAM.md`.
> **Goal:** make the **Rollback Guarantee true platform-wide** by extending SEO's field-scoped, drift-aware delta to the runtimes that still use full-object snapshots (G1), and closing the update-irreversibility gap (G2).
> **Constraints (Rule 8):** no commit / push / deploy / AI-enable / security-mode change / schema migration / baseline refresh without explicit authorization. This document plans; it does not change code.

---

## 0. Recon correction (intellectual honesty)
A code-recon sub-pass mis-reported `CommentsRuntimeManager` as having *no* rollback. **Verified false:** it has `store_rollback` (line 262), `rollback()` (line 218), full-object `before_state` (line 274) in option `wpcc_comments_rollbacks`. So the full-object set is **9 runtimes**, consistent with the original Phase B finding. All line numbers below were spot-verified; treat any single citation as "approximate, verify at implementation."

---

## 1. Runtime inventory

All mutating runtimes route through the single `OperationExecutor::run` chokepoint; reversal goes through `OperationExecutor::rollback` (dispatcher at `OperationExecutor.php:421–457`), which routes either via a handler's public `rollback()` method **or** via the `ACTION_ROLLBACKS` map (`:401–405`, currently `option_manage→option_rollback`, `content_manage→content_rollback`, `seo_manage→seo_restore`). `rollback_id` is surfaced uniformly post-STEP-102 via `normalize_success` + `RollbackContext::last()` (`:756–766`).

| # | Runtime / file | Rollback storage | before-state granularity | Restore primitive | Object identity | Pattern |
|---|---|---|---|---|---|---|
| — | **SEO** `SeoRuntimeManager.php` | **postmeta** `_wpcc_seo_rb_{id}` (+legacy option) | **touched-field delta v2** (`fields` map: prior+existed+after) | per backing-meta-key write, drift-aware | post_id | **B (delta) — reference** |
| — | **Patch/File** `PatchOperation` + `SnapshotManager` | disk snapshot + record | per-affected-file bytes + hash | byte replay + verify, auto-revert | file path | **C (byte snapshot+verify) — reference** |
| 1 | **Content** `ContentManager.php` (`store_rollback :492`) | option `wpcc_content_rollbacks` | full post fields (title/status/content/excerpt) | `wp_update_post()` whole object | post_id | A (full-object) |
| 2 | **Woo** `WooCommerceRuntimeManager.php` (`:647`) | option `wpcc_woo_rollbacks` (cap 200) | full product snapshot / order billing map | WC setters + `save()`; `wp_publish_post` | product/order/coupon/variation id | A |
| 3 | **Settings** `SettingsRuntimeManager.php` (`:61`) | option `wpcc_settings_rollbacks` (cap 200) | map of WP option name→prior value | `update_option()` per option | none (global; action-keyed) | A (but already option-granular) |
| 4 | **ACF** `ACFRuntimeManager.php` (`:646`) | option `wpcc_acf_rollbacks` (cap 200) | full ACF group/field definition (serialized) | `acf_update_field_group/field`, `update_field` | group_id/field_key/post_id | A (nested blobs) |
| 5 | **User** `UserManager.php` (`:381`) | option `wpcc_user_rollbacks` (cap 100) | subset scalar fields + roles | `wp_update_user()`, role swaps | user_id | A (partial capture) |
| 6 | **Forms** `FormsRuntimeManager.php` (`:94`) | option `wpcc_forms_rollbacks` (cap 200) | mostly **empty** (inverse-action) | inverse action (create→delete, delete→republish); update = no-op | form_id | A-shell / **D (inverse)** in practice |
| 7 | **Comments** `CommentsRuntimeManager.php` (`:262`) | option `wpcc_comments_rollbacks` | full comment state (content/approved) | `wp_update_comment` / untrash | comment_id | A |
| 8 | **Bulk** `BulkRuntimeManager.php` (`:91`) | option `wpcc_bulk_rollbacks` (cap 200) | per-bulk `{ids, before:{id→title}}` — **title only** | `wp_update_post` per item (title) | many post_ids/record | A (lossy: status not captured) |
| 9 | **Media-metadata** `MediaRuntimeManager.php` (`:622`) | option `wpcc_media_rollbacks` (cap 100) | full attachment meta snapshot | `restore_metadata()` selective (`:600–613`) | attachment_id | A (meta) — bytes handled by **C** |

**Out-of-pattern but in scope:** plugin/theme **update** (`PluginManager.php:243`, `ThemeManager.php:185`) store **no** rollback and are not flagged irreversible (G2).

---

## 2. Risk ranking

Risk = **corruption potential (F-1: sibling loss / clobber / resurrection) × coverage gap (lossy/absent rollback) × likelihood (layered field-wise edits to the same object)**.

| Rank | Runtime | Risk | Driver |
|---|---|---|---|
| **1** | **Bulk** | **VERY HIGH** | 200-item full-object records **and** status-change bulk ops don't snapshot status → rollback is a **silent partial/no-op** (correctness defect, not just F-1). Largest clobber surface. |
| **2** | **Woo (products)** | **HIGH** | Full product snapshot; price/stock/status edited independently and frequently → classic layered-edit clobber. Orders are relational (custom tables) — separate, harder sub-case. |
| **3** | **ACF** | **HIGH** | Full nested serialized definition; field rename + type change layered → second edit clobbered on rollback. Highest *delta-mapping* complexity. |
| **4** | **Content** | **MODERATE** | Posts edited field-wise (title vs body vs excerpt) across changes; whole-object `wp_update_post` restore loses concurrent siblings. |
| **5** | **User** | **MODERATE** | Partial field capture (email/display/first/last only) + **role full-replacement** clobbers layered role ops; uncaptured usermeta silently unreversed. |
| **6** | **Comments** | **LOW-MOD** | Full-object but small surface; status flips layered rarely; delete is the sharp edge. |
| **7** | **Media-metadata** | **LOW-MOD** | Selective restore already exists (`:600–613`); bytes safe via snapshot (Pattern C). Lowest corruption risk of the option-stored set. |
| **8** | **Settings** | **MINIMAL** | Already option-granular; update groups touch disjoint options; cross-group conflict near-zero. |
| **9** | **Forms** | **LOW (gap, not corruption)** | Inverse-action is drift-safe by construction, but **form_update rollback is a no-op** — a coverage gap, not an F-1 corruption. |
| **G2** | **plugin/theme update** | **MED-HIGH** | Not F-1; **silent irreversibility** — a breaking governed update can't be undone and isn't flagged. |

> Note the two distinct failure families: **corruption** (Bulk/Woo/ACF/Content/User/Comments/Media-meta — F-1) vs **coverage gap** (Bulk-status, Forms-update, plugin/theme-update, uncaptured User meta). The program must address both; they need different fixes.

---

## 3. Rollback architecture comparison

Five patterns exist in the codebase today:

| Pattern | Where | Mechanism | F-1 safe? | Strengths | Weaknesses |
|---|---|---|---|---|---|
| **A — Full-object snapshot** | 9 runtimes | option-stored `before_state`; restore writes whole object | ❌ No | simple; one record | sibling loss, same-field clobber, out-of-order resurrection, drift-blind, lossy if capture incomplete, option growth |
| **B — Field-scoped delta v2** | SEO | postmeta per-record; touched-field map (prior+existed+after); drift-skip; partial/complete/conflict | ✅ Yes | history-honest, idempotent, sibling-preserving, legacy-compatible | per-runtime field accessor needed; more code |
| **C — Byte snapshot + verify** | Patch, Media-bytes | disk snapshot, hash pre/post, auto-revert | ✅ Yes (for bytes) | exact binary fidelity | not field-oriented; disk cost |
| **D — Inverse-action** | Forms | reverse the op; no state | ✅ Yes (create/delete) | drift-immune | only for structural create/delete; **no update reversal** |
| **E — None / flagged** | plugin/theme update | nothing captured | ❌ N/A | — | silent irreversibility |

**Design conclusion:** there is no one pattern for all. The right target architecture is a **typed convergence**:
- **Named-field mutations → Pattern B** (the bulk of the work: Content, Settings, User, Woo-products, Comments, Media-metadata, ACF-with-caveats).
- **Binary/file mutations → keep Pattern C** (Media-bytes, Patch) — already correct.
- **Structural create/delete → Pattern D is acceptable**, but **update paths must move to B** (fixes Forms-update gap).
- **Irreversible-by-nature → Pattern E must become *visibly* E** (G2): explicit `reversible:false` + guard, optionally upgraded to a captured artifact.

### 3.1 The keystone: a shared delta core + per-runtime accessor
Pattern B today lives entirely inside SEO (`store_rollback`/`restore_delta`/`values_equal`/`capture_prior` + `SeoProvider::backing_keys/read_field`). Re-implementing drift/idempotency/partial-conflict logic nine times is the main risk to this program. **Recommended architecture:**

```
RollbackDelta (shared core, extracted from SEO — behavior-preserving)
  • record shape v2: { version:2, entity, id, fields:{name:{prior,existed,after,keys?}}, applied, meta }
  • capture(accessor, entity_id, touched_fields)            → prior+existed+after
  • restore(accessor, record)                                → drift-compare each field,
        write prior where !drift, skip+report where drift     → complete|partial|conflict
  • idempotency guard, legacy-record passthrough
FieldAccessor (interface, one tiny adapter per runtime)
  • identity(params) → entity_id
  • read(entity_id, field) → value
  • exists(entity_id, field) → bool
  • write(entity_id, field, value, existed) → void
  • equals(a,b) → bool   (drift comparator; default + per-runtime override)
```
Each runtime supplies an accessor (post columns, post/user/comment meta, WC setters, option names). SEO's `SeoProvider` becomes the first accessor; `restore_delta` becomes `RollbackDelta::restore`. This makes per-runtime migration a *small* adapter + a storage choice, not a re-derivation of correctness.

---

## 4. Delta-pattern applicability analysis

Applying the Pattern-B contract requires that a mutation be expressible as **a set of named fields on a stably-identified entity**, each field independently readable/writable with a drift comparison. Per runtime:

| Runtime | Field unit | Identity | Read/write primitive | Delta-fit | Notes / obstacles |
|---|---|---|---|---|---|
| **Settings** | WP option name | global (action group) | `get_option`/`update_option` | **CLEAN — easiest** | already field-granular; store record keyed by option name; no entity, store in a dedicated option-record |
| **Media-metadata** | attachment field/meta (title/alt/caption/description) | attachment_id | post columns + `_wp_attachment_image_alt` | **CLEAN** | selective restore already exists (`:600–613`); keep bytes on Pattern C (hybrid) |
| **Content** | post column (title/status/content/excerpt) + meta | post_id | `wp_update_post` single-field; `*_post_meta` | **CLEAN-ish** | drift-compare on post columns; `wp_update_post` accepts partial arrays so single-field writes are fine |
| **Comments** | comment column (content/approved) + meta | comment_id | `wp_update_comment`; commentmeta | **CLEAN** | small surface; straightforward accessor |
| **User** | user field (email/display/first/last) + role set + usermeta | user_id | `wp_update_user`; role API; usermeta | **FEASIBLE** | **roles are a set, not a scalar** → model as one field with set-semantics drift; **extend capture** to any usermeta the op writes (closes lossy gap) |
| **Woo (product)** | product property (price/stock/status/cats/attrs) | product_id | WC_Product setters + `save()` | **FEASIBLE** | accessor wraps setters/getters; drift via getter; **orders deferred** (relational, custom tables — separate sub-design) |
| **ACF (value_update)** | field value on a post | post_id+field_key | `get_field`/`update_field` | **FEASIBLE** | value-level edits map cleanly |
| **ACF (group/field config)** | nested config keys (e.g. `sub_fields[n].label`) | group_id/field_key | ACF config arrays (serialized) | **HARD** | true field-delta needs config-path parsing; **fallback: whole-definition record + drift guard** (detect external edit, skip rather than clobber) — gets F-1 *safety* without full path-delta |
| **Bulk** | per-item field (title/status/…) across N items | N post_ids | per-item `wp_update_post` | **NEEDS REDESIGN** | one delta **per item** (not one blob); **fix lossy status capture**; record size grows → store per-item delta rows (postmeta on each item, like SEO) instead of one giant option blob |
| **Forms (update)** | provider-defined form config | form_id | CF7/provider API | **PARTIAL** | if provider exposes form definition, capture as fields; else keep inverse-action for create/delete and **flag update irreversible** (G2-style) |
| **plugin/theme update** | n/a (binary package) | slug | upgrader | **NOT delta** | Pattern E→visible-E (flag) or Pattern C-like (capture pre-update ZIP/version artifact) |

**Bottom line:** 6 runtimes are clean/feasible delta conversions (Settings, Media-meta, Content, Comments, User, Woo-products, ACF-values); **3 need special handling** — ACF-config (whole-definition+drift-guard fallback), Bulk (per-item redesign + status fix), Forms-update (flag or provider-delta); and plugin/theme update is a **visibility** fix, not a delta.

---

## 5. Migration strategy

**Principles:** (1) extract-core-first so correctness is written once; (2) one runtime per phase, behavior-preserving, legacy-record compatible (dual-read like SEO); (3) **schema-free** — reuse postmeta/usermeta/commentmeta/option storage so DB_VERSION stays 2.5.0 (confirm per runtime — the R2 storage-fit check); (4) invariants 34·23·40·40·2.5.0 frozen (no op/cap/tool/schema change); (5) sequence by risk × ease, validating each before the next; (6) every deploy is a Rule-7 check-in.

### 5.1 Storage decision per entity type (schema-free target)
- **Post-bound** (Content, Media-meta, Comments-as-commentmeta, ACF-values, Bulk-per-item, Woo-products): store the v2 delta record in **meta on the target entity** (mirrors SEO's `_wpcc_seo_rb_{id}` postmeta) → bounded, co-located, no option bloat, no new table.
- **Global** (Settings): no entity to attach to → keep an **option record** but in v2 delta shape (per-option fields), or a dedicated option key per record.
- **User** (usermeta), **Comments** (commentmeta): analogous to postmeta.
- **No new DB table or column anywhere** is the target. If any runtime cannot fit (e.g. needs an index for discovery), that runtime **escalates to a schema check-in before code** (R2).

### 5.2 Legacy compatibility
Each migrated runtime keeps reading its old `wpcc_*_rollbacks` option records via a legacy path (exactly as SEO retained `restore_legacy_meta`/`seo_restore_legacy`). New writes use v2 delta; old records still restore. No data migration of historical records required.

### 5.3 Special-case handling
- **Bulk:** redesign to **per-item delta records** (not one option blob); **capture status** (close the lossy no-op); cap/stream for large selections; reuse `SelectionResolver` bounds.
- **ACF-config:** ship the **whole-definition + drift-guard** variant first (F-1 *safety*: detect external edit, skip+report instead of clobber), defer true path-level field-delta as an enhancement.
- **Forms-update / plugin-update / theme-update (G2):** if a faithful pre-state artifact is capturable, capture it; otherwise return `reversible:false` + a visible irreversibility notice + (for plugin/theme) DestructiveGuard-style acknowledgement. **Make irreversibility visible, never silent.**
- **Woo-orders:** explicitly **deferred** to a sub-design (relational/custom-table state); products only in the first Woo phase.

### 5.4 What is explicitly NOT in this program
- **A2-1 stale-`executing` reaper** — needs `claimed_at` column = schema migration (Rule-7). Schedule independently.
- **Prod token-gated verify + full serial T2** — deploy-coupled; run at the deploy decision.
- Security-mode/capability/MCP-contract changes — none required or permitted here.

---

## 6. Validation strategy

Every phase mirrors the Phase-3 gate that this program just passed for SEO.

### 6.1 Per-runtime acceptance suite (clone `test-seo-rollback-delta.sh`)
Adapt the **S1–S9 scenario set** to the runtime's fields:
- **S1 empty-prior fidelity** — apply to absent field → rollback deletes/clears exactly.
- **S2 value-prior fidelity** — apply over existing → rollback restores exact prior.
- **S3 disjoint layered** — change A=field1, B=field2; rollback A → **field2 (B) survives**.
- **S4 same-field drift** — A and B both touch field1; rollback A → **conflict, B not clobbered, reported skipped**.
- **S5 out-of-order** — rollback B then retry A → original, **no resurrection**.
- **S6 type-specific fidelity** — runtime's structured field (roles set / WC price / ACF value / bulk status).
- **S7 legacy record** — pre-migration `before_state` option record still restores.
- **S8 idempotency** — repeat rollback guarded.
- **S9 history honesty** — partial returns restored/skipped; drifted sibling preserved.
Plus static assertions (v2 record shape, drift compare, existence flag, partial/conflict codes, legacy branch retained).

### 6.2 Drift-injection (the F-1 reproduction)
For each runtime, construct the **exact** layered-edit scenario that the old full-object path corrupts, and assert sibling survival + honest reporting. This is the proof the migration *closes* F-1, not just that tests pass.

### 6.3 Regression discipline
- The runtime's **existing suite must stay green at its current tally** (e.g. `content-runtime` 98/0 stays 98/0).
- After **core extraction (P4.0)**, **SEO must still score 52/52** — the proof the refactor is behavior-preserving.
- **Net-new attributable = 0** vs `regression-baseline.tsv`; **serial T2 before any deploy**; do not refresh baseline unless directed.
- Re-run any concurrency-sensitive suite (e.g. change-history-rollback) **standalone** to disambiguate (as done this session: 45/3 contended → 48/0 standalone).

### 6.4 Coverage-gap assertions (negative tests)
- **Bulk status** rollback actually restores status (was silent no-op).
- **Forms/plugin/theme update** that are irreversible return `reversible:false` + visible notice (no silent success).
- **User** rollback restores previously-uncaptured usermeta the op wrote.

### 6.5 Invariant guard each phase
`OPERATION_MAP=34 · capabilities=23 · catalogue=40 · MCP=40 · DB_VERSION=2.5.0` re-verified (static + `operations-registry`/`capability-runtime`/`mcp-error-surface` green). Any delta-record storage that would bump DB_VERSION stops the phase pending a schema check-in.

---

## 7. Implementation roadmap

Each phase = **design report → implement → self-audit → validate → report → update handoff/roadmap** (program rules). Sequenced extract-core-first, then risk × ease, with the hardest/structural cases later. Every phase ends locally; **deploy is a separate Rule-7 decision**.

| Phase | Scope | Why here | Schema? | Gate |
|---|---|---|---|---|
| **P4.0 — Delta core extraction** | Extract `RollbackDelta` + `FieldAccessor` from SEO; SEO becomes first consumer | keystone; write correctness once | none | **SEO re-scores 52/52** (behavior-preserving) |
| **P4.1 — Settings** | option-name field-delta | easiest; proves accessor on a non-entity, low risk | none (option record v2) | settings suite green + new S1–S9 |
| **P4.2 — Media-metadata** | title/alt/caption/description delta; **keep bytes on Pattern C** | selective restore exists; hybrid proves byte+delta coexistence | none (postmeta) | media suites green + S1–S9 |
| **P4.3 — Content** | title/status/content/excerpt (+meta) delta | high-traffic, moderate risk, clean fit | none (postmeta) | content-runtime 98/0 held + S1–S9 |
| **P4.4 — Comments** | content/approved (+commentmeta) delta | small clean surface | none (commentmeta) | comments suite green + S1–S9 |
| **P4.5 — User** | scalar fields + **roles-as-set** + extend usermeta capture | moderate; closes lossy capture | none (usermeta) | user suite green + S1–S9 + usermeta negative test |
| **P4.6 — Woo (products)** | product properties via WC accessor; **orders deferred** | HIGH risk, frequent layered edits | none (postmeta) | woo product suites green + S1–S9 |
| **P4.7 — ACF** | value_update delta; group/field config = **whole-def + drift-guard** | HIGH risk, hardest mapping | none | acf suites green + S1–S9 (config via drift-guard) |
| **P4.8 — Bulk redesign** | per-item delta records; **capture status**; bounded | VERY HIGH; needs redesign not port | none (per-item postmeta) | bulk suite green + status negative test + large-N bound |
| **P4.9 — G2 update visibility** | plugin/theme/Forms-update → visible `reversible:false` + guard, or captured artifact | coverage gap, independent track | none | plugin/theme/forms suites + irreversibility negative tests |
| **Gate (deploy-coupled)** | prod SEO token verify + **full serial T2** | closes Phase-3 residuals; pre-deploy stamp | — | net-new 0; prod functional verify |
| **Deferred (separate)** | A2-1 reaper (schema), Woo orders (relational) | trip schema/own-design | **yes (A2-1)** | own check-ins |

**Effort shape:** P4.0 is the highest-leverage single phase (unlocks all others cheaply). P4.1–P4.4 are fast (clean fits). P4.5–P4.7 are the substantive middle. P4.8 is a redesign. P4.9 is small but trust-critical. Order is adjustable, but **P4.0 must be first** and **ACF/Bulk last** among the delta conversions.

---

## 8. Open decisions for the owner (require direction, not assumption)
1. **Bulk record storage** — per-item postmeta (schema-free, recommended) vs a dedicated table (faster discovery, but **schema check-in**). Default: postmeta.
2. **ACF depth** — ship whole-definition+drift-guard now and defer true path-delta? (recommended) or invest in config-path parsing up front?
3. **G2 resolution** — visible-irreversible flag (cheap, immediate) vs captured pre-update artifact (true reversibility, more work)? Could ship flag first, artifact later.
4. **Woo orders** — confirm deferral to a separate sub-design.
5. **Comments scope** — full delta now, or lower priority given small risk?

None of the above changes invariants or requires schema **except** the optional Bulk-table choice and the separately-scheduled A2-1 reaper — both of which would trip the Rule-7 schema check-in before any code.

---

## 9. Summary
- **9 runtimes** carry the F-1 full-object pattern; **SEO is the proven delta reference**; **Patch/Media-bytes are the proven byte-snapshot reference**.
- The migration is **tractable** because the hard part (drift-aware delta correctness) is already written and gated — **extract it once (P4.0)**, then add small per-runtime accessors.
- Risk-ranked order: **Bulk > Woo > ACF > Content > User > Comments > Media-meta > Settings > Forms**, plus the **G2** update-visibility track.
- Target is **schema-free, invariant-frozen, legacy-compatible, validated per runtime with the SEO gate**, deploy decoupled.
- Two items stay **out**: A2-1 reaper (schema) and Woo-orders (relational) — separately scheduled.

**Recommended first action on approval:** produce the **P4.0 (delta core extraction) design report** — affected files (`SeoRuntimeManager`, `SeoProvider`, new `RollbackDelta`/`FieldAccessor`), behavior-preserving refactor plan, and the SEO-52/52 re-validation gate.
