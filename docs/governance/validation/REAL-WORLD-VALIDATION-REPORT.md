# Phase B — Real-World Validation Report (10-Category Matrix)

> **Program:** Phase 3 Acceptance Gate + Real-World Validation (autonomous mode).
> **Date:** 2026-06-23 · **Env:** DEV (AMPPS), PHP 8.2.27, wp-cli live, Rank Math active, security mode developer.
> **Method:** for each category — (1) code-grounded *expected behavior* (handler + op/MCP mapping + rollback pattern + governance path), (2) *actual behavior* from live suite execution this session, (3) gaps, (4) risks, (5) recommendations.
> **Constraints honored:** no commit / push / deploy / AI-enable / mode change / schema change.
> **Aggregate this session:** ~**1468 assertions passed**, **0 attributable failures**, **7 environmental** (4 Yoast-vs-RankMath in step91, 3 concurrency-contended backfill counts in change-history-rollback — both classified NON-ATTRIBUTABLE).

---

## How to read the matrix
- **Governed?** = routes through the single `OperationExecutor::run` chokepoint (capability check → approval gate → destructive guard → execute → audit → change record). ✅ for all mutating categories.
- **Rollback pattern:** `field-delta` (SEO, Phase 3 — best), `file-snapshot+verify` (Patch/Media bytes — strong), `full-object before_state` (the latent **F-1 pattern**), `state-only`, or `none`.

---

## 1. Content operations
- **Handler / op:** `ContentManager` → `content_manage` (MCP `content_manage`). Actions: list/get/create/update/delete/publish/unpublish/schedule/taxonomy_assign/featured_image_assign/content_rollback.
- **Expected:** governed CRUD; pre-write `before_state` snapshot (title/status/content/excerpt); delete behind DestructiveGuard.
- **Actual:** `test-content-runtime.sh` **98 / 0**. Create/update/delete/publish round-trips + rollback all pass. Governed via OperationExecutor; delete classified destructive.
- **Gaps:** rollback is **full-object `before_state`** (F-1 pattern). Single-object/single-change rollback is correct; **layered** field-by-field edits across multiple changes carry the same sibling-loss exposure SEO just fixed.
- **Risks:** MEDIUM (latent) — materializes only under layered rollbacks of the same post's distinct fields.
- **Recommendation:** include Content in the systemic F-1 delta rollout (after SEO), prioritized by how often posts are edited field-wise over time.

## 2. Media operations
- **Handler / op:** `MediaRuntimeManager` + `MediaSnapshot` + `MediaEnhancementRuntimeManager` → `media_manage`, `media_enhance`. Actions incl. update/replace/delete/restore/regenerate + snapshot create/restore/verify.
- **Expected:** file-mutating ops (replace, enhance, regenerate) backed by **byte-level snapshots** with verify; metadata edits captured for rollback; delete guarded.
- **Actual:** `test-media-runtime-step90.sh` **25 / 0**, `test-media-snapshot-step100-1.sh` **23 / 0**. Byte-for-byte snapshot capture/restore/verify confirmed.
- **Gaps:** file bytes use the strong **snapshot+verify** pattern; **metadata** edits (alt/caption/title/description) use **full-object `before_state`** (F-1 pattern at the meta level).
- **Risks:** MEDIUM (latent) — file path is safe; metadata path shares the F-1 layered-rollback exposure.
- **Recommendation:** keep the file-snapshot path; bring media **metadata** rollback onto the field-delta pattern during systemic F-1.

## 3. File operations (File / Code-search / Patch)
- **Handler / op:** `FileManager` (read), `CodeSearchOperation` (read), `PatchOperation` (write), `RollbackOperation` → `file_manage`, `code_search`, `patch_manage`, `rollback_manage`.
- **Expected:** read ops side-effect-free; patch apply snapshots each affected file (hash pre/post), auto-reverts on verify fail; `PatchGuard` blocks header-stripping; high-risk paths behind DestructiveGuard.
- **Actual:** `test-file-read-search.sh` **39 / 0**, `test-file-patch-bridge.sh` **37 / 0**, `test-patch-changesets.sh` **62 / 0**. Hash-verified snapshot + auto-revert + guard all pass.
- **Gaps:** **none identified.** This is the reference implementation for reversibility (per-file snapshot + verification).
- **Risks:** LOW.
- **Recommendation:** treat the Patch snapshot+verify model as the canonical pattern; reuse its discipline for the F-1 delta work.

