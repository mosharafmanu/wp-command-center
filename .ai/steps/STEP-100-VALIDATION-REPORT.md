# STEP 100 — Real-World Validation Report (Media Subsystem)

**Executed:** 2026-06-15 · **Plan:** `STEP-100-REAL-WORLD-VALIDATION.md`
**Evidence:** `artifacts/step-100-validation/` (61 files)
**Status:** validation executed, environment restored, **no code changed, nothing
deployed.**

---

## 0. Executive summary

The complete STEP 100 media subsystem (100.1 → 100.9) was exercised end-to-end with
**live REST + MCP calls** against a real multi-plugin WordPress install
(WooCommerce, ACF Pro, Elementor Pro, Yoast, classic-editor, GD+WebP). 42 seeded
attachments spanning Core / WooCommerce / ACF / Elementor / Theme were classified,
and the destructive + reversible write paths were driven and rolled back with
byte-for-byte verification.

**Headline results — evidence, not assumptions:**

- **Classification correctness: 41/41 PASS, 0 failures.** Every protected,
  genuinely-referenced image (featured, blocks, classic, private, Woo featured/
  gallery/variation, ACF image/gallery/repeater/flexible/options, Elementor widget/
  background/template, custom_logo/site_icon/site_logo/theme_mod/CSS) classified
  `active` or `indirect` — **never `unused`.**
- **Zero false positives among protected items** in `unused_media_find` (gate G1). ✅
- **All 5 documented blind spots REPRODUCED** (WooCommerce category term-meta, Woo
  placeholder option-ID, ACF Gutenberg-block field, Elementor global kit, file-only
  hardcoded URL). ✅ (expected, mitigated, backlog)
- **1 NEW undocumented false-positive vector found:** legacy `ids`-only Gutenberg
  **gallery block** format (no inner image markup) → `unused`. Modern (5.9+) nested
  gallery format is detected correctly. ⚠️ (reversible; reported below)
- **Reversibility: byte-for-byte PASS** for thumbnail regenerate, WebP generate,
  image optimize, and cleanup (trash) — combined md5 identical pre-op vs
  post-rollback; sidecars/temp files cleaned; dimensions preserved.
- **Cleanup is never permanent:** `permanently_deleted:false` in every case; row +
  bytes always recoverable; DestructiveGuard handshake enforced; read tokens
  rejected.
- **Regression:** 100.8 suite 40/40, 100.9 suite 31/31 — green.

**Verdict:** **SAFE FOR STAGING** · **NOT SAFE FOR PRODUCTION** (conditions in §11).

---

## 1. Environment (artifacts/env/environment.txt)

| Item | Value |
|---|---|
| Site | `http://localhost/ClientProjects/WordPress/2026/plugins-dev` (**dev site**) |
| Theme | hello-elementor |
| Plugins | WooCommerce, ACF Pro, Elementor + Elementor Pro, Yoast, classic-editor, contact-form-7, svg-support, +others |
| Image lib | GD **yes**, `imagewebp` **yes**, Imagick no → `webp_encode:true` |
| Tokens | full token (write) + a minted read-only token (revoked after test) |
| Security mode | developer (production parity noted in §11) |

**Deviation from plan §2:** No separate staging *clone* exists in this environment;
validation ran on the **dev site**. Mitigated by: (a) all content seeded with a
unique `wpcc-v100-` prefix, (b) full teardown executed and verified (0 residue), (c)
destructive cleanup run **only** on seeded throwaway items, (d) WPCC snapshot/
rollback option stores backed up before and reset after. Site identity settings
(site_icon/site_logo/custom_logo/header_image/`woocommerce_placeholder_image`)
captured and restored — `woocommerce_placeholder_image` confirmed back to `110`.

---

## 2. Tests executed (by scenario)

### 2.A WordPress Core
| ID | Scenario | Result |
|---|---|---|
| A1 | Normal upload → regenerate(all)+webp+optimize+rollback | **PASS** (byte-for-byte) |
| A2 | Featured image → `active`, cleanup refused | **PASS** |
| A3 | Gutenberg image block (`attrs.id`) → `active` | **PASS** |
| A3 | Gutenberg **gallery** block (modern nested) → `active` | **PASS** |
| A3 | Gutenberg **gallery** block (legacy `ids`-only) → `unused` | ⚠️ **NEW FALSE POSITIVE** |
| A4 | Classic content (`wp-image-N` + bare URL) → `active` | **PASS** |
| A5 | Draft-only reference → `indirect`, not candidate, refused | **PASS** |
| A6 | Revision-only reference → `indirect` (source `revision`), refused | **PASS** |
| A7 | private→`active`, pending→`indirect` | **PASS** |
| A8 | Genuinely-unused → cleanup → trashed reversibly → rollback | **PASS** |
| A9 | Orphan (original missing) detection + cleanup behavior | **PASS** (see §7 deviation) |
| A10 | Manual-restore reconciliation | **PASS** |

