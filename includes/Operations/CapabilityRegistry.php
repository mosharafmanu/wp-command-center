<?php
/**
 * Step 44 — Capability Registry.
 * Defines all platform capabilities and operation-to-capability mappings.
 */

namespace WPCommandCenter\Operations;

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
		// Seed operations are unrestricted (read-only/low-risk):
		// 'content_seed', 'acf_seed', 'cf7_seed', 'woo_product_seed'
		// They do not require explicit capability assignment.
	];

	/**
	 * Operations a `read_only` token is permitted to call (read-only in effect,
	 * regardless of CapabilityRegistry::OPERATION_MAP mapping). Every other
	 * operation requires a `full`-scope token, mirroring RestApi::require_write().
	 */
	const READ_ONLY_SCOPE_OPERATIONS = [ 'database_inspect', 'search_manage' ];

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
	];

	public function action_risk( string $action ): string {
		return in_array( $action, [ self::ACTION_ASSIGN, self::ACTION_REMOVE ], true ) ? self::RISK_HIGH : self::RISK_LOW;
	}

	public function get_required_capability( string $operation_id ): ?string {
		return self::OPERATION_MAP[ $operation_id ] ?? null;
	}

	/**
	 * Whether an operation requires a `full`-scope token. True for every
	 * operation except those in READ_ONLY_SCOPE_OPERATIONS — including
	 * operations absent from OPERATION_MAP (e.g. the seed operations),
	 * which would otherwise be fail-open for a `read_only` token.
	 */
	public function requires_full_scope( string $operation_id ): bool {
		return ! in_array( $operation_id, self::READ_ONLY_SCOPE_OPERATIONS, true );
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
			return new \WP_Error( 'wpcc_invalid_capability', __( 'Unknown capability.', 'wp-command-center' ) );
		}
		if ( self::CAP_SYSTEM_ADMIN === $capability ) {
			return new \WP_Error( 'wpcc_cannot_assign_admin', __( 'system.admin can only be assigned via direct configuration.', 'wp-command-center' ) );
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
			return new \WP_Error( 'wpcc_invalid_capability', __( 'Unknown capability.', 'wp-command-center' ) );
		}
		$all = $this->get_assignments();
		$key = $subject . ':' . $subject_id;
		if ( ! isset( $all[ $key ] ) ) {
			return new \WP_Error( 'wpcc_capability_not_assigned', __( 'No capabilities assigned to this subject.', 'wp-command-center' ) );
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

	public function validate( string $operation_id, string $subject = 'token', string $subject_id = '' ): array {
		$required = $this->get_required_capability( $operation_id );
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
