<?php
/**
 * Step 15 — Operations Registry.
 *
 * A discoverable registry of supported WordPress operations for AI agents.
 * Metadata and discovery only — does not execute operations.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\SiteIntelligence\SiteScanner;

defined( 'ABSPATH' ) || exit;

final class OperationRegistry {

	/**
	 * Get all registered operations with their current availability status.
	 *
	 * @return array<int, array{
	 *     id: string,
	 *     title: string,
	 *     description: string,
	 *     risk_level: string,
	 *     requires_approval: bool,
	 *     parameters: array,
	 *     available: bool
	 * }>
	 */
	public function get_operations(): array {
		$operations = [
			[
				'id'                => 'content_seed',
				'title'             => __( 'Content Seeding', 'wp-command-center' ),
				'description'       => __( 'Generate and insert sample posts, pages, or custom post types.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'type', 'type' => 'string', 'enum' => [ 'post', 'page' ], 'required' => true ],
					[ 'name' => 'count', 'type' => 'integer', 'required' => false, 'default' => 5, 'min' => 1, 'max' => 100 ],
					[ 'name' => 'status', 'type' => 'string', 'enum' => [ 'draft', 'publish' ], 'required' => false, 'default' => 'draft' ],
					[ 'name' => 'title_pattern', 'type' => 'string', 'required' => false, 'default' => 'Demo {n}' ],
					[ 'name' => 'content_template', 'type' => 'string', 'required' => false, 'default' => 'Sample content' ],
				],
				'available'         => true,
			],
			[
				'id'                => 'acf_seed',
				'title'             => __( 'Seed ACF Fields', 'wp-command-center' ),
				'description'       => __( 'Populate existing ACF fields on WordPress content using native ACF APIs.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'post_id', 'type' => 'integer', 'required' => true ],
					[ 'name' => 'fields', 'type' => 'object', 'required' => true, 'description' => 'Key-value pairs of field names and their new values.' ],
				],
				'available'         => $this->is_plugin_active( 'advanced-custom-fields/acf.php' ) || $this->is_plugin_active( 'advanced-custom-fields-pro/acf.php' ),
			],
			[
				'id'                => 'cf7_seed',
				'title'             => __( 'Contact Form 7 Seeding', 'wp-command-center' ),
				'description'       => __( 'Generate sample forms and mail configurations for Contact Form 7.', 'wp-command-center' ),
				'risk_level'        => 'low',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'title', 'type' => 'string', 'required' => false, 'default' => 'Contact Form' ],
					[ 'name' => 'form_template', 'type' => 'string', 'enum' => [ 'contact_basic', 'newsletter', 'quote_request' ], 'required' => false, 'default' => 'contact_basic' ],
				],
				'available'         => $this->is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ),
			],
			[
				'id'                => 'woo_product_seed',
				'title'             => __( 'WooCommerce Product Seeder', 'wp-command-center' ),
				'description'       => __( 'Generate and insert simple WooCommerce products using native APIs.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'name', 'type' => 'string', 'required' => true ],
					[ 'name' => 'sku', 'type' => 'string', 'required' => false ],
					[ 'name' => 'regular_price', 'type' => 'string', 'required' => true ],
					[ 'name' => 'sale_price', 'type' => 'string', 'required' => false ],
					[ 'name' => 'status', 'type' => 'string', 'enum' => [ 'draft', 'publish' ], 'required' => false, 'default' => 'draft' ],
					[ 'name' => 'stock_quantity', 'type' => 'integer', 'required' => false, 'default' => 10 ],
					[ 'name' => 'manage_stock', 'type' => 'boolean', 'required' => false, 'default' => true ],
					[ 'name' => 'categories', 'type' => 'array', 'required' => false, 'description' => 'List of category names.' ],
				],
				'available'         => class_exists( 'WooCommerce' ),
			],
			[
				'id'                => 'safe_search_replace',
				'title'             => __( 'Safe Search & Replace', 'wp-command-center' ),
				'description'       => __( 'Perform a dry-run or live search and replace in the database with rollback support.', 'wp-command-center' ),
				'risk_level'        => 'high',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'search', 'type' => 'string', 'required' => true ],
					[ 'name' => 'replace', 'type' => 'string', 'required' => true ],
					[ 'name' => 'dry_run', 'type' => 'boolean', 'required' => false, 'default' => true ],
					[ 'name' => 'tables', 'type' => 'array', 'required' => true, 'description' => 'List of tables to search.' ],
					[ 'name' => 'case_sensitive', 'type' => 'boolean', 'required' => false, 'default' => false ],
				],
				'available'         => true,
			],
			[
				'id'                => 'media_import',
				'title'             => __( 'Media Library Import', 'wp-command-center' ),
				'description'       => __( 'Safe Media Library import using native APIs.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'source_url', 'type' => 'string', 'required' => true ],
					[ 'name' => 'title', 'type' => 'string', 'required' => false ],
					[ 'name' => 'alt', 'type' => 'string', 'required' => false ],
					[ 'name' => 'caption', 'type' => 'string', 'required' => false ],
					[ 'name' => 'description', 'type' => 'string', 'required' => false ],
					[ 'name' => 'attach_to_post_id', 'type' => 'integer', 'required' => false ],
				],
				'available'         => true,
			],
			[
				'id'                => 'safe_updates',
				'title'             => __( 'Safe WordPress Updates', 'wp-command-center' ),
				'description'       => __( 'Update WordPress core, plugins, or themes with automatic snapshot and health verification.', 'wp-command-center' ),
				'risk_level'        => 'high',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'type', 'type' => 'string', 'enum' => [ 'plugin', 'theme' ], 'required' => true ],
					[ 'name' => 'slug', 'type' => 'string', 'required' => true ],
					[ 'name' => 'dry_run', 'type' => 'boolean', 'required' => false, 'default' => true ],
				],
				'available'         => true,
			],
			[
				'id'                => 'capability_manage',
				'title'             => __( 'Capability Management', 'wp-command-center' ),
				'description'       => __( 'Manage which agents, tokens, and integrations may access which platform capabilities. Authorization layer.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Capability action.' ],
					[ 'name' => 'subject', 'type' => 'string', 'required' => false ],
					[ 'name' => 'subject_id', 'type' => 'string', 'required' => false ],
					[ 'name' => 'capability', 'type' => 'string', 'required' => false ],
					[ 'name' => 'operation', 'type' => 'string', 'required' => false ],
				],
				'available'         => true,
			],
			[
				'id'                => 'database_inspect',
				'title'             => __( 'Database Inspection', 'wp-command-center' ),
				'description'       => __( 'Read-only database health and structure inspection. No INSERT/UPDATE/DELETE/DROP. No arbitrary SQL.', 'wp-command-center' ),
				'risk_level'        => 'low',
				'requires_approval' => false,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Inspection operation.' ],
					[ 'name' => 'table', 'type' => 'string', 'required' => false, 'description' => 'Target table (core tables only).' ],
				],
				'available'         => true,
			],
			[
				'id'                => 'content_manage',
				'title'             => __( 'Content Management', 'wp-command-center' ),
				'description'       => __( 'Safely inspect and manage WordPress content. Operations: list, get, create, update, delete, publish, unpublish, schedule, taxonomy, featured image. WordPress API-based.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Content operation.' ],
					[ 'name' => 'content_id', 'type' => 'integer', 'required' => false ],
					[ 'name' => 'title', 'type' => 'string', 'required' => false ],
					[ 'name' => 'content', 'type' => 'string', 'required' => false ],
					[ 'name' => 'type', 'type' => 'string', 'required' => false, 'enum' => [ 'post', 'page' ] ],
				],
				'available'         => true,
			],
			[
				'id'                => 'snapshot_manage',
				'title'             => __( 'Snapshot Management', 'wp-command-center' ),
				'description'       => __( 'Create, list, inspect, verify, and restore file snapshots. Wraps the existing Snapshot and Rollback Engines.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'snapshot_create', 'snapshot_list', 'snapshot_details', 'snapshot_restore', 'snapshot_verify' ], 'description' => 'The snapshot action.' ],
					[ 'name' => 'path', 'type' => 'string', 'required' => false ],
					[ 'name' => 'label', 'type' => 'string', 'required' => false ],
					[ 'name' => 'snapshot_id', 'type' => 'string', 'required' => false ],
				],
				'available'         => true,
			],
			[
				'id'                => 'theme_manage',
				'title'             => __( 'Theme Management', 'wp-command-center' ),
				'description'       => __( 'Safely inspect and manage WordPress themes. Operations: list, install, activate, update, delete. Registry-driven, approval-aware, health-verified.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'theme_list', 'theme_install', 'theme_activate', 'theme_update', 'theme_delete' ], 'description' => 'The theme action to perform.' ],
					[ 'name' => 'slug', 'type' => 'string', 'required' => false, 'description' => 'The theme slug (required for all actions except theme_list).' ],
				],
				'available'         => true,
			],
			[
				'id'                => 'plugin_manage',
				'title'             => __( 'Plugin Management', 'wp-command-center' ),
				'description'       => __( 'Safely inspect and manage WordPress plugins. Operations: list, install, activate, deactivate, update, delete. Registry-driven, approval-aware, health-verified.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'plugin_list', 'plugin_install', 'plugin_activate', 'plugin_deactivate', 'plugin_update', 'plugin_delete' ], 'description' => 'The plugin action to perform.' ],
					[ 'name' => 'slug', 'type' => 'string', 'required' => false, 'description' => 'The plugin slug (required for all actions except plugin_list).' ],
				],
				'available'         => true,
			],
			[
				'id'                => 'option_manage',
				'title'             => __( 'Option Management', 'wp-command-center' ),
				'description'       => __( 'Safely inspect and update approved WordPress options through the operations framework. Registry-driven, risk-scored, approval-aware.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'option_get', 'option_update', 'option_rollback' ], 'description' => 'The action to perform.' ],
					[ 'name' => 'option_id', 'type' => 'string', 'required' => true, 'description' => 'The registered option ID.' ],
					[ 'name' => 'value', 'type' => 'mixed', 'required' => false, 'description' => 'New value (required for option_update).' ],
					[ 'name' => 'rollback_id', 'type' => 'string', 'required' => false, 'description' => 'Rollback record ID (required for option_rollback).' ],
				],
				'available'         => true,
			],
			[
				'id'                => 'wp_cli_bridge',
				'title'             => __( 'WP-CLI Bridge', 'wp-command-center' ),
				'description'       => __( 'Execute structured WP-CLI commands with risk-based approval workflow. Accepts command_id + args or legacy bare command.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'command_id', 'type' => 'string', 'required' => false, 'description' => 'Structured command ID from the supported command registry.' ],
					[ 'name' => 'args', 'type' => 'object', 'required' => false, 'description' => 'Command args matching the allowed_args_schema for the given command_id.' ],
					[ 'name' => 'command', 'type' => 'string', 'required' => false, 'enum' => [ 'plugin_list', 'theme_list', 'cache_flush', 'cron_event_list', 'option_get_siteurl', 'db_size_check' ], 'description' => 'Legacy bare command (limited 6-command allowlist).' ],
				],
				'available'         => ( new WpCliBridge() )->is_available(),
			],
			'user_manage' => [
				'id'                => 'user_manage',
				'title'             => __( 'User Management', 'wp-command-center' ),
				'description'       => __( 'Safely manage WordPress users: list, get, search, create, update, delete, suspend, reset password, assign role, remove role. WordPress API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'User operation: user_list, user_get, user_search, user_create, user_update, user_delete, user_suspend, user_reset_password, user_assign_role, user_remove_role.' ],
				],
				'available'         => true,
			],
			'media_manage' => [
				'id'                => 'media_manage',
				'title'             => __( 'Media Management', 'wp-command-center' ),
				'description'       => __( 'Safely manage WordPress media: list, get, search, upload, replace, delete, restore, assign featured image, remove featured image, regenerate metadata. WordPress API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Media operation: media_list, media_get, media_search, media_upload, media_replace, media_delete, media_restore, featured_image_assign, featured_image_remove, media_regenerate_metadata.' ],
				],
				'available'         => true,
			],
			'woocommerce_manage' => [
				'id'                => 'woocommerce_manage',
				'title'             => __( 'WooCommerce Management', 'wp-command-center' ),
				'description'       => __( 'Safely manage WooCommerce: products, inventory, pricing, categories, attributes, variations, orders (read-only), coupons. WooCommerce API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'        => 'variable',
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'WooCommerce operation' ],
				],
				'available'         => class_exists( 'WooCommerce' ),
			],
			'acf_manage' => [
				'id' => 'acf_manage', 'title' => __( 'ACF Management', 'wp-command-center' ),
				'description' => __( 'Manage Advanced Custom Fields: field groups, fields, locations, JSON sync/import/export/diff, field values, bulk updates, and ACF inventory. ACF API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level' => 'variable', 'requires_approval' => true,
				'parameters' => [[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'ACF operation' ]],
				'available' => function_exists( 'acf_get_field_groups' ),
			],
			'forms_manage' => [
				'id' => 'forms_manage', 'title' => __( 'Forms Management', 'wp-command-center' ),
				'description' => __( 'Manage WordPress forms across multiple providers (CF7, FluentForms, WPForms, GravityForms): list, get, search, create, update, delete, entries, notifications, analysis. Provider-abstracted, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level' => 'variable', 'requires_approval' => true,
				'parameters' => [[ 'name' => 'action', 'type' => 'string', 'required' => true ], [ 'name' => 'provider', 'type' => 'string', 'required' => false, 'description' => 'Form provider: cf7, fluentforms, wpforms, gravityforms' ]],
				'available' => true,
			],
			'menu_manage' => [
				'id' => 'menu_manage', 'title' => __( 'Menu Management', 'wp-command-center' ),
				'description' => __( 'Manage WordPress navigation menus: create, update, delete, duplicate, export, import menus; add, update, remove, move, reorder items; assign locations; tree inspection and repair; menu analysis. WordPress API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level' => 'variable', 'requires_approval' => true,
				'parameters' => [[ 'name' => 'action', 'type' => 'string', 'required' => true ]],
				'available' => true,
			],
			'settings_manage' => [
				'id' => 'settings_manage', 'title' => __( 'Site Settings', 'wp-command-center' ),
				'description' => __( 'Manage WordPress core settings: general, reading, discussion, media, permalink, privacy. Read, update, analyze, inventory. WordPress API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level' => 'variable', 'requires_approval' => true,
				'parameters' => [[ 'name' => 'action', 'type' => 'string', 'required' => true ]],
				'available' => true,
			],
			'search_manage' => [
				'id' => 'search_manage', 'title' => __( 'Search & Reports', 'wp-command-center' ),
				'description' => __( 'Universal search across content, media, users, WooCommerce, forms, ACF, menus. Site-wide reports: orphans, unused media, content/woo inventory, site summary. Read-only, no approval required.', 'wp-command-center' ),
				'risk_level' => 'low', 'requires_approval' => false,
				'parameters' => [[ 'name' => 'action', 'type' => 'string', 'required' => true ]],
				'available' => true,
			],
			'bulk_manage' => [
				'id' => 'bulk_manage', 'title' => __( 'Bulk Operations', 'wp-command-center' ),
				'description' => __( 'Execute bulk operations across content, media, WooCommerce, ACF, and batch execution. Supports bulk content update, bulk publish/unpublish, bulk media, bulk WooCommerce, bulk ACF, batch execute, and rollback. Approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level' => 'high', 'requires_approval' => true,
				'parameters' => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Bulk operation: bulk_content, bulk_publish, bulk_unpublish, bulk_media, bulk_woocommerce, bulk_acf, batch_execute.' ],
					[ 'name' => 'ids', 'type' => 'array', 'required' => false, 'description' => 'Array of post/attachment/product IDs to operate on.' ],
					[ 'name' => 'fields', 'type' => 'object', 'required' => false, 'description' => 'Fields to update (for bulk_content).' ],
				],
				'available' => true,
			],
			'workflow_manage' => [
				'id' => 'workflow_manage', 'title' => __( 'Workflow Runtime', 'wp-command-center' ),
				'description' => __( 'Create, list, get, update, delete, execute, import, and export multi-step operation workflows. Supports executing any registered operation (e.g. database_inspect) within workflow steps. History tracking, MCP discovery, and timeline integration.', 'wp-command-center' ),
				'risk_level' => 'high', 'requires_approval' => true,
				'parameters' => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Workflow action: workflow_list, workflow_get, workflow_create, workflow_update, workflow_delete, workflow_execute, workflow_import, workflow_export, workflow_history.' ],
					[ 'name' => 'workflow_id', 'type' => 'string', 'required' => false, 'description' => 'Workflow identifier (required for get, update, delete, execute, export).' ],
				],
				'available' => true,
			],
			'comments_manage' => [
				'id' => 'comments_manage', 'title' => __( 'Comments Management', 'wp-command-center' ),
				'description' => __( 'Safely manage WordPress comments: list, get, approve, unapprove, spam, trash, delete, reply. WordPress comment API-based, approval-aware, rollback-capable for trash/delete.', 'wp-command-center' ),
				'risk_level' => 'variable', 'requires_approval' => true,
				'parameters' => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Comment operation: comment_list, comment_get, comment_approve, comment_unapprove, comment_spam, comment_trash, comment_delete, comment_reply.' ],
					[ 'name' => 'comment_id', 'type' => 'integer', 'required' => false, 'description' => 'The comment ID (required for get, approve, unapprove, spam, trash, delete, reply).' ],
				],
				'available' => true,
			],
			'widgets_manage' => [
				'id' => 'widgets_manage', 'title' => __( 'Widgets & Sidebars', 'wp-command-center' ),
				'description' => __( 'Manage WordPress widgets and sidebar assignments: list, get, add, update, remove widgets; assign/remove sidebar placements. WordPress widget API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level' => 'variable', 'requires_approval' => true,
				'parameters' => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Widget operation: widget_list, widget_get, widget_add, widget_update, widget_remove, sidebar_assign, sidebar_remove.' ],
					[ 'name' => 'widget_id', 'type' => 'string', 'required' => false, 'description' => 'Widget ID (required for get, update, remove, sidebar_assign, sidebar_remove).' ],
					[ 'name' => 'sidebar_id', 'type' => 'string', 'required' => false, 'description' => 'Sidebar ID (required for add, assign, remove).' ],
					[ 'name' => 'widget_type', 'type' => 'string', 'required' => false, 'description' => 'Widget base type (required for add).' ],
					[ 'name' => 'widget_settings', 'type' => 'object', 'required' => false, 'description' => 'Widget instance settings (for add/update).' ],
				],
				'available' => true,
			],
			'cpt_manage' => [
				'id' => 'cpt_manage', 'title' => __( 'Custom Post Types', 'wp-command-center' ),
				'description' => __( 'Manage WordPress custom post types and taxonomies: list, get, create, update, disable post types; list, create, update taxonomies. WordPress register/unregister API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level' => 'variable', 'requires_approval' => true,
				'parameters' => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'CPT operation: cpt_list, cpt_get, cpt_create, cpt_update, cpt_disable, taxonomy_list, taxonomy_create, taxonomy_update.' ],
					[ 'name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Post type or taxonomy name (required for get, create, update, disable).' ],
					[ 'name' => 'label', 'type' => 'string', 'required' => false, 'description' => 'Human-readable label (required for create).' ],
					[ 'name' => 'config', 'type' => 'object', 'required' => false, 'description' => 'Configuration array (for create/update).' ],
					[ 'name' => 'object_type', 'type' => 'string', 'required' => false, 'description' => 'Object type for taxonomy (for create).' ],
				],
				'available' => true,
			],
		];

		// Some entries above use string keys (e.g. 'acf_manage') for readability;
		// re-index to a plain list so the REST response serializes as a JSON array.
		return array_values( $operations );
	}

	/**
	 * Get a specific operation by ID.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public function get_operation( string $id ): ?array {
		foreach ( $this->get_operations() as $op ) {
			if ( $op['id'] === $id ) {
				return $op;
			}
		}

		return null;
	}

	private function is_plugin_active( string $plugin_file ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}
}
