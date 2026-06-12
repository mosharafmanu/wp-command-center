# Operations Reference

Every operation in WP Command Center is defined in `includes/Operations/OperationRegistry.php`. This document describes each operation family, its capability requirements, approval workflow, risk level, rollback support, sub-actions, and example payloads.

---

## 1. content_manage — Content Management

**Description:** Safely inspect and manage WordPress content. All operations use native WordPress APIs (no raw SQL).

**Capability required:** `content.manage`

**Approval required:** Yes (via the operations/requests workflow)

**Risk level:** Variable (per sub-action):

| Sub-action | Risk |
|---|---|
| `content_list` | low |
| `content_get` | low |
| `content_create` | medium |
| `content_update` | medium |
| `content_delete` | high |
| `content_publish` | high |
| `content_unpublish` | high |
| `content_schedule` | high |
| `taxonomy_assign` | medium |
| `featured_image_assign` | medium |

**Rollback support:** No (content changes are tracked in revision history, not snapshotted)

**Sub-actions:** `content_list`, `content_get`, `content_create`, `content_update`, `content_delete`, `content_publish`, `content_unpublish`, `content_schedule`, `taxonomy_assign`, `featured_image_assign`

**Supported content types:** `post`, `page`

**Example request JSON:**
```json
{
  "operation_id": "content_manage",
  "payload": {
    "action": "content_create",
    "type": "post",
    "title": "Hello World",
    "content": "This is a sample post."
  }
}
```

**Example response JSON:**
```json
{
  "action": "content_create",
  "content_id": 42,
  "title": "Hello World",
  "status": "draft",
  "type": "post"
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/content_manage/run`

---

## 2. plugin_manage — Plugin Management

**Description:** Safely inspect and manage WordPress plugins. Registry-driven, approval-aware, health-verified. Uses native WordPress plugin APIs.

**Capability required:** `plugin.manage`

**Approval required:** Yes

**Risk level:** Variable (per sub-action):

| Sub-action | Risk |
|---|---|
| `plugin_list` | low |
| `plugin_install` | medium |
| `plugin_activate` | medium |
| `plugin_deactivate` | medium |
| `plugin_update` | high |
| `plugin_delete` | critical |

**Rollback support:** No (activation/deactivation are reversible manually; install/delete are permanent)

**Sub-actions:** `plugin_list`, `plugin_install`, `plugin_activate`, `plugin_deactivate`, `plugin_update`, `plugin_delete`

**Example request JSON:**
```json
{
  "operation_id": "plugin_manage",
  "payload": {
    "action": "plugin_install",
    "slug": "wordpress-seo"
  }
}
```

**Example response JSON:**
```json
{
  "action": "plugin_install",
  "slug": "wordpress-seo",
  "status": "installed",
  "version": "21.0",
  "name": "Yoast SEO"
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/plugin_manage/run`

---

## 3. theme_manage — Theme Management

**Description:** Safely inspect and manage WordPress themes. Registry-driven, approval-aware, health-verified. Uses native WordPress theme APIs.

**Capability required:** `theme.manage`

**Approval required:** Yes

**Risk level:** Variable (per sub-action):

| Sub-action | Risk |
|---|---|
| `theme_list` | low |
| `theme_install` | medium |
| `theme_activate` | critical |
| `theme_update` | high |
| `theme_delete` | critical |

**Rollback support:** No

**Sub-actions:** `theme_list`, `theme_install`, `theme_activate`, `theme_update`, `theme_delete`

**Example request JSON:**
```json
{
  "operation_id": "theme_manage",
  "payload": {
    "action": "theme_activate",
    "slug": "twentytwentyfive"
  }
}
```

**Example response JSON:**
```json
{
  "action": "theme_activate",
  "slug": "twentytwentyfive",
  "status": "active",
  "previous_theme": "twentytwentyfour"
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/theme_manage/run`

---

## 4. option_manage — Option Management

**Description:** Safely inspect and update approved WordPress options through the operations framework. Registry-driven, risk-scored, approval-aware. Only registered options may be accessed; no arbitrary option names.

**Capability required:** `option.manage`

**Approval required:** Yes

**Risk level:** Variable (per option; see option definitions in `includes/Operations/OptionRegistry.php`)

