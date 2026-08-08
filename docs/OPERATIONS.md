# Operations reference

42 operations, each exposed as one MCP tool and one REST route. Generated from the live
catalogue — the same data `wpcc://operations` and `GET /operations` serve.

Every operation takes an `action`. An unknown action is refused with that runtime's own
error code and a message listing every valid action, so you can always discover the
surface from an error.

---

## Risk tiers and approval

| Risk tier | Standard protection | Strict approval | Development |
|---|---|---|---|
| diagnostic | immediate | immediate | immediate |
| low | immediate | approval | immediate |
| medium | approval | approval | immediate |
| high | approval | approval | immediate |
| critical | approval | approval | immediate |

An operation's declared risk is the default; individual actions may override it, which is
why a read action inside a write operation (`plugin_list` inside `plugin_manage`) stays
immediate.

---

## Parameter aliases

Some operations accept a synonym for their target parameter, normalised before validation.
Supplying both the canonical name and an alias with **different** values is refused rather
than guessed at.

| Operation | Canonical | Also accepted |
|---|---|---|
| `content_manage`, `seo_manage` | `content_id` | `post_id`, `page_id` |
| `site_builder_manage`, `elementor_manage` | `page_id` | `post_id`, `content_id` |
| `media_manage`, `media_enhance` | `media_id` | `attachment_id` |
| `acf_manage` | `object_id` | `content_id` |
| `option_manage` | `option_id` | `option`, `option_name` |
| `safe_updates` | `type` | `update_type` |

`media_manage` deliberately does **not** alias `post_id` to `media_id`:
`featured_image_assign` takes `post_id` as the target post and `media_id` as the image,
so mapping one to the other would silently retarget the write.

---

## The catalogue

### `acf_manage` — ACF Management

Risk: **medium**. Actions (31): `acf_group_list`, `acf_group_get`, `acf_group_create`, `acf_group_update`, `acf_group_delete`, `acf_group_duplicate`, `acf_group_activate`, `acf_group_deactivate`, `acf_field_list`, `acf_field_get`, `acf_field_create`, `acf_field_update`, `acf_field_delete`, `acf_field_duplicate`, `acf_location_list`, `acf_location_assign`, `acf_location_remove`, `acf_json_status`, `acf_json_export`, `acf_json_import`, `acf_json_sync`, `acf_json_diff`, `acf_value_get`, `acf_value_update`, `acf_value_set`, `acf_bulk_value_update`, `acf_inventory`, `acf_layout_create`, `acf_layout_update`, `acf_layout_usage`, `acf_describe`

### `acf_seed` — Seed ACF Fields

Risk: **medium**. Actions (0): _no action enum_

### `approval_manage` — Approval Runtime

Risk: **diagnostic**. Actions (13): `request_create`, `request_list`, `request_get`, `request_approve`, `request_reject`, `request_cancel`, `queue_list`, `queue_get`, `queue_run`, `queue_cancel`, `queue_retry`, `results_list`, `results_get`

### `bulk_manage` — Bulk Operations

Risk: **high**. Actions (7): `bulk_content`, `bulk_publish`, `bulk_unpublish`, `bulk_media`, `bulk_woocommerce`, `bulk_acf`, `batch_execute`

### `cache_manage` — Cache Purge

Risk: **low**. Actions (4): `cache_status`, `cache_purge_all`, `cache_purge_url`, `cache_describe`

### `capability_manage` — Capability Management

Risk: **critical**. Actions (5): `capability_list`, `capability_get`, `capability_assign`, `capability_remove`, `capability_validate`

### `cf7_seed` — Contact Form 7 Seeding

Risk: **medium**. Actions (0): _no action enum_

### `change_history` — Change History Runtime

Risk: **diagnostic**. Actions (6): `history_list`, `history_get`, `history_timeline`, `rollback_discover`, `rollback_target`, `operation_status`

### `code_search` — Code Search

Risk: **diagnostic**. Actions (3): `search_text`, `search_symbol`, `search_file`

### `comments_manage` — Comments Management

Risk: **medium**. Actions (8): `comment_list`, `comment_get`, `comment_approve`, `comment_unapprove`, `comment_spam`, `comment_trash`, `comment_delete`, `comment_reply`

### `content_manage` — Content Management

