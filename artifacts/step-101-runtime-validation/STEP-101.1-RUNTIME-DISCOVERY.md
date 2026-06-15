# STEP 101.1 — Runtime Discovery (Discovery Only)

**Date:** 2026-06-15
**Scope:** Discover and document every runtime/capability exposed through WP Command Center and MCP. **No destructive tests. No production data touched. No validation executed.**

## Method

This was **actual MCP/tool discovery**, not docs-only:

1. Live `tools/list` JSON-RPC call against the running dev MCP endpoint (`POST {base}/mcp`, token auth) → returned the authoritative tool set.
2. Live `resources/list` + `resources/read wpcc://operations` → confirmed resource set and operation count.
3. Live REST `GET /operations` → full per-operation objects (`risk_level`, `requires_approval`, `action_risks`, `available`, `parameters`).
4. Cross-checked the live results against the source of truth `includes/Operations/OperationRegistry.php` and the MCP `tools_list()` generator in `includes/Mcp/McpServerRuntime.php`.

**Key architectural fact:** MCP `tools/list` iterates `OperationRegistry::get_operations()` 1:1 — **every registered operation is exactly one MCP tool** (tool `name` = operation `id`). There are no MCP tools registered outside the operation registry. Multi-action operations expose their sub-actions through an `action` parameter, each with its own risk tier in `action_risks`.

## Headline Numbers (live, verified)

| Metric | Value |
|---|---|
| MCP server | `WP Command Center` v`0.1.0`, MCP protocol `2024-11-05` |
| **MCP tools** | **39** (live `tools/list` == registry == `wpcc://operations` count) |
| **MCP resources** | **7** (`wpcc://` manifest, context, capabilities, operations, queue, results, recommendations) |
| Tools available on dev | 39 / 39 (ACF, WooCommerce, CF7, SEO all active on dev) |
| Distinct sub-actions (in `action_risks`) | 264 |
| Runtimes identified | 29 |
| Unclassified tools | 0 |

### Operation-level risk distribution

| risk_level | count |
|---|---|
| diagnostic | 8 |
| low | 1 |
| medium | 15 |
| high | 9 |
| critical | 6 |

`requires_approval`: 31 tools `true`, 8 `false` (the 8 are the read-only/control-plane tools: `system_info`, `database_inspect`, `approval_manage`, `search_manage`, `file_manage`, `code_search`, `report_manage`, `media_enhance`).

> Note: approval gating is **per-action**, not per-tool. `SecurityModeManager` lets `diagnostic` sub-actions through in all modes even when the parent op is `requires_approval=true`. So a "medium" op like `content_manage` still serves `content_list`/`content_get` without approval.

## Runtimes (29) and their MCP tools

Each tool is assigned to exactly one **primary** runtime (so the 39 sum cleanly). Cross-cutting relationships are noted.

| # | Runtime | MCP tool(s) | Posture |
|---|---|---|---|
| 1 | Site Intelligence & Diagnostics | `system_info` | read-only; also 7 MCP resources + REST `/site-intelligence`, `/diagnostics`, `/health` |
| 2 | Database | `database_inspect`, `safe_search_replace` | inspect read-only; search_replace **critical** write (dry-run+rollback) |
| 3 | File | `file_manage` | read-only (redacted, blocked paths) |
| 4 | Code Search | `code_search` | read-only |
| 5 | Patch | `patch_manage` | high; apply snapshots+verifies+auto-reverts; APPLY_PATCH handshake on high-risk files |
| 6 | Snapshot | `snapshot_manage` | high; create/restore file snapshots |
| 7 | Rollback | `rollback_manage` | high; restore from pre-apply snapshot w/ hash verify |
| 8 | Agent / Authorization | `capability_manage` | **critical** (assign/remove); list/get/validate diagnostic |
| 9 | Approval | `approval_manage` | diagnostic control-plane; never itself requires approval |
| 10 | WP-CLI | `wp_cli_bridge` | **critical**; structured command registry |
| 11 | Plugin | `plugin_manage` | **critical** (delete handshake); install/activate/update high |
| 12 | Theme | `theme_manage` | **critical** (delete handshake) |
| 13 | Update | `safe_updates` | high; snapshot + health-verify + dry-run |
| 14 | Option & Settings | `option_manage`, `settings_manage` | high writes, rollback-capable |
| 15 | Content | `content_manage`, `comments_manage`, `cpt_manage` | medium/high; cpt_* high |
| 16 | Media | `media_manage`, `media_import`, `media_enhance` | medium; `unused_media_cleanup` high (TRASH-only, CLEANUP_MEDIA handshake) |
| 17 | ACF | `acf_manage` | medium; gated on ACF |
| 18 | Forms | `forms_manage` | medium; CF7/Fluent/WPForms/Gravity |
| 19 | WooCommerce | `woocommerce_manage` | medium; refund_create high; gated on Woo |
| 20 | SEO | `seo_manage` | medium; gated on Rank Math/Yoast |
| 21 | Site Builder | `site_builder_manage` | medium |
| 22 | Elementor | `elementor_manage` | medium |
| 23 | Menu | `menu_manage` | medium |
| 24 | Widgets | `widgets_manage` | medium |
| 25 | User | `user_manage` | **critical** (delete, role assign/remove) |
| 26 | Workflow | `workflow_manage` | high; single approval runs any registered ops |
| 27 | Bulk Operations | `bulk_manage` | high; `batch_execute` **critical** |
| 28 | Search & Reports | `search_manage`, `report_manage` | diagnostic read-only |
| 29 | Seeder | `content_seed`, `acf_seed`, `cf7_seed`, `woo_product_seed` | low/medium sample-data generators |

