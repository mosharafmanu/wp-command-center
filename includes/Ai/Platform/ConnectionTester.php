<?php
/**
 * PROGRAM-6R — connection tester (by DIALECT, not provider).
 *
 * One tester per dialect covers unlimited providers. Minimal, manual, secret-safe;
 * errors returned as data; no generation of substance, no mutation. Result:
 * [ ok:bool, code:string, message:string ] (message secret-free, Redactor-scrubbed).
 *
 *   anthropic         → AnthropicClient::send (reads its own key; key never extracted)
 *   openai-compatible → GET {base_url}/models with Bearer key (no auth for keyless local)
 *   gemini            → GET {endpoint}/models?key=…
 */

namespace WPCommandCenter\Ai\Platform;

use WPCommandCenter\Ai\AnthropicClient;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class ConnectionTester {

	private const TIMEOUT = 10;

	/**
	 * @param array  $conn normalized connection
	 * @param string $key  raw secret (may be '' for keyless local / constant-backed anthropic)
	 * @return array{ok:bool,code:string,message:string}
	 */
	public function test( array $conn, string $key ): array {
		$dialect = (string) ( $conn['dialect'] ?? '' );
		if ( ! Dialect::test_supported( $dialect ) ) {
			return $this->res( false, 'test_unsupported', __( 'A connection test is not available for this dialect.', 'wp-command-center' ) );
		}
		switch ( $dialect ) {
			case Dialect::ANTHROPIC:
				return $this->test_anthropic( (string) $conn['model'] );
			case Dialect::OPENAI:
				return $this->test_openai_compatible( $conn, $key );
			case Dialect::GEMINI:
				return $this->test_gemini( $conn, $key );
			default:
				return $this->res( false, 'test_unsupported', __( 'Unsupported dialect.', 'wp-command-center' ) );
		}
	}

	private function test_anthropic( string $model ): array {
		$client = new AnthropicClient();
		if ( ! $client->is_configured() ) {
			return $this->res( false, 'not_configured', __( 'No API key configured.', 'wp-command-center' ) );
		}
		$m   = $client->model( '' !== $model ? $model : 'claude-sonnet-4-6' );
		$r   = $client->send( [ [ 'role' => 'user', 'content' => 'ping' ] ], 1, $m, [ 'timeout' => self::TIMEOUT ] );
		$ok  = ! empty( $r['ok'] );
		return $this->res( $ok, $ok ? 'ok' : (string) ( $r['code'] ?? 'error' ), $ok ? '' : (string) ( $r['message'] ?? '' ) );
	}

	private function test_openai_compatible( array $conn, string $key ): array {
		$base = rtrim( (string) $conn['endpoint'], '/' );
		if ( '' === $base ) {
			return $this->res( false, 'no_endpoint', __( 'Set a base URL for this connection first.', 'wp-command-center' ) );
		}
		$def      = ProviderCatalog::get( (string) $conn['provider'] ) ?? [];
		$optional = (bool) ( $def['key_optional'] ?? false );
		if ( '' === $key && ! $optional ) {
			return $this->res( false, 'not_configured', __( 'No API key configured.', 'wp-command-center' ) );
		}
		$headers = [];
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$res = wp_remote_get( $base . '/models', [ 'timeout' => self::TIMEOUT, 'headers' => $headers ] );
		return $this->from_http( $res );
	}

	private function test_gemini( array $conn, string $key ): array {
		if ( '' === $key ) {
			return $this->res( false, 'not_configured', __( 'No API key configured.', 'wp-command-center' ) );
		}
		$base = rtrim( (string) $conn['endpoint'], '/' );
		$url  = add_query_arg( 'key', rawurlencode( $key ), $base . '/models' ); // key never logged.
		$res  = wp_remote_get( $url, [ 'timeout' => self::TIMEOUT ] );
		return $this->from_http( $res );
	}

	private function from_http( $res ): array {
		if ( is_wp_error( $res ) ) {
			return $this->res( false, 'request_failed', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 ) {
			// Honest model discovery from the SAME /models response (no extra call).
			$models = 0;
			if ( is_array( $body ) ) {
				if ( isset( $body['data'] ) && is_array( $body['data'] ) ) {
					$models = count( $body['data'] );              // OpenAI-compatible
				} elseif ( isset( $body['models'] ) && is_array( $body['models'] ) ) {
					$models = count( $body['models'] );            // Gemini
				}
			}
			return $this->res( true, 'ok', '', $models );
		}
		$msg = is_array( $body ) ? (string) ( $body['error']['message'] ?? ( is_string( $body['error'] ?? null ) ? $body['error'] : '' ) ) : '';
		return $this->res( false, 'api_error_' . $code, '' !== $msg ? $msg : ( 'HTTP ' . $code ) );
	}

	private function res( bool $ok, string $code, string $message, int $models = 0 ): array {
		return [
			'ok'      => $ok,
			'code'    => $code,
			'message' => $ok ? '' : ( new Redactor() )->redact( $message )['text'],
			'models'  => $models,
		];
	}
}
