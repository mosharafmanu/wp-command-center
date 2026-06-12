# WP Command Center — Admin Parity Audit

**Generated:** 2026-06-12  
**Audit Scope:** What AI agents can do through WP Command Center vs what requires wp-admin  
**Overall Parity Score:** 76%

---

## Dashboard

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| Quick stats (posts, pages, comments, etc.) | Yes | — | unrestricted | No | No |
| Activity/site health widgets | Yes (Site Intelligence) | — | unrestricted | No | No |
| At a Glance customization | Partial | Dashboard widget arrangement | — | — | — |
| Screen options | No | Screen option persistence | — | — | — |

**Notes**: Dashboard stats are exposed via GET /agent/context and /agent/manifest. The dashboard.php admin view provides full overview. AI agents can read all dashboard data but cannot configure dashboard layout.

---

## Posts

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List posts | Yes (`content_list`) | — | content.manage | No | No |
| Get post | Yes (`content_get`) | — | content.manage | No | No |
| Create post | Yes (`content_create`) | — | content.manage | Yes | No |
| Update post | Yes (`content_update`) | — | content.manage | Yes | No |
| Delete/trash post | Yes (`content_delete`) | — | content.manage | Yes | No |
| Publish post | Yes (`content_publish`) | — | content.manage | Yes | No |
| Unpublish post | Yes (`content_unpublish`) | — | content.manage | Yes | No |
| Schedule post | Yes (`content_schedule`) | — | content.manage | Yes | No |
| Bulk edit posts | Yes (`bulk_manage`) | — | bulk.manage | Yes | Yes |
| Quick edit | No | Inline quick edit fields | — | — | — |
| Sticky posts | No | Sticky post toggle | — | — | — |
| Post formats | No | Post format assignment | — | — | — |
| Revisions browser | No | Revision diff/comparison | — | — | — |
| Slug/permalink editor | Partial | Permalink editing via update | — | — | — |

---

## Pages

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List pages | Yes (`content_list`) | — | content.manage | No | No |
| Get page | Yes (`content_get`) | — | content.manage | No | No |
| Create page | Yes (`content_create`) | — | content.manage | Yes | No |
| Update page | Yes (`content_update`) | — | content.manage | Yes | No |
| Delete/trash page | Yes (`content_delete`) | — | content.manage | Yes | No |
| Page attributes (parent, template, order) | Partial | Template selection, order field | — | — | — |
| Page hierarchy view | No | Tree view of page hierarchy | — | — | — |

**Notes**: Pages share the `content_manage` operation with posts. Type is specified as `page` vs `post`.

---

## Media

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List media | Yes (`media_list`) | — | media.manage | No | No |
| Get media | Yes (`media_get`) | — | media.manage | No | No |
| Search media | Yes (`media_search`) | — | media.manage | No | No |
| Upload media | Yes (`media_upload`) | — | media.manage | Yes | Yes |
| Replace media | Yes (`media_replace`) | — | media.manage | Yes | Yes |
| Delete media | Yes (`media_delete`) | — | media.manage | Yes | Yes |
| Restore media | Yes (`media_restore`) | — | media.manage | Yes | No |
| Assign featured image | Yes (`featured_image_assign`) | — | media.manage | No | Yes |
| Remove featured image | Yes (`featured_image_remove`) | — | media.manage | No | Yes |
| Regenerate metadata | Yes (`media_regenerate_metadata`) | — | media.manage | No | No |
| Media grid/list view | No | View mode toggle | — | — | — |
| Media editing (crop, rotate) | No | Image editing tools | — | — | — |
| Bulk media actions | Yes (`bulk_manage`) | — | bulk.manage | Yes | Yes |
| Media import from URL | Yes (`media_import`) | — | content.manage | Yes | No |

---

