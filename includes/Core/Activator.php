<?php
namespace WPCommandCenter\Core;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		Schema::install();

		if ( ! wp_next_scheduled( \WPCommandCenter\Operations\OperationWorker::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'wpcc_five_minutes', \WPCommandCenter\Operations\OperationWorker::CRON_HOOK );
		}

		// Step 80 — Seed the security mode on fresh installs. Existing sites that
		// had wpcc_enforce_approval set manually keep their setting until they
		// explicitly choose a mode via the WP Admin Security UI (STEP 80B).
		if ( false === get_option( 'wpcc_security_mode' ) ) {
			add_option( 'wpcc_security_mode', \WPCommandCenter\Operations\SecurityModeManager::DEFAULT_MODE );
		}
	}
}
