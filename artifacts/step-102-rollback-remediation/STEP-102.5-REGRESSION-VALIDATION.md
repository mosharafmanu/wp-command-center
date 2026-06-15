# STEP 102.5 — Rollback Remediation Regression Validation

**Date:** 2026-06-15
**Goal:** Verify STEP 102 rollback remediation introduced no regressions, and that the rollback contract is consistent across write-capable runtimes.
**Mode:** DEV only. Full Create → Verify → Rollback → Verify-Restore lifecycle per runtime. All assets cleaned up (verified). No scope expansion, no new features.

## Verdict: **PASS WITH RISKS**

STEP 102 introduced **no regressions**: all 5 previously-passing runtimes still pass, all 6 remediated runtimes surface `rollback_id` + `rollback_available` and execute rollback, and audit + timeline are intact. **13/14 runtimes fully pass.** The one risk — **ACF group rollback does not restore state** — is a **pre-existing defect unrelated to STEP 102** (STEP 102 never touched ACF's rollback logic), newly exposed because the rollback path is now reachable.

## Results: 13 PASS / 1 FAIL (14 runtimes)

### Group 1 — Previously failing (remediated in 102)

| Runtime | rollback_id | rollback_available | executable | restored | Verdict |
|---|---|---|---|---|---|
| Content | ✅ | ✅ | ✅ (`content_rollback`) | ✅ | **PASS** |
| Menu | ✅ | ✅ | ✅ (REST) | ✅ | **PASS** |
| ACF | ✅ | ✅ | ✅ (REST) | ❌ | **FAIL** (restore — see F-4) |
| User | ✅ | ✅ | ✅ (REST) | ✅ | **PASS** |
| WooCommerce | ✅ | ✅ | ✅ (REST) | ✅ | **PASS** |
| Settings | ✅ | ✅ | ✅ (REST) | ✅ | **PASS** |

### Group 2 — Previously passing (no regression)

| Runtime | handle | executable | restored | Verdict |
|---|---|---|---|---|
| Option | rollback_id ✅ + available ✅ | ✅ (`option_rollback`) | ✅ | **PASS** |
| Media | rollback_id ✅ + available ✅ | ✅ (REST) | ✅ | **PASS** |
| Snapshot | snapshot_id ✅ (verify valid) | ✅ (`snapshot_restore`) | ✅ | **PASS** |
| Patch | rollback_id ✅ | ✅ (`rollback_manage`) | ✅ (file on disk) | **PASS** |
| Workflow | execution_id ✅ | ✅ (`workflow_rollback`) | ✅ | **PASS** |

### Group 3 — Contract consistency (shared-cover runtimes, first exercised end-to-end)

| Runtime | rollback_id | rollback_available | executable | restored | Verdict |
|---|---|---|---|---|---|
| Forms (CF7) | ✅ | ✅ | ✅ (REST) | ✅ | **PASS** |
| Site Builder | ✅ | ✅ | ✅ (REST) | ✅ | **PASS** |
| CPT | ✅ | ✅ | ✅ (REST) | ✅ | **PASS** |

> Forms/SiteBuilder/CPT were "shared-cover (not re-verified)" after STEP 102; this step confirms the shared `RollbackContext` surfacing **does** propagate to them and their rollback round-trips work. Comments (no comment fixture on dev), Elementor (no Elementor page — F-4 from 101.2), and Widgets (sidebar fixture) were not exercised end-to-end; they inherit the same surfacing mechanism.

## 5. rollback_id / rollback_available

Confirmed present on **every** write that stores a rollback, across all three groups (14/14 write responses carried both fields, or the runtime's native handle for Snapshot/Patch/Workflow). Reads (`content_list`, `system_info`, `plugin_list`) carry **neither** field — no spurious injection. The shared contract is consistent.

## 6. Audit trail & Timeline

- **Audit trail: ✅** — `report_agent_activity` (operations recorded), `report_patch_activity`, `report_approval_activity` all returned populated structured reports.
- **Timeline: ✅** — `GET /agent/timeline` returns operation entries (type `operation`, status `completed`).

## Risk — F-4: ACF group rollback does not restore (pre-existing, NOT a 102 regression)

**Severity:** MEDIUM. **Regression introduced by STEP 102:** NO.

**Reproduction (deterministic, 3/3):** `acf_manage acf_group_create` → `acf_group_update {title:"X RENAMED"}` (returns `rollback_id` + `rollback_available`) → REST `/operations/acf_manage/rollback` (returns `{"action":"acf_rollback"}` success) → `acf_group_get` shows title **still "X RENAMED"** (not restored).

**Root cause (code, untouched by STEP 102):** `ACFRuntimeManager::group_update()` captures `before_state = $this->summarize_group($g)`, which is lossy — `summarize_group()` returns `['key','title','active','location'=>count(...),'field_count'=>0]`, collapsing `location` to an integer and dropping the post `ID` and other fields. `rollback()` then calls `acf_update_field_group($before)` with that malformed summary, which cannot faithfully restore the group. The rollback reports success regardless (its return value isn't checked).

**Why STEP 102 reported ACF PASS:** STEP 102's single ACF check used `"RENAMED" not in <get response>`; the lossy restore produced a corrupted/empty read, yielding a **false-positive** "restored". The 102.5 deterministic 3× probe exposed the true behavior. STEP 102's surfacing/executability claims for ACF stand; only the restore-correctness claim was a false positive.

**Suggested fix (one line, rollback-lifecycle only — NOT applied here per "do not expand scope"):** in `ACFRuntimeManager::group_update()`, store the full group as before-state — `$before = $g;` instead of `$before = $this->summarize_group($g);` — so `rollback()`'s existing `acf_update_field_group($before)` receives a complete group. Recommend applying + re-verifying ACF in a small follow-up (102.6), not in this validation step.

## Conclusion

- **No regression from STEP 102.** Previously-passing runtimes: 5/5 PASS. Remediated runtimes: surfacing + executability 6/6 PASS. Shared-cover runtimes confirmed: 3/3 PASS. Audit + timeline intact.
- **One pre-existing risk (F-4): ACF group rollback restore is broken** due to a lossy before-state, independent of STEP 102. One-line fix identified; deferred to a follow-up to honor the no-scope-expansion instruction.

**Final verdict: PASS WITH RISKS.**

Evidence: `regression-results-102.5.json`.