### 2.B WooCommerce
| ID | Scenario | Result |
|---|---|---|
| B1 | Product featured: published→`active`, draft→`indirect`, private→`active`; refused | **PASS** |
| B2 | Product gallery (`_product_image_gallery`) → `active` | **PASS** |
| B3 | Variation images (`product_variation` `_thumbnail_id`) → `active` | **PASS** |
| B4 | **Product category thumbnail (term meta)** → `unused` | ⚠️ **BLIND SPOT REPRODUCED** |
| B5 | **`woocommerce_placeholder_image` (option ID)** → `unused` | ⚠️ **BLIND SPOT REPRODUCED** |

### 2.C ACF Pro
| ID | Scenario | Result |
|---|---|---|
| C1 | Image field → `active` | **PASS** |
| C2 | Gallery field (serialized) → `active` | **PASS** |
| C3 | Repeater image sub-field → `active` | **PASS** |
| C4 | Flexible-content image → `active` | **PASS** |
| C5 | Options-page image → `active` (source `acf_options`), refused | **PASS** |
| C6 | **ACF Gutenberg-block image field** → `unused` | ⚠️ **BLIND SPOT REPRODUCED** |

### 2.D Elementor
| ID | Scenario | Result |
|---|---|---|
| D1 | Image widget (`"id":N`) → `active` | **PASS** |
| D2 | Section background image (`"background_image":{"id":N}`) → `active` | **PASS** |
| D3 | Template (`elementor_library` `_elementor_data`) → `active` | **PASS** |
| D4 | **Global kit (`_elementor_page_settings`)** → `unused` | ⚠️ **BLIND SPOT REPRODUCED** |

### 2.E Theme / Customizer
| ID | Scenario | Result |
|---|---|---|
| E1 | custom_logo → `active`, refused (+ exclusion) | **PASS** |
| E2 | site_icon → `active`, refused (+ exclusion) | **PASS** |
| E3 | site_logo → `active`, refused (+ exclusion) | **PASS** |
| E4 | theme_mod header image (URL) → `active` | **PASS** |
| E5 | option-stored URL (widget blob) → `indirect` | **PASS** |
| E6a | Additional CSS (`custom_css` post) URL → `active` | **PASS** |
| E6b | **File-only hardcoded URL** → `unused` | ⚠️ **BLIND SPOT REPRODUCED** |

### 2.F Cross-cutting / action stress
| Action | Conditions tested | Result |
|---|---|---|
| `media_usage_scan` | non-attachment → `wpcc_media_not_found` | **PASS** |
| `media_usage_report` | library aggregate: 173 scanned / 99 active / 5 indirect / 69 unused / 2 orphaned / 69 candidates | **PASS** |
| `unused_media_find` | 0 false positives among 30 protected items | **PASS** |
| `orphaned_media_find` | seeded orphan present | **PASS** |
| `unused_media_cleanup` | no-confirm/wrong-phrase → `confirmation_required`; active/draft/revision/woo/logo/icon → `wpcc_media_cleanup_refused`; non-attachment → `wpcc_media_not_found` | **PASS** |
| `rollback` | byte-for-byte for thumbnail/webp/optimize/cleanup; idempotent no-op on 2nd call; manual-restore reconcile | **PASS** |
| `webp_generate` | GIF → `wpcc_webp_unsupported_mime`; cap off → `wpcc_image_lib_unavailable`; originals md5 unchanged; rollback deletes sidecars | **PASS** |
| `image_optimize` | GIF → `wpcc_optimize_unsupported_mime`; cap off → `wpcc_image_lib_unavailable`; dimensions preserved; byte-for-byte rollback; no temp leftovers | **PASS** |
| `thumbnail_regenerate` | mode=all → 8 sizes; byte-for-byte rollback | **PASS** |
| Token scope | read token: read OK; write (`webp_generate`) → `wpcc_insufficient_scope`; destructive (`cleanup`) → `wpcc_insufficient_scope` | **PASS** |
| MCP parity | cleanup + rollback via `/mcp` tools/call | **PASS** |

---

## 3. Tests skipped / partial