**Rollback support:** Yes (`option_update` records the previous value; use `option_rollback` with the returned `rollback_id`)

**Sub-actions:** `option_get`, `option_update`, `option_rollback`

**Supported options (13):**

| Option ID | WordPress option | Type | Risk | Group |
|---|---|---|---|---|
| `site_title` | `blogname` | string | low | site_settings |
| `tagline` | `blogdescription` | string | low | site_settings |
| `timezone` | `timezone_string` | string | low | site_settings |
| `date_format` | `date_format` | string | low | site_settings |
| `time_format` | `time_format` | string | low | site_settings |
| `start_of_week` | `start_of_week` | integer | low | site_settings |
| `posts_per_page` | `posts_per_page` | integer | medium | reading_settings |
| `show_on_front` | `show_on_front` | string | medium | reading_settings |
| `page_on_front` | `page_on_front` | integer | medium | reading_settings |
| `page_for_posts` | `page_for_posts` | integer | medium | reading_settings |
| `default_comment_status` | `default_comment_status` | string | medium | discussion_settings |
| `default_ping_status` | `default_ping_status` | string | medium | discussion_settings |
| `admin_email` | `admin_email` | email | high | admin |

**Example request JSON:**
```json
{
  "operation_id": "option_manage",
  "payload": {
    "action": "option_update",
    "option_id": "site_title",
    "value": "My New Site Title"
  }
}
```

**Example response JSON:**
```json
{
  "action": "option_update",
  "option_id": "site_title",
  "option_name": "blogname",
  "previous_value": "Old Site Title",
  "new_value": "My New Site Title",
  "rollback_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/option_manage/run`

---

## 5. snapshot_manage — Snapshot Management

**Description:** Create, list, inspect, verify, and restore file snapshots. Wraps the existing Snapshot and Rollback Engines. Snapshots capture file contents at a point in time for rollback.

**Capability required:** `snapshot.manage`

**Approval required:** Yes

**Risk level:** Variable (per sub-action):

| Sub-action | Risk |
|---|---|
| `snapshot_list` | low |
| `snapshot_details` | low |
| `snapshot_create` | medium |
| `snapshot_verify` | medium |
| `snapshot_restore` | critical |

**Rollback support:** Yes (this _is_ the rollback system; `snapshot_restore` restores a file to a previous snapshot)

**Sub-actions:** `snapshot_create`, `snapshot_list`, `snapshot_details`, `snapshot_verify`, `snapshot_restore`

**Example request JSON:**
```json
{
  "operation_id": "snapshot_manage",
  "payload": {
    "action": "snapshot_create",
    "path": "themes/twentytwentyfive/functions.php",
    "label": "Pre-update snapshot"
  }
}
```

**Example response JSON:**
```json
{
  "action": "snapshot_create",
  "snapshot_id": "snap_a1b2c3d4",
  "path": "themes/twentytwentyfive/functions.php",
  "label": "Pre-update snapshot",
  "created_at": "2026-06-12T10:30:00Z",
  "hash": "sha256:abc123..."
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/snapshot_manage/run`

---

## 6. database_inspect — Database Inspection

**Description:** Read-only database health and structure inspection. No INSERT, UPDATE, DELETE, DROP, or arbitrary SQL allowed. Write keywords are blocked. Only core WordPress tables (`wp_*` prefix) are accessible.

**Capability required:** `database.inspect`

**Approval required:** No (read-only)

**Risk level:** Low

**Rollback support:** N/A (read-only)

**Sub-actions:** `db_table_list`, `db_table_stats`, `db_table_size`, `db_row_counts`, `db_autoload_analysis`, `db_options_health`, `db_index_analysis`, `db_orphan_detection`, `db_health_summary`

**Allowed tables:** All core WordPress tables with the site's table prefix (`wp_posts`, `wp_options`, `wp_postmeta`, `wp_users`, `wp_usermeta`, `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`, `wp_comments`, `wp_commentmeta`, `wp_links`)

**Prohibited keywords (blocked from queries):** INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE, REPLACE, GRANT, REVOKE, LOCK, UNLOCK, RENAME, LOAD, INTO OUTFILE, INTO DUMPFILE

**Risk model per sub-action:**