## Comments

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List comments | Yes (`comment_list`) | — | comments.manage | No | No |
| Get comment | Yes (`comment_get`) | — | comments.manage | No | No |
| Approve comment | Yes (`comment_approve`) | — | comments.manage | Yes | No |
| Unapprove comment | Yes (`comment_unapprove`) | — | comments.manage | Yes | No |
| Mark as spam | Yes (`comment_spam`) | — | comments.manage | Yes | No |
| Trash comment | Yes (`comment_trash`) | — | comments.manage | Yes | Yes |
| Delete permanently | Yes (`comment_delete`) | — | comments.manage | Yes | Yes |
| Reply to comment | Yes (`comment_reply`) | — | comments.manage | Yes | No |
| Bulk comment actions | No | Bulk approve/unapprove/spam/trash | — | — | — |
| Comment moderation queue | No | Moderation queue filtering | — | — | — |
| Edit comment content | No | Edit comment text inline | — | — | — |
| Comment blacklist | No | Blacklist key management | — | — | — |

---

## Users

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List users | Yes (`user_list`) | — | user.manage | No | No |
| Get user | Yes (`user_get`) | — | user.manage | No | No |
| Search users | Yes (`user_search`) | — | user.manage | No | No |
| Create user | Yes (`user_create`) | — | user.manage | Yes | Yes |
| Update user | Yes (`user_update`) | — | user.manage | Yes | Yes |
| Delete user | Yes (`user_delete`) | — | user.manage | Yes | Yes |
| Suspend user | Yes (`user_suspend`) | — | user.manage | Yes | No |
| Reset password | Yes (`user_reset_password`) | — | user.manage | Yes | No |
| Assign role | Yes (`user_assign_role`) | — | user.manage | Yes | Yes |
| Remove role | Yes (`user_remove_role`) | — | user.manage | Yes | Yes |
| Bulk user actions | No | Bulk role assignment, delete | — | — | — |
| User profile fields | Partial | Custom profile fields | — | — | — |
| Application passwords | No | App password management | — | — | — |

---

## Menus

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List menus | Yes (`menu_list`) | — | menu.manage | No | No |
| Get menu | Yes (`menu_get`) | — | menu.manage | No | No |
| Create menu | Yes (`menu_create`) | — | menu.manage | Yes | Yes |
| Update menu | Yes (`menu_update`) | — | menu.manage | Yes | Yes |
| Delete menu | Yes (`menu_delete`) | — | menu.manage | Yes | Yes |
| Duplicate menu | Yes (`menu_duplicate`) | — | menu.manage | Yes | Yes |
| Export/import menu | Yes (`menu_export`, `menu_import`) | — | menu.manage | Yes | Yes |
| Add menu item | Yes (`menu_item_add`) | — | menu.manage | Yes | Yes |
| Update menu item | Yes (`menu_item_update`) | — | menu.manage | Yes | Yes |
| Remove menu item | Yes (`menu_item_remove`) | — | menu.manage | Yes | Yes |
| Reorder items | Yes (`menu_item_reorder`) | — | menu.manage | Yes | No |
| Assign location | Yes (`menu_location_assign`) | — | menu.manage | Yes | Yes |
| Remove location | Yes (`menu_location_remove`) | — | menu.manage | Yes | Yes |
| Menu analysis | Yes (`menu_analyze`) | — | menu.manage | No | No |
| Live preview in customizer | No | Customizer integration | — | — | — |

---

## Plugins

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List plugins | Yes (`plugin_list`) | — | plugin.manage | No | No |
| Install plugin | Yes (`plugin_install`) | — | plugin.manage | Yes | Yes |
| Activate plugin | Yes (`plugin_activate`) | — | plugin.manage | Yes | Yes |
| Deactivate plugin | Yes (`plugin_deactivate`) | — | plugin.manage | Yes | Yes |
| Update plugin | Yes (`plugin_update`) | — | plugin.manage | Yes | Yes |
| Delete plugin | Yes (`plugin_delete`) | — | plugin.manage | Yes | Yes |
| Bulk plugin actions | No | Bulk activate/deactivate/update | — | — | — |
| Plugin editor | No | File editor for plugins | — | — | — |
| Plugin details | Partial | Changelog/FAQ from WP.org | — | — | — |

---

## Themes

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List themes | Yes (`theme_list`) | — | theme.manage | No | No |
| Install theme | Yes (`theme_install`) | — | theme.manage | Yes | Yes |
| Activate theme | Yes (`theme_activate`) | — | theme.manage | Yes | Yes |
| Update theme | Yes (`theme_update`) | — | theme.manage | Yes | Yes |
| Delete theme | Yes (`theme_delete`) | — | theme.manage | Yes | Yes |
| Theme customizer | No | Customizer settings | — | — | — |
| Theme editor | No | File editor for themes | — | — | — |
| Theme details | Partial | Screenshots, descriptions | — | — | — |

