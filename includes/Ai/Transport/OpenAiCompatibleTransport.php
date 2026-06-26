<?php
/**
 * Phase D — Universal AI Provider Runtime: OpenAI-compatible transport.
 *
 * The sole owner of OpenAI-compatible (Chat Completions) wire knowledge —
 * endpoint construction, auth header, request/response/error shape — for the
 * openai-compatible dialect (OpenAI, Azure, OpenRouter, Groq, Together, Fireworks,
 * DeepInfra, Mistral, Perplexity, xAI, Ollama, LM Studio, vLLM, custom gateways).
 * It translates a neutral GenerationRequest via OpenAiCompatibleCodec and the
 * per-provider OpenAiCompatProfiles, runs the single HTTP attempt through the
 * shared AiHttpClient, and returns a neutral GenerationResult — errors as DATA.
 *
 * Phase A boundaries preserved: ONE attempt (no retry), NO endpoint/SSRF policy,
 * NO logging, key never returned (errors go through Redactor via the HTTP client
 * and here). It reads NO WordPress options — the runtime hands it the resolved
 * key, endpoint, provider, and deployment.
 *
 * Strict boundaries — this class NEVER:
 *   - reads WordPress options/constants,
 *   - performs retry or endpoint validation,
 *   - writes WordPress data or touches the proposal/operation engine,
 *   - logs or returns the API key.
 */

namespace WPCommandCenter\Ai\Transport;

use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationResult;
use WPCommandCenter\Ai\Http\AiEndpointGuard;
use WPCommandCenter\Ai\Http\AiHttpClient;
use WPCommandCenter\Ai\Http\AiHttpRequest;
use WPCommandCenter\Ai\Platform\ProviderCatalog;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class OpenAiCompatibleTransport {

	private const DEFAULT_TIMEOUT = 30;

	private AiHttpClient $http;

	/** @var callable(string,bool):array Endpoint validator; injectable for tests. */
	private $guard;

	/**
	 * @param AiHttpClient|null              $http  Injectable for tests; defaults to the real client.
	 * @param callable(string,bool):array|null $guard Endpoint validator; defaults to AiEndpointGuard.
	 */
	public function __construct( ?AiHttpClient $http = null, ?callable $guard = null ) {
		$this->http  = $http ?? new AiHttpClient();
		$this->guard = $guard ?? [ AiEndpointGuard::class, 'validate' ];
	}

	/**
	 * Generate against an OpenAI-compatible endpoint.
	 *
	 * @param GenerationRequest $request    Neutral request.
	 * @param string            $api_key    Resolved key (the transport never reads options).
	 * @param string            $endpoint   Base URL (e.g. https://api.openai.com/v1).
	 * @param string            $provider   Provider id (selects the compat profile).
	 * @param string            $deployment Azure deployment name (ignored by others).
	 */
	public function generate( GenerationRequest $request, string $api_key, string $endpoint, string $provider, string $deployment = '' ): GenerationResult {
		$model = $request->model();

		if ( '' === $api_key ) {
			return GenerationResult::error( 'not_configured', __( 'No API key configured for this provider.', 'wp-command-center' ), $model );
		}
		if ( '' === $endpoint ) {
			return GenerationResult::error( 'not_configured', __( 'No endpoint configured for this provider.', 'wp-command-center' ), $model );
		}

		$profile = OpenAiCompatProfiles::for_provider( $provider );
		$url     = $this->url( $endpoint, $deployment, $profile );

		// SSRF guard: validate the admin-supplied endpoint before any outbound call.
		// Declared local providers (Ollama/LM Studio/vLLM) may use loopback/private.
		$verdict = ( $this->guard )( $url, ProviderCatalog::is_local( $provider ) );
		if ( empty( $verdict['ok'] ) ) {
			return GenerationResult::error( 'endpoint_blocked', $this->scrub( (string) ( $verdict['message'] ?? '' ) ), $model );
		}

		$http_request = new AiHttpRequest(
			$url,
			$this->headers( $api_key, $profile ),
			(string) wp_json_encode( OpenAiCompatibleCodec::request_body( $request, $profile ) ),
			$request->timeout() > 0 ? $request->timeout() : self::DEFAULT_TIMEOUT,
			0 // never follow redirects on a custom AI endpoint (no 3xx-bounce into private space).
		);

		$response = $this->http->send( $http_request );

		if ( $response->is_error() ) {
			// error_message() is already redacted by the HTTP client.
			return GenerationResult::error( 'request_failed', $response->error_message(), $model );
		}

		$code = $response->status();
		$data = json_decode( $response->body(), true );

		if ( 200 !== $code ) {
			$msg = OpenAiCompatibleCodec::error_message( $data );
			return GenerationResult::error( 'api_error_' . $code, $this->scrub( '' !== $msg ? $msg : ( 'HTTP ' . $code ) ), $model );
		}

		return GenerationResult::ok( OpenAiCompatibleCodec::parse_text( $data ), $model );
	}

	/** Build the request URL from the base endpoint + profile (Azure deployment/api-version aware). */
	private function url( string $endpoint, string $deployment, array $profile ): string {
		$base = rtrim( $endpoint, '/' );
		$path = (string) ( $profile['chat_path'] ?? '/chat/completions' );

		if ( ! empty( $profile['deploy_path'] ) && '' !== $deployment ) {
			$url = $base . '/openai/deployments/' . rawurlencode( $deployment ) . $path;
		} else {
			$url = $base . $path;
		}

		$api_version = (string) ( $profile['api_version'] ?? '' );
		if ( '' !== $api_version ) {
			$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'api-version=' . rawurlencode( $api_version );
		}

		return $url;
	}

	/**
	 * Build request headers from the profile auth + any static extra headers.
	 *
	 * @return array<string,string>
	 */
	private function headers( string $api_key, array $profile ): array {
		$headers = [ 'content-type' => 'application/json' ];

		if ( 'api-key' === ( $profile['auth'] ?? 'bearer' ) ) {
			$headers['api-key'] = $api_key;
		} else {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$extra = isset( $profile['headers'] ) && is_array( $profile['headers'] ) ? $profile['headers'] : [];
		foreach ( $extra as $k => $v ) {
			$headers[ (string) $k ] = (string) $v;
		}

		return $headers;
	}

	/** Scrub any secret pattern from an error string before it leaves the transport. */
	private function scrub( string $message ): string {
		return ( new Redactor() )->redact( $message )['text'];
	}
}
