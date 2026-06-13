<?php
namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	private const CAPABILITY = 'manage_options';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar_badge' ], 100 );
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
		add_submenu_page( 'wp-command-center', __( 'Site Intelligence', 'wp-command-center' ), __( 'Site Intelligence', 'wp-command-center' ), self::CAPABILITY, 'wpcc-site-intelligence', [ $this, 'render_site_intelligence' ] );
		add_submenu_page( 'wp-command-center', __( 'Diagnostics', 'wp-command-center' ), __( 'Diagnostics', 'wp-command-center' ), self::CAPABILITY, 'wpcc-diagnostics', [ $this, 'render_diagnostics' ] );
		add_submenu_page( 'wp-command-center', __( 'File Access', 'wp-command-center' ), __( 'File Access', 'wp-command-center' ), self::CAPABILITY, 'wpcc-file-access', [ $this, 'render_file_access' ] );
		add_submenu_page( 'wp-command-center', __( 'Patches', 'wp-command-center' ), __( 'Patches', 'wp-command-center' ), self::CAPABILITY, 'wpcc-patches', [ $this, 'render_patches' ] );
		add_submenu_page( 'wp-command-center', __( 'Rollback', 'wp-command-center' ), __( 'Rollback', 'wp-command-center' ), self::CAPABILITY, 'wpcc-rollback', [ $this, 'render_rollback' ] );
		add_submenu_page( 'wp-command-center', __( 'Settings', 'wp-command-center' ), __( 'Settings', 'wp-command-center' ), self::CAPABILITY, 'wpcc-settings', [ $this, 'render_settings' ] );
		add_submenu_page( 'wp-command-center', __( 'AI Integrations', 'wp-command-center' ), __( 'AI Integrations', 'wp-command-center' ), self::CAPABILITY, 'wpcc-ai-integrations', [ $this, 'render_ai_integrations' ] );
		add_submenu_page( 'wp-command-center', __( 'Pending Approvals', 'wp-command-center' ), __( 'Pending Approvals', 'wp-command-center' ), self::CAPABILITY, 'wpcc-approvals', [ $this, 'render_approvals' ] );
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
			'href'  => admin_url( 'admin.php?page=wpcc-approvals' ),
		] );
	}

	public function render_dashboard(): void {
		$this->render_view( 'dashboard' );
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

	public function render_rollback(): void {
		$this->render_view( 'rollback' );
	}

	public function render_settings(): void {
		$this->render_view( 'settings' );
	}

	public function render_ai_integrations(): void {
		$this->render_view( 'ai-integrations' );
	}

	public function render_approvals(): void {
		$this->render_view( 'approvals' );
	}

	private function render_view( string $view ): void {
		$path = WPCC_PLUGIN_DIR . "includes/Admin/views/{$view}.php";

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}
