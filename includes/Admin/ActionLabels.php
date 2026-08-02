<?php
/**
 * V1 refinement — plain-language names for operations, actions, and targets.
 *
 * The engine speaks in identifiers: `plugin_manage` / `plugin_update`,
 * `acf_value_set`, `option_update`. Those are correct, stable, and exactly the
 * wrong thing to show someone who is deciding whether to approve a change to
 * their live website. An approval card that says "acf_manage · acf_value_set"
 * asks the user to do the translation; one that says "Set a custom field value"
 * lets them make a decision.
 *
 * PRESENTATION ONLY. This class is read-only, adds no route, operation,
 * capability, MCP tool, or schema, and is never the source of truth for what an
 * operation *does* — the registry is. Nothing here can change behaviour: the
 * worst a bug in this file can do is show a clumsy sentence.
 *
 * Design: a small explicit map for the phrases users actually meet, plus a
 * deterministic humanizer for the long tail. The humanizer means a newly
 * registered action degrades to "Update media item" rather than to a raw ID —
 * and it can never silently misreport, because it only ever re-words the
 * identifier it was given. The technical ID always remains available next to
 * the label for anyone who wants it.
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class ActionLabels {

	/**
	 * Verb tokens → the word a person would use. Applied to whichever end of the
	 * action ID carries the verb.
	 *
	 * @return array<string,string>
	 */
	private static function verbs(): array {
		return [
			'get'         => __( 'View', 'wp-command-center' ),
			'list'        => __( 'List', 'wp-command-center' ),
			'details'     => __( 'View', 'wp-command-center' ),
			'describe'    => __( 'Describe', 'wp-command-center' ),
			'count'       => __( 'Count', 'wp-command-center' ),
			'search'      => __( 'Search', 'wp-command-center' ),
			'find'        => __( 'Find', 'wp-command-center' ),
			'read'        => __( 'Read', 'wp-command-center' ),
			'preview'     => __( 'Preview', 'wp-command-center' ),
			'status'      => __( 'Check', 'wp-command-center' ),
			'validate'    => __( 'Check', 'wp-command-center' ),
			'assign'      => __( 'Assign', 'wp-command-center' ),
			'manage'      => __( 'Manage', 'wp-command-center' ),
			'scan'        => __( 'Scan', 'wp-command-center' ),
			'audit'       => __( 'Check', 'wp-command-center' ),
			'analyze'     => __( 'Analyse', 'wp-command-center' ),
			'verify'      => __( 'Verify', 'wp-command-center' ),
			'create'      => __( 'Create', 'wp-command-center' ),
			'add'         => __( 'Add', 'wp-command-center' ),
			'seed'        => __( 'Create sample', 'wp-command-center' ),
			'generate'    => __( 'Generate', 'wp-command-center' ),
			'import'      => __( 'Import', 'wp-command-center' ),
			'export'      => __( 'Export', 'wp-command-center' ),
			'update'      => __( 'Update', 'wp-command-center' ),
			'set'         => __( 'Set', 'wp-command-center' ),
			'replace'     => __( 'Replace', 'wp-command-center' ),
			'enhance'     => __( 'Improve', 'wp-command-center' ),
			'optimize'    => __( 'Optimise', 'wp-command-center' ),
			'regenerate'  => __( 'Regenerate', 'wp-command-center' ),
			'install'     => __( 'Install', 'wp-command-center' ),
			'activate'    => __( 'Activate', 'wp-command-center' ),
			'deactivate'  => __( 'Deactivate', 'wp-command-center' ),
			'delete'      => __( 'Delete', 'wp-command-center' ),
			'remove'      => __( 'Remove', 'wp-command-center' ),
			'cleanup'     => __( 'Clean up', 'wp-command-center' ),
			'flush'       => __( 'Clear', 'wp-command-center' ),
			'purge'       => __( 'Clear', 'wp-command-center' ),
			'restore'     => __( 'Restore', 'wp-command-center' ),
			'rollback'    => __( 'Undo', 'wp-command-center' ),
			'apply'       => __( 'Apply', 'wp-command-center' ),
			'approve'     => __( 'Approve', 'wp-command-center' ),
			'reject'      => __( 'Reject', 'wp-command-center' ),
			'refund'      => __( 'Refund', 'wp-command-center' ),
			'run'         => __( 'Run', 'wp-command-center' ),
			'execute'     => __( 'Run', 'wp-command-center' ),
		];
	}

	/**
	 * Noun tokens → the words a site owner uses for the same thing.
	 *
	 * @return array<string,string>
	 */
	private static function nouns(): array {
		return [
			'acf'           => __( 'custom field', 'wp-command-center' ),
			'field'         => __( 'field', 'wp-command-center' ),
			'value'         => __( 'value', 'wp-command-center' ),
			'layout'        => __( 'layout', 'wp-command-center' ),
			'group'         => __( 'group', 'wp-command-center' ),
			'content'       => __( 'content', 'wp-command-center' ),
			'post'          => __( 'post', 'wp-command-center' ),
			'page'          => __( 'page', 'wp-command-center' ),
			'cpt'           => __( 'post type', 'wp-command-center' ),
			'term'          => __( 'category or tag', 'wp-command-center' ),
			'taxonomy'      => __( 'taxonomy', 'wp-command-center' ),
			'media'         => __( 'media', 'wp-command-center' ),
			'image'         => __( 'image', 'wp-command-center' ),
			'alt'           => __( 'alt text', 'wp-command-center' ),
			'featured'      => __( 'featured image', 'wp-command-center' ),
			'thumbnail'     => __( 'thumbnail', 'wp-command-center' ),
			'option'        => __( 'setting', 'wp-command-center' ),
			'setting'       => __( 'setting', 'wp-command-center' ),
			'settings'      => __( 'settings', 'wp-command-center' ),
			'plugin'        => __( 'plugin', 'wp-command-center' ),
			'theme'         => __( 'theme', 'wp-command-center' ),
			'user'          => __( 'user', 'wp-command-center' ),
			'role'          => __( 'role', 'wp-command-center' ),
			'comment'       => __( 'comment', 'wp-command-center' ),
			'menu'          => __( 'menu', 'wp-command-center' ),
			'widget'        => __( 'widget', 'wp-command-center' ),
			'form'          => __( 'form', 'wp-command-center' ),
			'product'       => __( 'product', 'wp-command-center' ),
			'order'         => __( 'order', 'wp-command-center' ),
			'customer'      => __( 'customer', 'wp-command-center' ),
			'coupon'        => __( 'coupon', 'wp-command-center' ),
			'note'          => __( 'note', 'wp-command-center' ),
			'status'        => __( 'status', 'wp-command-center' ),
			'seo'           => __( 'SEO details', 'wp-command-center' ),
			'meta'          => __( 'metadata', 'wp-command-center' ),
			'cache'         => __( 'cache', 'wp-command-center' ),
			'snapshot'      => __( 'snapshot', 'wp-command-center' ),
			'patch'         => __( 'file change', 'wp-command-center' ),
			'file'          => __( 'file', 'wp-command-center' ),
			'template'      => __( 'template', 'wp-command-center' ),
			'pattern'       => __( 'pattern', 'wp-command-center' ),
			'workflow'      => __( 'workflow', 'wp-command-center' ),
			'report'        => __( 'report', 'wp-command-center' ),
			'capability'    => __( 'permission', 'wp-command-center' ),
			'database'      => __( 'database', 'wp-command-center' ),
			'table'         => __( 'table', 'wp-command-center' ),
			'sidebar'       => __( 'sidebar', 'wp-command-center' ),
			'usage'         => __( 'usage', 'wp-command-center' ),
			'responsive'    => __( 'responsive images', 'wp-command-center' ),
			'woocommerce'   => 'WooCommerce',
			'woo'           => 'WooCommerce',
			'seo_meta'      => __( 'SEO details', 'wp-command-center' ),
			'webp'          => 'WebP',
			'acf_json'      => 'ACF JSON',
		];
	}

	/**
	 * Phrases worth writing by hand, because the derived version would be clumsy
	 * or, worse, would understate the risk of the thing being approved.
	 *
	 * @return array<string,string>
	 */
	private static function explicit(): array {
		return [
			/*
			 * Actions the verb/noun deriver cannot reach.
			 *
			 * derive() splits an action into verb + noun ("plugin_install" ->
			 * "Install a plugin"). That fails when the verb is not the last segment
			 * ("bulk_content"), when the noun IS the verb ("content_publish"), or
			 * when the phrase only makes sense as a whole ("db_autoload_analysis").
			 * Those 32 actions were falling through to the raw ID, so the Changes
			 * timeline showed a paying customer "bulk_content" and "comment_spam".
			 */
			/*
			 * derive() puts the verb first and leaves the rest in source order, which
			 * turns "settings_general_update" into "Update settings general". These
			 * appear in the Approvals queue — the screen where a customer decides
			 * whether to allow a change — so they have to read like a sentence.
			 */
			'settings_general_update'    => __( 'Change general settings', 'wp-command-center' ),
			'settings_reading_update'    => __( 'Change reading settings', 'wp-command-center' ),
			'settings_discussion_update' => __( 'Change discussion settings', 'wp-command-center' ),
			'settings_media_update'      => __( 'Change media settings', 'wp-command-center' ),
			'settings_permalink_update'  => __( 'Change permalink settings', 'wp-command-center' ),
			'settings_privacy_update'    => __( 'Change privacy settings', 'wp-command-center' ),
			'settings_general_get'       => __( 'View general settings', 'wp-command-center' ),
			'settings_reading_get'       => __( 'View reading settings', 'wp-command-center' ),
			'settings_discussion_get'    => __( 'View discussion settings', 'wp-command-center' ),
			'settings_media_get'         => __( 'View media settings', 'wp-command-center' ),
			'settings_permalink_get'     => __( 'View permalink settings', 'wp-command-center' ),
			'settings_privacy_get'       => __( 'View privacy settings', 'wp-command-center' ),
			'media_replace_verify'       => __( 'Check a replaced media file', 'wp-command-center' ),
			'stock_bulk_update'          => __( 'Update stock for several products', 'wp-command-center' ),
			'image_optimize_verify'      => __( 'Check an optimised image', 'wp-command-center' ),
			'db_table_list'              => __( 'List database tables', 'wp-command-center' ),
			/*
			 * Integration actions. These were unreachable until WooCommerce, ACF,
			 * Contact Form 7 and Rank Math were installed, so nothing had ever
			 * rendered them — the Approvals queue fell back to the operation title
			 * and asked a shop owner to approve "WooCommerce Management".
			 */
			'product_publish'          => __( 'Publish a product', 'wp-command-center' ),
			'product_unpublish'        => __( 'Unpublish a product', 'wp-command-center' ),
			'product_duplicate'        => __( 'Duplicate a product', 'wp-command-center' ),
			'product_search'           => __( 'Search products', 'wp-command-center' ),
			'product_category_assign'  => __( 'Add a product to a category', 'wp-command-center' ),
			'product_category_remove'  => __( 'Remove a product from a category', 'wp-command-center' ),
			'product_attribute_assign' => __( 'Add an attribute to a product', 'wp-command-center' ),
			'product_attribute_remove' => __( 'Remove an attribute from a product', 'wp-command-center' ),
			'menu_item_move'           => __( 'Move a menu item', 'wp-command-center' ),
			'menu_item_reorder'        => __( 'Reorder menu items', 'wp-command-center' ),
			'menu_location_sync'       => __( 'Sync menu locations', 'wp-command-center' ),
			'menu_tree_repair'         => __( 'Repair a menu structure', 'wp-command-center' ),
			'menu_tree_validate'       => __( 'Check a menu structure', 'wp-command-center' ),
			'menu_inventory'           => __( 'List all menus', 'wp-command-center' ),
			'acf_json_import'          => __( 'Import custom fields from JSON', 'wp-command-center' ),
			'acf_json_export'          => __( 'Export custom fields to JSON', 'wp-command-center' ),
			'acf_json_sync'            => __( 'Sync custom fields with JSON', 'wp-command-center' ),
			'acf_json_diff'            => __( 'Compare custom fields with JSON', 'wp-command-center' ),
			'acf_layout_usage'         => __( 'Check where a layout is used', 'wp-command-center' ),
			'acf_group_duplicate'      => __( 'Duplicate a field group', 'wp-command-center' ),
			'acf_group_activate'       => __( 'Activate a field group', 'wp-command-center' ),
			'acf_group_deactivate'     => __( 'Deactivate a field group', 'wp-command-center' ),
			'acf_field_duplicate'      => __( 'Duplicate a field', 'wp-command-center' ),
			'acf_location_assign'      => __( 'Set where a field group appears', 'wp-command-center' ),
			'acf_location_remove'      => __( 'Remove where a field group appears', 'wp-command-center' ),
			'form_duplicate'           => __( 'Duplicate a form', 'wp-command-center' ),
			'form_activate'            => __( 'Activate a form', 'wp-command-center' ),
			'form_deactivate'          => __( 'Deactivate a form', 'wp-command-center' ),
			'entry_search'             => __( 'Search form entries', 'wp-command-center' ),
			'entry_export'             => __( 'Export form entries', 'wp-command-center' ),
			'notification_get'         => __( 'View a form notification', 'wp-command-center' ),
			'notification_update'      => __( 'Change a form notification', 'wp-command-center' ),
			'notification_test'        => __( 'Send a test notification', 'wp-command-center' ),
			'submission_stats'         => __( 'View form submission statistics', 'wp-command-center' ),
			'widgets_rollback'         => __( 'Undo a widget change', 'wp-command-center' ),
			'cpt_rollback'             => __( 'Undo a post type change', 'wp-command-center' ),
			'bulk_content'         => __( 'Edit several items at once', 'wp-command-center' ),
			'bulk_publish'         => __( 'Publish several items at once', 'wp-command-center' ),
			'bulk_unpublish'       => __( 'Unpublish several items at once', 'wp-command-center' ),
			'bulk_media'           => __( 'Edit several media files at once', 'wp-command-center' ),
			'bulk_acf'             => __( 'Edit several custom fields at once', 'wp-command-center' ),
			'bulk_woocommerce'     => __( 'Edit several products at once', 'wp-command-center' ),
			'content_publish'      => __( 'Publish content', 'wp-command-center' ),
			'content_unpublish'    => __( 'Unpublish content', 'wp-command-center' ),
			'content_schedule'     => __( 'Schedule content', 'wp-command-center' ),
			'comment_unapprove'    => __( 'Unapprove a comment', 'wp-command-center' ),
			'comment_spam'         => __( 'Mark a comment as spam', 'wp-command-center' ),
			'comment_trash'        => __( 'Move a comment to trash', 'wp-command-center' ),
			'comment_reply'        => __( 'Reply to a comment', 'wp-command-center' ),
			'user_suspend'         => __( 'Suspend a user', 'wp-command-center' ),
			'user_reset_password'  => __( 'Reset a password', 'wp-command-center' ),
			'media_upload'         => __( 'Upload a media file', 'wp-command-center' ),
			'menu_duplicate'       => __( 'Duplicate a menu', 'wp-command-center' ),
			'cpt_disable'          => __( 'Disable a post type', 'wp-command-center' ),
			'request_cancel'       => __( 'Cancel a request', 'wp-command-center' ),
			'queue_cancel'         => __( 'Cancel a queued item', 'wp-command-center' ),
			'queue_retry'          => __( 'Retry a queued item', 'wp-command-center' ),
			'workflow_history'     => __( 'View workflow history', 'wp-command-center' ),
			'acf_inventory'        => __( 'List all custom fields', 'wp-command-center' ),
			'settings_inventory'   => __( 'List all settings', 'wp-command-center' ),
			'db_table_stats'       => __( 'Check table statistics', 'wp-command-center' ),
			'db_table_size'        => __( 'Check table sizes', 'wp-command-center' ),
			'db_row_counts'        => __( 'Count database rows', 'wp-command-center' ),
			'db_autoload_analysis' => __( 'Check auto-loaded settings', 'wp-command-center' ),
			'db_options_health'    => __( 'Check settings health', 'wp-command-center' ),
			'db_index_analysis'    => __( 'Check database indexes', 'wp-command-center' ),
			'db_orphan_detection'  => __( 'Find orphaned database rows', 'wp-command-center' ),
			'db_health_summary'    => __( 'Summarise database health', 'wp-command-center' ),
			'safe_search_replace' => __( 'Find and replace text across the database', 'wp-command-center' ),
			'search_replace'      => __( 'Find and replace text across the database', 'wp-command-center' ),
			'plugin_update'       => __( 'Update a plugin', 'wp-command-center' ),
			'plugin_install'      => __( 'Install a plugin', 'wp-command-center' ),
			'plugin_delete'       => __( 'Delete a plugin', 'wp-command-center' ),
			'theme_update'        => __( 'Update a theme', 'wp-command-center' ),
			'theme_install'       => __( 'Install a theme', 'wp-command-center' ),
			'theme_delete'        => __( 'Delete a theme', 'wp-command-center' ),
			'theme_activate'      => __( 'Switch the active theme', 'wp-command-center' ),
			'option_update'       => __( 'Change a WordPress setting', 'wp-command-center' ),
			'option_rollback'     => __( 'Undo a settings change', 'wp-command-center' ),
			'content_create'      => __( 'Create a post or page', 'wp-command-center' ),
			'content_update'      => __( 'Edit a post or page', 'wp-command-center' ),
			'content_delete'      => __( 'Delete a post or page', 'wp-command-center' ),
			'acf_value_set'       => __( 'Set a custom field value', 'wp-command-center' ),
			'acf_value_get'       => __( 'Read a custom field value', 'wp-command-center' ),
			'media_replace'       => __( 'Replace a media file', 'wp-command-center' ),
			'set_featured'        => __( 'Set the featured image', 'wp-command-center' ),
			'remove_featured'     => __( 'Remove the featured image', 'wp-command-center' ),
			'patch_apply'         => __( 'Apply a change to site files', 'wp-command-center' ),
			'patch_create'        => __( 'Prepare a change to site files', 'wp-command-center' ),
			'patch_rollback'      => __( 'Undo a change to site files', 'wp-command-center' ),
			'user_create'         => __( 'Create a user account', 'wp-command-center' ),
			'user_delete'         => __( 'Delete a user account', 'wp-command-center' ),
			'user_update'         => __( 'Edit a user account', 'wp-command-center' ),
			'cache_flush'         => __( 'Clear the site cache', 'wp-command-center' ),
			'refund_create'       => __( 'Issue a refund', 'wp-command-center' ),
			'status_change'       => __( 'Change the order status', 'wp-command-center' ),
			'wp_cli_bridge'       => __( 'Run a WP-CLI command', 'wp-command-center' ),
			'database_inspect'    => __( 'Inspect the database', 'wp-command-center' ),
			'unused_media_cleanup' => __( 'Move unused media out of the library', 'wp-command-center' ),
			// The engine's word for undoing something; the customer's word is "undo".
			'rollback_target'     => __( 'Undo a change', 'wp-command-center' ),
			'rollback_discover'   => __( 'Find changes that can be undone', 'wp-command-center' ),
			// Reads whose derived phrasing would come out backwards.
			'file_tree'           => __( 'Browse site files', 'wp-command-center' ),
			'file_metadata'       => __( 'View file details', 'wp-command-center' ),
			'history_timeline'    => __( 'View change history', 'wp-command-center' ),
			'operation_status'    => __( 'Check on a running request', 'wp-command-center' ),
			'media_usage_report'  => __( 'See where media is used', 'wp-command-center' ),
			'media_enhance_capabilities' => __( 'Check which image tools this server supports', 'wp-command-center' ),
			'image_size_recommendations' => __( 'Suggest better image sizes', 'wp-command-center' ),
			'navigation_manage'   => __( 'Manage navigation', 'wp-command-center' ),
			'template_assign'     => __( 'Assign a template', 'wp-command-center' ),
			'menu_assign'         => __( 'Assign a menu to a location', 'wp-command-center' ),
		];
	}

	/**
	 * The plain-language title for one action.
	 *
	 * @param string $action        The action ID (may be empty).
	 * @param string $operation_id  The owning operation (used when there is no action).
	 * @param string $fallback      Registry title, used when nothing else resolves.
	 */
	public static function action( string $action, string $operation_id = '', string $fallback = '' ): string {
		$action = trim( $action );

		if ( '' === $action ) {
			// No action supplied: name the area instead of inventing a verb.
			if ( '' !== $fallback ) {
				return $fallback;
			}
			return '' !== $operation_id ? self::area( self::runtime_of( $operation_id ) ) : __( 'Change', 'wp-command-center' );
		}

		$explicit = self::explicit();
		if ( isset( $explicit[ $action ] ) ) {
			return $explicit[ $action ];
		}

		// The report family reads as a noun, not a command: `report_site_health`
		// is "Site health report", never "Report site health".
		if ( 0 === strpos( $action, 'report_' ) ) {
			$subject = self::humanize_tokens( explode( '_', substr( $action, 7 ) ) );
			if ( '' !== $subject ) {
				/* translators: %s: what the report covers, e.g. "Site health" */
				return sprintf( __( '%s report', 'wp-command-center' ), ucfirst( $subject ) );
			}
		}

		$derived = self::derive( $action );
		if ( '' !== $derived ) {
			return $derived;
		}

		return '' !== $fallback ? $fallback : $action;
	}

	/**
	 * Turn `noun_noun_verb` or `verb_noun` into a sentence. Returns '' when the
	 * identifier carries no recognisable verb — the caller then falls back rather
	 * than printing a confidently wrong phrase.
	 */
	private static function derive( string $action ): string {
		$parts = array_values( array_filter( explode( '_', strtolower( $action ) ), 'strlen' ) );
		if ( [] === $parts ) {
			return '';
		}

		// Scope modifiers ride on the end of an ID (`..._batch`, `cache_purge_all`).
		// They qualify the action rather than name it, so peel them off before
		// looking for the verb and re-attach the meaning afterwards.
		$qualifier = '';
		$modifiers = [
			'batch'      => __( '(in bulk)', 'wp-command-center' ),
			'all'        => __( '(everything)', 'wp-command-center' ),
			'attachment' => '',
			'url'        => '',
			'structure'  => '',
		];
		while ( count( $parts ) > 1 && isset( $modifiers[ $parts[ count( $parts ) - 1 ] ] ) ) {
			$mod = array_pop( $parts );
			if ( '' === $qualifier && '' !== $modifiers[ $mod ] ) {
				$qualifier = $modifiers[ $mod ];
			}
		}

		$verbs = self::verbs();
		$verb  = '';
		$rest  = [];

		$last = $parts[ count( $parts ) - 1 ];
		if ( isset( $verbs[ $last ] ) ) {
			$verb = $verbs[ $last ];
			$rest = array_slice( $parts, 0, -1 );
		} elseif ( isset( $verbs[ $parts[0] ] ) ) {
			$verb = $verbs[ $parts[0] ];
			$rest = array_slice( $parts, 1 );
		} else {
			// The verb can sit in the middle (`elementor_update_text`). Take the
			// first one found and treat every other token as part of the object.
			foreach ( $parts as $i => $token ) {
				if ( isset( $verbs[ $token ] ) ) {
					$verb = $verbs[ $token ];
					$rest = array_merge( array_slice( $parts, 0, $i ), array_slice( $parts, $i + 1 ) );
					break;
				}
			}
			if ( '' === $verb ) {
				return '';
			}
		}

		$object = self::humanize_tokens( $rest );
		if ( '' === $object ) {
			return trim( $verb . ( '' !== $qualifier ? ' ' . $qualifier : '' ) );
		}

		/* translators: 1: verb such as "Update", 2: object such as "plugin" */
		$label = sprintf( _x( '%1$s %2$s', 'action label', 'wp-command-center' ), $verb, $object );
		return trim( $label . ( '' !== $qualifier ? ' ' . $qualifier : '' ) );
	}

	/**
	 * Map identifier tokens onto everyday words, leaving anything unrecognised as
	 * itself (with underscores/hyphens opened up).
	 *
	 * @param string[] $tokens
	 */
	private static function humanize_tokens( array $tokens ): string {
		$nouns  = self::nouns();
		$phrase = [];
		foreach ( $tokens as $token ) {
			$token = trim( (string) $token );
			if ( '' === $token ) {
				continue;
			}
			$phrase[] = $nouns[ $token ] ?? str_replace( '-', ' ', $token );
		}
		return trim( implode( ' ', $phrase ) );
	}

	/** `plugin_manage` → `plugin`. Mirrors ChangeRecorder's runtime derivation. */
	public static function runtime_of( string $operation_id ): string {
		return (string) preg_replace( '/_manage$/', '', trim( $operation_id ) );
	}

	/**
	 * The area of the site a runtime touches, as a person would name it. Used for
	 * History session summaries ("Content, SEO details").
	 */
	public static function area( string $runtime ): string {
		$areas = [
			'content'            => __( 'Posts & pages', 'wp-command-center' ),
			'content_seed'       => __( 'Sample content', 'wp-command-center' ),
			'media'              => __( 'Media', 'wp-command-center' ),
			'media_enhance'      => __( 'Media', 'wp-command-center' ),
			'media_import'       => __( 'Media', 'wp-command-center' ),
			'seo'                => __( 'SEO', 'wp-command-center' ),
			'option'             => __( 'Settings', 'wp-command-center' ),
			'settings'           => __( 'Settings', 'wp-command-center' ),
			'user'               => __( 'Users', 'wp-command-center' ),
			'comments'           => __( 'Comments', 'wp-command-center' ),
			'term'               => __( 'Categories & tags', 'wp-command-center' ),
			'menu'               => __( 'Menus', 'wp-command-center' ),
			'widgets'            => __( 'Widgets', 'wp-command-center' ),
			'cpt'                => __( 'Post types', 'wp-command-center' ),
			'forms'              => __( 'Forms', 'wp-command-center' ),
			'acf'                => __( 'Custom fields', 'wp-command-center' ),
			'acf_seed'           => __( 'Custom fields', 'wp-command-center' ),
			'woocommerce'        => __( 'Shop', 'wp-command-center' ),
			'woo_product_seed'   => __( 'Shop', 'wp-command-center' ),
			'cf7_seed'           => __( 'Forms', 'wp-command-center' ),
			'plugin'             => __( 'Plugins', 'wp-command-center' ),
			'theme'              => __( 'Themes', 'wp-command-center' ),
			'safe_updates'       => __( 'Updates', 'wp-command-center' ),
			'site_builder'       => __( 'Site design', 'wp-command-center' ),
			'elementor'          => __( 'Site design', 'wp-command-center' ),
			'cache'              => __( 'Cache', 'wp-command-center' ),
			'patch'              => __( 'Site files', 'wp-command-center' ),
			'file'               => __( 'Site files', 'wp-command-center' ),
			'code_search'        => __( 'Site files', 'wp-command-center' ),
			'safe_search_replace' => __( 'Database', 'wp-command-center' ),
			'database_inspect'   => __( 'Database', 'wp-command-center' ),
			'wp_cli_bridge'      => __( 'WP-CLI', 'wp-command-center' ),
			'snapshot'           => __( 'Snapshots', 'wp-command-center' ),
			'rollback'           => __( 'Undo', 'wp-command-center' ),
			'workflow'           => __( 'Workflows', 'wp-command-center' ),
			'bulk'               => __( 'Bulk changes', 'wp-command-center' ),
			'capability'         => __( 'Permissions', 'wp-command-center' ),
			'approval'           => __( 'Approvals', 'wp-command-center' ),
			'report'             => __( 'Reports', 'wp-command-center' ),
			'search'             => __( 'Search', 'wp-command-center' ),
			'system_info'        => __( 'Site info', 'wp-command-center' ),
			'change_history'     => __( 'History', 'wp-command-center' ),
		];

		$runtime = trim( $runtime );
		if ( isset( $areas[ $runtime ] ) ) {
			return $areas[ $runtime ];
		}
		return ucfirst( str_replace( '_', ' ', $runtime ) );
	}

	/**
	 * A one-line "what this touches" from the request payload — the detail that
	 * turns "Edit a post or page" into "Edit a post or page — “About us” (#42)".
	 *
	 * Deliberately conservative: it reports only identifying fields it recognises
	 * and never guesses at the *shape* of a change. An empty string is a correct
	 * answer and callers must handle it.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function target( array $payload ): string {
		$bits = [];

		// A human-meaningful name, if the payload carries one. `option_id` is the
		// canonical parameter for settings — omitting it meant a correctly-formed
		// request rendered with LESS detail than a malformed one.
		foreach ( [ 'title', 'post_title', 'name', 'label', 'slug', 'option_id', 'option', 'option_name', 'field_name', 'plugin', 'theme', 'path', 'query', 'search', 'taxonomy' ] as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				$value  = self::friendly_option( (string) $payload[ $key ] );
				$bits[] = '“' . self::shorten( $value ) . '”';
				break;
			}
		}

		// An ID, so the user can find the exact thing.
		foreach ( [ 'post_id', 'id', 'attachment_id', 'user_id', 'term_id', 'product_id', 'order_id', 'comment_id' ] as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
				$bits[] = '#' . self::shorten( (string) $payload[ $key ], 20 );
				break;
			}
		}

		// How many things, when the request is a batch.
		foreach ( [ 'count', 'limit', 'ids' ] as $key ) {
			if ( ! isset( $payload[ $key ] ) ) {
				continue;
			}
			$n = is_array( $payload[ $key ] ) ? count( $payload[ $key ] ) : (int) $payload[ $key ];
			if ( $n > 1 ) {
				/* translators: %d: number of items affected */
				$bits[] = sprintf( _n( '%d item', '%d items', $n, 'wp-command-center' ), $n );
			}
			break;
		}

		return implode( ' ', $bits );
	}

	/**
	 * The full plain-language sentence for a request: title plus target.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function describe( string $operation_id, string $action, array $payload = [], string $fallback = '' ): string {
		$title  = self::action( $action, $operation_id, $fallback );
		$target = self::target( $payload );

		// An undo carries only the id of the change it reverses, and `change_id` is
		// not a name or an object id, so target() found nothing and the approval row
		// read a bare "Undo a change". With one undo pending that is merely terse;
		// with several it is genuinely ambiguous — the owner cannot tell which change
		// they are approving the reversal of. Name the original instead.
		if ( '' === $target ) {
			$target = self::undo_target( $payload );
		}

		if ( '' === $target ) {
			return $title;
		}
		/* translators: 1: what the change does, 2: which item it affects */
		return sprintf( _x( '%1$s — %2$s', 'change summary', 'wp-command-center' ), $title, $target );
	}

	/**
	 * Describe the change an undo request reverses, e.g. `Update price — #1185`.
	 *
	 * One indexed, read-only lookup on change_id (the column is unique-keyed), and
	 * the original row is then run through this same humanizer, so the words on the
	 * undo approval are identical to the words on the change it undoes. Returns ''
	 * whenever the change cannot be resolved — a missing original must never stop an
	 * approval row from rendering.
	 *
	 * @param array<string,mixed> $payload
	 */
	private static function undo_target( array $payload ): string {
		/*
		 * An undo names its target by change_id OR by rollback_id — the latter is the
		 * handle a write returns and, since the rollback routing contract, the value a
		 * caller is most likely to send. Look the row up by the matching COLUMN;
		 * searching change_id for a rollback_id silently found nothing and the row fell
		 * back to a bare "Undo a change".
		 */
		foreach ( [ 'change_id' => 'change_id', 'target_change_id' => 'change_id', 'rollback_id' => 'rollback_id' ] as $key => $column ) {
			$id = isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ? (string) $payload[ $key ] : '';
			if ( '' === $id ) {
				continue;
			}

			global $wpdb;
			$table = $wpdb->prefix . 'wpcc_change_log';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin-owned table, single indexed row, display only.
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT operation_id, action, target_summary FROM {$table} WHERE {$column} = %s ORDER BY id DESC LIMIT 1", $id ),
				ARRAY_A
			);
			if ( ! $row ) {
				continue;
			}

			// target_summary stores an ALREADY-RENDERED label (quotes included), not a
			// raw payload — running it back through target() would wrap it a second
			// time and print ““like this””. Take the title from the humanizer and the
			// label verbatim.
			$summary = json_decode( (string) ( $row['target_summary'] ?? '' ), true );
			$title   = self::action( (string) ( $row['action'] ?? '' ), (string) ( $row['operation_id'] ?? '' ) );
			$label   = is_array( $summary ) && isset( $summary['label'] ) && is_scalar( $summary['label'] )
				? trim( (string) $summary['label'] )
				: '';

			if ( '' === $title ) {
				return $label;
			}
			if ( '' === $label ) {
				return $title;
			}
			/* translators: 1: what the original change did, 2: which item it affected */
			return sprintf( _x( '%1$s — %2$s', 'change summary', 'wp-command-center' ), $title, $label );
		}

		return '';
	}

	/**
	 * A ready-made lookup for client-side rendering: every action the registry
	 * declares, already resolved to its plain-language label, plus the area name
	 * for every operation.
	 *
	 * Built here rather than re-implementing the humanizer in JavaScript, so the
	 * words a customer reads in the History timeline are byte-identical to the
	 * ones on the approval card. Bounded by the registry (a couple of hundred
	 * short strings) and computed from a read-only catalogue call.
	 *
	 * @return array{actions:array<string,string>,areas:array<string,string>,titles:array<string,string>}
	 */
	public static function dictionary(): array {
		$actions = [];
		$areas   = [];
		$titles  = [];

		foreach ( ( new \WPCommandCenter\Operations\OperationRegistry() )->get_operations() as $operation ) {
			$id = (string) ( $operation['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$areas[ $id ]  = self::area( self::runtime_of( $id ) );
			// Registry title, for changes recorded without an explicit action.
			$titles[ $id ] = (string) ( $operation['title'] ?? $id );

			// Operations declare their actions in two places and not consistently:
			// most as the enum of the `action` parameter, some only as the keys of
			// `action_risks`. Read both, or the timeline falls back to raw IDs for
			// exactly the destructive operations that most need plain words.
			$declared = [];

			foreach ( ( $operation['parameters'] ?? [] ) as $param ) {
				if ( 'action' === ( $param['name'] ?? '' ) && is_array( $param['enum'] ?? null ) ) {
					$declared = array_merge( $declared, $param['enum'] );
				}
			}
			if ( is_array( $operation['action_risks'] ?? null ) ) {
				$declared = array_merge( $declared, array_keys( $operation['action_risks'] ) );
			}

			foreach ( $declared as $value ) {
				$value = (string) $value;
				if ( '' !== $value && ! isset( $actions[ $value ] ) ) {
					$actions[ $value ] = self::action( $value, $id );
				}
			}
		}

		return [ 'actions' => $actions, 'areas' => $areas, 'titles' => $titles ];
	}

	/**
	 * WordPress's own setting keys are not words a site owner recognises. On an
	 * approval card, "blogdescription" asks them to know WordPress internals in
	 * order to decide; "Tagline" is the label they see in Settings → General.
	 * Anything unrecognised is returned untouched.
	 */
	private static function friendly_option( string $value ): string {
		$known = [
			// Registry IDs (option_manage speaks these) …
			'site_title'       => __( 'Site title', 'wp-command-center' ),
			'tagline'          => __( 'Tagline', 'wp-command-center' ),
			'timezone'         => __( 'Timezone', 'wp-command-center' ),
			// … and raw WordPress option names, for operations that pass those.
			'blogname'         => __( 'Site title', 'wp-command-center' ),
			'blogdescription'  => __( 'Tagline', 'wp-command-center' ),
			'admin_email'      => __( 'Admin email', 'wp-command-center' ),
			'siteurl'          => __( 'WordPress address', 'wp-command-center' ),
			'home'             => __( 'Site address', 'wp-command-center' ),
			'posts_per_page'   => __( 'Posts per page', 'wp-command-center' ),
			'date_format'      => __( 'Date format', 'wp-command-center' ),
			'time_format'      => __( 'Time format', 'wp-command-center' ),
			'start_of_week'    => __( 'Week starts on', 'wp-command-center' ),
			'timezone_string'  => __( 'Timezone', 'wp-command-center' ),
			'permalink_structure' => __( 'Permalink structure', 'wp-command-center' ),
			'blog_public'      => __( 'Search engine visibility', 'wp-command-center' ),
			'default_category' => __( 'Default category', 'wp-command-center' ),
			'show_on_front'    => __( 'Homepage display', 'wp-command-center' ),
			'page_on_front'    => __( 'Homepage', 'wp-command-center' ),
			'page_for_posts'   => __( 'Posts page', 'wp-command-center' ),
		];
		return $known[ $value ] ?? $value;
	}

	/** Trim a value to something that fits on a card without wrapping. */
	private static function shorten( string $value, int $max = 48 ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) <= $max : strlen( $value ) <= $max ) {
			return $value;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
		return rtrim( $cut ) . '…';
	}
}
