# PROGRAM-4.1 — Settings Runtime Rollback Integrity · Design Report

> **Type:** design report (design-first; no code yet in this section). Autonomous mode.
> **Date:** 2026-06-23 · **Phase:** P4.1 of PROGRAM-4 Rollback Integrity Expansion. **Branch:** `program-4.1-settings-rollback` (stacked on `program-4a-p4.0-rollbackdelta-core` @ `2234dcc`).
> **Goal:** replace Settings full-object rollback with field-scoped, drift-aware `RollbackDelta` behaviour, reusing the P4.0 core. **No SEO regression.**
> **Constraints honoured:** only the Settings runtime + a new generic OptionAccessor are touched. No Woo/ACF/Content/User/Media/Bulk/Plugin/Theme; no schema/DB_VERSION/op/cap/MCP/REST/UI; no push/deploy.

---

## 1. Current state (verified in source)
`includes/Operations/SettingsRuntimeManager.php` (63 lines, compact). Six mutating actions (general/reading/discussion/media/permalink/privacy `_update`), each writing a set of WP options. Rollback today:
- `store_rollback($action, [], $cx)` is called from `run()` **after** the update method already ran; it ignores its `$before` arg and snapshots `get_option($opt)` for the action's **whole option group**, storing a full-object `before_state` record in option `wpcc_settings_rollbacks` (array, cap 200).
- `rollback()` resolves by id, writes every `before_state` option back via `update_option`, marks applied.
- Dispatched via `OperationExecutor::rollback`'s **public-method path** (handler has `rollback()`), audited as `operation.rollback.dispatched`.

### 1.1 Defect discovered (DEF-1) — capture happens AFTER the write
`run()` calls the update method (line 25, which does the `update_option` writes) **before** `store_rollback` (line 30), and `store_rollback` snapshots `get_option()` at line 61 — i.e. it records the **post-write** values. The stored `before_state` is therefore the *new* state, so **the current Settings rollback restores the just-written values — a no-op.** This is a genuine pre-existing correctness bug, independent of F-1. The P4.1 redesign fixes it by capturing **before** the write (mission explicitly permits fixing defects found during validation).

### 1.2 F-1 over-reach (DEF-2)
The snapshot captures the action's **entire** option group regardless of which options the call actually wrote (e.g. `permalink_update` only writes `permalink_structure`, but the snapshot map also lists `category_base`/`tag_base`). Restoring the whole group can clobber siblings changed by a later call. Field-scoped capture (only touched options) fixes this.

---

## 2. Design

### 2.1 New generic accessor — `OptionAccessor` (`includes/Rollback/OptionAccessor.php`)
Implements `FieldAccessor` over WP options; reusable by any option-backed runtime. For Settings, a field **is** its option name (1:1):
- `backing_keys(field)` → `[ field ]` (the option itself).
- `read_field($_, field)` → `get_option(field)` (drift LHS).
- `key_exists($_, key)` → sentinel test: `get_option(key, SENTINEL) !== SENTINEL` (distinguishes absent from present-but-empty/false — existence fidelity).
- `key_get($_, key)` → `get_option(key)`.
- `key_set($_, key, value)` → `update_option(key, value)`.
- `key_delete($_, key)` → `delete_option(key)`.
- `equals(field, current, after)` → scalar string compare (all Settings options are scalar; no structured field).
- `$entity_id` is unused (options are global) — callers pass `0`.

