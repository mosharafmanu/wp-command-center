<?php
namespace WPCommandCenter\Admin;

use WPCommandCenter\Operations\SecurityModeManager;
use WPCommandCenter\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

/**
 * Experience Layer — 5-C navigation menu.
 *
 * The former ~12 flat submenus are collapsed into five sections (Overview ·
 * Operate · Audit · Access · Connect), each rendered by {@see AppShell}. Every
 * legacy slug keeps working: a single admin_init redirect maps the old slug to its
 * new section + `wpcc_tab`, passing through all other query args so deep links
 * (e.g. a Change History timeline session) still resolve. No routes, operations,
 * capabilities, MCP tools, or schema change here — this is navigation only.
 */
final class AdminMenu {

	private const CAPABILITY = 'manage_options';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar_badge' ], 100 );
		// The legacy-slug redirect MUST run on admin_menu (priority 0), NOT admin_init.
		// Core requires wp-admin/includes/menu.php — which fires `admin_menu` and then,
		// at the end of the same file, runs user_can_access_admin_page() and wp_die()s
		// with a 403 for any ?page= that is no longer a registered submenu — ALL before
		// `admin_init` fires. Redirecting on admin_init is therefore too late for the
		// collapsed legacy slugs (e.g. wpcc-tokens, wpcc-file-access): they 403 first.
		// Running at admin_menu priority 0 redirects them before that access check.
		add_action( 'admin_menu', [ $this, 'redirect_legacy_slugs' ], 0 );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'WP Command Center', 'wp-command-center' ),
			__( 'Command Center', 'wp-command-center' ),
			self::CAPABILITY,
			AppShell::HOME_SLUG,
			[ $this, 'render_overview' ],
			'dashicons-shield-alt',
			65
		);

		// Submenu order mirrors the 5-C IA. Overview reuses the parent slug.
		add_submenu_page( AppShell::HOME_SLUG, __( 'Overview', 'wp-command-center' ), __( 'Overview', 'wp-command-center' ), self::CAPABILITY, AppShell::HOME_SLUG, [ $this, 'render_overview' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'Operate', 'wp-command-center' ), __( 'Operate', 'wp-command-center' ), self::CAPABILITY, 'wpcc-operate', [ $this, 'render_operate' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'Audit', 'wp-command-center' ), __( 'Audit', 'wp-command-center' ), self::CAPABILITY, 'wpcc-audit', [ $this, 'render_audit' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'Access', 'wp-command-center' ), __( 'Access', 'wp-command-center' ), self::CAPABILITY, 'wpcc-access', [ $this, 'render_access' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'Connect', 'wp-command-center' ), __( 'Connect', 'wp-command-center' ), self::CAPABILITY, 'wpcc-connect', [ $this, 'render_connect' ] );
	}

	/**
	 * Keep every legacy bookmark/deep-link working after the IA collapse. Maps the
	 * old `?page=` slug to its new section + `wpcc_tab` and passes through all other
	 * query args (so a hosted view still receives its own tab/view/session_id/etc.).
	 * Fires for unregistered slugs too — admin_init runs before page validation.
	 */
	public function redirect_legacy_slugs(): void {
		if ( ! is_admin() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page navigation, no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page ) {
			return;
		}

		$map = AppShell::legacy_map();

		// Special case: the old Settings → API Tokens section deep-links to the
		// Tokens tab (not Security), preserving the STEP 107.4 behavior.
		if ( 'wpcc-settings' === $page ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) )
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				: ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' );
			if ( in_array( $section, [ 'tokens', 'api-tokens', 'api_tokens' ], true ) ) {
				$this->redirect_to( 'wpcc-access', 'tokens' );
				return;
			}
		}

		if ( ! isset( $map[ $page ] ) ) {
			return;
		}

		[ $section_slug, $wpcc_tab ] = $map[ $page ];
		$this->redirect_to( $section_slug, $wpcc_tab );
	}

	/**
	 * Redirect to a section page, setting `wpcc_tab` and carrying through every
	 * original query arg except `page`/`wpcc_tab` (sanitized).
	 */
	private function redirect_to( string $section_slug, string $wpcc_tab ): void {
		$args = [ 'page' => $section_slug ];
		if ( '' !== $wpcc_tab ) {
			$args['wpcc_tab'] = $wpcc_tab;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; passthrough is sanitized below.
		foreach ( $_GET as $key => $value ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, [ 'page', 'wpcc_tab' ], true ) || is_array( $value ) ) {
				continue;
			}
			$args[ $key ] = sanitize_text_field( wp_unslash( $value ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function admin_bar_badge( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! SecurityModeManager::requires_human_approver() ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			OperationManager::STATUS_PENDING_REVIEW
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
			'href'  => admin_url( 'admin.php?page=wpcc-operate&wpcc_tab=approvals' ),
		] );
	}

	public function render_overview(): void {
		( new AppShell() )->render( AppShell::HOME_SLUG );
	}

	public function render_operate(): void {
		( new AppShell() )->render( 'wpcc-operate' );
	}

	public function render_audit(): void {
		( new AppShell() )->render( 'wpcc-audit' );
	}

	public function render_access(): void {
		( new AppShell() )->render( 'wpcc-access' );
	}

	public function render_connect(): void {
		( new AppShell() )->render( 'wpcc-connect' );
	}
}
