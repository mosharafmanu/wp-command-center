<?php
/**
 * PROGRAM-6S — connection health derivation (read-only, honest).
 *
 * EXPERIENCE ONLY. Maps a connection's stored last-test result + config state to a
 * human health status, color, and a recommended next action. No calls, no runtime,
 * no architecture change — it only interprets data the platform already stores.
 */

namespace WPCommandCenter\Ai\Platform;

defined( 'ABSPATH' ) || exit;

final class Health {

	/**
	 * @param array          $conn  normalized connection
	 * @param ConnectionStore $store
	 * @return array{state:string,label:string,color:string,dot:string,action:string}
	 */
	public static function of( array $conn, ConnectionStore $store ): array {
		$has_key = $store->is_configured( $conn );
		$const   = $store->credentials()->is_constant_backed( $conn );

		if ( empty( $conn['enabled'] ) ) {
			return self::r( 'disabled', __( 'Disabled', 'action-steward' ), '#646970', __( 'Enable this connection to use it.', 'action-steward' ) );
		}
		if ( ! $has_key ) {
			return self::r( 'needs_setup', __( 'Needs a key', 'action-steward' ), '#dba617',
				$const ? __( 'Define the key constant in wp-config.php.', 'action-steward' ) : __( 'Add an API key to finish setup.', 'action-steward' ) );
		}

		$lt = $conn['last_test'];
		if ( ! is_array( $lt ) || ! isset( $lt['code'] ) || 'untested' === $lt['code'] ) {
			return self::r( 'untested', __( 'Not tested yet', 'action-steward' ), '#2271b1', __( 'Run a test to confirm the connection works.', 'action-steward' ) );
		}

		$code    = (string) $lt['code'];
		$latency = (int) ( $lt['latency_ms'] ?? 0 );

		if ( 'ok' === $code ) {
			if ( $latency > 0 && $latency >= 4000 ) {
				return self::r( 'slow', __( 'Healthy — slow', 'action-steward' ), '#dba617', __( 'Responding, but latency is high. Consider a faster model or region.', 'action-steward' ) );
			}
			return self::r( 'healthy', __( 'Healthy', 'action-steward' ), '#00a32a', __( 'Working. Nothing to do.', 'action-steward' ) );
		}
		if ( in_array( $code, [ 'api_error_401', 'api_error_403' ], true ) ) {
			return self::r( 'auth_failed', __( 'Authentication failed', 'action-steward' ), '#d63638', __( 'The key was rejected. Paste a new key and test again.', 'action-steward' ) );
		}
		if ( 'api_error_429' === $code ) {
			return self::r( 'rate_limited', __( 'Rate limited', 'action-steward' ), '#dba617', __( 'The provider is rate-limiting. Wait and retry, or check your plan.', 'action-steward' ) );
		}
		if ( 'request_failed' === $code || 'no_endpoint' === $code ) {
			return self::r( 'offline', __( 'Unreachable', 'action-steward' ), '#d63638', __( 'Could not reach the endpoint. Check the base URL and that the server is running.', 'action-steward' ) );
		}
		if ( 'not_configured' === $code ) {
			return self::r( 'needs_setup', __( 'Needs a key', 'action-steward' ), '#dba617', __( 'Add an API key, then test.', 'action-steward' ) );
		}
		return self::r( 'attention', __( 'Needs attention', 'action-steward' ), '#d63638',
			/* translators: %s: error code */
			sprintf( __( 'Last test failed (%s). Review the connection and test again.', 'action-steward' ), $code ) );
	}

	/** Roll up overall platform health into one line. */
	public static function summary( array $conns, ConnectionStore $store ): array {
		$healthy = 0; $attention = 0; $untested = 0; $total = count( $conns );
		foreach ( $conns as $c ) {
			$s = self::of( $c, $store )['state'];
			if ( 'healthy' === $s || 'slow' === $s ) { $healthy++; }
			elseif ( in_array( $s, [ 'auth_failed', 'offline', 'attention', 'rate_limited' ], true ) ) { $attention++; }
			elseif ( 'untested' === $s || 'needs_setup' === $s ) { $untested++; }
		}
		return [ 'total' => $total, 'healthy' => $healthy, 'attention' => $attention, 'untested' => $untested ];
	}

	private static function r( string $state, string $label, string $color, string $action ): array {
		return [ 'state' => $state, 'label' => $label, 'color' => $color, 'dot' => $color, 'action' => $action ];
	}
}
