<?php
/**
 * Core orchestrator. Boots the admin UI and, over time, the engines
 * described in the canonical spec (Site Intelligence, Diagnostics,
 * AI Agent Gateway, Patch System, Rollback).
 */

namespace WPCommandCenter\Core;

use WPCommandCenter\Admin\AdminMenu;
use WPCommandCenter\Admin\AdminRestApi;
use WPCommandCenter\Admin\Assets;
use WPCommandCenter\AiAgent\RestApi;
use WPCommandCenter\Mcp\McpRestApi;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	/**
	 * Warn when this single-site plugin is running network-activated.
	 *
	 * V1 creates its tables and settings for one site. Network activation leaves
	 * every other site in the network without them while still showing the menu, so
	 * the Command Center looks installed where it cannot work. New activations are
	 * refused outright in Activator::activate(); this covers a network that was
	 * already in that state.
	 */
	public function network_activation_notice(): void {
		if ( ! is_multisite() || ! function_exists( 'is_plugin_active_for_network' ) ) {
			return;
		}
		if ( ! is_plugin_active_for_network( WPCC_PLUGIN_BASENAME ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'WP Command Center is network activated.', 'ai-command-center' ),
			esc_html__( 'This version supports one site at a time. Its tables and settings exist only for the site it was set up on, so other sites in this network will show an empty Command Center. Deactivate it for the network and activate it on each site that should have one.', 'ai-command-center' )
		);
	}

	public function run(): void {
		Schema::maybe_upgrade();

		// A network that was already network-activated before this build cannot be
		// caught by the activation hook, so say so where an administrator will see
		// it rather than letting the sub-sites look configured when they are not.
		add_action( 'admin_notices', [ $this, 'network_activation_notice' ] );

		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( \WPCommandCenter\Operations\OperationWorker::CRON_HOOK, [ new \WPCommandCenter\Operations\OperationWorker(), 'handle_cron' ] );

		// Performance: OperationRegistry memoizes the operation catalogue per request
		// (it otherwise re-probes WP-CLI/plugin availability — a ~200ms shell_exec —
		// on every lookup). Bust that memo exactly when availability can change, so a
		// request that activates/deactivates a plugin or switches theme and then runs
		// an operation still sees fresh availability (preserves pre-memo behavior).
		$wpcc_reset_catalogue = [ \WPCommandCenter\Operations\OperationRegistry::class, 'reset_cache' ];
		add_action( 'activated_plugin', $wpcc_reset_catalogue );
		add_action( 'deactivated_plugin', $wpcc_reset_catalogue );
		add_action( 'switch_theme', $wpcc_reset_catalogue );

		( new RestApi() )->init();
		( new McpRestApi() )->init();
		( new AdminRestApi() )->init();

		// PROGRAM-8 — runtime telemetry observer (read-only; subscribes to the
		// behavior-neutral wpcc_audit_recorded hook; never affects execution).
		( new \WPCommandCenter\Telemetry\TelemetrySubscriber() )->init();

		// PROGRAM-9 — runtime event bus bridge: publishes one typed RuntimeEvent per
		// audit record onto the EventBus so future subscribers (notifications,
		// webhooks, live dashboard, fleet, analytics) attach with zero runtime change.
		// Additive + behavior-neutral; Audit stays authoritative, Telemetry unchanged.
		( new \WPCommandCenter\Events\EventBridge() )->init();

		if ( is_admin() ) {
			( new AdminMenu() )->init();
			( new Assets() )->init();
			// Put this plugin's screens into WordPress's own ⌘K palette. Navigate-only,
			// same destinations and same capability gate as the admin menu; without it
			// the palette's fuzzy matcher had no product-owned answer to rank and
			// offered Marketing rows for "token". See CommandPaletteIntegration.
			( new \WPCommandCenter\Admin\CommandPaletteIntegration() )->init();
			// Per-feature classes retain their no-JS admin-post fallback handlers + Bulk
			// Actions; their row links are consolidated into the single AI Assist entry.
			( new \WPCommandCenter\Admin\SeoRowActions() )->init();
			( new \WPCommandCenter\Admin\ContentRowActions() )->init();
			( new \WPCommandCenter\Admin\MediaRowActions() )->init();
			// One consolidated "✨ AI Assist" row action (Posts/Pages/Products/Media),
			// reading AiActionRegistry; opens the shared Governed Action Panel chooser.
			( new \WPCommandCenter\Admin\AiAssistRowActions() )->init();
			// One generalized Governed Action Panel, shared by every AI content
			// workflow (SEO / Title / Excerpt / Alt Text), enqueued centrally.
			( new \WPCommandCenter\Admin\ActionPanelAssets() )->init();
		}
	}

	public function add_cron_schedules( array $schedules ): array {
		$schedules['wpcc_five_minutes'] = [
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'ai-command-center' ),
		];
		return $schedules;
	}
}