All mission-named runtimes are present. Additional runtimes discovered beyond the prompt list: **Menu, Widgets, User, Bulk Operations, Workflow, Comments (folded into Content), CPT (folded into Content), Update (safe_updates), Site Builder, Elementor, Forms, SEO, Search & Reports**.

Full per-tool breakdown (read-only vs write vs approval-required vs dangerous actions) is in `runtime-inventory.json`.

## Potentially dangerous operations (observed from metadata, not tested)

8 sub-actions are tier **critical**, plus several high-risk destructive ones:

- `capability_manage` → `capability_assign`, `capability_remove` (authorization changes)
- `plugin_manage` → `plugin_delete` (confirm + `DELETE_PLUGIN` + reason; folder backed up)
- `theme_manage` → `theme_delete` (confirm + `DELETE_THEME` + reason)
- `user_manage` → `user_delete`, `user_assign_role`, `user_remove_role`
- `bulk_manage` → `batch_execute` (runs arbitrary batched operations)
- `safe_search_replace` (whole op critical — DB-wide search/replace)
- `wp_cli_bridge` (whole op critical — command execution)
- High-risk destructive but reversible: `media_enhance` → `unused_media_cleanup` (TRASH only, `CLEANUP_MEDIA` handshake; never permanent), `woocommerce_manage` → `refund_create`.

All destructive deletes are guarded by the STEP 84 DestructiveGuard confirmation handshake (phrase + reason + target).

## Missing / unclear metadata (directly observed)

1. **Thin parameter schemas on multi-action ops.** Four tools expose only `action` (+ auto-added `reason`) in their `parameters`, so per-action inputs are NOT discoverable from tool metadata: **`settings_manage`, `woocommerce_manage`, `menu_manage`, `user_manage`**. `search_manage` exposes only `action`; `forms_manage` only `action`+`provider`. An AI agent must infer required fields (e.g. `user_id`, `role`, order/coupon fields) from descriptions or trial-and-error. (Other multi-action ops like `content_manage`, `acf_manage`, `media_manage`, `media_enhance`, `site_builder_manage`, `elementor_manage`, `workflow_manage` do enumerate richer params.)
2. **`wp_cli_bridge` command registry not enumerated.** Only the 6 legacy bare commands appear in an enum; the structured `command_id` allowlist (the intended path) is not exposed in operation metadata.
3. **Op-level `risk_level` can understate action risk.** `media_enhance` is op-level `diagnostic` / `requires_approval=false`, yet contains medium write actions (thumbnail/webp/optimize) and a high `unused_media_cleanup`. The action-level `action_risks` map is the accurate source; the op-level summary alone is misleading.
4. **Snapshot scope split.** File snapshots live under `snapshot_manage`; byte-level media snapshots live under `media_manage` (`media_snapshot_*`). Two snapshot surfaces, one runtime label.

> Per the mission, these are **observations during discovery**, not bug reports. None were reproduced or exploited.

## Unclassified tools

**None.** All 39 tools map cleanly to a runtime (see `unclassified-tools.md`).

## Artifacts produced

- `STEP-101.1-RUNTIME-DISCOVERY.md` (this file)
- `runtime-inventory.json` (machine-readable, data-driven from live `/operations`)
- `raw-operations.json` (raw live payload, evidence)
- `unclassified-tools.md`
- `next-validation-plan.md`
