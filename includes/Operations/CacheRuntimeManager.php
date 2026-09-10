<?php
/**
 * ISSUE 12 — Cache purge runtime (no shell required).
 *
 * wp_cli_bridge cache_flush needs shell (proc_open), which is disabled on many
 * hosts — yet page-cache purging does not need shell at all. This runtime detects
 * installed cache layers and calls their PHP purge APIs directly, so a verified
 * change is not left invisible behind a stale page cache (the exact LiteSpeed
 * situation from the production session).
 *
 * Detected layers: LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache,
 * Cache Enabler, and the persistent object cache (wp_cache_flush). Actions:
 * cache_status (detect only), cache_purge_all, cache_purge_url.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class CacheRuntimeManager {

	public const ACTIONS = [ 'cache_status', 'cache_purge_all', 'cache_purge_url', 'cache_describe' ];

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function run( array $payload, array $context = [] ): array {
		$action = (string) ( $payload['action'] ?? '' );

		switch ( $action ) {
			case 'cache_status':
				return [ 'action' => 'cache_status', 'detected' => $this->detect() ];
			case 'cache_purge_all':
				return $this->purge_all( $context );
			case 'cache_purge_url':
				return $this->purge_url( $payload, $context );
			case 'cache_describe':
				return [ 'action' => 'cache_describe', 'runtime' => 'cache_manage', 'actions' => self::ACTIONS, 'notes' => __( 'cache_purge_all clears every detected layer + object cache. cache_purge_url purges one URL where the layer supports it. cache_status only detects. No shell required.', 'action-steward' ) ];
			default:
				return $this->error(
					'wpcc_invalid_cache_action',
					sprintf(
						/* translators: 1: invalid action, 2: valid actions */
						__( 'Invalid cache action "%1$s". Valid actions: %2$s.', 'action-steward' ),
						$action,
						implode( ', ', self::ACTIONS )
					),
					[ 'valid_actions' => self::ACTIONS ]
				);
		}
	}

	/**
	 * Which cache layers are installed/active right now.
	 *
	 * @return array<int,string>
	 */
	private function detect(): array {
		$layers = [];
		if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_all' ) || function_exists( 'run_litespeed_cache' ) ) {
			$layers[] = 'litespeed';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$layers[] = 'wp_rocket';
		}
		if ( function_exists( 'w3tc_flush_all' ) || defined( 'W3TC' ) ) {
			$layers[] = 'w3_total_cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$layers[] = 'wp_super_cache';
		}
		if ( has_action( 'cache_enabler_clear_complete_cache' ) || class_exists( '\\Cache_Enabler' ) ) {
			$layers[] = 'cache_enabler';
		}
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$layers[] = 'object_cache';
		}
		return $layers;
	}

	/** @param array<string,mixed> $context */
	private function purge_all( array $context ): array {
		$detected = $this->detect();
		$purged   = [];

		if ( in_array( 'litespeed', $detected, true ) ) {
			do_action( 'litespeed_purge_all' );
			$purged[] = 'litespeed';
		}
		if ( in_array( 'wp_rocket', $detected, true ) && function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$purged[] = 'wp_rocket';
		}
		if ( in_array( 'w3_total_cache', $detected, true ) && function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'w3_total_cache';
		}
		if ( in_array( 'wp_super_cache', $detected, true ) && function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$purged[] = 'wp_super_cache';
		}
		if ( in_array( 'cache_enabler', $detected, true ) ) {
			do_action( 'cache_enabler_clear_complete_cache' );
			$purged[] = 'cache_enabler';
		}
		// Object cache last (drops transient/page fragments other layers may reuse).
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
			$purged[] = 'object_cache';
		}

		$this->audit->record( 'cache.purge_all', [ 'detected' => $detected, 'purged' => $purged ] );
		return [ 'action' => 'cache_purge_all', 'detected' => $detected, 'purged' => $purged ];
	}

	/**
	 * @param array<string,mixed> $p
	 * @param array<string,mixed> $context
	 */
	private function purge_url( array $p, array $context ): array {
		$url = esc_url_raw( (string) ( $p['url'] ?? '' ) );
		if ( '' === $url ) {
			return $this->error( 'wpcc_missing_url', __( "cache_purge_url requires a 'url' parameter (the page URL to purge).", 'action-steward' ) );
		}

		$detected = $this->detect();
		$purged   = [];

		if ( in_array( 'litespeed', $detected, true ) ) {
			do_action( 'litespeed_purge_url', $url );
			$purged[] = 'litespeed';
		}
		if ( in_array( 'wp_rocket', $detected, true ) && function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( [ $url ] );
			$purged[] = 'wp_rocket';
		}
		if ( in_array( 'cache_enabler', $detected, true ) && has_action( 'cache_enabler_clear_page_cache_by_url' ) ) {
			do_action( 'cache_enabler_clear_page_cache_by_url', $url );
			$purged[] = 'cache_enabler';
		}
		// Layers without per-URL purge (W3TC/Super Cache) are reported as unsupported
		// here rather than silently doing nothing — the caller can cache_purge_all.
		$unsupported = array_values( array_diff( $detected, $purged, [ 'object_cache' ] ) );

		$this->audit->record( 'cache.purge_url', [ 'url' => $url, 'purged' => $purged ] );
		return [ 'action' => 'cache_purge_url', 'url' => $url, 'detected' => $detected, 'purged' => $purged, 'no_per_url_support' => $unsupported ];
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message, array $extra = [] ): array {
		return array_merge( [ 'error' => true, 'code' => $code, 'message' => $message ], $extra );
	}
}
