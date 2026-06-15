# STEP 101.2 — Observed Findings

Only directly observed, reproducible behavior is recorded. No assumptions, theoretical risks, or architecture opinions. Severity reflects read-only-validation impact, not production risk.

---

## F-1 — Thin parameter schemas accept undeclared params and work correctly (metadata gap, not a bug)

**Severity:** Low (metadata completeness / agent discoverability).
**Tools:** `settings_manage`, `woocommerce_manage`, `menu_manage`, `user_manage` (and also `media_manage` for the `search` key).

**Observed:**
- These tools declare only `action` (+ auto-added `reason`) in their `inputSchema`, yet they correctly consume per-action params that are **not** in the schema.
  - `user_manage {action:user_get, user_id:<valid>}` → returned the user record (Phase B PASS).
  - `media_manage {action:media_search, search:"a"}` → returned 50 items. (My first attempt used `title` and got the structured error `wpcc_media_empty_search` "Search term is required." — confirming `search` is the real, undeclared key.)
- With the id omitted, the tools return clean structured "not found" errors (the missing id defaults to 0):
  - `woocommerce_manage {action:product_get}` → `wpcc_product_not_found`.
  - `menu_manage {action:menu_get}` → `wpcc_menu_not_found`.
  - `user_manage {action:user_get}` → `wpcc_user_not_found`.

**Conclusion:** Behavior is correct and safe; the limitation is purely that an AI agent cannot discover the required param names (`user_id`, `product_id`, `menu_id`, `search`, etc.) from tool metadata and must infer them from the description prose or trial-and-error. **Determination: intentional/lossy metadata, NOT a functional bug.** Recommend (non-blocking) enriching these schemas in a future step.

---

## F-2 — WP-CLI structured command registry not enumerated in tool metadata

**Severity:** Low (discoverability).
**Tool:** `wp_cli_bridge`.

**Observed:**
- Read-only commands execute correctly via the legacy bare-command path: `db_size_check` (HTTP 200, valid payload, ~2.1s) and `plugin_list` (HTTP 200, valid payload, ~3.7s).
- The operation metadata exposes only the 6 legacy bare commands in an enum; the structured `command_id` allowlist (the intended primary path) is not enumerated anywhere in `tools/list` output.

**Conclusion:** Command execution works for the read-only commands tested. The partially-visible registry matches what 101.1 discovery already flagged. **Determination: consistent with design; registry visibility is a metadata gap, not a malfunction.** No bug observed.

---

## F-3 — `media_enhance/media_usage_report` is slow (~25s) on the dev library

**Severity:** Low-Medium (performance, not correctness).
**Tool/action:** `media_enhance {action:media_usage_report}`.

**Observed:** Returned a valid, well-formed aggregate (HTTP 200, not an error) but took ~25.25s — the library-wide cross-runtime usage scan (core content/blocks/WooCommerce/ACF/Elementor/theme_mods/options) over the full attachment set. Every other media read (`media_list`, `media_get`, `media_search`, `media_usage_scan` for one item, `image_sizes_list`, `webp_audit`, capabilities) completed in ≤1.7s.

**Conclusion:** Correct output; the cost is inherent to a full-library aggregate. See `performance-observations.md` for the cadence recommendation (single-item `media_usage_scan` is fast; reserve the library aggregate for batched/off-peak use). **No correctness defect.**

---

## F-4 — Elementor has no positive sample on current dev data (test-data gap)

**Severity:** Informational.
**Tool:** `elementor_manage`.

**Observed:** All 5 pages returned by `site_builder_manage page_list` (ids 1655, 112, 113, 10911, 114) return the structured error `wpcc_not_elementor_page` from `elementor_get_page`. The runtime is accessible and responds correctly; it simply has no Elementor-built page to read on the current dev site (despite STEP 96 having verified it on Elementor 4.1.3 previously — that fixture is no longer present).

**Conclusion:** Runtime behavior is correct (graceful structured response). A positive read could not be validated for lack of data. **Recommend creating an Elementor page fixture before 101.3** so the Elementor write/rollback path can be exercised. No defect.

---

## Positive confirmations (no action needed)

- **STEP 89 error contract holds everywhere:** 17/17 invalid-input probes → `{isError, code, message}` at HTTP 200, including unknown tool name → `operation_not_found`. No opaque `-32000/-32001`, no crashes, no hangs.
- **Per-action approval gating works as discovered:** diagnostic sub-actions of approval-gated tools (e.g. `content_list`, `media_list`, `acf_group_list`, `woocommerce product_list`) executed directly without an approval handshake, while no write was ever triggered.
- **`file_manage` path safety:** `file_read wp-config.php` (relative to wp-content) → `wpcc_not_found` (the path resolves outside wp-content and is not exposed). Read-only and safe.
- **Media runtime re-check (previously validated):** listing, retrieval, metadata, and search all PASS; `media_enhance` audits all PASS read-only.