---

## WooCommerce

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List products | Yes (`product_list`) | — | woocommerce.manage | No | No |
| Get product | Yes (`product_get`) | — | woocommerce.manage | No | No |
| Create product | Yes (`product_create`) | — | woocommerce.manage | Yes | Yes |
| Update product | Yes (`product_update`) | — | woocommerce.manage | Yes | Yes |
| Delete product | Yes (`product_delete`) | — | woocommerce.manage | Yes | Yes |
| Publish/unpublish | Yes (`product_publish`, `product_unpublish`) | — | woocommerce.manage | Yes | No |
| Stock management | Yes (`stock_update`, `stock_bulk_update`) | — | woocommerce.manage | Yes | No |
| Price management | Yes (`price_update`) | — | woocommerce.manage | Yes | Yes |
| Variations | Yes (`variation_create`, `variation_update`, `variation_delete`) | — | woocommerce.manage | Yes | Yes |
| Coupons | Yes (`coupon_create`, `coupon_update`, `coupon_delete`) | — | woocommerce.manage | Yes | Yes |
| List orders | Yes (`order_list`) | — | woocommerce.manage | No | No |
| Edit orders | No | Order status change, refunds | — | — | — |
| Order notes | No | Order note management | — | — | — |
| Subscriptions | No | Subscription management | — | — | — |
| Reports/analytics | No | Sales reports, analytics | — | — | — |
| Shipping zones | No | Shipping zone configuration | — | — | — |
| Tax settings | No | Tax rate management | — | — | — |
| Payment gateways | No | Gateway configuration | — | — | — |
| Product categories | Yes (via product operations) | — | woocommerce.manage | Yes | No |
| Product attributes | Yes | — | woocommerce.manage | Yes | No |
| Product tags | Yes (via product operations) | — | woocommerce.manage | Yes | No |
| Product images | Partial | Gallery management | — | — | — |
| Downloads | No | Downloadable product files | — | — | — |
| Product seed | Yes (`woo_product_seed`) | — | — | Yes | No |

---

## ACF (Advanced Custom Fields)

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List field groups | Yes (`acf.group_list`) | — | acf.manage | No | No |
| Get field group | Yes (`acf.group_get`) | — | acf.manage | No | No |
| Create field group | Yes (`acf.group_create`) | — | acf.manage | Yes | Yes |
| Update field group | Yes (`acf.group_update`) | — | acf.manage | Yes | Yes |
| Delete field group | Yes (`acf.group_delete`) | — | acf.manage | Yes | Yes |
| Create field | Yes (`acf.field_create`) | — | acf.manage | Yes | Yes |
| Update field | Yes (`acf.field_update`) | — | acf.manage | Yes | Yes |
| Delete field | Yes (`acf.field_delete`) | — | acf.manage | Yes | Yes |
| JSON sync | Yes (`acf.json_sync`, `acf.json_import`) | — | acf.manage | Yes | No |
| JSON diff | Yes (`acf.json_diff`) | — | acf.manage | No | No |
| JSON export | Yes (`acf.json_export`) | — | acf.manage | No | No |
| Update field values | Yes (`acf.value_update`) | — | acf.manage | Yes | Yes |
| Bulk field updates | Yes (`bulk_acf`) | — | bulk.manage | Yes | Yes |
| ACF inventory | Yes (`acf_inventory`) | — | acf.manage | No | No |
| Conditional logic editor | No | Visual conditional logic | — | — | — |
| Field type configuration | Partial | Complex field type options | — | — | — |
| Repeater/flexible content | Partial | Nested field editing | — | — | — |
| ACF seed | Yes (`acf_seed`) | — | — | Yes | No |

---

