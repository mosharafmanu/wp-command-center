# Capabilities

A capability is a named permission a token may hold. There are **23**, and every
operation maps to exactly one. Enforcement is on by default
(`wpcc_enforce_capabilities`).

Capabilities are the third of four layers; see [SECURITY.md](SECURITY.md). They answer
*"is this token allowed to ask?"* — not *"may this change happen without review?"*, which
is the protection mode's job.

---

## The 23

| Capability | Covers |
|---|---|
| `content.manage` | Posts, pages, taxonomies, featured images |
| `media.manage` | Media library, alt text, replacement, snapshots |
| `user.manage` | Users, roles, passwords |
| `comments.manage` | Comments and moderation |
| `cpt.manage` | Custom post types and taxonomies |
| `menu.manage` | Navigation menus |
| `widgets.manage` | Widgets and sidebars |
| `settings.manage` | Site settings |
| `option.manage` | Individual options |
| `theme.manage` | Themes |
| `plugin.manage` | Plugins |
| `acf.manage` | Advanced Custom Fields |
| `woocommerce.manage` | Products, orders, coupons, variations |
| `forms.manage` | Contact Form 7 and other providers |
| `search.manage` | Search, code search, reporting |
| `bulk.manage` | Bulk operations |
| `workflow.manage` | Multi-step workflows |
| `snapshot.manage` | Snapshots |
| `database.inspect` | Read-only database inspection |
| `wpcli.execute` | WP-CLI bridge |
| `system.admin` | System information and diagnostics |
| `capability.admin` | Managing capabilities themselves |
| `history.read` | Change history and undo discovery |

## Scope comes first

Before capabilities are consulted, the token's **scope** applies:

- `read_only` — limited to search, file reads and history lookups. A write tool is refused
  at the transport with `wpcc_token_read_only`, whatever capabilities the token holds.
- `full` — may request anything its capabilities allow.

## Denials

```json
{"code":"wpcc_capability_denied",
 "message":"Missing capability: content.manage",
 "data":{"status":403}}
```

The message always names the capability required, so the fix is unambiguous.

## Inspecting

```bash
# The 23-entry registry and the operation → capability map
curl -s "$BASE/claude/discovery" -H "Authorization: Bearer $TOKEN"

# What this SERVER can do — a different thing entirely (see API.md)
curl -s "$BASE/capabilities" -H "Authorization: Bearer $TOKEN"
```

Or **Settings → Advanced → Capabilities** in the admin.

## Choosing capabilities for a token

Grant the narrowest set that lets the assistant do its job. A content-writing assistant
usually needs `content.manage`, `media.manage`, `search.manage` and `history.read` — and
nothing else. Capabilities limit blast radius; the protection mode still decides whether a
human sees the change first.