| Item | Reason |
|---|---|
| Dedicated staging **clone** | Not available; ran on dev site with full teardown (§1). |
| Elementor theme-builder via real UI | Seeded `_elementor_data` / `_elementor_page_settings` directly (functional equivalent to what the resolver scans). Real Pro theme-builder header/footer would be `elementor_library` + `_elementor_data` → same code path as D3 (PASS). |
| Snapshot/rollback store **cap** stress (G7) | Stores are FIFO capped (200 snapshots / 100 rollbacks). Not exhaustively driven to eviction; analyzed in §10. No batch larger than a handful was run. |
| `media_replace` (100.2) re-test | Not re-driven here; covered by its own committed suite. Out of this run's scope. |
| Large-library (>1000) report | `media_usage_report` is bounded (default 150 / max 1000); confirmed bounded behavior, not run against a >1000 library. |

---

## 4. Passes / Failures tally

- **Classification scenarios:** 41 PASS / 0 FAIL (`artifacts/scans/RESULTS.csv`).
- **Reversibility:** 4/4 write paths byte-for-byte PASS.
- **Cleanup safety (refusals + guard + reversibility + idempotency + reconcile):**
  all PASS.
- **Security (token scope + capability fail-closed + unsupported mime + structured
  errors):** all PASS.
- **Regression suites:** 100.8 = 40/40, 100.9 = 31/31.
- **Net defects requiring code change:** **0 critical.** 1 new non-critical
  false-positive vector reported (§6), 1 plan-prediction refinement (§7). Per
  instructions, **no fixes implemented** — reported for approval.

---

## 5. Blind-spot verification results (the 5 documented)

All five documented structural blind spots were **reproduced** exactly as predicted
— a genuinely-used image reads as `unused` and appears as a cleanup candidate:

| # | Blind spot | Seeded item | Result | Mitigation in place today |
|---|---|---|---|---|
| B4 | WooCommerce category image (**term meta** `thumbnail_id`) | woocat (15264) | `unused` | trash+snapshot reversible; `wpcc_media_cleanup_protected` filter |
| B5 | `woocommerce_placeholder_image` (**option ID**, not URL) | wooplaceholder (15265) | `unused` | same |
| C6 | ACF **Gutenberg-block** image field (`"image":id` in block JSON) | acfblock (15272) | `unused` | same |
| D4 | Elementor **global kit** (`_elementor_page_settings`) | elekit (15276) | `unused` | same |
| E6b | **File-only** hardcoded uploads URL (never in DB) | filehardcoded (15283) | `unused` | same |

Each is **reversible** (trash, not delete) but **not prevented**. Confirmed:
cleaning a blind-spot item (acfblock) trashed it reversibly and rolled back
byte-for-byte. These remain **backlog** (resolver enhancements: term-meta scan,
option-ID scan, ACF-block JSON scan, `_elementor_page_settings` scan) — not in scope
for stabilization.

---

## 6. False-positive findings

### 6.1 NEW — Legacy `ids`-only Gutenberg gallery block (undocumented)
- **Finding:** A gallery block serialized as `<!-- wp:gallery {"ids":[A,B]} -->`
  with **no inner `wp:image` blocks and no `wp-image-N` / `src` markup** in the saved
  content is classified **`unused`** for each image A, B.
- **Evidence:** `artifacts/scans/ggal1-15247.json`, `ggal2-15248.json` → `unused`.
  After re-seeding the **modern** nested-image gallery format, the same IDs became
  `active` (`by_source.block`).
- **Root cause:** `MediaUsageResolver::references()` channel-4 SQL pre-gate selects
  posts only when content contains `wp-image-N`, `"id":N`, or a file basename. The
  legacy gallery stores `"ids":[…]` — which contains neither `"id":N` nor
  `wp-image-N`. The block-attribute parser (`blocks_reference_id`, which *does*
  check `attrs.ids`) is **never reached** because the SQL gate didn't select the row.
  So the `attrs.ids` handling is effectively dead for galleries lacking inner image
  markup.
- **Realistic exposure:** Modern WP (5.9+) galleries refactor to nested image
  blocks carrying `wp-image-N` → **detected**. Legacy galleries (pre-5.9, or
  produced by some import/builder tools) that retain the flat `ids` attribute →
  **missed**. Moderate likelihood on older sites; **reversible** if cleaned.
- **Severity:** **Moderate** — same risk class as the 5 documented blind spots
  (false positive, reversible via trash+snapshot). **Not a data-loss defect.**
- **Recommendation (NOT implemented — awaiting approval):** broaden the channel-4
  pre-gate (or add a dedicated scan) to also match `"ids":[…N…]`, OR document
  legacy galleries alongside the existing blind spots and rely on
  `wpcc_media_cleanup_protected`. Defer to backlog with the other resolver gaps.

