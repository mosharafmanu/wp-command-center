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

	public function run(): void {
		Schema::maybe_upgrade();

		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( \WPCommandCenter\Operations\OperationWorker::CRON_HOOK, [ new \WPCommandCenter\Operations\OperationWorker(), 'handle_cron' ] );

		( new RestApi() )->init();
		( new McpRestApi() )->init();
		( new AdminRestApi() )->init();

		if ( is_admin() ) {
			( new AdminMenu() )->init();
			( new Assets() )->init();
		}
	}

	public function add_cron_schedules( array $schedules ): array {
		$schedules['wpcc_five_minutes'] = [
			'interval' => 300,
			'display'  => __( 'Every 5 Minutes', 'wp-command-center' ),
		];
		return $schedules;
	}
}
