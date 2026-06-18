<?php
namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	private const CAPABILITY = 'manage_options';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar_badge' ], 100 );
		// STEP 105.3 — the legacy Rollback page is merged into Change History.
		add_action( 'admin_init', [ $this, 'redirect_legacy_rollback' ] );
		// STEP 106.4 — the "Pending Approvals" page becomes the Approval Center.
		add_action( 'admin_init', [ $this, 'redirect_legacy_approvals' ] );
		// STEP 107.4 — token management moved off the Settings page into the
		// Tokens & Capabilities manager; keep old token deep-links working.
		add_action( 'admin_init', [ $this, 'redirect_legacy_tokens' ] );
	}

	/**
	 * STEP 107.4 — token create/revoke/delete moved out of the Settings page into
	 * the dedicated "Tokens & Capabilities" manager (slug `wpcc-tokens`). Old
	 * deep-links into the Settings token section (e.g. `?page=wpcc-settings&
	 * section=api-tokens` or `&tab=tokens`) now land on the new manager. The
	 * Settings page itself remains for Security Mode + the connection reference.
	 */
	public function redirect_legacy_tokens(): void {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page nav, no state change.
		if ( ! isset( $_GET['page'] ) || 'wpcc-settings' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' );

		if ( in_array( $section, [ 'tokens', 'api-tokens', 'api_tokens' ], true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpcc-tokens' ) );
			exit;
		}
	}

	/**
	 * STEP 105.3 — keep old bookmarks/links working after the Rollback submenu
	 * was merged into Change History. Restore now lives on the Change History
	 * Timeline / Detail views (routed through OperationExecutor).
	 */
	public function redirect_legacy_rollback(): void {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page nav, no state change.
		if ( isset( $_GET['page'] ) && 'wpcc-rollback' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpcc-change-history' ) );
			exit;
		}
	}

	/**
	 * STEP 106.4 — keep old "Pending Approvals" bookmarks/links (and the
	 * pending_approval URLs the engine emits) working after the page was renamed
	 * to the Approval Center under the `wpcc-approval-center` slug. The tab/view
	 * deep-link args are preserved so a bookmarked detail/queue link still lands
	 * in the right place.
	 */
	public function redirect_legacy_approvals(): void {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page nav, no state change.
		if ( ! isset( $_GET['page'] ) || 'wpcc-approvals' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$args = [ 'page' => 'wpcc-approval-center' ];
		foreach ( [ 'tab', 'view', 'session_id', 'status' ] as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page nav.
			if ( isset( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WP Command Center', 'wp-command-center' ),
			__( 'Command Center', 'wp-command-center' ),
			self::CAPABILITY,
			'wp-command-center',
			[ $this, 'render_dashboard' ],
			'dashicons-admin-generic',
			65
		);

		add_submenu_page( 'wp-command-center', __( 'Dashboard', 'wp-command-center' ), __( 'Dashboard', 'wp-command-center' ), self::CAPABILITY, 'wp-command-center', [ $this, 'render_dashboard' ] );
		// STEP 105.4 — single feature seam (ungated today; future Free/Pro switch).
		if ( FeatureGate::allows( 'change_history' ) ) {
			add_submenu_page( 'wp-command-center', __( 'Change History', 'wp-command-center' ), __( 'Change History', 'wp-command-center' ), self::CAPABILITY, 'wpcc-change-history', [ $this, 'render_change_history' ] );
		}
		add_submenu_page( 'wp-command-center', __( 'Site Intelligence', 'wp-command-center' ), __( 'Site Intelligence', 'wp-command-center' ), self::CAPABILITY, 'wpcc-site-intelligence', [ $this, 'render_site_intelligence' ] );
		add_submenu_page( 'wp-command-center', __( 'Diagnostics', 'wp-command-center' ), __( 'Diagnostics', 'wp-command-center' ), self::CAPABILITY, 'wpcc-diagnostics', [ $this, 'render_diagnostics' ] );
		add_submenu_page( 'wp-command-center', __( 'File Access', 'wp-command-center' ), __( 'File Access', 'wp-command-center' ), self::CAPABILITY, 'wpcc-file-access', [ $this, 'render_file_access' ] );
		add_submenu_page( 'wp-command-center', __( 'Patches', 'wp-command-center' ), __( 'Patches', 'wp-command-center' ), self::CAPABILITY, 'wpcc-patches', [ $this, 'render_patches' ] );
		// STEP 107.1 — Token & Capability Manager (read surface). Gated by the same
		// FeatureGate seam as Change History / Approval Center (ungated today;
		// future Free/Pro switch). Token + capability management migrates here off
		// the Settings page in STEP 107.4.
		if ( FeatureGate::allows( 'token_capability_manager' ) ) {
			add_submenu_page( 'wp-command-center', __( 'Tokens & Capabilities', 'wp-command-center' ), __( 'Tokens & Capabilities', 'wp-command-center' ), self::CAPABILITY, 'wpcc-tokens', [ $this, 'render_token_capability_manager' ] );
		}
		add_submenu_page( 'wp-command-center', __( 'Settings', 'wp-command-center' ), __( 'Settings', 'wp-command-center' ), self::CAPABILITY, 'wpcc-settings', [ $this, 'render_settings' ] );
		add_submenu_page( 'wp-command-center', __( 'AI Integrations', 'wp-command-center' ), __( 'AI Integrations', 'wp-command-center' ), self::CAPABILITY, 'wpcc-ai-integrations', [ $this, 'render_ai_integrations' ] );
		// STEP 106.4 — "Pending Approvals" becomes the Approval Center (Pending /
		// History / Queue). Gated by the same FeatureGate seam as Change History
		// (ungated today; future Free/Pro switch). Old slug redirects in via
		// redirect_legacy_approvals().
		if ( FeatureGate::allows( 'approval_center' ) ) {
			add_submenu_page( 'wp-command-center', __( 'Approval Center', 'wp-command-center' ), __( 'Approval Center', 'wp-command-center' ), self::CAPABILITY, 'wpcc-approval-center', [ $this, 'render_approval_center' ] );
		}
	}

	public function admin_bar_badge( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( ! \WPCommandCenter\Operations\SecurityModeManager::requires_human_approver() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			\WPCommandCenter\Operations\OperationManager::STATUS_PENDING_REVIEW
		) );

		if ( $count <= 0 ) {
			return;
		}

		$wp_admin_bar->add_node( [
			'id'    => 'wpcc-pending-approvals',
			'title' => sprintf(
				'%s <span style="background:#d63638;color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;margin-left:4px;">%d</span>',
				esc_html__( 'AI Requests', 'wp-command-center' ),
				$count
			),
			'href'  => admin_url( 'admin.php?page=wpcc-approval-center' ),
		] );
	}

	public function render_dashboard(): void {
		$this->render_view( 'dashboard' );
	}

	public function render_change_history(): void {
		$this->render_view( 'change-history' );
	}

	public function render_site_intelligence(): void {
		$this->render_view( 'site-intelligence' );
	}

	public function render_diagnostics(): void {
		$this->render_view( 'diagnostics' );
	}

	public function render_file_access(): void {
		$this->render_view( 'file-access' );
	}

	public function render_patches(): void {
		$this->render_view( 'patches' );
	}

	public function render_settings(): void {
		$this->render_view( 'settings' );
	}

	public function render_token_capability_manager(): void {
		// STEP 107.1 — Token & Capability Manager (Tokens / Capabilities /
		// Operation Map), read-only visibility surface over the token + capability
		// system.
		$this->render_view( 'token-capability-manager' );
	}

	public function render_ai_integrations(): void {
		$this->render_view( 'ai-integrations' );
	}

	public function render_approval_center(): void {
		// STEP 106 — Approval Center (Pending / History / Queue), the surface that
		// replaced the thin "Pending Approvals" page.
		$this->render_view( 'approval-center' );
	}

	private function render_view( string $view ): void {
		$path = WPCC_PLUGIN_DIR . "includes/Admin/views/{$view}.php";

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}