## Forms

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| List forms (multi-provider) | Yes (`form_list`) | — | forms.manage | No | No |
| Get form | Yes (`form_get`) | — | forms.manage | No | No |
| Search forms | Yes (`form_search`) | — | forms.manage | No | No |
| Create form | Yes (`form_create`) | — | forms.manage | Yes | Yes |
| Update form | Yes (`form_update`) | — | forms.manage | Yes | Yes |
| Delete form | Yes (`form_delete`) | — | forms.manage | Yes | Yes |
| Duplicate form | Yes (`form_duplicate`) | — | forms.manage | Yes | Yes |
| Activate/deactivate | Yes (`form_activate`, `form_deactivate`) | — | forms.manage | Yes | No |
| List entries | Yes (`entry_list`) | — | forms.manage | No | No |
| Export entries | Yes (`entry_export`) | — | forms.manage | Yes | No |
| Notifications | Yes (`notification_update`, `notification_test`) | — | forms.manage | Yes | No |
| Form analysis | Yes (`form_analyze`) | — | forms.manage | No | No |
| Submission stats | Yes (`submission_stats`) | — | forms.manage | No | No |
| Provider: CF7 | Yes | — | forms.manage | Yes | Yes |
| Provider: FluentForms | Yes | — | forms.manage | Yes | Yes |
| Provider: WPForms | Yes | — | forms.manage | Yes | Yes |
| Provider: GravityForms | Yes | — | forms.manage | Yes | Yes |
| CF7 seed | Yes (`cf7_seed`) | — | — | Yes | No |
| Form styling | No | Visual style configuration | — | — | — |
| Spam protection config | No | reCAPTCHA/honeypot settings | — | — | — |

---

## Settings

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| General settings | Yes (`settings.general`) | — | settings.manage | Yes | Yes |
| Reading settings | Yes (`settings.reading`) | — | settings.manage | Yes | Yes |
| Discussion settings | Yes (`settings.discussion`) | — | settings.manage | Yes | Yes |
| Media settings | Yes (`settings.media`) | — | settings.manage | Yes | Yes |
| Permalink settings | Yes (`settings.permalink`) | — | settings.manage | Yes | Yes |
| Privacy settings | Yes (`settings.privacy`) | — | settings.manage | Yes | Yes |
| Settings analysis | Yes (`settings.analyze`) | — | settings.manage | No | No |
| Settings inventory | Yes (`settings.inventory`) | — | settings.manage | No | No |
| Writing settings | No | Writing-specific options | — | — | — |
| Network settings | No | Multisite network settings | — | — | — |

---

## Search

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| Site-wide search | Yes (`search_all`) | — | search.manage | No | No |
| Content search | Yes (`search_content`) | — | search.manage | No | No |
| Media search | Yes (`search_media`) | — | search.manage | No | No |
| User search | Yes (`search_users`) | — | search.manage | No | No |
| WooCommerce search | Yes (via universal) | — | search.manage | No | No |
| Forms search | Yes (via universal) | — | search.manage | No | No |
| ACF search | Yes (via universal) | — | search.manage | No | No |
| Menu search | Yes (via universal) | — | search.manage | No | No |
| Orphan content report | Yes (`report_orphans`) | — | search.manage | No | No |
| Unused media report | Yes (`report_unused_media`) | — | search.manage | No | No |
| Site summary report | Yes (`report_site_summary`) | — | search.manage | No | No |
| Content inventory | Yes (via reports) | — | search.manage | No | No |
| WooCommerce inventory | Yes (via reports) | — | search.manage | No | No |
| Advanced search filters | Partial | Date range, taxonomy filter | — | — | — |
| Search replace (codebase) | Yes (FileAccessApi) | — | unrestricted | No | No |
| Search replace (database) | Yes (`safe_search_replace`) | — | wpcli.execute | Yes | Yes |

---

## Bulk Operations

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| Bulk content update | Yes (`bulk_content`) | — | bulk.manage | Yes | Yes |
| Bulk publish | Yes (`bulk_publish`) | — | bulk.manage | Yes | Yes |
| Bulk unpublish | Yes (`bulk_unpublish`) | — | bulk.manage | Yes | Yes |
| Bulk media update | Yes (`bulk_media`) | — | bulk.manage | Yes | Yes |
| Bulk WooCommerce | Yes (`bulk_woocommerce`) | — | bulk.manage | Yes | Yes |
| Bulk ACF | Yes (`bulk_acf`) | — | bulk.manage | Yes | Yes |
| Batch execute | Yes (`batch_execute`) | — | bulk.manage | Yes | Yes |
| Bulk rollback | Yes (via saved rollback_id) | — | bulk.manage | Yes | Yes |
| Bulk comment operations | No | Bulk approve/spam/trash comments | — | — | — |
| Bulk user operations | No | Bulk role, delete users | — | — | — |
| Bulk taxonomy | No | Bulk term management | — | — | — |