## 4. Plugin operations
- **Handler / op:** `PluginManager` → `plugin_manage`. Actions: list/install/activate/deactivate/update/delete/rollback.
- **Expected:** mutations HIGH-risk/approval-gated; delete CRITICAL (phrase + reason + backup); reversibility for state changes.
- **Actual:** `test-plugin-runtime.sh` **58 / 0**. activate/deactivate store `before_state` (previous active slug) → reversible; delete backs up the folder + guard.
- **Gaps:** **`plugin_update` has NO rollback** (verified at `PluginManager.php:243–297`): it captures `$was_active` for reactivation and audits start/fail, but stores **no version snapshot and no rollback_id**. The update is **irreversible by the engine and not flagged as irreversible** in the response.
- **Risks:** **MEDIUM-HIGH** — a governed plugin update that breaks a site cannot be undone through WPCC; this is a silent breach of the "reversible *or visibly* irreversible" guarantee.
- **Recommendation:** either (a) capture the pre-update plugin ZIP/version as a rollback artifact, or (b) explicitly mark `plugin_update` irreversible in the result + require an acknowledgement (DestructiveGuard-style), so irreversibility is *visible*, not silent. Track as a named gap (G2).

## 5. Theme operations
- **Handler / op:** `ThemeManager` → `theme_manage`. Actions: list/install/activate/update/delete/rollback.
- **Expected:** mirror of plugin governance; delete only for inactive themes; activate reversible.
- **Actual:** `test-theme-runtime.sh` **77 / 0**. activate stores previous-slug `before_state`; delete guarded + inactive-only.
- **Gaps:** **`theme_update` has NO rollback** (`ThemeManager.php:185`, no `store_rollback` in the update path) — identical to plugin_update.
- **Risks:** **MEDIUM-HIGH** — same silent-irreversibility breach as G2.
- **Recommendation:** same as plugin (G2): snapshot the pre-update theme or surface visible irreversibility.

## 6. Approval workflows
- **Handler / op:** `SecurityModeManager` + `OperationManager` + `ApprovalRuntimeManager` → `approval_manage`. Request/queue/results lifecycle.
- **Expected:** mode-aware gating (developer = none; client = medium+ ; enterprise = all non-diagnostic); human-approver guard (no token self-approval); execute-once + atomic claim.
- **Actual:** `test-approval-enforcement.sh` **16 / 0**, `test-approval-center.sh` **127 / 0**, `test-security-modes.sh` **28 / 0**, `test-operation-requests.sh` **16 / 0**. Mode gating, human-approver guard, atomic single-winner claim (A-1), execute-once (B2-2), execution-truth (B2-1) all pass.
- **Gaps:** **A2-1 uncatchable-fatal reaper** still deferred — OOM/timeout/process-kill can strand a request in `executing` (uncatchable by try/catch); fix needs a `claimed_at` column = schema migration.
- **Risks:** MEDIUM (narrow) — only under hard process death mid-execution.
- **Recommendation:** schedule the schema-bearing reaper independently (it trips the Rule-7 schema check-in); not part of F-1.

## 7. MCP workflows
- **Handler / op:** `McpServerRuntime` → `tools_list` / `tools_call` over all 40 operations (1:1 tools).
- **Expected:** tool failures surfaced as `{isError, code, message}` (STEP 89, not raw JSON-RPC); transport errors stay JSON-RPC; per-call time budget (~200s < client 240s) prevents silent partial writes; every tool call routes through OperationExecutor governance.
- **Actual:** `test-mcp-approval-runtime.sh` **25 / 0**, `test-mcp-error-surface.sh` **18 / 0**. Error-surface + REST parity + approval surfacing pass. Catalogue/MCP parity = 40 confirmed at runtime.
- **Gaps:** none functional. (Note: live MCP responses keep file `contents` while audit storage strips them — intended redaction, not a gap.)
- **Risks:** LOW.
- **Recommendation:** none beyond keeping tool/op parity = 40 invariant under future additions.

## 8. Agent workflows
- **Handler / op:** `AuthTokens` (scope `read_only` | `full`, salted-hash manifest) + `AuditLog` + agent REST/MCP surface. Capability bootstrap per scope at token creation.
- **Expected:** token-only auth (no cookies); capability-scoped; every agent action attributed (actor = token) and audited; tokens may *request* but not *approve* under client/enterprise.
- **Actual:** `test-agent-actions.sh` **85 / 0**, `test-agent-review.sh` **35 / 0**. Token scoping, capability enforcement, actor attribution, request-not-approve guard all pass.
- **Gaps:** none net-new. (Prod **token-gated functional verify** remains the one production-only check — out of scope here.)
- **Risks:** LOW in DEV; production token-path remains formally unverified (deploy-coupled).
- **Recommendation:** run the prod token-gated verify when a deploy decision is authorized.

