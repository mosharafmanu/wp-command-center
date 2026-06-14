# STEP 96 — Elementor Runtime

## Goal

Let AI agents read and edit Elementor pages over REST and MCP — without touching
Elementor's editor — by operating directly on the page's `_elementor_data`
element tree.

## Architecture

A new `elementor_manage` operation (`ElementorRuntimeManager` +
`ElementorRegistry`). Gated behind `defined( 'ELEMENTOR_VERSION' )`; returns
`wpcc_elementor_inactive` if Elementor is not active. Dev verified on Elementor
4.1.3.

## Data model

`_elementor_data` post meta is a `wp_slash`'d JSON string holding a nested tree:
`section → column → widget`. Each element is `{ id, elType, settings, elements
(children), widgetType }`. Widget text lives at type-specific setting keys:

| Widget type | Text key | Image | Button |
|-------------|----------|-------|--------|
| `heading` | `settings.title` | — | — |
| `text-editor` | `settings.editor` | — | — |
| `image` | — | `settings.image{url,id}` | — |
| `button` | `settings.text` | — | `settings.link{url}` |

Writes persist via `update_post_meta($id, '_elementor_data',
wp_slash(wp_json_encode($data)))` and then clear Elementor's CSS cache
(`delete_post_meta($id, '_elementor_css')` + `files_manager->clear_cache()`).

## Operations (REST `/operations/elementor_manage/run` + `/rollback`, MCP)

| Action | Risk | Rollback |
|--------|------|----------|
| `elementor_get_page` | diagnostic | — |
| `elementor_export_structure` | diagnostic | — |
| `elementor_list_widgets` | diagnostic | — |
| `elementor_update_text` | medium | full `_elementor_data` snapshot |
| `elementor_update_image` | medium | full `_elementor_data` snapshot |
| `elementor_update_button` | medium | full `_elementor_data` snapshot |

- **Reads** — `get_page` returns the decoded tree + title; `export_structure`
  returns a summarized tree (`id`/`elType`/`widgetType`/`children`);
  `list_widgets` recursively flattens every widget with `id`, `widget_type`, and
  a stripped text preview.
- **Edits** — locate the target widget by `widget_id` (recursive, matches
  `elType === 'widget'`), apply the field mutation, persist, clear cache. Each
  edit snapshots the full pre-edit `_elementor_data` and returns a `rollback_id`.
  - `update_text` — sets the type's text key (`heading→title`, `text-editor→
    editor`, `button→text`); a `field` param can override the key.
  - `update_image` — sets `settings.image.url` and/or `.id` (requires `image_url`
    or `image_id`).
  - `update_button` — sets `settings.text` and/or `settings.link.url` (requires
    `text` or `url`).
- **Rollback** — `/rollback { rollback_id }` restores the snapshotted
  `_elementor_data` and clears cache; one-shot (`rollback_applied` guard).
  Storage: `wpcc_elementor_rollbacks` option (capped at 100).

## Structured error codes

`wpcc_elementor_inactive`, `wpcc_invalid_elementor_action`, `wpcc_page_not_found`,
`wpcc_not_elementor_page`, `wpcc_elementor_data_corrupt`, `wpcc_missing_widget_id`,
`wpcc_widget_not_found`, `wpcc_missing_image`, `wpcc_missing_button_fields`,
`wpcc_missing_rollback_id`, `wpcc_rollback_not_found`,
`wpcc_rollback_already_applied`. In-band `{error:true}` results are surfaced as
MCP `isError` by `McpServerRuntime` (STEP 89).

## Wiring

- `OperationExecutor::resolve_handler` → `new ElementorRuntimeManager()`.
- `OperationRegistry` operation def with `action_risks`, `requires_approval`,
  full parameter schema.
- `CapabilityRegistry::OPERATION_MAP['elementor_manage'] = CAP_CONTENT_MANAGE`
  (mapped operations 31 → 32).
- `RestApi`: `run`/`rollback` routes + `ROUTE_MANIFEST` entries
  (`run_elementor_manage` via `run_bridge_operation`; `run_elementor_rollback`).

## Security / safety

- Edits are `medium` risk → approval-aware in Client/Enterprise modes; auto-run
  in Developer mode.
- All writes are rollback-capable and audited (`elementor.*` audit events).
- Content sanitized on write (`wp_kses_post` for text, `esc_url_raw` for URLs,
  `sanitize_text_field` for button label).
- Reads/writes are token-capability gated (`content.manage`).

## Tests

`tests/test-elementor-step96.sh` — **26/26 PASS**: build an Elementor-shaped page
→ get_page → export_structure → list_widgets → update_text/image/button →
button rollback → cache-clear verification → MCP parity → 7 structured errors →
MCP `isError` surface. `tests/test-capability-runtime.sh` bumped to 32 mapped
operations (61/61). Full bash regression: 0 net-new failures (24 pre-existing
baseline).