### 6.2 Protected-item false positives
**None.** Across 30 protected items (featured, blocks, classic, private, Woo
featured/gallery/variation, ACF all field types + options, Elementor
widget/background/template, custom_logo/site_icon/site_logo/theme_mod/CSS), **zero**
appeared in `unused_media_find` and **zero** were trashable (all cleanup attempts
refused). Gate **G1 PASS**.

---

## 7. False-negative findings & deviations

### 7.1 Orphan cleanup — plan prediction refined (not a defect)
- **Plan predicted:** cleanup of an orphan (missing original) → snapshot fails →
  `wpcc_media_cleanup_snapshot_failed`, no trash.
- **Observed:** a "partial orphan" (original deleted, **size files still present**)
  → `MediaSnapshot::capture()` snapshots the surviving size files → cleanup
  **trashes reversibly** (`artifacts/cleanup/orphan-cleanup.json`).
- **Verified the abort path exists:** a **fully-fileless** attachment (original +
  all sizes removed) → `wpcc_media_cleanup_snapshot_failed`, **aborted, status stays
  `inherit`** (`artifacts/cleanup/fullorphan-cleanup.json`). Code basis:
  `MediaSnapshot::capture()` returns `wpcc_media_no_files` only when **zero** files
  exist (`MediaSnapshot.php:48`).
- **Assessment:** **Safe in both cases.** No irreversible loss — partial-orphan
  cleanup snapshots what exists and is DB-reversible; full-orphan cleanup aborts
  cleanly. The plan's expectation was correct only for the *fully*-fileless case;
  the "orphaned" flag in the resolver keys off the **original** file alone, so a
  partial orphan is still snapshot-able. **No action required.**

### 7.2 No missed live references
No protected/live item was classified `unused` or `indirect`-when-should-be-active.
Notably: WooCommerce variations (host `product_variation`), ACF serialized galleries,
Elementor nested `background_image` objects, and `custom_css` post URLs were all
correctly detected — these were the highest-risk "might be missed" cases and all
PASSED.

---

## 8. Rollback verification results

| Path | rollback_id | Byte-for-byte | Side effects cleaned | Result |
|---|---|---|---|---|
| `thumbnail_regenerate` (mode=all) | ed56862f… | combined md5 BEFORE==AFTER | regenerated files reverted | **PASS** |
| `webp_generate` (9 sidecars) | b94a48db… | originals md5 unchanged | 9 `.webp` → 0 after rollback | **PASS** |
| `image_optimize` (q50) | d26f7880… | original md5 restored | no `.wpccopt` temp leftovers | **PASS** |
| `unused_media_cleanup` (REST) | 183f00d5… | file md5 restored | status `inherit`, trash meta cleared | **PASS** |
| `unused_media_cleanup` (MCP) | (per run) | — | status `inherit` | **PASS** |
| Idempotency | — | 2nd rollback = no-op | — | **PASS** |
| Snapshot self-ref guard | — | post-rollback scan still `unused` (not falsely `indirect` from its own snapshot) | — | **PASS** |
| Manual-restore reconcile | — | rollback after WP-Admin untrash → final `inherit`, no error/re-trash | — | **PASS** |

Evidence: `artifacts/rollback/a1-rollbacks.txt`, `unused1-rollback.json`,
`unused2-reconcile.json`. Combined-md5 check for a1 across original + 8 sizes was
identical before writes and after the three rollbacks (`5b2eb9f0…`).

---

## 9. Cleanup verification results

- **DestructiveGuard handshake enforced:** missing `confirm` and wrong
  `confirmation_phrase` both → `confirmation_required`. Correct phrase
  `CLEANUP_MEDIA` + reason + media_id required.
- **Refusals (belt-and-suspenders):** active / draft-only / revision-only / Woo
  featured / custom_logo / site_icon all → `wpcc_media_cleanup_refused`.
- **Never permanent:** `permanently_deleted:false`, `reversible:true`,
  `verified:true`; trashed row + file bytes survive; restorable.
- **Token scope:** read token → `wpcc_insufficient_scope` for cleanup.
- **MCP parity:** identical behavior over `/mcp`.
- **Idempotency + reconciliation:** verified (§8).

Evidence: `artifacts/cleanup/unused1-cleanup.json`, `errors/readtoken-cleanup.json`.

---

## 10. Highest-risk items — status after execution

