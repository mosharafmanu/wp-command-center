# STEP 101.2 — Read-Only Runtime Validation

**Date:** 2026-06-15
**Mode:** READ-ONLY. No content/settings/options/media modified. No posts/media created. No writes, approvals, patches, or rollbacks executed. Safe for production (run here against local dev).
**Transport:** live MCP JSON-RPC `tools/call` against the running dev server (`POST {base}/mcp`, bearer token), plus the live `/operations` discovery payload from 101.1 for Phase A.

## Verdict: **PASS WITH OBSERVATIONS**

- **29/29 runtimes accessible via MCP** and returning valid, structured responses.
- **73 read-only calls**: **72 PASS, 0 FAIL, 1 OBSERVATION.**
- **0 runtimes inaccessible.** **0 inconsistent responses.** **0 crashes/hangs.**
- Error handling is uniform and correct: **17/17 invalid-input probes returned structured `{isError, code, message}`** (STEP 89 contract) with HTTP 200 — never an opaque transport failure.
- Two performance flags (1 concerning, 1 slow) — both explainable, neither a correctness defect (see `performance-observations.md`).

## Counts

| Metric | Value |
|---|---|
| Runtimes validated | 29 |
| Total read-only calls | 73 |
| PASS | 72 |
| FAIL | 0 |
| Observations | 1 (Elementor — no Elementor-built page on dev) |
| Phase B read PASS | 25 runtimes |
| Phase B N/A (write-only) | 3 runtimes (Update, Bulk Operations, Seeder) |
| Phase C error-handling PASS | 13 runtimes w/ dedicated cases (+ global invalid-tool & invalid-action probes) |
| Runtimes inaccessible via MCP | 0 |
| Runtimes with inconsistent responses | 0 |

## Runtime Validation Matrix

A = Discovery (visible + available + metadata) · B = Read ops · C = Error handling · D = Performance.
`N/A` in B = write-only runtime, not executed to honor the read-only mandate. Full machine-readable form in `runtime-validation-matrix.json`; raw call evidence in `evidence.json`.

| Runtime | A | B | C | Perf (max) |
|---|---|---|---|---|
| Site Intelligence & Diagnostics | PASS | PASS | (global) | NORMAL (1055ms) |
| Database | PASS | PASS | PASS | NORMAL (832ms) |
| File | PASS | PASS | PASS | NORMAL (788ms) |
| Code Search | PASS | PASS | (global) | NORMAL (778ms) |
| Patch | PASS | PASS | (global) | NORMAL (806ms) |
| Snapshot | PASS | PASS | (global) | NORMAL (810ms) |
| Rollback | PASS | PASS | (global) | NORMAL (826ms) |
| Agent / Authorization | PASS | PASS | (global) | NORMAL (772ms) |
| Approval | PASS | PASS | (global) | NORMAL (868ms) |
| WP-CLI | PASS | PASS | (global) | **SLOW (3711ms)** |
| Plugin | PASS | PASS | PASS | NORMAL (1021ms) |
| Theme | PASS | PASS | (global) | NORMAL (837ms) |
| Update (safe_updates) | PASS | N/A (write-only) | N/A | n/a |
| Option & Settings | PASS | PASS | PASS | NORMAL (857ms) |
| Content (content/comments/cpt) | PASS | PASS | PASS | NORMAL (1972ms) |
| Media (manage/import/enhance) | PASS | PASS | PASS | **CONCERNING (25250ms)** |
| ACF | PASS | PASS | (global) | NORMAL (770ms) |
| Forms (CF7/Fluent/WPForms/Gravity) | PASS | PASS | (global) | NORMAL (783ms) |
| WooCommerce | PASS | PASS | PASS | NORMAL (821ms) |
| SEO | PASS | PASS | PASS | NORMAL (799ms) |
| Site Builder | PASS | PASS | (global) | NORMAL (822ms) |
| Elementor | PASS | **OBSERVATION** | PASS | NORMAL (812ms) |
| Menu | PASS | PASS | PASS | NORMAL (862ms) |
| Widgets | PASS | PASS | (global) | NORMAL (802ms) |
| User | PASS | PASS | PASS | NORMAL (857ms) |
| Workflow | PASS | PASS | (global) | NORMAL (773ms) |
| Bulk Operations | PASS | N/A (write-only) | PASS | NORMAL (795ms) |
| Search & Reports | PASS | PASS | PASS | NORMAL (811ms) |
| Seeder (content/acf/cf7/woo) | PASS | N/A (write-only) | N/A | n/a |

