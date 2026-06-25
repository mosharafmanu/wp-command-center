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

		// Submenu order mirrors the product-language IA (UX Master Blueprint §2):
		// Home · Built-in AI · Connect · Activity · History · Settings. Home reuses
		// the parent slug. Architecture words never appear in the menu.
		add_submenu_page( AppShell::HOME_SLUG, __( 'Home', 'wp-command-center' ), __( 'Home', 'wp-command-center' ), self::CAPABILITY, AppShell::HOME_SLUG, [ $this, 'render_overview' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'Built-in AI', 'wp-command-center' ), __( 'Built-in AI', 'wp-command-center' ), self::CAPABILITY, AppShell::BUILTIN_SLUG, [ $this, 'render_builtin' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'Connect', 'wp-command-center' ), __( 'Connect', 'wp-command-center' ), self::CAPABILITY, AppShell::CONNECT_SLUG, [ $this, 'render_connect' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'Activity', 'wp-command-center' ), __( 'Activity', 'wp-command-center' ), self::CAPABILITY, AppShell::ACTIVITY_SLUG, [ $this, 'render_activity' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'History', 'wp-command-center' ), __( 'History', 'wp-command-center' ), self::CAPABILITY, AppShell::HISTORY_SLUG, [ $this, 'render_history' ] );
		add_submenu_page( AppShell::HOME_SLUG, __( 'Settings', 'wp-command-center' ), __( 'Settings', 'wp-command-center' ), self::CAPABILITY, AppShell::SETTINGS_SLUG, [ $this, 'render_settings' ] );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab = isset( $_GET['wpcc_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpcc_tab'] ) ) : '';

		// Special case: the old Settings → API Tokens section deep-links to the
		// Access tab (not Security), preserving the STEP 107.4 behavior.
		if ( 'wpcc-settings' === $page && '' === $tab ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) )
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				: ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' );
			if ( in_array( $section, [ 'tokens', 'api-tokens', 'api_tokens' ], true ) ) {
				$this->redirect_to( AppShell::SETTINGS_SLUG, 'access' );
				return;
			}
		}

		// Resolve any legacy slug (and, for retired 5-C section slugs, the old
		// wpcc_tab) onto its canonical home in the current IA. Null = already current.
		$resolved = AppShell::resolve_legacy( $page, $tab );
		if ( null === $resolved ) {
			return;
		}

		// resolve_legacy may return an optional 3rd element: extra query args that
		// land a legacy deep-link on a specific second-level pane (Diagnostics/Advanced
		// hubs), e.g. [ 'dpane' => 'patches' ].
		$section_slug = $resolved[0];
		$wpcc_tab     = $resolved[1];
		$extra_args   = isset( $resolved[2] ) && is_array( $resolved[2] ) ? $resolved[2] : [];
		$this->redirect_to( $section_slug, $wpcc_tab, $extra_args );
	}

	/**
	 * Redirect to a section page, setting `wpcc_tab` (+ optional hub-pane args) and
	 * carrying through every original query arg except `page`/`wpcc_tab`/pane (sanitized).
	 *
	 * @param array<string,string> $extra_args Hub-pane selectors (e.g. dpane/apane).
	 */
	private function redirect_to( string $section_slug, string $wpcc_tab, array $extra_args = [] ): void {
		$args = [ 'page' => $section_slug ];
		if ( '' !== $wpcc_tab ) {
			$args['wpcc_tab'] = $wpcc_tab;
		}
		foreach ( $extra_args as $k => $v ) {
			$args[ sanitize_key( (string) $k ) ] = sanitize_key( (string) $v );
		}
		$reserved = array_merge( [ 'page', 'wpcc_tab' ], array_keys( $args ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation; passthrough is sanitized below.
		foreach ( $_GET as $key => $value ) {
			$key = sanitize_key( $key );
			if ( in_array( $key, $reserved, true ) || is_array( $value ) ) {
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
			'href'  => admin_url( 'admin.php?page=' . AppShell::ACTIVITY_SLUG . '&wpcc_tab=approvals' ),
		] );
	}

	public function render_overview(): void {
		( new AppShell() )->render( AppShell::HOME_SLUG );
	}

	public function render_builtin(): void {
		( new AppShell() )->render( AppShell::BUILTIN_SLUG );
	}

	public function render_connect(): void {
		( new AppShell() )->render( AppShell::CONNECT_SLUG );
	}

	public function render_activity(): void {
		( new AppShell() )->render( AppShell::ACTIVITY_SLUG );
	}

	public function render_history(): void {
		( new AppShell() )->render( AppShell::HISTORY_SLUG );
	}

	public function render_settings(): void {
		( new AppShell() )->render( AppShell::SETTINGS_SLUG );
	}
}
