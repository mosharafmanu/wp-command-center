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
			'get'         => __( 'View', 'action-steward' ),
			'list'        => __( 'List', 'action-steward' ),
			'details'     => __( 'View', 'action-steward' ),
			'describe'    => __( 'Describe', 'action-steward' ),
			'count'       => __( 'Count', 'action-steward' ),
			'search'      => __( 'Search', 'action-steward' ),
			'find'        => __( 'Find', 'action-steward' ),
			'read'        => __( 'Read', 'action-steward' ),
			'preview'     => __( 'Preview', 'action-steward' ),
			'status'      => __( 'Check', 'action-steward' ),
			'validate'    => __( 'Check', 'action-steward' ),
			'assign'      => __( 'Assign', 'action-steward' ),
			'manage'      => __( 'Manage', 'action-steward' ),
			'scan'        => __( 'Scan', 'action-steward' ),
			'audit'       => __( 'Check', 'action-steward' ),
			'analyze'     => __( 'Analyse', 'action-steward' ),
			'verify'      => __( 'Verify', 'action-steward' ),
			'create'      => __( 'Create', 'action-steward' ),
			'add'         => __( 'Add', 'action-steward' ),
			'seed'        => __( 'Create sample', 'action-steward' ),
			'generate'    => __( 'Generate', 'action-steward' ),
			'import'      => __( 'Import', 'action-steward' ),
			'export'      => __( 'Export', 'action-steward' ),
			'update'      => __( 'Update', 'action-steward' ),
			'set'         => __( 'Set', 'action-steward' ),
			'replace'     => __( 'Replace', 'action-steward' ),
			'enhance'     => __( 'Improve', 'action-steward' ),
			'optimize'    => __( 'Optimise', 'action-steward' ),
			'regenerate'  => __( 'Regenerate', 'action-steward' ),
			'install'     => __( 'Install', 'action-steward' ),
			'activate'    => __( 'Activate', 'action-steward' ),
			'deactivate'  => __( 'Deactivate', 'action-steward' ),
			'delete'      => __( 'Delete', 'action-steward' ),
			'remove'      => __( 'Remove', 'action-steward' ),
			'cleanup'     => __( 'Clean up', 'action-steward' ),
			'flush'       => __( 'Clear', 'action-steward' ),
			'purge'       => __( 'Clear', 'action-steward' ),
			'restore'     => __( 'Restore', 'action-steward' ),
			'rollback'    => __( 'Undo', 'action-steward' ),
			'apply'       => __( 'Apply', 'action-steward' ),
			'approve'     => __( 'Approve', 'action-steward' ),
			'reject'      => __( 'Reject', 'action-steward' ),
			'refund'      => __( 'Refund', 'action-steward' ),
			'run'         => __( 'Run', 'action-steward' ),
			'execute'     => __( 'Run', 'action-steward' ),
		];
	}

	/**
	 * Noun tokens → the words a site owner uses for the same thing.
	 *
	 * @return array<string,string>
	 */
	private static function nouns(): array {
		return [
			'acf'           => __( 'custom field', 'action-steward' ),
			'field'         => __( 'field', 'action-steward' ),
			'value'         => __( 'value', 'action-steward' ),
			'layout'        => __( 'layout', 'action-steward' ),
			'group'         => __( 'group', 'action-steward' ),
			'content'       => __( 'content', 'action-steward' ),
			'post'          => __( 'post', 'action-steward' ),
			'page'          => __( 'page', 'action-steward' ),
			'cpt'           => __( 'post type', 'action-steward' ),
			'term'          => __( 'category or tag', 'action-steward' ),
			'taxonomy'      => __( 'taxonomy', 'action-steward' ),
			'media'         => __( 'media', 'action-steward' ),
			'image'         => __( 'image', 'action-steward' ),
			'alt'           => __( 'alt text', 'action-steward' ),
			'featured'      => __( 'featured image', 'action-steward' ),
			'thumbnail'     => __( 'thumbnail', 'action-steward' ),
			'option'        => __( 'setting', 'action-steward' ),
			'setting'       => __( 'setting', 'action-steward' ),
			'settings'      => __( 'settings', 'action-steward' ),
			'plugin'        => __( 'plugin', 'action-steward' ),
			'theme'         => __( 'theme', 'action-steward' ),
			'user'          => __( 'user', 'action-steward' ),
			'role'          => __( 'role', 'action-steward' ),
			'comment'       => __( 'comment', 'action-steward' ),
			'menu'          => __( 'menu', 'action-steward' ),
			'widget'        => __( 'widget', 'action-steward' ),
			'form'          => __( 'form', 'action-steward' ),
			'product'       => __( 'product', 'action-steward' ),
			'order'         => __( 'order', 'action-steward' ),
			'customer'      => __( 'customer', 'action-steward' ),
			'coupon'        => __( 'coupon', 'action-steward' ),
			'note'          => __( 'note', 'action-steward' ),
			'status'        => __( 'status', 'action-steward' ),
			'seo'           => __( 'SEO details', 'action-steward' ),
			'meta'          => __( 'metadata', 'action-steward' ),
			'cache'         => __( 'cache', 'action-steward' ),
			'snapshot'      => __( 'snapshot', 'action-steward' ),
			'patch'         => __( 'file change', 'action-steward' ),
			'file'          => __( 'file', 'action-steward' ),
			'template'      => __( 'template', 'action-steward' ),
			'pattern'       => __( 'pattern', 'action-steward' ),
			'workflow'      => __( 'workflow', 'action-steward' ),
			'report'        => __( 'report', 'action-steward' ),
			'capability'    => __( 'permission', 'action-steward' ),
			'database'      => __( 'database', 'action-steward' ),
			'table'         => __( 'table', 'action-steward' ),
			'sidebar'       => __( 'sidebar', 'action-steward' ),
			'usage'         => __( 'usage', 'action-steward' ),
			'responsive'    => __( 'responsive images', 'action-steward' ),
			'woocommerce'   => 'WooCommerce',
			'woo'           => 'WooCommerce',
			'seo_meta'      => __( 'SEO details', 'action-steward' ),
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
			'settings_general_update'    => __( 'Change general settings', 'action-steward' ),
			'settings_reading_update'    => __( 'Change reading settings', 'action-steward' ),
			'settings_discussion_update' => __( 'Change discussion settings', 'action-steward' ),
			'settings_media_update'      => __( 'Change media settings', 'action-steward' ),
			'settings_permalink_update'  => __( 'Change permalink settings', 'action-steward' ),
			'settings_privacy_update'    => __( 'Change privacy settings', 'action-steward' ),
			'settings_general_get'       => __( 'View general settings', 'action-steward' ),
			'settings_reading_get'       => __( 'View reading settings', 'action-steward' ),
			'settings_discussion_get'    => __( 'View discussion settings', 'action-steward' ),
			'settings_media_get'         => __( 'View media settings', 'action-steward' ),
			'settings_permalink_get'     => __( 'View permalink settings', 'action-steward' ),
			'settings_privacy_get'       => __( 'View privacy settings', 'action-steward' ),
			'media_replace_verify'       => __( 'Check a replaced media file', 'action-steward' ),
			'stock_bulk_update'          => __( 'Update stock for several products', 'action-steward' ),
			'image_optimize_verify'      => __( 'Check an optimised image', 'action-steward' ),
			'db_table_list'              => __( 'List database tables', 'action-steward' ),
			/*
			 * Integration actions. These were unreachable until WooCommerce, ACF,
			 * Contact Form 7 and Rank Math were installed, so nothing had ever
			 * rendered them — the Approvals queue fell back to the operation title
			 * and asked a shop owner to approve "WooCommerce Management".
			 */
			'product_publish'          => __( 'Publish a product', 'action-steward' ),
			'product_unpublish'        => __( 'Unpublish a product', 'action-steward' ),
			'product_duplicate'        => __( 'Duplicate a product', 'action-steward' ),
			'product_search'           => __( 'Search products', 'action-steward' ),
			'product_category_assign'  => __( 'Add a product to a category', 'action-steward' ),
			'product_category_remove'  => __( 'Remove a product from a category', 'action-steward' ),
			'product_attribute_assign' => __( 'Add an attribute to a product', 'action-steward' ),
			'product_attribute_remove' => __( 'Remove an attribute from a product', 'action-steward' ),
			'menu_item_move'           => __( 'Move a menu item', 'action-steward' ),
			'menu_item_reorder'        => __( 'Reorder menu items', 'action-steward' ),
			'menu_location_sync'       => __( 'Sync menu locations', 'action-steward' ),
			'menu_tree_repair'         => __( 'Repair a menu structure', 'action-steward' ),
			'menu_tree_validate'       => __( 'Check a menu structure', 'action-steward' ),
			'menu_inventory'           => __( 'List all menus', 'action-steward' ),
			'acf_json_import'          => __( 'Import custom fields from JSON', 'action-steward' ),
			'acf_json_export'          => __( 'Export custom fields to JSON', 'action-steward' ),
			'acf_json_sync'            => __( 'Sync custom fields with JSON', 'action-steward' ),
			'acf_json_diff'            => __( 'Compare custom fields with JSON', 'action-steward' ),
			'acf_layout_usage'         => __( 'Check where a layout is used', 'action-steward' ),
			'acf_group_duplicate'      => __( 'Duplicate a field group', 'action-steward' ),
			'acf_group_activate'       => __( 'Activate a field group', 'action-steward' ),
			'acf_group_deactivate'     => __( 'Deactivate a field group', 'action-steward' ),
			'acf_field_duplicate'      => __( 'Duplicate a field', 'action-steward' ),
			'acf_location_assign'      => __( 'Set where a field group appears', 'action-steward' ),
			'acf_location_remove'      => __( 'Remove where a field group appears', 'action-steward' ),
			'form_duplicate'           => __( 'Duplicate a form', 'action-steward' ),
			'form_activate'            => __( 'Activate a form', 'action-steward' ),
			'form_deactivate'          => __( 'Deactivate a form', 'action-steward' ),
			'entry_search'             => __( 'Search form entries', 'action-steward' ),
			'entry_export'             => __( 'Export form entries', 'action-steward' ),
			'notification_get'         => __( 'View a form notification', 'action-steward' ),
			'notification_update'      => __( 'Change a form notification', 'action-steward' ),
			'notification_test'        => __( 'Send a test notification', 'action-steward' ),
			'submission_stats'         => __( 'View form submission statistics', 'action-steward' ),
			'widgets_rollback'         => __( 'Undo a widget change', 'action-steward' ),
			'cpt_rollback'             => __( 'Undo a post type change', 'action-steward' ),
			'bulk_content'         => __( 'Edit several items at once', 'action-steward' ),
			'bulk_publish'         => __( 'Publish several items at once', 'action-steward' ),
			'bulk_unpublish'       => __( 'Unpublish several items at once', 'action-steward' ),
			'bulk_media'           => __( 'Edit several media files at once', 'action-steward' ),
			'bulk_acf'             => __( 'Edit several custom fields at once', 'action-steward' ),
			'bulk_woocommerce'     => __( 'Edit several products at once', 'action-steward' ),
			'content_publish'      => __( 'Publish content', 'action-steward' ),
			'content_unpublish'    => __( 'Unpublish content', 'action-steward' ),
			'content_schedule'     => __( 'Schedule content', 'action-steward' ),
			'comment_unapprove'    => __( 'Unapprove a comment', 'action-steward' ),
			'comment_spam'         => __( 'Mark a comment as spam', 'action-steward' ),
			'comment_trash'        => __( 'Move a comment to trash', 'action-steward' ),
			'comment_reply'        => __( 'Reply to a comment', 'action-steward' ),
			'user_suspend'         => __( 'Suspend a user', 'action-steward' ),
			'user_reset_password'  => __( 'Reset a password', 'action-steward' ),
			'media_upload'         => __( 'Upload a media file', 'action-steward' ),
			'menu_duplicate'       => __( 'Duplicate a menu', 'action-steward' ),
			'cpt_disable'          => __( 'Disable a post type', 'action-steward' ),
			'request_cancel'       => __( 'Cancel a request', 'action-steward' ),
			'queue_cancel'         => __( 'Cancel a queued item', 'action-steward' ),
			'queue_retry'          => __( 'Retry a queued item', 'action-steward' ),
			'workflow_history'     => __( 'View workflow history', 'action-steward' ),
			'acf_inventory'        => __( 'List all custom fields', 'action-steward' ),
			'settings_inventory'   => __( 'List all settings', 'action-steward' ),
			'db_table_stats'       => __( 'Check table statistics', 'action-steward' ),
			'db_table_size'        => __( 'Check table sizes', 'action-steward' ),
			'db_row_counts'        => __( 'Count database rows', 'action-steward' ),
			'db_autoload_analysis' => __( 'Check auto-loaded settings', 'action-steward' ),
			'db_options_health'    => __( 'Check settings health', 'action-steward' ),
			'db_index_analysis'    => __( 'Check database indexes', 'action-steward' ),
			'db_orphan_detection'  => __( 'Find orphaned database rows', 'action-steward' ),
			'db_health_summary'    => __( 'Summarise database health', 'action-steward' ),
			'safe_search_replace' => __( 'Find and replace text across the database', 'action-steward' ),
			'search_replace'      => __( 'Find and replace text across the database', 'action-steward' ),
			'plugin_update'       => __( 'Update a plugin', 'action-steward' ),
			'plugin_install'      => __( 'Install a plugin', 'action-steward' ),
			'plugin_delete'       => __( 'Delete a plugin', 'action-steward' ),
			'theme_update'        => __( 'Update a theme', 'action-steward' ),
			'theme_install'       => __( 'Install a theme', 'action-steward' ),
			'theme_delete'        => __( 'Delete a theme', 'action-steward' ),
			'theme_activate'      => __( 'Switch the active theme', 'action-steward' ),
			'option_update'       => __( 'Change a WordPress setting', 'action-steward' ),
			'option_rollback'     => __( 'Undo a settings change', 'action-steward' ),
			'content_create'      => __( 'Create a post or page', 'action-steward' ),
			'content_update'      => __( 'Edit a post or page', 'action-steward' ),
			'content_delete'      => __( 'Delete a post or page', 'action-steward' ),
			'acf_value_set'       => __( 'Set a custom field value', 'action-steward' ),
			'acf_value_get'       => __( 'Read a custom field value', 'action-steward' ),
			'media_replace'       => __( 'Replace a media file', 'action-steward' ),
			'set_featured'        => __( 'Set the featured image', 'action-steward' ),
			'remove_featured'     => __( 'Remove the featured image', 'action-steward' ),
			'patch_apply'         => __( 'Apply a change to site files', 'action-steward' ),
			'patch_create'        => __( 'Prepare a change to site files', 'action-steward' ),
			'patch_rollback'      => __( 'Undo a change to site files', 'action-steward' ),
			'user_create'         => __( 'Create a user account', 'action-steward' ),
			'user_delete'         => __( 'Delete a user account', 'action-steward' ),
			'user_update'         => __( 'Edit a user account', 'action-steward' ),
			'cache_flush'         => __( 'Clear the site cache', 'action-steward' ),
			'refund_create'       => __( 'Issue a refund', 'action-steward' ),
			'status_change'       => __( 'Change the order status', 'action-steward' ),
			'wp_cli_bridge'       => __( 'Run a WP-CLI command', 'action-steward' ),
			'database_inspect'    => __( 'Inspect the database', 'action-steward' ),
			'unused_media_cleanup' => __( 'Move unused media out of the library', 'action-steward' ),
			// The engine's word for undoing something; the customer's word is "undo".
			'rollback_target'     => __( 'Undo a change', 'action-steward' ),
			'rollback_discover'   => __( 'Find changes that can be undone', 'action-steward' ),
			// Reads whose derived phrasing would come out backwards.
			'file_tree'           => __( 'Browse site files', 'action-steward' ),
			'file_metadata'       => __( 'View file details', 'action-steward' ),
			'history_timeline'    => __( 'View change history', 'action-steward' ),
			'operation_status'    => __( 'Check on a running request', 'action-steward' ),
			'media_usage_report'  => __( 'See where media is used', 'action-steward' ),
			'media_enhance_capabilities' => __( 'Check which image tools this server supports', 'action-steward' ),
			'image_size_recommendations' => __( 'Suggest better image sizes', 'action-steward' ),
			'navigation_manage'   => __( 'Manage navigation', 'action-steward' ),
			'template_assign'     => __( 'Assign a template', 'action-steward' ),
			'menu_assign'         => __( 'Assign a menu to a location', 'action-steward' ),
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
			return '' !== $operation_id ? self::area( self::runtime_of( $operation_id ) ) : __( 'Change', 'action-steward' );
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
				return sprintf( __( '%s report', 'action-steward' ), ucfirst( $subject ) );
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
			'batch'      => __( '(in bulk)', 'action-steward' ),
			'all'        => __( '(everything)', 'action-steward' ),
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
		$label = sprintf( _x( '%1$s %2$s', 'action label', 'action-steward' ), $verb, $object );
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
			'content'            => __( 'Posts & pages', 'action-steward' ),
			'content_seed'       => __( 'Sample content', 'action-steward' ),
			'media'              => __( 'Media', 'action-steward' ),
			'media_enhance'      => __( 'Media', 'action-steward' ),
			'media_import'       => __( 'Media', 'action-steward' ),
			'seo'                => __( 'SEO', 'action-steward' ),
			'option'             => __( 'Settings', 'action-steward' ),
			'settings'           => __( 'Settings', 'action-steward' ),
			'user'               => __( 'Users', 'action-steward' ),
			'comments'           => __( 'Comments', 'action-steward' ),
			'term'               => __( 'Categories & tags', 'action-steward' ),
			'menu'               => __( 'Menus', 'action-steward' ),
			'widgets'            => __( 'Widgets', 'action-steward' ),
			'cpt'                => __( 'Post types', 'action-steward' ),
			'forms'              => __( 'Forms', 'action-steward' ),
			'acf'                => __( 'Custom fields', 'action-steward' ),
			'acf_seed'           => __( 'Custom fields', 'action-steward' ),
			'woocommerce'        => __( 'Shop', 'action-steward' ),
			'woo_product_seed'   => __( 'Shop', 'action-steward' ),
			'cf7_seed'           => __( 'Forms', 'action-steward' ),
			'plugin'             => __( 'Plugins', 'action-steward' ),
			'theme'              => __( 'Themes', 'action-steward' ),
			'safe_updates'       => __( 'Updates', 'action-steward' ),
			'site_builder'       => __( 'Site design', 'action-steward' ),
			'elementor'          => __( 'Site design', 'action-steward' ),
			'cache'              => __( 'Cache', 'action-steward' ),
			'patch'              => __( 'Site files', 'action-steward' ),
			'file'               => __( 'Site files', 'action-steward' ),
			'code_search'        => __( 'Site files', 'action-steward' ),
			'safe_search_replace' => __( 'Database', 'action-steward' ),
			'database_inspect'   => __( 'Database', 'action-steward' ),
			'wp_cli_bridge'      => __( 'WP-CLI', 'action-steward' ),
			'snapshot'           => __( 'Snapshots', 'action-steward' ),
			'rollback'           => __( 'Undo', 'action-steward' ),
			'workflow'           => __( 'Workflows', 'action-steward' ),
			'bulk'               => __( 'Bulk changes', 'action-steward' ),
			'capability'         => __( 'Permissions', 'action-steward' ),
			'approval'           => __( 'Approvals', 'action-steward' ),
			'report'             => __( 'Reports', 'action-steward' ),
			'search'             => __( 'Search', 'action-steward' ),
			'system_info'        => __( 'Site info', 'action-steward' ),
			'change_history'     => __( 'History', 'action-steward' ),
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
	/**
	 * The WordPress object a payload is about, by name, or '' when it is not about
	 * one. Reads only ids that unambiguously identify a post-table object —
	 * `content_id` and `media_id` are the Built-in AI parameter names, the rest are
	 * the engine's. Returns '' for a missing or untitled object so the caller falls
	 * through to its existing behaviour rather than printing an empty quote.
	 *
	 * @param array<string,mixed> $payload
	 */
	private static function object_name( array $payload ): string {
		foreach ( [ 'content_id', 'post_id', 'media_id', 'attachment_id', 'product_id' ] as $key ) {
			if ( ! isset( $payload[ $key ] ) || ! is_scalar( $payload[ $key ] ) ) {
				continue;
			}
			$id = (int) $payload[ $key ];
			if ( $id <= 0 ) {
				continue;
			}
			$title = get_the_title( $id );
			$title = is_string( $title ) ? trim( $title ) : '';
			if ( '' !== $title ) {
				return $title;
			}
		}
		return '';
	}

	public static function target( array $payload ): string {
		$bits = [];

		/*
		 * The NAME OF THE THING BEING CHANGED, resolved from its id, first.
		 *
		 * Two failures this fixes, both found scanning a real approval queue:
		 *
		 *  - "Update SEO details" named nothing at all. SEO payloads carry
		 *    `content_id` and nest their values under `seo`, so neither loop below
		 *    matched: no id key knew about `content_id`, and the name scan only
		 *    looks at the top level. Every SEO approval in the queue therefore read
		 *    identically, and a customer with six of them could not tell which page
		 *    each one touched without opening it.
		 *
		 *  - Content approvals named the WRONG thing. Their payload has a top-level
		 *    `title` — which is the SUGGESTED NEW title, not the page's name — so a
		 *    suggestion of "Shopping Basket" for the Cart page rendered as
		 *    'Edit a post or page — "Shopping Basket"', describing a page that does
		 *    not exist. Coincidentally right when the suggestion echoed the title;
		 *    misleading the moment it did not.
		 *
		 * Resolving the object's real title from its id answers "which page is this
		 * about?" correctly in both cases, and is skipped entirely for payloads that
		 * carry no such id (settings, plugins, files…), which keep the behaviour
		 * they had.
		 */
		$object_name = self::object_name( $payload );
		if ( '' !== $object_name ) {
			$bits[] = '“' . self::shorten( $object_name ) . '”';
		}

		// A human-meaningful name, if the payload carries one. `option_id` is the
		// canonical parameter for settings — omitting it meant a correctly-formed
		// request rendered with LESS detail than a malformed one.
		if ( empty( $bits ) ) {
			foreach ( [ 'title', 'post_title', 'name', 'label', 'slug', 'option_id', 'option', 'option_name', 'field_name', 'plugin', 'theme', 'path', 'query', 'search', 'taxonomy' ] as $key ) {
				if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) && '' !== (string) $payload[ $key ] ) {
					$value  = self::friendly_option( (string) $payload[ $key ] );
					$bits[] = '“' . self::shorten( $value ) . '”';
					break;
				}
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
				$bits[] = sprintf( _n( '%d item', '%d items', $n, 'action-steward' ), $n );
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
		return sprintf( _x( '%1$s — %2$s', 'change summary', 'action-steward' ), $title, $target );
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
	 * PUBLIC because the approval detail needs it directly, not only through
	 * describe(). That screen's "What will change" table renders the payload
	 * field-by-field, so an undo showed its one field as `Change id:
	 * 98ba74ef-83d0-…` — a raw internal identifier presented as the answer to
	 * "what will change" on the screen where consent is given. The heading above
	 * it already said "Undo a change — Update SEO details", so the product could
	 * clearly resolve the target; the table just had no way to ask for it.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function undo_target( array $payload ): string {
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
			return sprintf( _x( '%1$s — %2$s', 'change summary', 'action-steward' ), $title, $label );
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
		return self::option_labels()[ $value ] ?? $value;
	}

	/**
	 * The setting-key → human-label map, exposed so a single list serves every
	 * surface that has to name a setting.
	 *
	 * The approval detail screen carried its OWN copy of this map in JavaScript,
	 * and the two had drifted: the PHP list learned the registry ids the settings
	 * operation actually emits (`site_title`, `tagline`, `timezone`) while the JS
	 * copy still knew only the raw WordPress option names (`blogname`,
	 * `blogdescription`). The result was that the most common approval in the
	 * product — a settings change — rendered its heading as "Site title" from this
	 * map and then, four lines below, labelled the same field `site_title` from
	 * the other one. Two lists describing one thing will always drift; there is
	 * one list now.
	 *
	 * @return array<string,string>
	 */
	public static function option_labels(): array {
		return [
			// Registry IDs (option_manage speaks these) …
			'site_title'       => __( 'Site title', 'action-steward' ),
			'tagline'          => __( 'Tagline', 'action-steward' ),
			'timezone'         => __( 'Timezone', 'action-steward' ),
			// … and raw WordPress option names, for operations that pass those.
			'blogname'         => __( 'Site title', 'action-steward' ),
			'blogdescription'  => __( 'Tagline', 'action-steward' ),
			'admin_email'      => __( 'Admin email', 'action-steward' ),
			'siteurl'          => __( 'WordPress address', 'action-steward' ),
			'home'             => __( 'Site address', 'action-steward' ),
			'posts_per_page'   => __( 'Posts per page', 'action-steward' ),
			'date_format'      => __( 'Date format', 'action-steward' ),
			'time_format'      => __( 'Time format', 'action-steward' ),
			'start_of_week'    => __( 'Week starts on', 'action-steward' ),
			'timezone_string'  => __( 'Timezone', 'action-steward' ),
			'permalink_structure' => __( 'Permalink structure', 'action-steward' ),
			'blog_public'      => __( 'Search engine visibility', 'action-steward' ),
			'default_category' => __( 'Default category', 'action-steward' ),
			'show_on_front'    => __( 'Homepage display', 'action-steward' ),
			'page_on_front'    => __( 'Homepage', 'action-steward' ),
			'page_for_posts'   => __( 'Posts page', 'action-steward' ),
			// The remaining keys settings_general_get/update speaks, which the
			// approval card previously printed raw.
			'language'         => __( 'Site language', 'action-steward' ),
			'week_start'       => __( 'Week starts on', 'action-steward' ),
		];
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