> "(global)" in column C = the runtime had no dedicated negative probe, but error handling is covered by the global invalid-tool (`operation_not_found`) and invalid-action probes, plus the runtime's own read calls. Every runtime that *was* probed with invalid input returned a graceful structured error.

## Phase summaries

### Phase A — Discovery (all PASS)
All 29 runtimes / 39 tools are visible via `tools/list`, all `available=true` on dev, and all carry coherent `title`/`description`/`risk_level`/`action_risks` metadata (established live in 101.1 and re-confirmed from the `/operations` payload). No runtime is hidden or missing metadata at the tool level.

### Phase B — Read operations (25 PASS, 3 N/A, 1 OBSERVATION)
Representative diagnostic actions executed and returned well-formed, accurate payloads, e.g.:
- `system_info` → full environment block (WP/PHP/MySQL versions, theme, memory, etc.).
- `database_inspect` `db_health_summary`/`db_table_list` → structured DB health (read-only, no SQL).
- `plugin_manage`/`theme_manage` list → installed inventory with status.
- `content_manage` list+`content_get` (valid id) → post records.
- `media_manage` list+`media_get`+`media_search` (search="a" → 50 items).
- `media_enhance` `media_enhance_capabilities` (GD/Imagick + WebP probe), `image_sizes_list`, `webp_audit`, `media_usage_report`, `media_usage_scan`, `image_size_verify`.
- `acf_manage` `acf_group_list`/`acf_inventory`; `seo_manage` `seo_get`/`seo_validate`; `woocommerce_manage` product/order/coupon lists; `settings_manage` general_get/inventory/analyze; `menu_manage`, `widgets_manage`, `cpt_manage`, `site_builder_manage`, `forms_manage`, `capability_manage`, `workflow_manage`, `snapshot_manage`, `rollback_manage`, `approval_manage` (request/queue/results lists).
- `wp_cli_bridge` read-only `db_size_check` and `plugin_list`.
- `patch_manage` `patch_preview` (diff preview only — no snapshot/write).

**N/A (write-only, not executed):** Update (`safe_updates`), Bulk Operations (`bulk_manage`), Seeder (`content_seed`/`acf_seed`/`cf7_seed`/`woo_product_seed`). These expose no non-mutating read action; executing them — even a dry-run — would violate the read-only mandate, so they were intentionally not run. They remain Phase-A accessible.

**OBSERVATION (Elementor):** `elementor_get_page`/`elementor_list_widgets` are accessible and respond correctly, but none of the 5 pages on dev are Elementor-built, so the runtime returns the structured `wpcc_not_elementor_page` for each. A positive payload could not be produced on current dev data. Not a defect — the runtime behaves correctly; only the test data is missing.

### Phase C — Error handling (all graceful)
17/17 invalid-input probes returned HTTP 200 with structured `{isError, code, message}` and no crash/hang. Sample codes observed:
`wpcc_content_not_found`, `wpcc_media_not_found`, `wpcc_user_not_found`, `wpcc_seo_post_not_found`, `wpcc_product_not_found`, `wpcc_menu_not_found`, `wpcc_invalid_option_id`, `wpcc_missing_db_table`, `wpcc_invalid_plugin_action` (with the valid-action list in the message), `wpcc_invalid_settings_action`, `operation_not_found` (unknown tool name), and generic `invalid` for bogus search/bulk actions.

### Phase D — Performance
See `performance-observations.md`. 71/73 calls completed under ~2s. Two flags: `media_enhance/media_usage_report` (~25s — library-wide cross-runtime aggregate), and `wp_cli_bridge` (~2–4s — process spawn overhead). Both expected for what they do; neither blocks read validation.

## Special-attention areas (from 101.1)

- **Thin parameter schemas** (`settings_manage`, `woocommerce_manage`, `menu_manage`, `user_manage`): validated as **functional but discoverability-limited** — see `observed-findings.md` F-1. Not classified as a bug (behavior is correct when the undeclared params are supplied).
- **WP-CLI registry visibility**: read-only commands execute correctly; structured `command_id` registry remains non-enumerated in tool metadata — see F-2.
- **Media runtime re-check**: listing/retrieval/metadata/search all PASS; `media_enhance` read/audit actions all PASS; cleanup-related read intelligence (`media_usage_report`/`media_usage_scan`/`unused_media_find` family) accessible read-only. Only the library-wide aggregate is slow (F-3 / performance).

## Recommended next step

Proceed to **STEP 101.3 — reversible write validation on dev** (create → verify → rollback per write-capable runtime), beginning with the lowest-risk reversible writes. Address the two metadata findings (thin schemas, WP-CLI registry) as documentation/schema improvements; neither blocks 101.3.

**Do not begin STEP 101.3 until instructed.**
