<?php
/**
 * STEP 111 — GA#2 Slice 2a: shared Anthropic transport.
 *
 * The single low-level Anthropic Messages transport for WP Command Center,
 * extracted (extract-on-second-use) from the GA#1 AnthropicVisionProvider so that
 * both the vision provider and future text providers share one outbound path,
 * one BYO key, one timeout, and one redaction pass. WPCC's outbound AI calls live
 * ONLY here.
 *
 * It is operation-agnostic: the caller supplies the `messages` content + max_tokens
 * + model; this class owns the URL/version/headers/timeout/HTTP call, the key and
 * model resolution (canonical names with back-compat to the original vision names),
 * response parsing + HTTP error mapping, and Redactor scrubbing. It returns a
 * normalized array (errors as DATA, never thrown).
 *
 * Strict boundaries — this class NEVER:
 *   - writes WordPress data (no post/meta/option writes),
 *   - touches ProposalStore / ProposalApplyService / OperationExecutor,
 *   - logs or returns the API key (every error string is run through Redactor).
 *
 * Key resolution (first non-empty wins):
 *   1. WPCC_ANTHROPIC_API_KEY constant   (canonical, shared)
 *   2. wpcc_anthropic_api_key option     (canonical, shared)
 *   3. WPCC_VISION_API_KEY constant      (legacy GA#1 — back-compat)
 *   4. wpcc_alt_text_api_key option      (legacy GA#1 — back-compat)
 *
 * Model resolution (first non-empty wins, else the caller's default):
 *   1. WPCC_ANTHROPIC_MODEL constant / wpcc_anthropic_model option   (canonical)
 *   2. WPCC_VISION_MODEL constant / wpcc_alt_text_model option       (legacy)
 *   3. $default supplied by the caller
 */

namespace WPCommandCenter\Ai;

use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class AnthropicClient {

	private const API_URL         = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION     = '2023-06-01';
	private const DEFAULT_TIMEOUT = 30; // hard request timeout (seconds)

	/** True when any key (canonical or legacy) is configured. No outbound call. */
	public function is_configured(): bool {
		return '' !== $this->key();
	}

	/**
	 * Non-secret label of which source provided the key (diagnostics/tests):
	 * anthropic_constant | anthropic_option | vision_constant | vision_option | none.
	 * NEVER returns the key itself.
	 */
	public function key_source(): string {
		if ( defined( 'WPCC_ANTHROPIC_API_KEY' ) && '' !== (string) WPCC_ANTHROPIC_API_KEY ) {
			return 'anthropic_constant';
		}
		if ( '' !== (string) get_option( 'wpcc_anthropic_api_key', '' ) ) {
			return 'anthropic_option';
		}
		if ( defined( 'WPCC_VISION_API_KEY' ) && '' !== (string) WPCC_VISION_API_KEY ) {
			return 'vision_constant';
		}
		if ( '' !== (string) get_option( 'wpcc_alt_text_api_key', '' ) ) {
			return 'vision_option';
		}
		return 'none';
	}

	/**
	 * Resolve the model: canonical constant/option → legacy constant/option →
	 * the caller-supplied default (preserves each feature's own default).
	 */
	public function model( string $default = '' ): string {
		if ( defined( 'WPCC_ANTHROPIC_MODEL' ) && '' !== (string) WPCC_ANTHROPIC_MODEL ) {
			return (string) WPCC_ANTHROPIC_MODEL;
		}
		$canon = (string) get_option( 'wpcc_anthropic_model', '' );
		if ( '' !== $canon ) {
			return $canon;
		}
		if ( defined( 'WPCC_VISION_MODEL' ) && '' !== (string) WPCC_VISION_MODEL ) {
			return (string) WPCC_VISION_MODEL;
		}
		$legacy = (string) get_option( 'wpcc_alt_text_model', '' );
		if ( '' !== $legacy ) {
			return $legacy;
		}
		return $default;
	}

	/**
	 * Send a Messages request. Caller supplies the already-built `messages` content
	 * (text and/or image blocks), max_tokens, and the resolved model. Returns a
	 * normalized array — never throws:
	 *   success:  [ 'ok' => true,  'text' => string, 'model' => string ]
	 *   failure:  [ 'ok' => false, 'code' => string, 'message' => string (redacted), 'model' => string ]
	 *
	 * @param array<int,array<string,mixed>> $messages Anthropic `messages` array.
	 * @param array<string,mixed>            $opts     { timeout?: int }
	 * @return array<string,mixed>
	 */
	public function send( array $messages, int $max_tokens, string $model, array $opts = [] ): array {
		$key = $this->key();
		if ( '' === $key ) {
			return [ 'ok' => false, 'code' => 'not_configured', 'message' => __( 'No Anthropic API key configured.', 'wp-command-center' ), 'model' => $model ];
		}

		$timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : self::DEFAULT_TIMEOUT;

		$response = wp_remote_post( self::API_URL, [
			'timeout' => $timeout,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'messages'   => $messages,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'code' => 'request_failed', 'message' => $this->scrub( $response->get_error_message() ), 'model' => $model ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			return [ 'ok' => false, 'code' => 'api_error_' . $code, 'message' => $this->scrub( '' !== $msg ? $msg : ( 'HTTP ' . $code ) ), 'model' => $model ];
		}

		$text = '';
		if ( is_array( $data ) && isset( $data['content'][0]['text'] ) ) {
			$text = trim( (string) $data['content'][0]['text'] );
		}

		return [ 'ok' => true, 'text' => $text, 'model' => $model ];
	}

	/** Key: canonical constant/option → legacy constant/option. Never logged/returned. */
	private function key(): string {
		if ( defined( 'WPCC_ANTHROPIC_API_KEY' ) && '' !== (string) WPCC_ANTHROPIC_API_KEY ) {
			return (string) WPCC_ANTHROPIC_API_KEY;
		}
		$canon = (string) get_option( 'wpcc_anthropic_api_key', '' );
		if ( '' !== $canon ) {
			return $canon;
		}
		if ( defined( 'WPCC_VISION_API_KEY' ) && '' !== (string) WPCC_VISION_API_KEY ) {
			return (string) WPCC_VISION_API_KEY;
		}
		return (string) get_option( 'wpcc_alt_text_api_key', '' );
	}

	/** Scrub any secret pattern from an error string before it leaves the client. */
	private function scrub( string $message ): string {
		return ( new Redactor() )->redact( $message )['text'];
	}
}
