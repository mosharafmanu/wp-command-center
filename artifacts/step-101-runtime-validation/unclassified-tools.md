# STEP 101.1 — Unclassified Tools

**Result: NONE.**

All **39** MCP tools discovered via live `tools/list` map cleanly to exactly one primary runtime (29 runtimes total). Every tool is a registered `OperationRegistry` operation with a known `risk_level`, `action_risks`, and `requires_approval`, so there were no tools with unknown or unclassifiable behavior.

## Why classification was unambiguous

- MCP `tools/list` is generated 1:1 from `OperationRegistry::get_operations()` — there is no separate/dynamic tool source to leave anything unaccounted for.
- Each tool's purpose is declared in its `title` + `description`, and its behavior tier in `risk_level` / `action_risks`.
- The 39 live tool names matched the 39 registry `id`s and the `wpcc://operations` count (39) exactly.

## Borderline cases (classified, but worth noting)

These were assigned a primary runtime despite touching multiple domains:

| Tool | Primary runtime | Also touches |
|---|---|---|
| `safe_updates` | Update | Plugin, Theme |
| `safe_search_replace` | Database | Content (string replace across tables) |
| `report_manage` | Search & Reports | Timeline/Audit (activity reports read the audit log) |
| `bulk_manage` | Bulk Operations | Content, Media, WooCommerce, ACF (delegates) |
| `workflow_manage` | Workflow | every runtime (steps can invoke any registered op) |
| `site_builder_manage` | Site Builder | Menu (delegates menu_* to menu_manage) |
| `media_enhance` | Media | Snapshot/Rollback (snapshot-backed reversible writes) |

None of these required an "Unclassified" bucket.