| Risk (from plan §6) | Outcome |
|---|---|
| 1. False positive → live image trashed | **Partially realized as designed-reversible.** 5 documented + 1 new blind spot reproduce a false `unused`; all reversible via trash+snapshot. No protected/standard reference was ever a false positive (G1). |
| 2. Revision/draft regression | **No regression** — both `indirect`, refused. |
| 3. Snapshot-store self-reference | **No regression** — post-rollback scan still `unused`. |
| 4. Irreversibility | **None observed** — all paths byte-for-byte reversible; full-orphan aborts. |
| 5. DestructiveGuard bypass | **None** — enforced; read tokens rejected. |
| 6. Snapshot store exhaustion (G7) | **Not stress-tested.** FIFO caps (200/100). No large batch run. Recommend bounding batch sizes < cap before any bulk cleanup; flagged as a pre-production check. |
| 7. WebP/optimize corrupting originals | **None** — md5 unchanged; dimensions preserved. |
| 8. Large-library under-classification | **By design** — report is bounded; single-item cleanup re-verifies. Operator must not treat a bounded report as a full audit. |

---

## 11. Production risk assessment

**The destructive surface is architecturally safe:** cleanup only ever targets
`status==unused`, re-verifies at execution time, requires a DestructiveGuard
handshake in all modes, rejects read tokens, snapshots before acting, **trashes
rather than deletes**, and has proven byte-for-byte rollback with idempotency and
manual-restore reconciliation. All of this was confirmed by live execution.

**The residual production risk is false positives, not irreversibility.** Six
structural blind spots (5 documented + the new legacy-gallery vector) can mark an
in-use image `unused`. On a real production library, automated/bulk cleanup could
therefore **trash genuinely-used images** — reversibly, but visibly (broken images
until rollback). This is why production is gated.

**Conditions required before any production cleanup:**
1. Human review of `unused_media_find` output, or an allow-list workflow.
2. `wpcc_media_cleanup_protected` populated for known blind-spot IDs (Woo category/
   placeholder, ACF-block, Elementor kit, file-referenced, legacy galleries).
3. Decide whether cleanup should require client/enterprise approval on prod
   (currently prod runs developer mode → auto-run).
4. Batch sizes bounded well under the FIFO rollback cap (100) so no needed rollback
   record is evicted (G7 — not yet stress-tested).
5. Production DB + uploads backup immediately before first cleanup.
6. Rehearse one trash+rollback on prod before any bulk run.

Read-only and reversible-write features (audits, usage scan/report,
regenerate/webp/optimize) are **low risk** and could ship independently of the
cleanup gate.

---

## 12. Final verdict

### Staging: **SAFE FOR STAGING** ✅
All safety-critical gates that prevent irreversible loss hold under live execution:
reversibility proven byte-for-byte, cleanup never permanent, guard + scope enforced,
no false positives among protected content, regression suites green. The known
blind spots are reversible and now have reproduced evidence + a mitigation path.

### Production: **NOT SAFE FOR PRODUCTION** ⛔
Blocked solely on the false-positive blind spots (6 total) combined with developer-
mode auto-run: unattended/bulk cleanup on a real library could trash in-use images
(reversibly). Clears to production only after the §11 conditions are met — i.e.
human-reviewed/allow-listed cleanup + protective filter for blind-spot IDs (and/or
the backlog resolver enhancements), plus the G7 batch-cap check.

---

## 13. Defects discovered → action

Per the engagement rule ("if validation discovers defects: stop and report; fix only
after approval"):

| Finding | Type | Severity | Code change made? |
|---|---|---|---|
| Legacy `ids`-only gallery → `unused` (§6.1) | Undocumented false-positive vector | Moderate (reversible) | **No** — reported, awaiting approval |
| 5 documented blind spots reproduced (§5) | Known scope gaps | Moderate (reversible) | No — backlog (as planned) |
| Orphan cleanup prediction (§7.1) | Plan refinement, behavior safe | Informational | No — none needed |

**No critical (irreversible/data-loss) defect was found.** I have **not** modified
any code. Recommended next actions (pending your approval): (a) add the legacy
gallery vector to the documented blind-spot list and/or broaden the channel-4
pre-gate to match `"ids":[…]`; (b) keep all resolver-coverage enhancements as
backlog; (c) gate production cleanup behind the §11 conditions.

---

## 14. Environment restoration (post-run)

- All 42 `wpcc-v100-*` attachments + files deleted (0 residue on disk, 0 in DB).
- All seeded posts/products/variations/templates/CSS/revision/term deleted.
- Site identity restored: `woocommerce_placeholder_image=110`, site_icon/site_logo/
  custom_logo/header_image reverted; seeded options removed.
- WPCC snapshot/rollback option stores reset to pre-validation (empty) state;
  backups in `artifacts/env/`.
- Minted read-only token revoked. Killswitch mu-plugin removed. Temp files cleaned.
- **Nothing deployed. No backlog work started.**
