<?php
/**
 * Step 15 — Operations Registry.
 *
 * A discoverable registry of supported WordPress operations for AI agents.
 * Metadata and discovery only — does not execute operations.
 *
 * Step 80 adds:
 *   - 'action_risks' per operation: maps specific action values to risk tiers
 *     so SecurityModeManager can gate only the risky sub-actions while leaving
 *     read/diagnostic actions free in all modes.
 *   - Corrected 'risk_level' values: all 'variable' labels replaced with the
 *     worst-case tier for that operation.
 *   - 'diagnostic' tier for purely read-only operations (database_inspect,
 *     search_manage, approval_manage) that must never be gated.
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
	 *     action_risks: array<string,string>,
	 *     requires_approval: bool,
	 *     parameters: array,
	 *     available: bool
	 * }>
	 */
	public function get_operations(): array {
		$operations = [
			[
				'id'                => 'system_info',
				'title'             => __( 'System Info', 'wp-command-center' ),
				'description'       => __( 'Returns site URL, WordPress version, PHP version, MySQL version, active theme, active plugin count, multisite status, memory limit, debug mode, environment type, locale, and timezone. Pure PHP — no WP-CLI or shell access required.', 'wp-command-center' ),
				'risk_level'        => 'diagnostic',
				'action_risks'      => [],
				'requires_approval' => false,
				'parameters'        => [],
				'available'         => true,
			],
			[
				'id'                => 'content_seed',
				'title'             => __( 'Content Seeding', 'wp-command-center' ),
				'description'       => __( 'Generate and insert sample posts, pages, or custom post types.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'action_risks'      => [],
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
				'action_risks'      => [],
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
				'action_risks'      => [],
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
				'action_risks'      => [],
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
				'risk_level'        => 'critical',
				'action_risks'      => [],
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
				'action_risks'      => [],
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
				'action_risks'      => [],
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
				'risk_level'        => 'critical',
				'action_risks'      => [
					'capability_list'     => 'diagnostic',
					'capability_get'      => 'diagnostic',
					'capability_validate' => 'diagnostic',
					'capability_assign'   => 'critical',
					'capability_remove'   => 'critical',
				],
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
				'risk_level'        => 'diagnostic',
				'action_risks'      => [],
				'requires_approval' => false,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => DatabaseRegistry::ACTIONS, 'description' => 'Inspection operation.' ],
					[ 'name' => 'table', 'type' => 'string', 'required' => false, 'description' => 'Target table (core tables only).' ],
				],
				'available'         => true,
			],
			[
				'id'                => 'content_manage',
				'title'             => __( 'Content Management', 'wp-command-center' ),
				'description'       => __( 'Safely inspect and manage WordPress content. Operations: list, get, create, update, delete, publish, unpublish, schedule, taxonomy, featured image. WordPress API-based.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'action_risks'      => [
					'content_list'          => 'low',
					'content_get'           => 'low',
					'content_create'        => 'medium',
					'content_update'        => 'medium',
					'content_delete'        => 'medium',
					'content_publish'       => 'medium',
					'content_unpublish'     => 'medium',
					'content_schedule'      => 'medium',
					'taxonomy_assign'       => 'medium',
					'featured_image_assign' => 'medium',
					'content_rollback'      => 'medium',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Content operation: content_list, content_get, content_create, content_update, content_delete, content_publish, content_unpublish, content_schedule, taxonomy_assign, featured_image_assign.' ],
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
				'risk_level'        => 'high',
				'action_risks'      => [
					'snapshot_list'    => 'diagnostic',
					'snapshot_details' => 'diagnostic',
					'snapshot_verify'  => 'diagnostic',
					'snapshot_create'  => 'medium',
					'snapshot_restore' => 'high',
				],
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
				'description'       => __( 'Safely inspect and manage WordPress themes. Operations: list, install, activate, update, delete. Registry-driven, approval-aware, health-verified. theme_delete is a CRITICAL destructive action: it requires confirm=true, confirmation_phrase="DELETE_THEME", and a reason; only inactive themes can be deleted.', 'wp-command-center' ),
				'risk_level'        => 'critical',
				'action_risks'      => [
					'theme_list'     => 'diagnostic',
					'theme_install'  => 'high',
					'theme_activate' => 'high',
					'theme_update'   => 'high',
					'theme_delete'   => 'critical',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'theme_list', 'theme_install', 'theme_activate', 'theme_update', 'theme_delete' ], 'description' => 'The theme action to perform.' ],
					[ 'name' => 'slug', 'type' => 'string', 'required' => false, 'description' => 'The theme slug (required for all actions except theme_list).' ],
					[ 'name' => 'confirm', 'type' => 'boolean', 'required' => false, 'description' => 'Must be true to execute theme_delete (destructive confirmation).' ],
					[ 'name' => 'confirmation_phrase', 'type' => 'string', 'required' => false, 'description' => 'Must equal "DELETE_THEME" to execute theme_delete.' ],
					[ 'name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Human-readable reason for the deletion; required for theme_delete.' ],
				],
				'available'         => true,
			],
			[
				'id'                => 'plugin_manage',
				'title'             => __( 'Plugin Management', 'wp-command-center' ),
				'description'       => __( 'Safely inspect and manage WordPress plugins. Operations: list, install, activate, deactivate, update, delete. Registry-driven, approval-aware, health-verified. plugin_delete is a CRITICAL destructive action: it requires confirm=true, confirmation_phrase="DELETE_PLUGIN", and a reason; only inactive plugins can be deleted and the plugin folder is backed up first.', 'wp-command-center' ),
				'risk_level'        => 'critical',
				'action_risks'      => [
					'plugin_list'       => 'diagnostic',
					'plugin_install'    => 'high',
					'plugin_activate'   => 'high',
					'plugin_deactivate' => 'high',
					'plugin_update'     => 'high',
					'plugin_delete'     => 'critical',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'plugin_list', 'plugin_install', 'plugin_activate', 'plugin_deactivate', 'plugin_update', 'plugin_delete' ], 'description' => 'The plugin action to perform.' ],
					[ 'name' => 'slug', 'type' => 'string', 'required' => false, 'description' => 'The plugin slug (required for all actions except plugin_list).' ],
					[ 'name' => 'confirm', 'type' => 'boolean', 'required' => false, 'description' => 'Must be true to execute plugin_delete (destructive confirmation).' ],
					[ 'name' => 'confirmation_phrase', 'type' => 'string', 'required' => false, 'description' => 'Must equal "DELETE_PLUGIN" to execute plugin_delete.' ],
					[ 'name' => 'reason', 'type' => 'string', 'required' => false, 'description' => 'Human-readable reason for the deletion; required for plugin_delete.' ],
				],
				'available'         => true,
			],
			[
				'id'                => 'option_manage',
				'title'             => __( 'Option Management', 'wp-command-center' ),
				'description'       => __( 'Safely inspect and update approved WordPress options through the operations framework. Registry-driven, risk-scored, approval-aware.', 'wp-command-center' ),
				'risk_level'        => 'high',
				'action_risks'      => [
					'option_get'      => 'diagnostic',
					'option_update'   => 'high',
					'option_rollback' => 'high',
				],
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
				'risk_level'        => 'critical',
				'action_risks'      => [],
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
				'risk_level'        => 'critical',
				'action_risks'      => [
					'user_list'           => 'diagnostic',
					'user_get'            => 'diagnostic',
					'user_search'         => 'diagnostic',
					'user_create'         => 'high',
					'user_update'         => 'high',
					'user_delete'         => 'critical',
					'user_suspend'        => 'high',
					'user_reset_password' => 'high',
					'user_assign_role'    => 'critical',
					'user_remove_role'    => 'critical',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'User operation: user_list, user_get, user_search, user_create, user_update, user_delete, user_suspend, user_reset_password, user_assign_role, user_remove_role.' ],
				],
				'available'         => true,
			],
			'media_manage' => [
				'id'                => 'media_manage',
				'title'             => __( 'Media Management', 'wp-command-center' ),
				'description'       => __( 'Safely manage WordPress media: list, get, search, upload, update (title/alt/caption/description), replace, delete, restore, set/remove featured image, regenerate metadata. WordPress API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'action_risks'      => [
					'media_list'                => 'diagnostic',
					'media_get'                 => 'diagnostic',
					'media_search'              => 'diagnostic',
					'media_upload'              => 'medium',
					'media_update'              => 'medium',
					'media_replace'             => 'medium',
					'media_delete'              => 'medium',
					'media_restore'             => 'medium',
					'featured_image_assign'     => 'medium',
					'featured_image_remove'     => 'medium',
					'media_set_featured'        => 'medium',
					'media_remove_featured'     => 'medium',
					'media_regenerate_metadata' => 'low',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Media operation: media_list, media_get, media_search, media_upload, media_update, media_replace, media_delete, media_restore, media_set_featured (alias featured_image_assign), media_remove_featured (alias featured_image_remove), media_regenerate_metadata.' ],
					[ 'name' => 'media_id', 'type' => 'integer', 'required' => false, 'description' => 'Attachment ID (required for get/update/replace/delete/featured operations).' ],
					[ 'name' => 'title', 'type' => 'string', 'required' => false, 'description' => 'Media title (media_upload, media_update).' ],
					[ 'name' => 'alt', 'type' => 'string', 'required' => false, 'description' => 'Alt text (media_upload, media_update).' ],
					[ 'name' => 'caption', 'type' => 'string', 'required' => false, 'description' => 'Caption (media_upload, media_update).' ],
					[ 'name' => 'description', 'type' => 'string', 'required' => false, 'description' => 'Description (media_upload, media_update).' ],
					[ 'name' => 'source_url', 'type' => 'string', 'required' => false, 'description' => 'HTTPS image URL (media_upload, media_replace).' ],
					[ 'name' => 'post_id', 'type' => 'integer', 'required' => false, 'description' => 'Target post ID (media_set_featured, media_remove_featured).' ],
				],
				'available'         => true,
			],
			'woocommerce_manage' => [
				'id'                => 'woocommerce_manage',
				'title'             => __( 'WooCommerce Management', 'wp-command-center' ),
				'description'       => __( 'Safely manage WooCommerce: products, inventory, pricing, categories, attributes, variations, orders (read-only), coupons. WooCommerce API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'action_risks'      => [
					'product_list'           => 'diagnostic',
					'product_get'            => 'diagnostic',
					'stock_get'              => 'diagnostic',
					'price_get'              => 'diagnostic',
					'product_category_list'  => 'diagnostic',
					'product_attribute_list' => 'diagnostic',
					'variation_list'         => 'diagnostic',
					'variation_get'          => 'diagnostic',
					'order_list'             => 'diagnostic',
					'order_get'              => 'diagnostic',
					'coupon_list'            => 'diagnostic',
					'coupon_get'             => 'diagnostic',
					'product_create'         => 'medium',
					'product_update'         => 'medium',
					'product_delete'         => 'medium',
					'stock_update'           => 'medium',
					'stock_bulk_update'      => 'medium',
					'price_update'           => 'medium',
					'sale_price_update'      => 'medium',
					'variation_create'       => 'medium',
					'variation_update'       => 'medium',
					'variation_delete'       => 'medium',
					'coupon_create'          => 'medium',
					'coupon_update'          => 'medium',
					'coupon_delete'          => 'medium',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'WooCommerce operation' ],
				],
				'available'         => class_exists( 'WooCommerce' ),
			],
			'acf_manage' => [
				'id'          => 'acf_manage',
				'title'       => __( 'ACF Management', 'wp-command-center' ),
				'description' => __( 'Manage Advanced Custom Fields: field groups, fields, locations, JSON sync/import/export/diff, field values, bulk updates, and ACF inventory. ACF API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'  => 'medium',
				'action_risks' => [
					'acf_group_list'     => 'diagnostic',
					'acf_group_get'      => 'diagnostic',
					'acf_field_list'     => 'diagnostic',
					'acf_field_get'      => 'diagnostic',
					'acf_location_list'  => 'diagnostic',
					'acf_value_get'      => 'diagnostic',
					'acf_group_create'       => 'medium',
					'acf_group_update'       => 'medium',
					'acf_group_delete'       => 'medium',
					'acf_field_create'       => 'medium',
					'acf_field_update'       => 'medium',
					'acf_field_delete'       => 'medium',
					'acf_value_update'       => 'medium',
					'acf_bulk_value_update'  => 'medium',
				],
				'requires_approval' => true,
				'parameters'        => [[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'ACF operation' ]],
				'available'         => function_exists( 'acf_get_field_groups' ),
			],
			'forms_manage' => [
				'id'          => 'forms_manage',
				'title'       => __( 'Forms Management', 'wp-command-center' ),
				'description' => __( 'Manage WordPress forms across multiple providers (CF7, FluentForms, WPForms, GravityForms): list, get, search, create, update, delete, entries, notifications, analysis. Provider-abstracted, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'  => 'medium',
				'action_risks' => [
					'form_list'     => 'diagnostic',
					'form_get'      => 'diagnostic',
					'form_search'   => 'diagnostic',
					'entry_list'    => 'diagnostic',
					'entry_get'     => 'diagnostic',
					'form_analyze'  => 'diagnostic',
					'form_create'   => 'medium',
					'form_update'   => 'medium',
					'form_delete'   => 'medium',
				],
				'requires_approval' => true,
				'parameters'        => [[ 'name' => 'action', 'type' => 'string', 'required' => true ], [ 'name' => 'provider', 'type' => 'string', 'required' => false, 'description' => 'Form provider: cf7, fluentforms, wpforms, gravityforms' ]],
				'available'         => true,
			],
			'menu_manage' => [
				'id'          => 'menu_manage',
				'title'       => __( 'Menu Management', 'wp-command-center' ),
				'description' => __( 'Manage WordPress navigation menus: create, update, delete, duplicate, export, import menus; add, update, remove, move, reorder items; assign locations; tree inspection and repair; menu analysis. WordPress API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'  => 'medium',
				'action_risks' => [
					'menu_list'          => 'diagnostic',
					'menu_get'           => 'diagnostic',
					'menu_item_list'     => 'diagnostic',
					'menu_item_get'      => 'diagnostic',
					'menu_location_list' => 'diagnostic',
					'menu_tree_get'      => 'diagnostic',
					'menu_create'        => 'medium',
					'menu_update'        => 'medium',
					'menu_delete'        => 'medium',
					'menu_item_add'      => 'medium',
					'menu_item_update'   => 'medium',
					'menu_item_remove'   => 'medium',
					'menu_location_assign' => 'medium',
					'menu_location_remove' => 'medium',
					'menu_duplicate'     => 'medium',
					'menu_export'        => 'diagnostic',
					'menu_import'        => 'medium',
				],
				'requires_approval' => true,
				'parameters'        => [[ 'name' => 'action', 'type' => 'string', 'required' => true ]],
				'available'         => true,
			],
			'settings_manage' => [
				'id'          => 'settings_manage',
				'title'       => __( 'Site Settings', 'wp-command-center' ),
				'description' => __( 'Manage WordPress core settings: general, reading, discussion, media, permalink, privacy. Read, update, analyze, inventory. WordPress API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'  => 'high',
				'action_risks' => [
					'settings_general_get'    => 'diagnostic',
					'settings_reading_get'    => 'diagnostic',
					'settings_discussion_get' => 'diagnostic',
					'settings_media_get'      => 'diagnostic',
					'settings_permalink_get'  => 'diagnostic',
					'settings_privacy_get'    => 'diagnostic',
					'settings_inventory'      => 'diagnostic',
					'settings_analyze'        => 'diagnostic',
					'settings_general_update'    => 'high',
					'settings_reading_update'    => 'high',
					'settings_discussion_update' => 'high',
					'settings_media_update'      => 'high',
					'settings_permalink_update'  => 'high',
					'settings_privacy_update'    => 'high',
				],
				'requires_approval' => true,
				'parameters'        => [[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => SettingsRegistry::ACTIONS ]],
				'available'         => true,
			],
			'approval_manage' => [
				'id'          => 'approval_manage',
				'title'       => __( 'Approval Runtime', 'wp-command-center' ),
				'description' => __( 'Create, inspect, approve, reject, cancel, and execute operation requests through the request/approval/queue pipeline (Agent -> Request -> Approval -> Execute -> Verify -> Audit -> Rollback), without direct database, SSH, or WP Admin access. Control-plane operation; diagnostic risk and never itself requires approval.', 'wp-command-center' ),
				'risk_level'  => 'diagnostic',
				'action_risks' => [],
				'requires_approval' => false,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => ApprovalRegistry::ACTIONS ],
					[ 'name' => 'operation_id', 'type' => 'string', 'required' => false, 'description' => 'Target operation id (request_create).' ],
					[ 'name' => 'payload', 'type' => 'object', 'required' => false, 'description' => 'Payload for the target operation (request_create).' ],
					[ 'name' => 'request_id', 'type' => 'string', 'required' => false, 'description' => 'Operation request id (request_get/approve/reject/cancel).' ],
					[ 'name' => 'queue_id', 'type' => 'string', 'required' => false, 'description' => 'Queue item id (queue_get/run/cancel/retry).' ],
					[ 'name' => 'result_id', 'type' => 'string', 'required' => false, 'description' => 'Result id (results_get).' ],
					[ 'name' => 'status', 'type' => 'string', 'required' => false, 'description' => 'Status filter for *_list actions.' ],
					[ 'name' => 'limit', 'type' => 'integer', 'required' => false, 'description' => 'Max rows for *_list actions.' ],
					[ 'name' => 'offset', 'type' => 'integer', 'required' => false, 'description' => 'Row offset for *_list actions.' ],
				],
				'available'         => true,
			],
			'search_manage' => [
				'id'          => 'search_manage',
				'title'       => __( 'Search & Reports', 'wp-command-center' ),
				'description' => __( 'Universal search across content, media, users, WooCommerce, forms, ACF, menus. Site-wide reports: orphans, unused media, content/woo inventory, site summary. Read-only, no approval required.', 'wp-command-center' ),
				'risk_level'  => 'diagnostic',
				'action_risks' => [],
				'requires_approval' => false,
				'parameters'        => [[ 'name' => 'action', 'type' => 'string', 'required' => true ]],
				'available'         => true,
			],
			'bulk_manage' => [
				'id'          => 'bulk_manage',
				'title'       => __( 'Bulk Operations', 'wp-command-center' ),
				'description' => __( 'Execute bulk operations across content, media, WooCommerce, ACF, and batch execution. Supports bulk content update, bulk publish/unpublish, bulk media, bulk WooCommerce, bulk ACF, batch execute, and rollback. Approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'  => 'high',
				'action_risks' => [
					'bulk_publish'    => 'medium',
					'bulk_unpublish'  => 'medium',
					'bulk_content'    => 'high',
					'bulk_media'      => 'high',
					'bulk_woocommerce' => 'high',
					'bulk_acf'        => 'high',
					'batch_execute'   => 'critical',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Bulk operation: bulk_content, bulk_publish, bulk_unpublish, bulk_media, bulk_woocommerce, bulk_acf, batch_execute.' ],
					[ 'name' => 'ids', 'type' => 'array', 'required' => false, 'description' => 'Array of post/attachment/product IDs to operate on.' ],
					[ 'name' => 'fields', 'type' => 'object', 'required' => false, 'description' => 'Fields to update (for bulk_content).' ],
				],
				'available'         => true,
			],
			'workflow_manage' => [
				'id'          => 'workflow_manage',
				'title'       => __( 'Workflow Runtime', 'wp-command-center' ),
				'description' => __( 'Create, list, get, update, delete, execute, import, and export multi-step operation workflows. Supports executing any registered operation (e.g. database_inspect) within workflow steps. History tracking, MCP discovery, and timeline integration.', 'wp-command-center' ),
				'risk_level'  => 'high',
				'action_risks' => [
					'workflow_list'    => 'diagnostic',
					'workflow_get'     => 'diagnostic',
					'workflow_history' => 'diagnostic',
					'workflow_export'  => 'diagnostic',
					'workflow_create'  => 'high',
					'workflow_update'  => 'high',
					'workflow_delete'  => 'high',
					'workflow_execute' => 'high',
					'workflow_import'  => 'high',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Workflow action: workflow_list, workflow_get, workflow_create, workflow_update, workflow_delete, workflow_execute, workflow_import, workflow_export, workflow_history.' ],
					[ 'name' => 'workflow_id', 'type' => 'string', 'required' => false, 'description' => 'Workflow identifier (required for get, update, delete, execute, export).' ],
				],
				'available'         => true,
			],
			'comments_manage' => [
				'id'          => 'comments_manage',
				'title'       => __( 'Comments Management', 'wp-command-center' ),
				'description' => __( 'Safely manage WordPress comments: list, get, approve, unapprove, spam, trash, delete, reply. WordPress comment API-based, approval-aware, rollback-capable for trash/delete.', 'wp-command-center' ),
				'risk_level'  => 'medium',
				'action_risks' => [
					'comment_list'     => 'diagnostic',
					'comment_get'      => 'diagnostic',
					'comment_approve'   => 'medium',
					'comment_unapprove' => 'medium',
					'comment_spam'      => 'medium',
					'comment_trash'     => 'medium',
					'comment_delete'    => 'medium',
					'comment_reply'     => 'medium',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Comment operation: comment_list, comment_get, comment_approve, comment_unapprove, comment_spam, comment_trash, comment_delete, comment_reply.' ],
					[ 'name' => 'comment_id', 'type' => 'integer', 'required' => false, 'description' => 'The comment ID (required for get, approve, unapprove, spam, trash, delete, reply).' ],
				],
				'available'         => true,
			],
			'widgets_manage' => [
				'id'          => 'widgets_manage',
				'title'       => __( 'Widgets & Sidebars', 'wp-command-center' ),
				'description' => __( 'Manage WordPress widgets and sidebar assignments: list, get, add, update, remove widgets; assign/remove sidebar placements. WordPress widget API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'  => 'medium',
				'action_risks' => [
					'widget_list'      => 'diagnostic',
					'widget_get'       => 'diagnostic',
					'widget_add'       => 'medium',
					'widget_update'    => 'medium',
					'widget_remove'    => 'medium',
					'sidebar_assign'   => 'medium',
					'sidebar_remove'   => 'medium',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'Widget operation: widget_list, widget_get, widget_add, widget_update, widget_remove, sidebar_assign, sidebar_remove.' ],
					[ 'name' => 'widget_id', 'type' => 'string', 'required' => false, 'description' => 'Widget ID (required for get, update, remove, sidebar_assign, sidebar_remove).' ],
					[ 'name' => 'sidebar_id', 'type' => 'string', 'required' => false, 'description' => 'Sidebar ID (required for add, assign, remove).' ],
					[ 'name' => 'widget_type', 'type' => 'string', 'required' => false, 'description' => 'Widget base type (required for add).' ],
					[ 'name' => 'widget_settings', 'type' => 'object', 'required' => false, 'description' => 'Widget instance settings (for add/update).' ],
				],
				'available'         => true,
			],
			'cpt_manage' => [
				'id'          => 'cpt_manage',
				'title'       => __( 'Custom Post Types', 'wp-command-center' ),
				'description' => __( 'Manage WordPress custom post types and taxonomies: list, get, create, update, disable post types; list, create, update taxonomies. WordPress register/unregister API-based, approval-aware, rollback-capable.', 'wp-command-center' ),
				'risk_level'  => 'high',
				'action_risks' => [
					'cpt_list'         => 'diagnostic',
					'cpt_get'          => 'diagnostic',
					'taxonomy_list'    => 'diagnostic',
					'cpt_create'       => 'high',
					'cpt_update'       => 'high',
					'cpt_disable'      => 'high',
					'taxonomy_create'  => 'high',
					'taxonomy_update'  => 'high',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'description' => 'CPT operation: cpt_list, cpt_get, cpt_create, cpt_update, cpt_disable, taxonomy_list, taxonomy_create, taxonomy_update.' ],
					[ 'name' => 'name', 'type' => 'string', 'required' => false, 'description' => 'Post type or taxonomy name (required for get, create, update, disable).' ],
					[ 'name' => 'label', 'type' => 'string', 'required' => false, 'description' => 'Human-readable label (required for create).' ],
					[ 'name' => 'config', 'type' => 'object', 'required' => false, 'description' => 'Configuration array (for create/update).' ],
					[ 'name' => 'object_type', 'type' => 'string', 'required' => false, 'description' => 'Object type for taxonomy (for create).' ],
				],
				'available'         => true,
			],
			// ── STEP 87 — File / Patch bridge (shared services, REST + MCP) ──
			'file_manage' => [
				'id'                => 'file_manage',
				'title'             => __( 'File Access', 'wp-command-center' ),
				'description'       => __( 'Read files and browse the file tree under themes, plugins, and mu-plugins. Read-only. Paths are relative to wp-content, e.g. "themes/my-theme/functions.php" or "plugins/my-plugin/file.php" (a leading "wp-content/" or an absolute path is also accepted). Blocked paths (.env, wp-config.php, vendor/, keys, etc.) are denied and secrets are redacted. Actions: file_read, file_tree, file_metadata.', 'wp-command-center' ),
				'risk_level'        => 'diagnostic',
				'action_risks'      => [
					'file_read'     => 'diagnostic',
					'file_tree'     => 'diagnostic',
					'file_metadata' => 'diagnostic',
				],
				'requires_approval' => false,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'file_read', 'file_tree', 'file_metadata' ], 'description' => 'The file action to perform.' ],
					[ 'name' => 'path', 'type' => 'string', 'required' => false, 'description' => 'Path relative to wp-content, e.g. "themes/my-theme/functions.php" (a leading "wp-content/" or absolute path is also accepted). Required for file_read and file_metadata; optional for file_tree (omit to list the themes/plugins/mu-plugins roots).' ],
				],
				'available'         => true,
			],
			'code_search' => [
				'id'                => 'code_search',
				'title'             => __( 'Code Search', 'wp-command-center' ),
				'description'       => __( 'Search code under themes, plugins, and mu-plugins. Read-only. Blocked files are skipped and secrets in matches are redacted. Actions: search_text, search_symbol (function/class/hook), search_file (by name).', 'wp-command-center' ),
				'risk_level'        => 'diagnostic',
				'action_risks'      => [
					'search_text'   => 'diagnostic',
					'search_symbol' => 'diagnostic',
					'search_file'   => 'diagnostic',
				],
				'requires_approval' => false,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'search_text', 'search_symbol', 'search_file' ], 'description' => 'The search action to perform.' ],
					[ 'name' => 'query', 'type' => 'string', 'required' => true, 'description' => 'The search term.' ],
					[ 'name' => 'path', 'type' => 'string', 'required' => false, 'description' => 'Limit the search to a directory relative to wp-content, e.g. "themes/my-theme" (a leading "wp-content/" or absolute path is also accepted). Omit to search all of themes/plugins/mu-plugins.' ],
					[ 'name' => 'max_results', 'type' => 'integer', 'required' => false, 'description' => 'Maximum number of matches to return.' ],
				],
				'available'         => true,
			],
			'patch_manage' => [
				'id'                => 'patch_manage',
				'title'             => __( 'Patch Engine', 'wp-command-center' ),
				'description'       => __( 'Safely propose and apply file changes through the Patch Engine. Every apply snapshots the file first, verifies PHP syntax (php -l or tokenizer fallback), and auto-reverts on failure. Patches touching high-risk files (theme functions.php, active theme templates, plugin main files) require confirm=true and confirmation_phrase="APPLY_PATCH". Actions: patch_preview, patch_create, patch_apply, patch_verify, patch_status.', 'wp-command-center' ),
				'risk_level'        => 'high',
				'action_risks'      => [
					'patch_preview' => 'diagnostic',
					'patch_status'  => 'diagnostic',
					'patch_verify'  => 'diagnostic',
					'patch_create'  => 'low',
					'patch_apply'   => 'high',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'patch_preview', 'patch_create', 'patch_apply', 'patch_verify', 'patch_status' ], 'description' => 'The patch action to perform.' ],
					[ 'name' => 'files', 'type' => 'array', 'required' => false, 'description' => 'Array of { path, modified } objects (required for patch_preview and patch_create). path is relative to wp-content, e.g. "themes/my-theme/functions.php" (a leading "wp-content/" or absolute path is also accepted); modified is the full new file content.' ],
					[ 'name' => 'patch_id', 'type' => 'string', 'required' => false, 'description' => 'Patch ID (required for patch_apply, patch_verify, patch_status).' ],
					[ 'name' => 'explanation', 'type' => 'string', 'required' => false, 'description' => 'Why the change is being made (patch_create).' ],
					[ 'name' => 'risk_level', 'type' => 'string', 'required' => false, 'description' => 'low | medium | high (patch_create).' ],
					[ 'name' => 'confirm', 'type' => 'boolean', 'required' => false, 'description' => 'Required true to apply a patch touching a high-risk file.' ],
					[ 'name' => 'confirmation_phrase', 'type' => 'string', 'required' => false, 'description' => 'Must equal "APPLY_PATCH" to apply a patch touching a high-risk file.' ],
				],
				'available'         => true,
			],
			'rollback_manage' => [
				'id'                => 'rollback_manage',
				'title'             => __( 'Rollback Engine', 'wp-command-center' ),
				'description'       => __( 'List, inspect, verify, and apply patch rollbacks. rollback_apply restores every affected file from the pre-apply snapshot with hash verification. Actions: rollback_list, rollback_get, rollback_apply, rollback_verify.', 'wp-command-center' ),
				'risk_level'        => 'high',
				'action_risks'      => [
					'rollback_list'   => 'diagnostic',
					'rollback_get'    => 'diagnostic',
					'rollback_verify' => 'diagnostic',
					'rollback_apply'  => 'high',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'rollback_list', 'rollback_get', 'rollback_apply', 'rollback_verify' ], 'description' => 'The rollback action to perform.' ],
					[ 'name' => 'patch_id', 'type' => 'string', 'required' => false, 'description' => 'Patch ID (required for rollback_get, rollback_apply, rollback_verify).' ],
				],
				'available'         => true,
			],
			// ── STEP 91 — Unified SEO runtime (Rank Math / Yoast) ──
			'seo_manage' => [
				'id'                => 'seo_manage',
				'title'             => __( 'SEO Management', 'wp-command-center' ),
				'description'       => __( 'Unified SEO management for the active SEO plugin (Rank Math or Yoast). Read, update, validate, and analyze SEO metadata (title, meta description, focus keyword, canonical, Open Graph, Twitter cards, robots). Rollback-capable, approval-aware. Actions: seo_get, seo_update, seo_validate, seo_analyze, seo_restore.', 'wp-command-center' ),
				'risk_level'        => 'medium',
				'action_risks'      => [
					'seo_get'      => 'diagnostic',
					'seo_validate' => 'diagnostic',
					'seo_analyze'  => 'diagnostic',
					'seo_update'   => 'medium',
					'seo_restore'  => 'medium',
				],
				'requires_approval' => true,
				'parameters'        => [
					[ 'name' => 'action', 'type' => 'string', 'required' => true, 'enum' => [ 'seo_get', 'seo_update', 'seo_validate', 'seo_analyze', 'seo_restore' ], 'description' => 'The SEO action to perform.' ],
					[ 'name' => 'content_id', 'type' => 'integer', 'required' => false, 'description' => 'Post/page ID (required for get/update/analyze; optional for validate).' ],
					[ 'name' => 'seo', 'type' => 'object', 'required' => false, 'description' => 'SEO fields for update/validate: title, description, focus_keyword, canonical, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image, robots (array of directives).' ],
					[ 'name' => 'rollback_id', 'type' => 'string', 'required' => false, 'description' => 'Rollback record ID (required for seo_restore).' ],
				],
				'available'         => SeoProvider::is_available(),
			],
		];

		// B6: Add optional `reason` param to any write operation so AI agents can
		// explain their intent — shown on the approval card in Client/Enterprise mode.
		$reason_param = [
			'name'        => 'reason',
			'type'        => 'string',
			'required'    => false,
			'description' => 'Brief explanation of why this action is being requested. Shown to the human approver.',
		];
		foreach ( $operations as &$op ) {
			if ( SecurityModeManager::RISK_DIAGNOSTIC !== ( $op['risk_level'] ?? '' ) ) {
				$op['parameters'][] = $reason_param;
			}
		}
		unset( $op );

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