Risk: **medium**. Actions (11): `content_list`, `content_get`, `content_create`, `content_update`, `content_delete`, `content_publish`, `content_unpublish`, `content_schedule`, `taxonomy_assign`, `featured_image_assign`, `content_rollback`

### `content_seed` — Content Seeding

Risk: **medium**. Actions (0): _no action enum_

### `cpt_manage` — Custom Post Types

Risk: **high**. Actions (9): `cpt_list`, `cpt_get`, `cpt_create`, `cpt_update`, `cpt_disable`, `taxonomy_list`, `taxonomy_create`, `taxonomy_update`, `cpt_rollback`

### `database_inspect` — Database Inspection

Risk: **diagnostic**. Actions (9): `db_table_list`, `db_table_stats`, `db_table_size`, `db_row_counts`, `db_autoload_analysis`, `db_options_health`, `db_index_analysis`, `db_orphan_detection`, `db_health_summary`

### `elementor_manage` — Elementor

Risk: **medium**. Actions (6): `elementor_get_page`, `elementor_export_structure`, `elementor_list_widgets`, `elementor_update_text`, `elementor_update_image`, `elementor_update_button`

### `file_manage` — File Access

Risk: **diagnostic**. Actions (3): `file_read`, `file_tree`, `file_metadata`

### `forms_manage` — Forms Management

Risk: **medium**. Actions (18): `form_list`, `form_get`, `form_search`, `form_create`, `form_update`, `form_duplicate`, `form_delete`, `form_activate`, `form_deactivate`, `entry_list`, `entry_get`, `entry_search`, `entry_export`, `notification_get`, `notification_update`, `notification_test`, `submission_stats`, `form_analyze`

### `media_enhance` — Media Enhancement Runtime

Risk: **diagnostic**. Actions (26): `media_enhance_capabilities`, `image_sizes_list`, `image_size_usage_audit`, `image_size_recommendations`, `image_size_verify`, `srcset_verify`, `responsive_image_audit`, `missing_sizes_audit`, `image_size_context_audit`, `thumbnail_verify`, `thumbnail_regenerate`, `thumbnail_regenerate_attachment`, `thumbnail_regenerate_batch`, `webp_audit`, `webp_verify`, `webp_generate`, `webp_generate_batch`, `image_optimize_audit`, `image_optimize_verify`, `image_optimize`, `image_optimize_batch`, `media_usage_scan`, `media_usage_report`, `unused_media_find`, `orphaned_media_find`, `unused_media_cleanup`

### `media_import` — Media Library Import

Risk: **medium**. Actions (0): _no action enum_

### `media_manage` — Media Management

Risk: **medium**. Actions (18): `media_list`, `media_get`, `media_search`, `media_upload`, `media_update`, `media_replace`, `media_replace_verify`, `media_delete`, `media_restore`, `featured_image_assign`, `featured_image_remove`, `media_set_featured`, `media_remove_featured`, `media_regenerate_metadata`, `media_snapshot_create`, `media_snapshot_restore`, `media_snapshot_verify`, `media_snapshot_list`

### `menu_manage` — Menu Management

Risk: **medium**. Actions (24): `menu_list`, `menu_get`, `menu_create`, `menu_update`, `menu_delete`, `menu_duplicate`, `menu_export`, `menu_import`, `menu_item_list`, `menu_item_get`, `menu_item_add`, `menu_item_update`, `menu_item_remove`, `menu_item_move`, `menu_item_reorder`, `menu_location_list`, `menu_location_assign`, `menu_location_remove`, `menu_location_sync`, `menu_tree_get`, `menu_tree_validate`, `menu_tree_repair`, `menu_analyze`, `menu_inventory`

### `option_manage` — Option Management

Risk: **high**. Actions (3): `option_get`, `option_update`, `option_rollback`

### `patch_manage` — Patch Engine

Risk: **high**. Actions (5): `patch_preview`, `patch_create`, `patch_apply`, `patch_verify`, `patch_status`

### `plugin_manage` — Plugin Management

Risk: **critical**. Actions (7): `plugin_list`, `plugin_install`, `plugin_activate`, `plugin_deactivate`, `plugin_update`, `plugin_delete`, `plugin_rollback`

### `report_manage` — Reporting Runtime

Risk: **diagnostic**. Actions (9): `report_list`, `report_site_health`, `report_plugin_health`, `report_security`, `report_content`, `report_woocommerce`, `report_agent_activity`, `report_approval_activity`, `report_patch_activity`

