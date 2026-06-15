# STEP 101.2 — Performance Observations

Light observation only — not an aggressive benchmark. Each call timed once (wall-clock, end-to-end MCP round trip over local HTTP on AMPPS dev). Absolute numbers include local-server overhead and are not production figures; the intent is to classify normal vs slow vs concerning.

## Distribution (72 timed calls)

| Bucket | Count |
|---|---|
| < 1000 ms | 62 |
| 1000–3000 ms | 8 |
| ≥ 3000 ms | 2 |

- **Min:** 757 ms · **Median:** 804 ms · **Max:** 25,250 ms
- ~86% of all read calls completed under 1 second. Baseline per-call overhead sits around ~0.75–0.9s (round trip + auth + bootstrap).

## Flagged calls

| ms | Tool (action) | Classification | Explanation |
|---|---|---|---|
| 25,250 | `media_enhance` (media_usage_report) | **CONCERNING** | Library-wide cross-runtime usage aggregate — scans every attachment against core content, blocks, WooCommerce, ACF, ACF-options, Elementor, theme_mods, and options. Returned a correct, well-formed payload (not an error). Cost is inherent to the full-library scan. |
| 3,711 | `wp_cli_bridge` (plugin_list) | SLOW | WP-CLI process spawn + WordPress reload per command. |
| 2,112 | `wp_cli_bridge` (db_size_check) | SLOW (borderline) | Same process-spawn overhead. |
| 1,972 | `content_manage` (content_list) | NORMAL-high | First content query of the run (cold caches). |
| 1,726 | `media_manage` (media_search) | NORMAL-high | `WP_Query` over attachments with `s=` term. |
| 1,055 | `system_info` | NORMAL | First call of the run (cold). |

Everything else (plugin/theme/option/settings/acf/seo/woo/menu/widgets/cpt/site-builder/forms/snapshot/rollback/capability/workflow/approval/file/code-search/patch-preview/database/media reads) ran 757–870 ms — **NORMAL**.

## Classification summary

- **Normal:** 70 of 72 calls.
- **Slow:** WP-CLI bridge calls (~2–4s) — expected; each spawns a `wp` subprocess that reloads WordPress.
- **Concerning:** `media_enhance/media_usage_report` (~25s) — the only call that would be a UX problem if invoked synchronously and frequently.

## Notes / recommendations (non-blocking)

1. **`media_usage_report`** — per-item `media_usage_scan` is fast (~1.0s) and is the right tool for interactive use. Reserve the library-wide aggregate for batched/background/off-peak runs, or expose a `limit`/cursor-paged variant (the op already declares a `limit` param — worth confirming it bounds this aggregate). Behavior is correct; only the latency is high.
2. **WP-CLI bridge latency** is structural (subprocess + WP bootstrap per command); acceptable for occasional ops, not for tight loops.
3. **No hangs, no timeouts, no crashes** observed across 73 calls. No payload was malformed or truncated unexpectedly (large list payloads are returned in a compact `{count, preview, truncated}` shape by the context optimizer).
