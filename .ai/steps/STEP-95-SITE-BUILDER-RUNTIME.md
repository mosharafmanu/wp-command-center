# STEP 95 — Site Builder Runtime

## Goal

Let AI agents construct WordPress sites over REST and MCP.

## Architecture

A new `site_builder_manage` operation (`SiteBuilderRuntimeManager` +
`SiteBuilderRegistry`). Menus are **delegated** to the existing `menu_manage`
runtime — not duplicated.

## Operations (REST `/operations/site_builder_manage/run` + `/rollback`, MCP)

| Action | Risk | Rollback |
|--------|------|----------|
| `page_list`, `page_get` | diagnostic | — |
| `page_create` | medium | delete page |
| `page_update` | medium | restore title/content/status/parent/order/template |
| `page_delete` | medium | untrash (soft delete) |
| `template_list` | diagnostic | — |
| `template_assign` | medium | restore previous template |
| `pattern_create` | medium | — (reusable block) |
| `pattern_list` | diagnostic | — |
| `navigation_manage` (op: create/update/get) | medium | delete / restore |
| `menu_create`, `menu_update`, `menu_assign` | medium | delegated to `menu_manage` |

- **Pages** — full page model: title, content, status (publish/draft/pending/
  private/future), parent, menu_order, and template. Each write returns
  `rollback_id`.
- **Templates** — `template_list` reads the active theme's page templates;
  `template_assign` validates the slug against the theme and sets
  `_wp_page_template` (or clears it for `default`).
- **Patterns** — `pattern_create` creates a `wp_block` (reusable block) from block
  markup.
- **Navigation** — `navigation_manage` creates/updates/reads `wp_navigation`
  posts for block themes.
- **Menus** — `menu_create`/`menu_update`/`menu_assign` delegate to
  `MenuRuntimeManager` (`menu_create`, `menu_update`, `menu_location_assign`);
  responses are tagged `delegated_from: site_builder_manage`.

Structured errors: `wpcc_missing_title`, `wpcc_page_not_found`,
`wpcc_missing_page_id`, `wpcc_page_create_failed`, `wpcc_missing_template`,
`wpcc_invalid_template`, `wpcc_pattern_create_failed`,
`wpcc_navigation_not_found`, `wpcc_invalid_site_builder_action`.

## Acceptance tests — `tests/test-site-builder-step95.sh` (21/21)

Workflow: create page → assign template → create pattern → create navigation →
create menu (delegated) → assign menu to a theme location → publish page →
verify frontend (HTTP 200) → page_get/page_list → MCP parity → structured errors
→ page update + rollback.

## Files changed / added

- `includes/Operations/SiteBuilderRegistry.php` — **new**.
- `includes/Operations/SiteBuilderRuntimeManager.php` — **new**.
- `includes/Operations/OperationExecutor.php` — dispatch.
- `includes/Operations/OperationRegistry.php` — operation definition.
- `includes/Operations/CapabilityRegistry.php` — `site_builder_manage` →
  `content.manage` (operation_map 30→31).
- `includes/AiAgent/RestApi.php` — run + rollback routes, callbacks, manifest.
- `tests/test-capability-runtime.sh` — operation count 30→31.

## Preserved guarantees

Backward compatible (new additive operation; menus reuse `menu_manage`, no
duplicate logic). Security modes (writes gated medium), approval, rollback,
audit, and REST/MCP parity intact.