| Sub-action | Risk |
|---|---|
| `db_table_list` | low |
| `db_row_counts` | low |
| `db_health_summary` | low |
| `db_table_stats` | medium |
| `db_table_size` | medium |
| `db_autoload_analysis` | medium |
| `db_options_health` | medium |
| `db_index_analysis` | medium |
| `db_orphan_detection` | medium |

**Example request JSON:**
```json
{
  "operation_id": "database_inspect",
  "payload": {
    "action": "db_table_size",
    "table": "wp_posts"
  }
}
```

**Example response JSON:**
```json
{
  "action": "db_table_size",
  "table": "wp_posts",
  "data_size_mb": 12.5,
  "index_size_mb": 3.2,
  "total_size_mb": 15.7,
  "rows": 1542
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/database_inspect/run`
(Note: this endpoint permits read_only tokens)

---

## 7. wp_cli_bridge — WP-CLI Bridge

**Description:** Execute structured WP-CLI commands with risk-based approval workflow. Accepts `command_id` + `args` or legacy bare `command` (limited 6-command allowlist). No raw shell, pipes, redirects, or arbitrary command execution.

**Capability required:** `wpcli.execute`

**Approval required:** Yes

**Risk level:** Variable (per command; 20 structured commands across 4 risk levels)

**Rollback support:** No (WP-CLI commands are executed directly)

**Sub-actions:** N/A (driven by `command_id` from the supported command registry; see `includes/Operations/WpCliCommandRegistry.php`)

**Supported commands by risk level:**

| Risk | Count | Commands |
|---|---|---|
| Low | 8 | `plugin_list`, `theme_list`, `option_get_siteurl`, `option_get_home`, `cron_event_list`, `transient_delete_expired`, `rewrite_list`, `db_size_check` |
| Medium | 5 | `cache_flush`, `rewrite_flush`, `cron_event_run_due_now`, `option_update_blogdescription`, `option_update_blogname` |
| High | 4 | `plugin_update_single`, `theme_update_single`, `search_replace_dry_run`, `search_replace_execute` |
| Critical | 3 | `db_export`, `db_optimize`, `db_repair` |

**Permanently blocked subcommands:** `db reset`, `db drop`, `db import`, `user delete`, `post delete`, `plugin delete`, `theme delete`, `core update`, `core download`, `eval`, `eval-file`, `shell`, `package install`, `scaffold`, `config set`, `config create`, `rewrite structure`

**Example request JSON (structured):**
```json
{
  "operation_id": "wp_cli_bridge",
  "payload": {
    "command_id": "plugin_list",
    "args": {
      "status": "active",
      "format": "json"
    }
  }
}
```

**Example response JSON:**
```json
{
  "command_id": "plugin_list",
  "exit_code": 0,
  "output": "[{\"name\":\"akismet\",\"status\":\"active\",\"version\":\"5.3\"}]",
  "duration_ms": 234
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/wp_cli_bridge/run`

---

## 8. safe_search_replace — Search & Replace

**Description:** Perform a dry-run or live search and replace in the database with rollback support. Dry-run is the default (`dry_run: true`) — you must explicitly set `dry_run: false` for a live run.

**Capability required:** `wpcli.execute`

**Approval required:** Yes

**Risk level:** High

**Rollback support:** No (this operation does not auto-snapshot; use snapshot_manage separately if needed)

**Sub-actions:** N/A

**Example request JSON (dry-run):**
```json
{
  "operation_id": "safe_search_replace",
  "payload": {
    "search": "http://oldsite.local",
    "replace": "https://newsite.com",
    "dry_run": true,
    "tables": ["wp_posts", "wp_postmeta", "wp_options"],
    "case_sensitive": false
  }
}
```

**Example request JSON (live):**
```json
{
  "operation_id": "safe_search_replace",
  "payload": {
    "search": "http://oldsite.local",
    "replace": "https://newsite.com",
    "dry_run": false,
    "tables": ["wp_posts", "wp_postmeta", "wp_options"],
    "case_sensitive": false
  }
}
```

**Example response JSON:**
```json
{
  "search": "http://oldsite.local",
  "replace": "https://newsite.com",
  "dry_run": true,
  "tables_scanned": 3,
  "rows_matched": 47,
  "changes": [
    {"table": "wp_posts", "rows": 12},
    {"table": "wp_postmeta", "rows": 30},
    {"table": "wp_options", "rows": 5}
  ]
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/safe_search_replace/run`

