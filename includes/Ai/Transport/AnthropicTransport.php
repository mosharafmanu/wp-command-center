<?php
/**
 * Phase A — Universal AI Provider Runtime: Anthropic transport.
 *
 * The ONLY component that knows the Anthropic Messages wire format. It translates
 * a neutral GenerationRequest (plus a resolved API key handed to it) into the
 * Anthropic request, delegates the single HTTP attempt to the shared AiHttpClient,
 * and maps the outcome back into a neutral GenerationResult — errors as DATA,
 * never thrown.
 *
 * It owns, and is the only owner of:
 *   - endpoint: https://api.anthropic.com/v1/messages
 *   - headers:  x-api-key, anthropic-version: 2023-06-01, content-type: application/json
 *   - body:     { model, max_tokens, messages }  (and nothing else)
 *   - response: text from content[0].text
 *   - errors:   not_configured | request_failed | api_error_<status>
 *
 * Phase A preserves the current request body EXACTLY: no system, temperature,
 * stop_sequences, or any other field is emitted.
 *
 * Strict boundaries — this class NEVER:
 *   - reads WordPress options or constants (it is GIVEN the key),
 *   - performs retry or endpoint validation (the HTTP client's future concern),
 *   - writes WordPress data or touches the proposal/operation engine,
 *   - logs or returns the API key (error strings are run through Redactor).
 */

namespace WPCommandCenter\Ai\Transport;

use WPCommandCenter\Ai\Contract\GenerationImagePart;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationResult;
use WPCommandCenter\Ai\Contract\GenerationTextPart;
use WPCommandCenter\Ai\Http\AiHttpClient;
use WPCommandCenter\Ai\Http\AiHttpRequest;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class AnthropicTransport {

	private const API_URL     = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION = '2023-06-01';

	private AiHttpClient $http;

	public function __construct( ?AiHttpClient $http = null ) {
		$this->http = $http ?? new AiHttpClient();
	}

	/**
	 * Generate against the Anthropic Messages API.
	 *
	 * @param GenerationRequest $request Neutral request.
	 * @param string            $api_key Resolved key (the transport never reads options).
	 */
	public function generate( GenerationRequest $request, string $api_key ): GenerationResult {
		$model = $request->model();

		if ( '' === $api_key ) {
			return GenerationResult::error( 'not_configured', __( 'No Anthropic API key configured.', 'wp-command-center' ), $model );
		}

		$http_request = new AiHttpRequest(
			self::API_URL,
			[
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			(string) wp_json_encode( [
				'model'      => $model,
				'max_tokens' => $request->max_tokens(),
				'messages'   => $this->wire_messages( $request ),
			] ),
			$request->timeout()
		);

		$response = $this->http->send( $http_request );

		if ( $response->is_error() ) {
			// error_message() is already redacted by the HTTP client.
			return GenerationResult::error( 'request_failed', $response->error_message(), $model );
		}

		$code = $response->status();
		$data = json_decode( $response->body(), true );

		if ( 200 !== $code ) {
			$msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			return GenerationResult::error( 'api_error_' . $code, $this->scrub( '' !== $msg ? $msg : ( 'HTTP ' . $code ) ), $model );
		}

		$text = '';
		if ( is_array( $data ) && isset( $data['content'][0]['text'] ) ) {
			$text = trim( (string) $data['content'][0]['text'] );
		}

		return GenerationResult::ok( $text, $model );
	}

	/**
	 * Serialize neutral messages back into the EXACT Anthropic `messages` wire
	 * shape (key order preserved: role→content; type→text; type→source→
	 * type/media_type/data). This round-trip must reproduce the pre-Phase-A body
	 * byte-for-byte for every live call shape — a parts array, or a scalar-text
	 * message (serialized as bare-string content, e.g. a connection-test ping).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function wire_messages( GenerationRequest $request ): array {
		$out = [];

		foreach ( $request->messages() as $message ) {
			// Scalar-text content stays a bare string (Anthropic shorthand).
			if ( $message->is_scalar_text() ) {
				$parts = $message->parts();
				$first = $parts[0] ?? null;
				if ( $first instanceof GenerationTextPart ) {
					$out[] = [
						'role'    => $message->role(),
						'content' => $first->text(),
					];
					continue;
				}
			}

			$content = [];

			foreach ( $message->parts() as $part ) {
				if ( $part instanceof GenerationTextPart ) {
					$content[] = [
						'type' => 'text',
						'text' => $part->text(),
					];
				} elseif ( $part instanceof GenerationImagePart ) {
					$content[] = [
						'type'   => 'image',
						'source' => [
							'type'       => 'base64',
							'media_type' => $part->media_type(),
							'data'       => $part->base64_data(),
						],
					];
				}
			}

			$out[] = [
				'role'    => $message->role(),
				'content' => $content,
			];
		}

		return $out;
	}

	/** Scrub any secret pattern from an error string before it leaves the transport. */
	private function scrub( string $message ): string {
		return ( new Redactor() )->redact( $message )['text'];
	}
}
