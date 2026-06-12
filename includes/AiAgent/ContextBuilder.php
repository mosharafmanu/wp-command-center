<?php
/**
 * §8.1 AI Context Engine — structured site snapshot handed to the AI
 * agent before it acts. Composes the Site Intelligence scan, the three
 * diagnostics modules, and a top-level map of the file access roots.
 */

namespace WPCommandCenter\AiAgent;

use WPCommandCenter\Diagnostics\PerformanceDiagnostics;
use WPCommandCenter\Diagnostics\SecurityDiagnostics;
use WPCommandCenter\Diagnostics\WooCommerceDiagnostics;
use WPCommandCenter\Security\PathGuard;
use WPCommandCenter\SiteIntelligence\SiteScanner;

defined( 'ABSPATH' ) || exit;

final class ContextBuilder {

	public function build( bool $include_files = true, bool $include_diagnostics = true ): array {
		$scan = ( new SiteScanner() )->scan();
		$context = [
			'generated_at'        => $scan['generated_at'],
			'wordpress'           => $scan['wordpress'],
			'php'                 => $scan['php'],
			'theme'               => $scan['theme'],
			'plugins'             => $scan['plugins'],
			'woocommerce'         => $scan['woocommerce'],
			'cache'               => $scan['cache'],
			'server_capabilities' => [
				'shell_exec' => $scan['server']['shell_exec_enabled'],
				'proc_open'  => $scan['server']['proc_open_enabled'],
				'wp_cli'     => $scan['server']['wp_cli_available'],
			],
		];

		if ( $include_diagnostics ) {
			$context['diagnostics'] = [
				'performance' => ( new PerformanceDiagnostics() )->analyze( $scan ),
				'security'    => ( new SecurityDiagnostics() )->analyze( $scan ),
				'woocommerce' => ( new WooCommerceDiagnostics() )->analyze(),
			];
		}

		if ( $include_files ) {
			$file_access = ( new FileAccessApi() )->list_directory( '' );
			$context['file_access'] = [
				'allowed_roots' => PathGuard::ALLOWED_ROOTS,
				'roots'         => is_wp_error( $file_access ) ? [] : $file_access['entries'],
			];
		}

		return $context;
	}
}
