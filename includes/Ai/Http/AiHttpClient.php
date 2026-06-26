<?php
/**
 * Phase A — Universal AI Provider Runtime: shared HTTP layer.
 *
 * The single shared outbound HTTP client for AI generation. It wraps
 * wp_remote_post, applies the caller's timeout, normalizes a transport failure
 * or a completed exchange into an AiHttpResponse, and routes any transport-error
 * message through the existing Redactor before it leaves the client. WPCC's
 * outbound AI HTTP lives here.
 *
 * Phase A behaviour (deliberately constrained):
 *   - performs EXACTLY ONE request attempt (no retry),
 *   - performs NO endpoint validation / SSRF guard,
 *   - never logs the request body or headers, never returns the API key.
 * Retry and endpoint validation are later phases; this client is their future
 * home but ships neither, so behaviour stays byte-identical to today.
 *
 * It is provider-agnostic: it does NOT build request bodies or parse response
 * bodies (no JSON decode, no provider shape) — a transport owns that.
 *
 * The outbound call is injectable for offline testing: the optional $sender has
 * the wp_remote_post( string $url, array $args ) signature and returns the same
 * value (array on success, WP_Error on failure). It defaults to wp_remote_post.
 */

namespace WPCommandCenter\Ai\Http;

use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class AiHttpClient {

	/** @var callable(string,array):mixed */
	private $sender;

	/**
	 * @param callable(string,array):mixed|null $sender Defaults to wp_remote_post.
	 */
	public function __construct( ?callable $sender = null ) {
		$this->sender = $sender ?? static function ( string $url, array $args ) {
			return wp_remote_post( $url, $args );
		};
	}

	/** Perform exactly one POST attempt and normalize the outcome. */
	public function send( AiHttpRequest $request ): AiHttpResponse {
		$response = ( $this->sender )(
			$request->url(),
			[
				'timeout' => $request->timeout(),
				'headers' => $request->headers(),
				'body'    => $request->body(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return AiHttpResponse::failure( $this->scrub( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		return AiHttpResponse::success( $code, $raw );
	}

	/** Scrub any secret pattern from an error string before it leaves the client. */
	private function scrub( string $message ): string {
		return ( new Redactor() )->redact( $message )['text'];
	}
}