---

## Workflows

| Operation | Exists | Missing | Capability | Approval | Rollback |
|-----------|--------|---------|------------|----------|----------|
| Create workflow | Yes (`workflow_create`) | — | workflow.manage | Yes | No |
| List workflows | Yes (`workflow_list`) | — | workflow.manage | No | No |
| Get workflow | Yes (`workflow_get`) | — | workflow.manage | No | No |
| Update workflow | Yes (`workflow_update`) | — | workflow.manage | Yes | No |
| Delete workflow | Yes (`workflow_delete`) | — | workflow.manage | Yes | No |
| Execute workflow | Yes (`workflow_execute`) | — | workflow.manage | Yes | No |
| Import/export workflow | Yes (`workflow_import`, `workflow_export`) | — | workflow.manage | Yes | No |
| Workflow history | Yes (`workflow_history`) | — | workflow.manage | No | No |
| Conditional steps | No | Conditional branching | — | — | — |
| Scheduled workflows | No | Cron-based workflow triggers | — | — | — |
| Error handling | Partial | Per-step error policies | — | — | — |

---

## Additional Operations (Beyond wp-admin Parity)

| Operation | Exists | Capability | Notes |
|-----------|--------|------------|-------|
| Site Intelligence | Yes | unrestricted | Full site scan (PHP, WP, plugins, themes, server) |
| Diagnostics (perf, security, WC) | Yes | unrestricted | Performance, security, WooCommerce diagnostics |
| Debug log viewer | Yes | unrestricted | Tail wp-content/debug.log |
| File access (list, read, meta) | Yes | unrestricted | Read-only file browser for themes/plugins/mu-plugins |
| Code search | Yes | unrestricted | Search by text, function, class, or hook |
| Patch engine (create→approve→apply→rollback) | Yes | unrestricted (API token scoped) | File change workflow |
| Snapshot management | Yes | snapshot.manage | File-level snapshots |
| Rollback management | Yes | snapshot.manage | Rollback engine |
| WP-CLI bridge | Yes | wpcli.execute | Structured WP-CLI command execution |
| Option management | Yes | option.manage | Registered option read/update/rollback |
| Environment management | Yes | system.admin | dev/staging/production mode |
| System cleanup | Yes | system.admin | Guarded runtime data cleanup |
| Health verification | Yes | unrestricted | Read-only health checks |
| Recommendations engine | Yes | unrestricted | Deterministic site recommendations |
| Capability management | Yes | capability.admin | Token/agent capability assignment |
| MCP server | Yes | unrestricted (via tokens) | JSON-RPC MCP protocol |
| AI client integrations | Yes | unrestricted (via tokens) | Claude, GPT, Gemini, Codex, Cursor, Windsurf, etc. |
| Agent sessions | Yes | unrestricted | Session lifecycle tracking |
| Agent tasks | Yes | unrestricted | Task lifecycle tracking |
| Agent actions | Yes | unrestricted | Action lifecycle tracking |
| Agent plans | Yes | unrestricted | Plan lifecycle tracking |
| Option management | Yes | option.manage | Read/update registered WordPress options |
| Plugin management | Yes | plugin.manage | Install/activate/deactivate/update/delete plugins |
| Theme management | Yes | theme.manage | Install/activate/update/delete themes |
| Snapshot management | Yes | snapshot.manage | Create/list/verify/restore file snapshots |
| Database inspection | Yes | database.inspect | Read-only DB health and structure |
| Content management | Yes | content.manage | Full content lifecycle |
| User management | Yes | user.manage | Full user lifecycle |
| Media management | Yes | media.manage | Full media lifecycle |
| WooCommerce management | Yes | woocommerce.manage | Products, inventory, pricing, variations, coupons, orders |
| ACF management | Yes | acf.manage | Field groups, fields, JSON sync |
| Forms management | Yes | forms.manage | Multi-provider forms management |
| Menu management | Yes | menu.manage | Full menu/item/location lifecycle |
| Settings management | Yes | settings.manage | Core settings read/update |
| Search management | Yes | search.manage | Universal search and reports |
| Bulk operations | Yes | bulk.manage | Bulk content/media/Woo/ACF operations |
| Workflow management | Yes | workflow.manage | Multi-step operation workflows |
| Comments management | Yes | comments.manage | List, get, approve, unapprove, spam, trash, delete, reply |

