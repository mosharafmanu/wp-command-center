<?php
/**
 * PROGRAM-6 — provider connection tester (read-only, minimal, secret-safe).
 *
 * Verifies that a stored key authenticates, using the smallest possible request.
 * Live tests exist for Anthropic, OpenAI and Gemini; every other type returns
 * 'unsupported' (we never fake a pass). Rules: manual only, short timeout, no
 * generation of substance, no site mutation, no proposal, never logs/returns the
 * key, every error string scrubbed by Redactor. Errors are returned as DATA.
 *
 * Result shape: [ ok:bool, code:string, message:string ] — message is secret-free.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Ai\AnthropicClient;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class ProviderConnectionTester {

	private const TIMEOUT = 10;

	/**
	 * @return array{ok:bool,code:string,message:string}
	 */
	public function test( string $type, string $key, string $model ): array {
		if ( ! ProviderCatalog::test_supported( $type ) ) {
			return [ 'ok' => false, 'code' => 'test_unsupported', 'message' => __( 'A connection test is not available for this provider yet.', 'wp-command-center' ) ];
		}

		switch ( $type ) {
			case 'anthropic':
				return $this->test_anthropic( $model );
			case 'openai':
				return $this->test_openai( $key );
			case 'gemini':
				return $this->test_gemini( $key );
			default:
				return [ 'ok' => false, 'code' => 'test_unsupported', 'message' => __( 'A connection test is not available for this provider yet.', 'wp-command-center' ) ];
		}
	}

	/** Anthropic: reuse the existing transport (it reads its own key — never extracted here). */
	private function test_anthropic( string $model ): array {
		$client = new AnthropicClient();
		if ( ! $client->is_configured() ) {
			return [ 'ok' => false, 'code' => 'not_configured', 'message' => __( 'No API key configured.', 'wp-command-center' ) ];
		}
		$m   = $client->model( $model !== '' ? $model : 'claude-sonnet-4-6' );
		$res = $client->send( [ [ 'role' => 'user', 'content' => 'ping' ] ], 1, $m, [ 'timeout' => self::TIMEOUT ] );
		$ok  = ! empty( $res['ok'] );
		return [
			'ok'      => $ok,
			'code'    => $ok ? 'ok' : (string) ( $res['code'] ?? 'error' ),
			'message' => $ok ? '' : $this->scrub( (string) ( $res['message'] ?? '' ) ),
		];
	}

	/** OpenAI: a minimal authenticated GET /v1/models — no generation, no cost. */
	private function test_openai( string $key ): array {
		if ( '' === $key ) {
			return [ 'ok' => false, 'code' => 'not_configured', 'message' => __( 'No API key configured.', 'wp-command-center' ) ];
		}
		$res = wp_remote_get( 'https://api.openai.com/v1/models', [
			'timeout' => self::TIMEOUT,
			'headers' => [ 'Authorization' => 'Bearer ' . $key ],
		] );
		return $this->from_http( $res );
	}

	/** Gemini: a minimal authenticated GET of the models list — no generation, no cost. */
	private function test_gemini( string $key ): array {
		if ( '' === $key ) {
			return [ 'ok' => false, 'code' => 'not_configured', 'message' => __( 'No API key configured.', 'wp-command-center' ) ];
		}
		// Key travels as a query param per Gemini's API; never logged (only the response is read).
		$url = add_query_arg( 'key', rawurlencode( $key ), 'https://generativelanguage.googleapis.com/v1beta/models' );
		$res = wp_remote_get( $url, [ 'timeout' => self::TIMEOUT ] );
		return $this->from_http( $res );
	}

	/** Normalize a wp_remote_* result into the secret-free shape. */
	private function from_http( $res ): array {
		if ( is_wp_error( $res ) ) {
			return [ 'ok' => false, 'code' => 'request_failed', 'message' => $this->scrub( $res->get_error_message() ) ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code ) {
			return [ 'ok' => true, 'code' => 'ok', 'message' => '' ];
		}
		// Pull a short provider message if present, scrubbed.
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$msg  = '';
		if ( is_array( $body ) ) {
			$msg = (string) ( $body['error']['message'] ?? ( is_string( $body['error'] ?? null ) ? $body['error'] : '' ) );
		}
		return [ 'ok' => false, 'code' => 'api_error_' . $code, 'message' => $this->scrub( '' !== $msg ? $msg : ( 'HTTP ' . $code ) ) ];
	}

	private function scrub( string $message ): string {
		return ( new Redactor() )->redact( $message )['text'];
	}
}