---

## 9. safe_updates — Plugin/Theme Updates with Health Verification

**Description:** Update WordPress plugins or themes with automatic snapshot and health verification. Supports `dry_run: true` (default) to preview what would happen.

**Capability required:** `plugin.manage`

**Approval required:** Yes

**Risk level:** High

**Rollback support:** Auto-snapshots before update; rollback via snapshot_manage if health check fails

**Sub-actions:** N/A

**Example request JSON:**
```json
{
  "operation_id": "safe_updates",
  "payload": {
    "type": "plugin",
    "slug": "wordpress-seo",
    "dry_run": false
  }
}
```

**Example response JSON:**
```json
{
  "type": "plugin",
  "slug": "wordpress-seo",
  "previous_version": "20.0",
  "new_version": "21.0",
  "health_check_passed": true,
  "snapshot_id": "snap_xyz789"
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/safe_updates/run`

---

## 10. media_import — Media Library Import

**Description:** Import a remote image to the WordPress Media Library using native WordPress APIs (`media_sideload_image`).

**Capability required:** `content.manage`

**Approval required:** Yes

**Risk level:** Medium

**Rollback support:** No

**Sub-actions:** N/A

**Example request JSON:**
```json
{
  "operation_id": "media_import",
  "payload": {
    "source_url": "https://example.com/images/photo.jpg",
    "title": "Sample Photo",
    "alt": "A sample photo",
    "caption": "This is a caption",
    "description": "Longer description text",
    "attach_to_post_id": 42
  }
}
```

**Example response JSON:**
```json
{
  "attachment_id": 99,
  "source_url": "https://example.com/images/photo.jpg",
  "title": "Sample Photo",
  "url": "https://mysite.com/wp-content/uploads/2026/06/photo.jpg",
  "mime_type": "image/jpeg"
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/media_import/run`

---

## 11. content_seed — Content Seeding

**Description:** Generate and insert sample posts or pages for testing/demo purposes.

**Capability required:** None (unrestricted — no explicit capability required)

**Approval required:** Yes

**Risk level:** Medium

**Rollback support:** No (created content can be manually deleted)

**Sub-actions:** N/A

**Example request JSON:**
```json
{
  "operation_id": "content_seed",
  "payload": {
    "type": "post",
    "count": 10,
    "status": "draft",
    "title_pattern": "Demo Post {n}",
    "content_template": "This is sample content for post {n}."
  }
}
```

**Example response JSON:**
```json
{
  "type": "post",
  "count": 10,
  "status": "draft",
  "created": [43, 44, 45, 46, 47, 48, 49, 50, 51, 52]
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/content_seed/run`

---

## 12. acf_seed — ACF Field Seeding

**Description:** Populate existing ACF fields on WordPress content using native ACF APIs. Only available when Advanced Custom Fields (free or Pro) is active.

**Capability required:** None (unrestricted)

**Approval required:** Yes

**Risk level:** Medium

**Rollback support:** No

**Sub-actions:** N/A

**Example request JSON:**
```json
{
  "operation_id": "acf_seed",
  "payload": {
    "post_id": 42,
    "fields": {
      "hero_title": "Welcome to Our Site",
      "hero_subtitle": "We build amazing things",
      "cta_text": "Get Started"
    }
  }
}
```

**Example response JSON:**
```json
{
  "post_id": 42,
  "fields_updated": 3,
  "fields": ["hero_title", "hero_subtitle", "cta_text"]
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/acf_seed/run`

---

## 13. cf7_seed — Contact Form 7 Seeding

**Description:** Generate sample forms and mail configurations for Contact Form 7. Only available when Contact Form 7 is active.

**Capability required:** None (unrestricted)

**Approval required:** Yes

**Risk level:** Low

**Rollback support:** No

**Sub-actions:** N/A

**Example request JSON:**
```json
{
  "operation_id": "cf7_seed",
  "payload": {
    "title": "My Contact Form",
    "form_template": "contact_basic"
  }
}
```

**Example response JSON:**
```json
{
  "form_id": 12,
  "title": "My Contact Form",
  "template": "contact_basic"
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/cf7_seed/run`

