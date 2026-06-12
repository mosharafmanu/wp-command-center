<?php
/**
 * Step 45 — MCP REST API registration.
 * Registers the MCP JSON-RPC endpoint under WP Command Center.
 */

namespace WPCommandCenter\Mcp;

use WPCommandCenter\Security\AuthTokens;

defined( 'ABSPATH' ) || exit;

final class McpRestApi {

	const NAMESPACE = 'wp-command-center/v1';

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/mcp', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_mcp' ],
			'permission_callback' => [ $this, 'require_read' ],
		] );
	}

	public function require_read( \WP_REST_Request $request ): bool|\WP_Error {
		$header = $request->get_header( 'authorization' );
		if ( ! $header || ! preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			return new \WP_Error( 'wpcc_missing_token', __( 'Missing API token.', 'wp-command-center' ) );
		}
		$auth  = new AuthTokens();
		$token = $auth->validate( $matches[1] );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return true;
	}

	public function handle_mcp( \WP_REST_Request $request ): \WP_REST_Response {
		$body  = $request->get_json_params();
		if ( empty( $body ) ) {
			return new \WP_REST_Response( [ 'jsonrpc' => '2.0', 'error' => [ 'code' => -32700, 'message' => 'Parse error' ], 'id' => null ], 400 );
		}

		$header  = $request->get_header( 'authorization' );
		$matches = [];
		$tid     = '';
		$scope   = '';
		if ( $header && preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
			$auth  = new AuthTokens();
			$token = $auth->validate( $matches[1] );
			$tid   = is_array( $token ) ? ( $token['id'] ?? '' ) : '';
			$scope = is_array( $token ) ? ( $token['scope'] ?? '' ) : '';
		}

		$context = [
			'actor'       => [ 'type' => 'mcp', 'token_id' => $tid ],
			'token_id'    => $tid,
			'token_scope' => $scope,
			'client'      => $request->get_header( 'User-Agent' ) ?: 'unknown',
		];

		try {
			$server = new McpServerRuntime();
			$result = $server->handle( $body, $context );
			$code   = isset( $result['error'] ) ? 202 : 200;
			return new \WP_REST_Response( $result, $code );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [
				'jsonrpc' => '2.0',
				'error'   => [ 'code' => -32603, 'message' => 'Internal error' ],
				'id'      => $body['id'] ?? null,
			], 500 );
		}
	}
}