### 2.2 Touched-option mapping (the field-scoped unit)
A single source of truth `option_field_map($action)` → `[ option_name => payload_key ]`, mirroring **exactly** what each update method writes (verified line-by-line against `general/reading/discussion/media/permalink/privacy_update`):
| Action | option ← payload_key |
|---|---|
| general | blogname←site_title, blogdescription←tagline, admin_email←admin_email, WPLANG←language, timezone_string←timezone, date_format←date_format, time_format←time_format, start_of_week←week_start |
| reading | page_on_front←front_page, page_for_posts←posts_page, posts_per_page←posts_per_page, posts_per_rss←feed_limit, blog_public←search_visibility |
| discussion | default_comment_status, comment_moderation, require_name_email, comment_registration, avatar_default, thread_comments (each ← same key) |
| media | thumbnail_size_w/h, thumbnail_crop, medium_size_w/h, large_size_w/h (each ← same key) |
| permalink | permalink_structure←structure |
| privacy | wp_page_for_privacy_policy←privacy_page |
`touched_options($action, $payload)` = options whose payload_key is `isset()` in the payload (matching each method's `isset` guard). This captures **only** what the call writes (fixes DEF-2).

### 2.3 Rewritten `run()` flow (fixes DEF-1)
```
if (mutation):
    $touched = touched_options($action, $payload)
    $prior   = RollbackDelta::capture(new OptionAccessor(), 0, $touched)   // BEFORE write
$result = $this->$method($payload)
if ($result['error']): return WP_Error                                     // no record stored
if (mutation):
    $after = [ opt => get_option(opt) for opt in $touched ]                // post-write
    $rid   = store_rollback($action, $touched, $prior, $after, $cx)        // v2 delta record
    $result['rollback_id'] = $rid                                          // surface (was discarded)
    ...audit labels unchanged...
```

### 2.4 v2 record (in the SAME option, legacy-compatible)
`store_rollback` builds:
```
[ 'id'=>uuid, 'version'=>2, 'action'=>$action,
  'fields'=>[ option => [ 'after'=>v, 'keys'=>[ option => ['existed'=>bool,'prior'=>v] ] ] ],
  'rollback_applied'=>false, 'created_at'=>time(), 'session_id'=>…, 'task_id'=>… ]
```
Stored in `wpcc_settings_rollbacks` (array, cap 200) — **no new option, no schema**. Legacy `before_state` records remain in the same array and still restore.

### 2.5 Rewritten `rollback()` (drift-aware + legacy branch)
```
resolve record by id; guard rollback_applied
if record has 'fields' (v2):
    $o = RollbackDelta::restore(new OptionAccessor(), 0, $record['fields'])
    if $o.status == 'complete': mark applied; persist
    audit 'settings.restored' { path:'delta', status, restored_fields, skipped_fields }
    if complete: return success envelope
    else: return error envelope { code: wpcc_rollback_conflict|partial, status, restored/skipped }
else (legacy before_state):
    update_option each; mark applied; audit { path:'legacy' }; return success
```
This satisfies design goals 1–7 directly (field-scoped, drift-aware, sibling-preserving, out-of-order safe via drift skip, existed-vs-empty via OptionAccessor, partial/conflict ≠ clean success, legacy intact) and goal 8 (reuses the P4.0 core unchanged).

---

## 3. Affected files
**New:** `includes/Rollback/OptionAccessor.php`; `tests/test-settings-rollback-delta.sh`.
**Modified:** `includes/Operations/SettingsRuntimeManager.php` (run/store_rollback/rollback + new helpers).
**Unchanged (asserted by audit):** `RollbackDelta.php`, `FieldAccessor.php`, `PostMetaAccessor.php`, `SeoFieldAccessor.php`, SEO runtime, OperationExecutor (dispatch via existing `rollback()` signature), SettingsRegistry, OperationRegistry, CapabilityRegistry, McpServerRuntime, Schema, REST, UI.

---

## 4. Risks
| # | Risk | Sev | Mitigation |
|---|---|---|---|
| R1 | DEF-1 fix changes behaviour (rollback now actually reverts) | expected | It's the intended correction; the shallow REST test only checks route + nonexistent-id, unaffected; new delta test proves correct revert |
| R2 | `touched_options` diverges from what a method writes → false drift / missed capture | MED | Map verified line-by-line (§2.2); `isset` semantics match; tests per action |
| R3 | OptionAccessor existence detection for falsey option values | MED | sentinel-default `get_option`; empty-but-existing scenario test |
| R4 | Legacy `before_state` records stop restoring | MED | explicit legacy branch + legacy-record test (hand-built fixture) |
| R5 | SEO/core regression from shared-core reuse | MED | OptionAccessor is additive; run SEO suites + core unit; RollbackDelta untouched |
| R6 | permalink rollback doesn't re-`flush_rewrite_rules` | LOW | matches existing legacy behaviour (option value IS restored); documented residual |
| R7 | Storing a record when nothing was touched (empty payload) | LOW | empty `fields` → restore is a `complete` no-op; preserves rollback_id contract |
| R8 | Invariant drift | LOW | no op/cap/tool/schema touched; re-verify 34/23/40/40/2.5.0 |

---

## 5. Validation plan
**Design goals → tests** (new `tests/test-settings-rollback-delta.sh`, wp-eval, direct manager calls — no token needed):
- **S0 (DEF-1):** update changes blogname → rollback restores the **original** (proves capture-before-write; currently fails on old code).
- **S1 empty-prior:** set a previously-absent option → rollback **deletes** it.
- **S2 value-prior:** rollback restores exact prior value.
- **S3 disjoint sibling + drift:** A sets blogname+tagline; B sets tagline; rollback A → blogname restored, B's tagline **survives** (drift skip), status `partial`.
- **S4 same-field drift:** A then B set blogname; rollback A → **conflict**, B's value kept, status `conflict`.
- **S5 out-of-order:** rollback B then A → original, **no resurrection**.
- **S6 empty-but-existing:** option existing as '' → update → rollback restores the empty row (not delete).
- **S7 repeated rollback:** second attempt guarded (`wpcc_rb_done`).
- **S8 partial/conflict ≠ clean success:** S3/S4 assert `restored=false`/error envelope, not `complete`.
- **S9 legacy record:** hand-built `before_state` record restores via legacy path.
- **Static:** OptionAccessor exists + primitives; SettingsRuntimeManager uses `RollbackDelta::capture/restore` + `OptionAccessor`; `version=>2`; capture-before-write ordering.

**Regression / required suites:**
- `test-site-settings-runtime.sh` (REST; may be token/env-limited — classify if so).
- `test-rollback-delta-core.sh` (25/0 must hold), `test-seo-rollback-delta.sh` (56/0 must hold — no SEO regression), seo store/apply/undo.
- `test-change-history-rollback.sh` standalone (48/0 — dispatcher path).
- `test-operations-registry.sh` / `test-capability-runtime.sh` / `test-mcp-error-surface.sh` (parity).
- Invariants 34/23/40/40/2.5.0 re-verified.

**Gate:** all behaviour goals proven; SEO/core unchanged; invariants held; any red root-caused + classified (attributable / non-attributable / environmental). Fix-in-scope + re-run on failure (Rule 5).

---

## 6. Decision
Proceed to implement on `program-4.1-settings-rollback`. The redesign fixes two pre-existing defects (DEF-1 capture-ordering no-op; DEF-2 group over-reach) while delivering the field-scoped, drift-aware contract via the P4.0 core — and is schema-free, invariant-frozen, legacy-compatible.
