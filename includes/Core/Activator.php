<?php
namespace WPCommandCenter\Core;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
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