---

## 14. woo_product_seed — WooCommerce Product Seeding

**Description:** Generate and insert simple WooCommerce products using native WooCommerce APIs. Only available when WooCommerce is active.

**Capability required:** None (unrestricted)

**Approval required:** Yes

**Risk level:** Medium

**Rollback support:** No

**Sub-actions:** N/A

**Example request JSON:**
```json
{
  "operation_id": "woo_product_seed",
  "payload": {
    "name": "Sample T-Shirt",
    "sku": "TS-001",
    "regular_price": "29.99",
    "sale_price": "24.99",
    "status": "draft",
    "stock_quantity": 100,
    "manage_stock": true,
    "categories": ["Clothing", "Summer"]
  }
}
```

**Example response JSON:**
```json
{
  "product_id": 201,
  "name": "Sample T-Shirt",
  "sku": "TS-001",
  "regular_price": "29.99",
  "status": "draft"
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/woo_product_seed/run`

---

## 15. capability_manage — Capability Management

**Description:** Manage which agents, tokens, and integrations may access which platform capabilities. The authorization layer for all other operations.

**Capability required:** `capability.admin`

**Approval required:** Yes

**Risk level:** Variable (per sub-action):

| Sub-action | Risk |
|---|---|
| `capability_list` | low |
| `capability_get` | low |
| `capability_validate` | low |
| `capability_assign` | high |
| `capability_remove` | high |

**Rollback support:** No (assignments are tracked in audit log; can reverse via `capability_remove`)

**Sub-actions:** `capability_list`, `capability_get`, `capability_assign`, `capability_remove`, `capability_validate`

**Supported subjects:** `token`, `role`, `integration`

**Example request JSON:**
```json
{
  "operation_id": "capability_manage",
  "payload": {
    "action": "capability_assign",
    "subject": "token",
    "subject_id": "tk_a1b2c3d4e5f6",
    "capability": "plugin.manage"
  }
}
```

**Example response JSON:**
```json
{
  "action": "capability_assign",
  "subject": "token",
  "subject_id": "tk_a1b2c3d4e5f6",
  "capability": "plugin.manage",
  "assigned": true
}
```

**Run endpoint:** `POST /wp-command-center/v1/operations/capability_manage/run`

---

## Approval Workflow

All operations with `requires_approval: true` must go through the operations/requests workflow:

1. `POST /operations/requests` — Create a request (status: `pending_review`)
2. `POST /operations/requests/{id}/approve` — Approve the request (status: `approved`)
3. `POST /operations/requests/{id}/execute` — Execute immediately, OR
4. `POST /operations/requests/{id}/queue` — Queue for execution via the background worker

Operations with `requires_approval: false` (e.g., `database_inspect`, low-risk sub-actions) may be run directly without the request workflow, assuming the token has the required capability.

## Run Endpoint Summary

| Operation | Run Endpoint |
|---|---|
| content_seed | `POST /wp-command-center/v1/operations/content_seed/run` |
| acf_seed | `POST /wp-command-center/v1/operations/acf_seed/run` |
| cf7_seed | `POST /wp-command-center/v1/operations/cf7_seed/run` |
| woo_product_seed | `POST /wp-command-center/v1/operations/woo_product_seed/run` |
| safe_search_replace | `POST /wp-command-center/v1/operations/safe_search_replace/run` |
| media_import | `POST /wp-command-center/v1/operations/media_import/run` |
| safe_updates | `POST /wp-command-center/v1/operations/safe_updates/run` |
| wp_cli_bridge | `POST /wp-command-center/v1/operations/wp_cli_bridge/run` |
| option_manage | `POST /wp-command-center/v1/operations/option_manage/run` |
| capability_manage | `POST /wp-command-center/v1/operations/capability_manage/run` |
| database_inspect | `POST /wp-command-center/v1/operations/database_inspect/run` |
| content_manage | `POST /wp-command-center/v1/operations/content_manage/run` |
| snapshot_manage | `POST /wp-command-center/v1/operations/snapshot_manage/run` |
| theme_manage | `POST /wp-command-center/v1/operations/theme_manage/run` |
| plugin_manage | `POST /wp-command-center/v1/operations/plugin_manage/run` |
