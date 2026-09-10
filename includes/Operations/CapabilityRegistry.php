<?php
/**
 * Step 44 — Capability Registry.
 * Defines all platform capabilities and operation-to-capability mappings.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\AuthTokens;

defined( 'ABSPATH' ) || exit;

final class CapabilityRegistry {

	const RISK_LOW  = 'low';
	const RISK_HIGH = 'high';

	const ACTION_LIST     = 'capability_list';
	const ACTION_GET      = 'capability_get';
	const ACTION_ASSIGN   = 'capability_assign';
	const ACTION_REMOVE   = 'capability_remove';
	const ACTION_VALIDATE = 'capability_validate';

	const ACTIONS = [ 'capability_list', 'capability_get', 'capability_assign', 'capability_remove', 'capability_validate' ];

	const CAP_CONTENT_MANAGE  = 'content.manage';
	const CAP_DATABASE_INSPECT = 'database.inspect';
	const CAP_PLUGIN_MANAGE   = 'plugin.manage';
	const CAP_THEME_MANAGE    = 'theme.manage';
	const CAP_OPTION_MANAGE   = 'option.manage';
	const CAP_SNAPSHOT_MANAGE = 'snapshot.manage';
	const CAP_WPCLI_EXECUTE   = 'wpcli.execute';
	const CAP_SYSTEM_ADMIN    = 'system.admin';
	const CAP_CAPABILITY_ADMIN = 'capability.admin';
	const CAP_USER_MANAGE      = 'user.manage';
	const CAP_MEDIA_MANAGE     = 'media.manage';
	const CAP_WOO_MANAGE       = 'woocommerce.manage';
	const CAP_ACF_MANAGE       = 'acf.manage';
	const CAP_FORMS_MANAGE     = 'forms.manage';
	const CAP_MENU_MANAGE      = 'menu.manage';
	const CAP_SETTINGS_MANAGE  = 'settings.manage';
	const CAP_SEARCH_MANAGE    = 'search.manage';
	const CAP_BULK_MANAGE      = 'bulk.manage';
	const CAP_WORKFLOW_MANAGE  = 'workflow.manage';
	const CAP_COMMENTS_MANAGE  = 'comments.manage';
	const CAP_WIDGETS_MANAGE   = 'widgets.manage';
	const CAP_CPT_MANAGE       = 'cpt.manage';
	// STEP 104.2 — read-only Change History runtime capability.
	const CAP_HISTORY_READ     = 'history.read';
	const CAP_SITE_READ        = 'site.read';

	const OPERATION_MAP = [
		// Content
		'content_manage'     => self::CAP_CONTENT_MANAGE,
		// Database inspection
		'database_inspect'   => self::CAP_DATABASE_INSPECT,
		// Plugin
		'plugin_manage'      => self::CAP_PLUGIN_MANAGE,
		// Theme
		'theme_manage'       => self::CAP_THEME_MANAGE,
		// Option
		'option_manage'      => self::CAP_OPTION_MANAGE,
		// Snapshot
		'snapshot_manage'    => self::CAP_SNAPSHOT_MANAGE,
		// WP-CLI
		'wp_cli_bridge'      => self::CAP_WPCLI_EXECUTE,
		// High-risk operations that were previously unmapped
		'safe_search_replace' => self::CAP_WPCLI_EXECUTE,
		'safe_updates'        => self::CAP_PLUGIN_MANAGE,
		'media_import'        => self::CAP_CONTENT_MANAGE,
		// Capability management (requires capability.admin to prevent escalation)
		'capability_manage'   => self::CAP_CAPABILITY_ADMIN,
		// User management
		'user_manage'         => self::CAP_USER_MANAGE,
		// Media management
		'media_manage'        => self::CAP_MEDIA_MANAGE,
		// WooCommerce
		'woocommerce_manage'  => self::CAP_WOO_MANAGE,
		// ACF
		'acf_manage'          => self::CAP_ACF_MANAGE,
		// Forms
		'forms_manage'        => self::CAP_FORMS_MANAGE,
		// Menus
		'menu_manage'         => self::CAP_MENU_MANAGE,
		'settings_manage'     => self::CAP_SETTINGS_MANAGE,
		'search_manage'       => self::CAP_SEARCH_MANAGE,
		'bulk_manage'         => self::CAP_BULK_MANAGE,
		'workflow_manage'     => self::CAP_WORKFLOW_MANAGE,
		'comments_manage'     => self::CAP_COMMENTS_MANAGE,
		'widgets_manage'      => self::CAP_WIDGETS_MANAGE,
		'cpt_manage'          => self::CAP_CPT_MANAGE,
		// MCP Approval Runtime — control plane over the request/approval/
		// queue pipeline itself; gated to system.admin (see Step 78 report).
		'approval_manage'     => self::CAP_SYSTEM_ADMIN,
		// STEP 87 — File / Patch bridge. Read ops reuse search.manage; the
		// patch/rollback write ops reuse snapshot.manage (the file-safety subsystem).
		'file_manage'         => self::CAP_SEARCH_MANAGE,
		'code_search'         => self::CAP_SEARCH_MANAGE,
		'patch_manage'        => self::CAP_SNAPSHOT_MANAGE,
		'rollback_manage'     => self::CAP_SNAPSHOT_MANAGE,
		// STEP 91 — SEO runtime edits post meta; gated like content.
		'seo_manage'          => self::CAP_CONTENT_MANAGE,
		// STEP 95 — Site builder creates pages/patterns/navigation; gated like content.
		'site_builder_manage' => self::CAP_CONTENT_MANAGE,
		// STEP 96 — Elementor edits page widget content; gated like content.
		'elementor_manage'    => self::CAP_CONTENT_MANAGE,
		// STEP 100.3 — Media Enhancement runtime; gated like media.
		'media_enhance'       => self::CAP_MEDIA_MANAGE,
		// STEP 104.2 — read-only Change History runtime.
		'change_history'      => self::CAP_HISTORY_READ,
		// Seed operations are unmapped here but ALWAYS require full scope:
		// 'content_seed', 'acf_seed', 'cf7_seed', 'woo_product_seed'
		// They do not require explicit capability assignment.
	];

	/**
	 * Legacy operation-only classification retained for idempotency callers.
	 * Authorization uses READ_ONLY_ACTIONS and must supply the action payload.
	 */
	const READ_ONLY_SCOPE_OPERATIONS = [ 'database_inspect', 'search_manage', 'file_manage', 'code_search', 'change_history', 'term_manage' ];

	/**
	 * Audited read effects, independent of approval risk. Unknown operations/actions
	 * fail closed. In particular approval_manage's diagnostic risk does NOT make
	 * request_create/approve/queue_run reads. See WPCC-READONLY-42-AUDIT.md.
	 * The empty action is permitted only on the actionless system_info tool.
	 */
	const READ_ONLY_ACTIONS = [
		'system_info' => [ '' ],
		'content_seed' => [  ],
		'acf_seed' => [  ],
		'cf7_seed' => [  ],
		'woo_product_seed' => [  ],
		'safe_search_replace' => [  ],
		'media_import' => [  ],
		'safe_updates' => [  ],
		'capability_manage' => [ 'capability_list', 'capability_get', 'capability_validate' ],
		'database_inspect' => [ 'db_table_list', 'db_table_stats', 'db_table_size', 'db_row_counts', 'db_autoload_analysis', 'db_options_health', 'db_index_analysis', 'db_orphan_detection', 'db_health_summary' ],
		'content_manage' => [ 'content_list', 'content_get' ],
		'snapshot_manage' => [ 'snapshot_list', 'snapshot_details', 'snapshot_verify' ],
		'theme_manage' => [ 'theme_list' ],
		'plugin_manage' => [ 'plugin_list' ],
		'option_manage' => [ 'option_get' ],
		'wp_cli_bridge' => [  ],
		'user_manage' => [ 'user_list', 'user_get', 'user_search' ],
		'media_manage' => [ 'media_list', 'media_get', 'media_search', 'media_replace_verify', 'media_snapshot_verify', 'media_snapshot_list' ],
		'woocommerce_manage' => [ 'woo_describe', 'product_list', 'product_get', 'stock_get', 'price_get', 'product_category_list', 'product_attribute_list', 'variation_list', 'variation_get', 'order_list', 'order_get', 'order_search', 'customer_get', 'customer_search', 'coupon_list', 'product_search', 'coupon_get' ],
		'acf_manage' => [ 'acf_group_list', 'acf_group_get', 'acf_field_list', 'acf_field_get', 'acf_location_list', 'acf_describe', 'acf_value_get', 'acf_inventory', 'acf_json_export', 'acf_json_diff', 'acf_layout_usage', 'acf_json_status' ],
		'term_manage' => [ 'term_list', 'term_get', 'term_search', 'term_describe' ],
		'cache_manage' => [ 'cache_status', 'cache_describe' ],
		'forms_manage' => [ 'form_list', 'form_get', 'form_search', 'entry_list', 'entry_get', 'entry_search', 'notification_get', 'submission_stats', 'form_analyze', 'entry_export' ],
		'menu_manage' => [ 'menu_list', 'menu_get', 'menu_item_list', 'menu_item_get', 'menu_location_list', 'menu_analyze', 'menu_inventory', 'menu_tree_validate', 'menu_tree_get', 'menu_export' ],
		'settings_manage' => [ 'settings_general_get', 'settings_reading_get', 'settings_discussion_get', 'settings_media_get', 'settings_permalink_get', 'settings_privacy_get', 'settings_inventory', 'settings_analyze' ],
		'approval_manage' => [ 'request_list', 'request_get', 'queue_list', 'queue_get', 'results_list', 'results_get' ],
		'search_manage' => [ 'search_all', 'search_content', 'search_media', 'search_users', 'search_woocommerce', 'search_forms', 'search_acf', 'search_menus', 'report_orphans', 'report_unused_media', 'report_content_inventory', 'report_woo_inventory', 'report_site_summary' ],
		'bulk_manage' => [  ],
		'workflow_manage' => [ 'workflow_list', 'workflow_get', 'workflow_history', 'workflow_export' ],
		'comments_manage' => [ 'comment_list', 'comment_get' ],
		'widgets_manage' => [ 'widget_list', 'widget_get' ],
		'cpt_manage' => [ 'cpt_list', 'cpt_get', 'taxonomy_list' ],
		'file_manage' => [ 'file_read', 'file_tree', 'file_metadata' ],
		'code_search' => [ 'search_text', 'search_symbol', 'search_file' ],
		'patch_manage' => [ 'patch_preview', 'patch_status', 'patch_verify' ],
		'rollback_manage' => [ 'rollback_list', 'rollback_get', 'rollback_verify' ],
		'seo_manage' => [ 'seo_get', 'seo_validate', 'seo_analyze' ],
		'site_builder_manage' => [ 'page_list', 'page_get', 'template_list', 'pattern_list' ],
		'elementor_manage' => [ 'elementor_get_page', 'elementor_export_structure', 'elementor_list_widgets' ],
		'report_manage' => [ 'report_list', 'report_site_health', 'report_plugin_health', 'report_security', 'report_content', 'report_woocommerce', 'report_agent_activity', 'report_approval_activity', 'report_patch_activity' ],
		'media_enhance' => [ 'media_enhance_capabilities', 'image_sizes_list', 'image_size_usage_audit', 'image_size_recommendations', 'image_size_verify', 'srcset_verify', 'responsive_image_audit', 'missing_sizes_audit', 'image_size_context_audit', 'thumbnail_verify', 'webp_audit', 'webp_verify', 'image_optimize_audit', 'image_optimize_verify', 'media_usage_scan', 'media_usage_report', 'unused_media_find', 'orphaned_media_find' ],
		'change_history' => [ 'history_list', 'history_get', 'history_timeline', 'rollback_discover', 'operation_status' ],
	];

	/**
	 * Step 79 — Capability profiles. Single source of truth for the
	 * capabilities a token receives automatically based on its scope.
	 */
	const PROFILE_READ_ONLY    = 'read_only';
	const PROFILE_FULL_ACCESS  = 'full_access';
	const PROFILE_SYSTEM_ADMIN = 'system_admin';

	const PROFILES = [
		// Covers the read-only-scope operations: database.inspect (database_inspect),
		// search.manage (search_manage + file_manage + code_search), and STEP 104.2
		// history.read (change_history).
		self::PROFILE_READ_ONLY    => [ self::CAP_DATABASE_INSPECT, self::CAP_SEARCH_MANAGE, self::CAP_HISTORY_READ, self::CAP_SITE_READ ],
		// system.admin's $has_admin shortcut == unrestricted; required so
		// approval_manage (the escape hatch when wpcc_enforce_approval=1)
		// is always reachable by a full-scope token.
		self::PROFILE_FULL_ACCESS  => [ self::CAP_SYSTEM_ADMIN ],
		self::PROFILE_SYSTEM_ADMIN => [ self::CAP_SYSTEM_ADMIN ],
	];

	const ALL_CAPABILITIES = [
		self::CAP_CONTENT_MANAGE,
		self::CAP_DATABASE_INSPECT,
		self::CAP_PLUGIN_MANAGE,
		self::CAP_THEME_MANAGE,
		self::CAP_OPTION_MANAGE,
		self::CAP_SNAPSHOT_MANAGE,
		self::CAP_WPCLI_EXECUTE,
		self::CAP_SYSTEM_ADMIN,
		self::CAP_CAPABILITY_ADMIN,
		self::CAP_USER_MANAGE,
		self::CAP_MEDIA_MANAGE,
		self::CAP_WOO_MANAGE,
		self::CAP_ACF_MANAGE,
		self::CAP_FORMS_MANAGE,
		self::CAP_MENU_MANAGE,
		self::CAP_SETTINGS_MANAGE,
		self::CAP_SEARCH_MANAGE,
		self::CAP_BULK_MANAGE,
		self::CAP_WORKFLOW_MANAGE,
		self::CAP_COMMENTS_MANAGE,
		self::CAP_WIDGETS_MANAGE,
		self::CAP_CPT_MANAGE,
		self::CAP_HISTORY_READ,
		self::CAP_SITE_READ,
	];

	public function action_risk( string $action ): string {
		return in_array( $action, [ self::ACTION_ASSIGN, self::ACTION_REMOVE ], true ) ? self::RISK_HIGH : self::RISK_LOW;
	}

	public function get_required_capability( string $operation_id ): ?string {
		return self::OPERATION_MAP[ $operation_id ] ?? null;
	}

	/**
	 * Whether an invocation requires full scope. Explicit audited actions alone
	 * are reads; unmapped/unknown operations and actions fail closed.
	 */
	public function requires_full_scope( string $operation_id, ?array $payload = null ): bool {
		// Preserve the legacy operation-only API for discovery/idempotency callers.
		// Authorization callers MUST supply the payload, including an empty array.
		if ( null === $payload ) {
			return ! in_array( $operation_id, self::READ_ONLY_SCOPE_OPERATIONS, true );
		}
		$action = $payload['action'] ?? '';
		return ! is_string( $action ) || ! in_array( $action, self::READ_ONLY_ACTIONS[ $operation_id ] ?? [], true );
	}

	public function get_storage_key(): string {
		return 'wpcc_capability_assignments';
	}

	public function get_assignments(): array {
		return get_option( $this->get_storage_key(), [] );
	}

	public function save_assignments( array $assignments ): void {
		update_option( $this->get_storage_key(), $assignments, false );
	}

	public function assign( string $subject, string $subject_id, string $capability ): ?\WP_Error {
		if ( ! in_array( $capability, self::ALL_CAPABILITIES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_capability', __( 'Unknown capability.', 'action-steward' ) );
		}
		if ( self::CAP_SYSTEM_ADMIN === $capability ) {
			return new \WP_Error( 'wpcc_cannot_assign_admin', __( 'system.admin can only be assigned via direct configuration.', 'action-steward' ) );
		}
		$all = $this->get_assignments();
		$key = $subject . ':' . $subject_id;
		if ( ! isset( $all[ $key ] ) ) {
			$all[ $key ] = [];
		}
		if ( ! in_array( $capability, $all[ $key ], true ) ) {
			$all[ $key ][] = $capability;
		}
		$this->save_assignments( $all );
		return null;
	}

	public function remove( string $subject, string $subject_id, string $capability ): ?\WP_Error {
		if ( ! in_array( $capability, self::ALL_CAPABILITIES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_capability', __( 'Unknown capability.', 'action-steward' ) );
		}
		$all = $this->get_assignments();
		$key = $subject . ':' . $subject_id;
		if ( ! isset( $all[ $key ] ) ) {
			return new \WP_Error( 'wpcc_capability_not_assigned', __( 'No capabilities assigned to this subject.', 'action-steward' ) );
		}
		$all[ $key ] = array_values( array_filter( $all[ $key ], static fn( $c ) => $c !== $capability ) );
		if ( empty( $all[ $key ] ) ) {
			unset( $all[ $key ] );
		}
		$this->save_assignments( $all );
		return null;
	}

	public function get_for_subject( string $subject, string $subject_id ): array {
		$all = $this->get_assignments();
		return $all[ $subject . ':' . $subject_id ] ?? [];
	}

	/**
	 * The capability profile a freshly created token of the given scope
	 * should receive. Fails safe to read_only for anything other than
	 * AuthTokens::SCOPE_FULL.
	 */
	public static function profile_for_scope( string $scope ): string {
		return AuthTokens::SCOPE_FULL === $scope ? self::PROFILE_FULL_ACCESS : self::PROFILE_READ_ONLY;
	}

	public function capabilities_for_profile( string $profile ): array {
		return self::PROFILES[ $profile ] ?? [];
	}

	/**
	 * Step 79 — Provision a token's capability assignment from its scope's
	 * profile. Overwrites any existing assignment for this token; only call
	 * on token creation or when the existing assignment is empty
	 * (see ensure_token_capabilities()).
	 */
	public function bootstrap_token( string $token_id, string $scope, string $reason = 'token_created' ): array {
		$profile = self::profile_for_scope( $scope );
		$caps    = $this->capabilities_for_profile( $profile );

		$all = $this->get_assignments();
		$all[ 'token:' . $token_id ] = $caps;
		$this->save_assignments( $all );

		( new AuditLog() )->record( 'capability.bootstrap', [
			'token_id'     => $token_id,
			'scope'        => $scope,
			'profile'      => $profile,
			'capabilities' => $caps,
			'reason'       => $reason,
		] );

		return $caps;
	}

	/**
	 * Step 79 — Remove a token's capability assignment, e.g. on revoke/delete.
	 */
	public function deprovision_token( string $token_id ): void {
		$all = $this->get_assignments();
		$key = 'token:' . $token_id;

		if ( ! isset( $all[ $key ] ) ) {
			return;
		}

		unset( $all[ $key ] );
		$this->save_assignments( $all );

		( new AuditLog() )->record( 'capability.deprovisioned', [ 'token_id' => $token_id ] );
	}

	/**
	 * Step 79 — Self-healing: if a token has no capability assignment at
	 * all, bootstrap one from its scope's profile. Idempotent — a non-empty
	 * existing assignment (including a manually customized one) is left
	 * untouched.
	 */
	public function ensure_token_capabilities( string $token_id, string $scope ): array {
		if ( '' === $token_id ) {
			return [];
		}

		$existing = $this->get_for_subject( 'token', $token_id );

		$legacy = [ self::CAP_DATABASE_INSPECT, self::CAP_SEARCH_MANAGE, self::CAP_HISTORY_READ ];
		$sorted = $existing;
		sort( $sorted );
		sort( $legacy );
		if ( AuthTokens::SCOPE_READ_ONLY === $scope && $sorted === $legacy ) {
			return $this->bootstrap_token( $token_id, $scope, 'read_profile_upgrade' );
		}
		if ( ! empty( $existing ) ) {
			return $existing;
		}

		return $this->bootstrap_token( $token_id, $scope, 'self_healed' );
	}

	public function validate( string $operation_id, string $subject = 'token', string $subject_id = '', ?array $payload = null, string $scope = '' ): array {
		$required = $this->get_required_capability( $operation_id );
		// A read capability is never a management capability. Only the authenticated
		// Read-only scope and an explicitly audited read action use this mapping.
		// Full-access/custom management assignments retain their existing behavior.
		if ( AuthTokens::SCOPE_READ_ONLY === $scope && null !== $payload
			&& ! $this->requires_full_scope( $operation_id, $payload )
			&& ! in_array( $operation_id, self::READ_ONLY_SCOPE_OPERATIONS, true ) ) {
			$required = self::CAP_SITE_READ;
		}
		// Unmapped operations are allowed (read-only or seed operations).
		if ( null === $required ) {
			return [ 'allowed' => true, 'required_capability' => null, 'reason' => 'unrestricted' ];
		}
		if ( '' === $subject_id ) {
			return [ 'allowed' => false, 'required_capability' => $required, 'reason' => 'no_subject' ];
		}
		$assigned = $this->get_for_subject( $subject, $subject_id );
		$has_admin = in_array( self::CAP_SYSTEM_ADMIN, $assigned, true );
		if ( $has_admin || in_array( $required, $assigned, true ) ) {
			return [ 'allowed' => true, 'required_capability' => $required, 'assigned' => $assigned ];
		}
		return [ 'allowed' => false, 'required_capability' => $required, 'assigned' => $assigned, 'reason' => 'missing_capability' ];
	}

	public function get_summary(): array {
		$all = $this->get_assignments();
		$counts = [];
		foreach ( $all as $key => $caps ) {
			$parts = explode( ':', $key, 2 );
			$subj  = $parts[0] ?? 'unknown';
			if ( ! isset( $counts[ $subj ] ) ) {
				$counts[ $subj ] = 0;
			}
			$counts[ $subj ] += count( $caps );
		}
		return [
			'capabilities'      => self::ALL_CAPABILITIES,
			'operation_map'     => self::OPERATION_MAP,
			'assignment_count'  => count( $all ),
			'subject_counts'    => $counts,
		];
	}
}
