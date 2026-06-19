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
		// STEP 109.1 — Dashboard Overview: an additive, read-only at-a-glance landing
		// that aggregates the existing surfaces (Approval Center / Change History /
		// Tokens & Capabilities / Operations Explorer) plus the live security posture
		// and platform invariants. Gated by the same FeatureGate seam as the other
		// surfaces (ungated today; future Free/Pro switch). It NEVER executes an
		// operation — the legacy operational Dashboard above keeps all write controls.
		if ( FeatureGate::allows( 'dashboard_overview' ) ) {
			add_submenu_page( 'wp-command-center', __( 'Dashboard Overview', 'wp-command-center' ), __( 'Dashboard Overview', 'wp-command-center' ), self::CAPABILITY, 'wpcc-dashboard-overview', [ $this, 'render_dashboard_overview' ] );
		}
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
		// STEP 108.1 — Operations Explorer: a read-only browser over the operation
		// catalogue (risk, required capability, availability). Gated by the same
		// FeatureGate seam as the other surfaces (ungated today; future Free/Pro
		// switch). Discovery only — it never executes an operation.
		if ( FeatureGate::allows( 'operations_explorer' ) ) {
			add_submenu_page( 'wp-command-center', __( 'Operations Explorer', 'wp-command-center' ), __( 'Operations Explorer', 'wp-command-center' ), self::CAPABILITY, 'wpcc-operations', [ $this, 'render_operations_explorer' ] );
		}

		// STEP 110 (Task 6) — Governed Drafts (Proposal Store) DEV validation
		// surface. Unlike the other surfaces, this is NOT a user feature: it is a
		// developer instrument to validate the Proposal Store primitive. It is
		// gated by a dev switch that defaults OFF (FeatureGate alone defaults ON,
		// so it cannot be the only gate here), AND by the future Free/Pro
		// FeatureGate seam. Real users never see it; the eventual AI Alt Text UI is
		// the product surface. Registered last; folds under Operate in Phase C.
		if ( $this->proposals_dev_ui_enabled() && FeatureGate::allows( 'proposal_store' ) ) {
			add_submenu_page( 'wp-command-center', __( 'Governed Drafts (Dev)', 'wp-command-center' ), __( 'Governed Drafts (Dev)', 'wp-command-center' ), self::CAPABILITY, 'wpcc-proposals', [ $this, 'render_proposals' ] );
		}

		// STEP 110 (Task 8.1) — Builder-facing AI Alt Text surface (scaffold +
		// Review tab). This is the real product surface, but it is incrementally
		// built (8.1–8.5): gated by a build flag that defaults OFF so it is not
		// shown to real users until the workflow is complete, AND by the Free/Pro
		// FeatureGate seam ('ai_alt_text'). The build flag is removed/defaulted on
		// when Task 8 completes. Folds under Operate in the Phase C 5-C IA.
		if ( $this->alt_text_ui_enabled() && FeatureGate::allows( 'ai_alt_text' ) ) {
			add_submenu_page( 'wp-command-center', __( 'AI Alt Text', 'wp-command-center' ), __( 'AI Alt Text', 'wp-command-center' ), self::CAPABILITY, 'wpcc-alt-text', [ $this, 'render_ai_alt_text' ] );
		}

		// Governed Action #2 — SEO Meta Generator (Builder). Incrementally built
		// (Slice 1 = read-only audit); gated by a build flag that defaults OFF until
		// the workflow lands, AND by the Free/Pro FeatureGate seam ('seo_meta_generator').
		if ( $this->seo_meta_ui_enabled() && FeatureGate::allows( 'seo_meta_generator' ) ) {
			add_submenu_page( 'wp-command-center', __( 'SEO Meta', 'wp-command-center' ), __( 'SEO Meta', 'wp-command-center' ), self::CAPABILITY, 'wpcc-seo', [ $this, 'render_seo_meta' ] );
		}
	}

	/**
	 * Build switch for the Builder AI Alt Text surface. Defaults OFF (the feature
	 * is built incrementally across Task 8.1–8.5 and must not surface a partial
	 * workflow to real users). Enable on a dev site via the WPCC_ALT_TEXT_UI
	 * constant or the `wpcc_alt_text_ui` filter; remove the gate when Task 8 lands.
	 */
	private function alt_text_ui_enabled(): bool {
		if ( defined( 'WPCC_ALT_TEXT_UI' ) && WPCC_ALT_TEXT_UI ) {
			return true;
		}
		return (bool) apply_filters( 'wpcc_alt_text_ui', false );
	}

	/**
	 * Build switch for the Builder SEO Meta surface (GA#2). Defaults OFF — the
	 * feature is built incrementally (Slice 1 = read-only audit) and must not surface
	 * a partial workflow to real users. Enable on a dev site via the WPCC_SEO_META_UI
	 * constant or the `wpcc_seo_meta_ui` filter; remove the gate when GA#2 lands.
	 */
	private function seo_meta_ui_enabled(): bool {
		if ( defined( 'WPCC_SEO_META_UI' ) && WPCC_SEO_META_UI ) {
			return true;
		}
		return (bool) apply_filters( 'wpcc_seo_meta_ui', false );
	}

	/**
	 * Dev switch for the Governed Drafts validation surface. Defaults OFF so real
	 * users never see it; enable on a dev site via the WPCC_PROPOSALS_DEV_UI
	 * constant or the `wpcc_proposals_dev_ui` filter.
	 */
	private function proposals_dev_ui_enabled(): bool {
		if ( defined( 'WPCC_PROPOSALS_DEV_UI' ) && WPCC_PROPOSALS_DEV_UI ) {
			return true;
		}
		return (bool) apply_filters( 'wpcc_proposals_dev_ui', false );
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

	public function render_dashboard_overview(): void {
		// STEP 109.1 — Dashboard Overview, a read-only at-a-glance roll-up of the
		// existing admin surfaces. Never executes an operation.
		$this->render_view( 'dashboard-overview' );
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

	public function render_proposals(): void {
		$this->render_view( 'proposals' );
	}

	public function render_ai_alt_text(): void {
		$this->render_view( 'ai-alt-text' );
	}

	public function render_seo_meta(): void {
		$this->render_view( 'seo-meta' );
	}

	public function render_approval_center(): void {
		// STEP 106 — Approval Center (Pending / History / Queue), the surface that
		// replaced the thin "Pending Approvals" page.
		$this->render_view( 'approval-center' );
	}

	public function render_operations_explorer(): void {
		// STEP 108.1 — Operations Explorer (catalogue list), read-only discovery
		// surface over the operation registry. Never executes an operation.
		$this->render_view( 'operations-explorer' );
	}

	private function render_view( string $view ): void {
		$path = WPCC_PLUGIN_DIR . "includes/Admin/views/{$view}.php";

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}