## 9. Rollback workflows
- **Handler / op:** unified `OperationExecutor::rollback` dispatcher (`ACTION_ROLLBACKS` map) + `RollbackOperation` + `ChangeHistoryRuntimeManager::rollback_target` (no bypass; legacy `rollback.php` removed). STEP 102 surfaces `rollback_id` **uniformly** via `normalize_success` + `RollbackContext::last()` (`OperationExecutor.php:756–766`).
- **Expected:** one governed reversal path; per-runtime restore; rollback itself approval/capability/destructive-gated.
- **Actual:** `test-seo-rollback-delta.sh` **52 / 0**, `test-seo-rollback-store.sh` **28 / 0**, `test-seo-undo.sh` **33 / 0**, `test-workflow-rollback-f61.sh` **16 / 0**, `test-media-snapshot-step100-1.sh` **23 / 0**, `test-patch-changesets.sh` **62 / 0**, `test-change-history-rollback.sh` sections 1–9 (rollback_discover, rollback_target runtime+patch, failure handling, approval-aware, destructive guard, read-only denial, MCP/REST parity) **PASS** (the 3 reds are Section-0 backfill counts contended by concurrent load — see classification).
- **Gaps:** **F-1 systemic (HIGH, OPEN).** Verified: **full-object `before_state`** rollback remains in `ContentManager`, `WooCommerceRuntimeManager`, `SettingsRuntimeManager`, `ACFRuntimeManager`, `UserManager`, `FormsRuntimeManager`, `CommentsRuntimeManager`, `BulkRuntimeManager`, and Media-metadata. **SEO is the only runtime converted to field-delta.** Plus the plugin/theme `*_update` no-rollback gap (G2). `rollback_id` surfacing (old F-2/F-3) appears **resolved** by STEP 102 — recommend a per-runtime confirmation test rather than treating it as open.
- **Risks:** **HIGH (latent, broad)** — every full-object runtime carries the sibling-loss / out-of-order-resurrection exposure that SEO's fix proves is real; severity scales with layered field-wise editing.
- **Recommendation:** systemic F-1 delta rollout, sequenced **Media-metadata → ACF → Woo → Settings/Content** (most layered-edit-prone first); reuse the SEO delta contract + Patch verify discipline. This is the program's centerpiece.

## 10. Audit workflows
- **Handler / op:** `AuditLog` (append-only JSONL + rotation) + `ChangeRecorder` → `wpcc_change_log` + `ChangeHistoryRuntimeManager` (read) → `change_history`.
- **Expected:** every operation recorded with actor (human/system/agent), op id, status, reversibility, rollback handle; no direct audit inserts outside the engine; change history read-only.
- **Actual:** `test-audit-log.sh` **19 / 0**, `test-change-history-runtime.sh` **57 / 0**. Append-only recording, status/reversible flags, actor attribution, read-layer queries all pass. SEO events now carry `rollback_format: delta` / restore `path` + `status`.
- **Gaps:** none functional. Backfill idempotency assertions are concurrency-fragile (test-quality, not product).
- **Risks:** LOW.
- **Recommendation:** harden the backfill test's count assertions against concurrent inserts (quiesce or count-delta tolerance) — test hygiene only.

---

## Summary table

| # | Category | Suites (passed/failed) | Governed | Rollback pattern | Top gap | Risk |
|---|---|---|---|---|---|---|
| 1 | Content | content-runtime 98/0 | ✅ | full-object | F-1 latent | MED |
| 2 | Media | 48/0 (90 + snapshot) | ✅ | file-snapshot+verify / meta full-object | meta F-1 | MED |
| 3 | File/Patch | 138/0 | ✅ | file-snapshot+verify | none | LOW |
| 4 | Plugin | 58/0 | ✅ | state-only / **update: none** | **G2 update no rollback** | MED-HIGH |
| 5 | Theme | 77/0 | ✅ | state-only / **update: none** | **G2 update no rollback** | MED-HIGH |
| 6 | Approval | 187/0 | ✅ | n/a (gate) | A2-1 reaper (schema) | MED |
| 7 | MCP | 43/0 | ✅ | via executor | none | LOW |
| 8 | Agent | 120/0 | ✅ | via executor | prod verify pending | LOW |
| 9 | Rollback | 214/0 + ch §1–9 | ✅ | mixed | **F-1 systemic (HIGH)** | HIGH |
| 10 | Audit | 76/0 | ✅ | append-only | test hygiene | LOW |

**Every category is governed through the single chokepoint. Every category's functional suites pass. No attributable failure anywhere.** The open risk is concentrated in **reversibility breadth** (F-1 systemic + plugin/theme update), not in correctness of what exists today.

---

## Classification of all non-green observations
| Observation | Class | Disposition |
|---|---|---|
| step91 4 reds (provider/Yoast meta) | NON-ATTRIBUTABLE / ENVIRONMENTAL | Rank-Math env vs Yoast-authored test; clean-room proven pre-existing. No fix (Rule 5 scope: test hygiene, not directed). |
| change-history-rollback 3 backfill reds (79702 vs 79720) | NON-ATTRIBUTABLE / ENVIRONMENTAL | Concurrent batch inserted ~18 change_log rows mid-count; rollback Sections 1–9 all pass. Re-validated standalone (see RUNNING-STATE). |
| change-history-rollback >7min | ENVIRONMENTAL (runtime budget) | One-time 74k-row backfill; not a failure. |
| full 137-suite serial T2 not completed | ENVIRONMENTAL / OUT-OF-SCOPE | Multi-hour serial; deploy-coupled; attributable subset clean. |

No ATTRIBUTABLE failures. No code fix was required or made.
