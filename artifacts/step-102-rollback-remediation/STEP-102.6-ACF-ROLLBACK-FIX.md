# STEP 102.6 — ACF Rollback Restoration Fix (F-4)

**Date:** 2026-06-15
**Scope:** Fix ACF group rollback restoration only. Smallest correct fix. No scope expansion, no unrelated runtimes, no rollback-infrastructure refactor. DEV verification only; all assets cleaned up.

## Verdict: **PASS**

ACF group rollback now restores the original group exactly — title, location rules, and field definitions — verified 3/3 deterministic runs, with `rollback_id` + `rollback_available` present and audit + timeline preserved.

---

## 1. Root cause confirmed

`ACFRuntimeManager::group_update()` (line 93) captured the rollback before-state as `$before = $this->summarize_group($g)`. `summarize_group()` returns a **lossy** representation:
```php
[ 'key'=>…, 'title'=>…, 'active'=>…, 'location'=> count($g['location']??[]), 'field_count'=>0 ]
```
— `location` collapsed to an **integer count**, and the post `ID` and all other group settings dropped. `rollback()` (line 614) then called `acf_update_field_group($before)` with that malformed summary, which could not faithfully restore the group (the `location` int is invalid; without proper structure the title/location were not reverted). The rollback returned success regardless, producing a silent no-op restore. Confirmed deterministic in STEP 102.5 (3/3 not restored).

## 2. Files modified

| File | Change |
|---|---|
| `includes/Operations/ACFRuntimeManager.php` | `group_update()`: capture the **complete** original group as before-state (`$before = $g;`) instead of `summarize_group($g)`. |

1 file, 6 insertions / 1 deletion (the change + an explanatory comment). `php -l` clean.

## 3. Fix implemented

```php
$g = acf_get_field_group( $id );
if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', … );
// STEP 102.6 (F-4): store the COMPLETE original group as the rollback before-state.
// summarize_group() was lossy (location -> int count, no post ID), so
// rollback()'s acf_update_field_group( $before ) could not faithfully restore.
// $g is the unmutated original here (the title/active edits below copy-on-write
// into $g, not $before), so this preserves the full group for exact restoration.
$before = $g;
if ( isset( $p['title'] ) ) $g['title'] = sanitize_text_field( (string) $p['title'] );
```

Why this is the smallest correct fix:
- `$before` is captured **before** the in-place edits to `$g`; PHP array copy-on-assignment means later `$g['title'] = …` does not mutate `$before`. So `$before` retains the full original group.
- The existing `rollback()` arm already does `acf_update_field_group($before)` — now it receives a complete, valid group and restores exactly. **No change to `rollback()`, `store_rollback()`, the rollback contract, audit, or timeline.**
- `summarize_group()` itself is untouched (still used by read actions `acf_group_get`/`acf_group_list` where a summary is appropriate).

## 4. Verification results (3× deterministic)

Lifecycle per run: **Create group (+location rule) → add field → Verify → Update (title + location 1→2 OR-groups) → Verify changed → Rollback → Verify restore.**

| Run | title (orig→upd→restored) | location OR-groups (orig→upd→restored) | fields (orig→restored) | rollback_id | rollback_available | restored | Verdict |
|---|---|---|---|---|---|---|---|
| 0 | `WPCC ACF Fix 0` → `RENAMED 0` → `WPCC ACF Fix 0` | 1 → 2 → 1 | 1 → 1 | ✅ | ✅ | ✅ | **PASS** |
| 1 | `WPCC ACF Fix 1` → `RENAMED 1` → `WPCC ACF Fix 1` | 1 → 2 → 1 | 1 → 1 | ✅ | ✅ | ✅ | **PASS** |
| 2 | `WPCC ACF Fix 2` → `RENAMED 2` → `WPCC ACF Fix 2` | 1 → 2 → 1 | 1 → 1 | ✅ | ✅ | ✅ | **PASS** |

Confirmed for every run:
- **Field group restored correctly** — title reverts to the original.
- **Location rules restored correctly** — OR-group structure reverts 2 → 1 (the update added a second OR-group; rollback removed it).
- **Field definitions restored correctly** — the text field remains present (count 1) and intact through the group update + rollback (group-level rollback does not disturb fields).
- **No corruption** — `acf_group_get` and `acf_field_list` succeed after rollback; the group is readable and well-formed.
- **rollback_id present** ✅ and **rollback_available** ✅ on the `acf_group_update` response.
- **Audit trail preserved** ✅ — `report_agent_activity` returns populated operation activity (executor audit path unchanged).
- **Timeline preserved** ✅ — `GET /agent/timeline` returns operation entries.

All validation groups were deleted after each run; residual-state check confirms **no leftover ACF groups**.

Evidence: `acf-rollback-verification.json`.

## 5. Remaining rollback issues

**None.** ACF group rollback now restores exactly. With this fix, the regression matrix from STEP 102.5 (13/14) becomes **14/14** for the runtimes validated end-to-end. The shared `RollbackContext` surfacing (STEP 102) and the per-runtime rollback paths remain intact and unchanged.

**Final verdict: PASS.**

(Note: changes for STEP 102 / 102.5 / 102.6 remain local on DEV — not yet committed or deployed.)