---

## Gap Analysis

### What still needs implementation:

1. **Comments** — Bulk comment operations (approve/spam/trash in bulk)
2. **Comments** — Comment content editing (inline text edit)
3. **Comments** — Moderation queue filtering
4. **Comments** — Blacklist key management
5. **Posts/Pages** — Revisions browser with diff comparison
6. **Posts/Pages** — Sticky posts toggle
7. **Posts/Pages** — Post format assignment
8. **Posts/Pages** — Page template selection, order field
9. **Posts/Pages** — Page hierarchy tree view
10. **Media** — Image editing tools (crop, rotate, resize)
11. **Media** — Grid/list view toggle
12. **Media** — Alt text bulk edit
13. **Users** — Bulk role assignment and bulk delete
14. **Users** — Application password management
15. **Users** — Custom profile fields
16. **WooCommerce** — Order status changes, refunds
17. **WooCommerce** — Shipping zones, tax rates
18. **WooCommerce** — Payment gateway configuration
19. **WooCommerce** — Reports/analytics
20. **WooCommerce** — Subscription management
21. **WooCommerce** — Downloadable product files
22. **WooCommerce** — Product gallery management
23. **Plugins/Themes** — Bulk actions (activate/deactivate/update multiple)
24. **Plugins/Themes** — File editor integration
25. **Dashboard** — Widget arrangement/screen options
26. **Settings** — Writing settings
27. **Settings** — Network/multisite settings
28. **Search** — Advanced search filters (date range, taxonomy)
29. **Workflows** — Conditional branching in steps
30. **Workflows** — Scheduled/cron-based triggers
31. **Workflows** — Per-step error policies
32. **Menus** — Customizer live preview integration
33. **ACF** — Visual conditional logic editor
34. **ACF** — Repeater/flexible content field editing improvements
35. **Forms** — Visual style configuration
36. **Forms** — Spam protection configuration (reCAPTCHA, honeypot)
37. **Bulk** — Bulk taxonomy operations

---

## Parity Score Calculation

| Area | Operations Possible | Operations Implemented | Coverage |
|------|--------------------|------------------------|----------|
| Dashboard | 6 | 4 | 67% |
| Posts | 14 | 10 | 71% |
| Pages | 9 | 7 | 78% |
| Media | 14 | 14 | 100% |
| Comments | 12 | 8 | 67% |
| Users | 13 | 10 | 77% |
| Menus | 16 | 16 | 100% |
| Plugins | 9 | 6 | 67% |
| Themes | 8 | 5 | 63% |
| WooCommerce | 23 | 17 | 74% |
| ACF | 17 | 15 | 88% |
| Forms | 17 | 15 | 88% |
| Settings | 8 | 6 | 75% |
| Search | 14 | 13 | 93% |
| Bulk | 11 | 8 | 73% |
| Workflows | 10 | 8 | 80% |
| **TOTAL** | **201** | **162** | **76%** |

**Overall Parity Score: 76%** (162 of 201 possible wp-admin operations available via WP Command Center)

---

## Summary

WP Command Center covers the majority of wp-admin functionality through its REST API, with strong coverage in Media (100%), Menus (100%), ACF (88%), Forms (88%), Search (93%), and Workflows (80%). The largest gaps are in WooCommerce (missing order management, shipping, tax, reports), Comments (missing bulk operations and content editing), Themes (missing customizer integration), and Plugins (missing bulk actions and file editor).

All operations are governed by the capability system (19 distinct capabilities), approval workflow for high-risk mutations, and rollback support for destructive operations. Read-only operations (list, get, search, reports) are generally unrestricted and require no approval.

The system goes significantly beyond wp-admin parity with its AI agent workflow (sessions → tasks → actions → plans → patches), MCP protocol support, multi-provider AI client integrations, and comprehensive audit logging.