### `rollback_manage` — Rollback Engine

Risk: **high**. Actions (4): `rollback_list`, `rollback_get`, `rollback_apply`, `rollback_verify`

### `safe_search_replace` — Safe Search & Replace

Risk: **critical**. Actions (0): _no action enum_

### `safe_updates` — Safe WordPress Updates

Risk: **high**. Actions (0): _no action enum_

### `search_manage` — Search & Reports

Risk: **diagnostic**. Actions (0): _no action enum_

### `seo_manage` — SEO Management

Risk: **medium**. Actions (5): `seo_get`, `seo_update`, `seo_validate`, `seo_analyze`, `seo_restore`

### `settings_manage` — Site Settings

Risk: **high**. Actions (14): `settings_general_get`, `settings_general_update`, `settings_reading_get`, `settings_reading_update`, `settings_discussion_get`, `settings_discussion_update`, `settings_media_get`, `settings_media_update`, `settings_permalink_get`, `settings_permalink_update`, `settings_privacy_get`, `settings_privacy_update`, `settings_inventory`, `settings_analyze`

### `site_builder_manage` — Site Builder

Risk: **medium**. Actions (13): `page_list`, `page_get`, `page_create`, `page_update`, `page_delete`, `template_list`, `template_assign`, `pattern_create`, `pattern_list`, `navigation_manage`, `menu_create`, `menu_update`, `menu_assign`

### `snapshot_manage` — Snapshot Management

Risk: **high**. Actions (5): `snapshot_create`, `snapshot_list`, `snapshot_details`, `snapshot_restore`, `snapshot_verify`

### `system_info` — System Info

Risk: **diagnostic**. Actions (0): _no action enum_

### `term_manage` — Term Lookup

Risk: **diagnostic**. Actions (4): `term_list`, `term_get`, `term_search`, `term_describe`

### `theme_manage` — Theme Management

Risk: **critical**. Actions (6): `theme_list`, `theme_install`, `theme_activate`, `theme_update`, `theme_delete`, `theme_rollback`

### `user_manage` — User Management

Risk: **critical**. Actions (10): `user_list`, `user_get`, `user_search`, `user_create`, `user_update`, `user_delete`, `user_suspend`, `user_reset_password`, `user_assign_role`, `user_remove_role`

### `widgets_manage` — Widgets & Sidebars

Risk: **medium**. Actions (8): `widget_list`, `widget_get`, `widget_add`, `widget_update`, `widget_remove`, `sidebar_assign`, `sidebar_remove`, `widgets_rollback`

### `woo_product_seed` — WooCommerce Product Seeder

Risk: **medium**. Actions (0): _no action enum_

### `woocommerce_manage` — WooCommerce Management

Risk: **medium**. Actions (41): `product_list`, `product_get`, `product_search`, `product_create`, `product_update`, `product_delete`, `product_publish`, `product_unpublish`, `product_duplicate`, `stock_get`, `stock_update`, `stock_bulk_update`, `price_get`, `price_update`, `sale_price_update`, `product_category_assign`, `product_category_remove`, `product_category_list`, `product_attribute_assign`, `product_attribute_remove`, `product_attribute_list`, `variation_list`, `variation_get`, `variation_create`, `variation_update`, `variation_delete`, `order_list`, `order_get`, `order_search`, `order_update`, `order_note_add`, `order_status_change`, `refund_create`, `customer_get`, `customer_search`, `coupon_list`, `coupon_get`, `coupon_create`, `coupon_update`, `coupon_delete`, `woo_describe`

### `workflow_manage` — Workflow Runtime

Risk: **high**. Actions (10): `workflow_list`, `workflow_get`, `workflow_create`, `workflow_update`, `workflow_delete`, `workflow_execute`, `workflow_import`, `workflow_export`, `workflow_history`, `workflow_rollback`

### `wp_cli_bridge` — WP-CLI Bridge

Risk: **critical**. Actions (0): _no action enum_

---

## Undo

Reversible results carry a `rollback` block naming exactly how to reverse them. The
general entry point is `change_history` with `action: rollback_target`. Patches are
reversed through `patch_manage`. Some operations also accept a direct action —
`content_rollback`, `option_rollback`, `seo_restore`.

Undo is field-scoped and drift-aware: fields changed since the original write are
**skipped rather than clobbered**, and a partial undo reports `restored_fields`,
`skipped_fields` and `conflicts`.
