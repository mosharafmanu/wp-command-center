<?php
namespace WPCommandCenter\Core;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate(): void {
		wp_clear_scheduled_hook( \WPCommandCenter\Operations\OperationWorker::CRON_HOOK );
	}
}
