<?php
namespace WPCommandCenter\Core;

defined( 'ABSPATH' ) || exit;

final class Activator {

	/**
	 * @param bool $network_wide True when WordPress is activating this plugin across
	 *                           a whole multisite network.
	 */
	public static function activate( bool $network_wide = false ): void {
		/*
		 * V1 is a single-site product and says so in the readme — but saying so was
		 * all it did. Network activation created this site's tables and options and
		 * left every other site in the network without them, so the Command Center
		 * appeared on all of them while working on one. That is a partial install
		 * presented as a whole one, which is the failure mode this plugin exists to
		 * avoid, so refuse it outright rather than half-perform it.
		 *
		 * wp_die() during the activation hook is WordPress's own mechanism for a
		 * plugin declining to activate: the plugin is left inactive and the operator
		 * is shown the reason.
		 */
		if ( $network_wide || ( function_exists( 'is_multisite' ) && is_multisite() && is_network_admin() ) ) {
			wp_die(
				esc_html__( 'WP Command Center is a single-site plugin and cannot be network activated. Activate it on each site that should have a Command Center, from that site\'s own Plugins screen. Network activation would create its tables and settings on one site only, while showing the Command Center on all of them.', 'ai-command-center' ),
				esc_html__( 'Network activation is not supported', 'ai-command-center' ),
				[ 'back_link' => true ]
			);
		}

		Schema::install();

		if ( ! wp_next_scheduled( \WPCommandCenter\Operations\OperationWorker::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'wpcc_five_minutes', \WPCommandCenter\Operations\OperationWorker::CRON_HOOK );
		}

		// RC-2 — CLIENT-SAFE RELEASE DEFAULT. Seed the security mode to CLIENT on
		// fresh installs so a real client site is governed (writes require human
		// approval) out of the box — the developer self-approve convenience must not
		// be a production default. Only seeds when unset (one-time); existing sites
		// keep their explicit choice, and SecurityModeManager::current()/DEFAULT_MODE
		// (the resolution fallback) are intentionally unchanged. Operators can still
		// switch to Developer mode via the Security UI (with the confirmation guard).
		if ( false === get_option( 'wpcc_security_mode' ) ) {
			add_option( 'wpcc_security_mode', \WPCommandCenter\Operations\SecurityModeManager::MODE_CLIENT );
		}
	}
}
