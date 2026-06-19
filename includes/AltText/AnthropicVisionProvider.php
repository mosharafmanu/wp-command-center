<?php
/**
 * STEP 110 — Phase 2 (AI Alt Text), Task 7B: BYO-key Anthropic vision provider.
 *
 * The first concrete AltTextProvider. WPCC's FIRST outbound AI call lives ONLY
 * here. It is a pure suggestion source:
 *   - Reads the image bytes from the attachment file path and sends them as a
 *     base64 payload to the Anthropic Messages API (no public URL needed).
 *   - BYO key: WPCC_VISION_API_KEY constant → else wpcc_alt_text_api_key option.
 *   - Model: WPCC_VISION_MODEL constant → else wpcc_alt_text_model option → a
 *     current vision-capable default (configurable up to Opus / down to Haiku).
 *   - Hard timeout; image size guard; errors returned as ProviderResult (never
 *     thrown).
 *
 * Strict boundaries — this class NEVER:
 *   - writes WordPress data (no post/meta/option writes),
 *   - touches ProposalStore / ProposalApplyService / OperationExecutor,
 *   - logs or returns the API key (every error string is run through Redactor).
 */

namespace WPCommandCenter\AltText;

use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class AnthropicVisionProvider implements AltTextProvider {

	private const API_URL        = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION    = '2023-06-01';
	private const DEFAULT_MODEL  = 'claude-sonnet-4-6';      // vision-capable; cost/quality balance for per-image alt text
	private const MAX_TOKENS     = 300;
	private const TIMEOUT        = 30;                        // hard request timeout (seconds)
	private const MAX_IMAGE_BYTES = 5242880;                  // 5 MB size guard

	private const PROMPT = 'Write concise, descriptive alt text for this image for accessibility (WCAG). Describe the visible content in one sentence, under 125 characters. Do not start with "image of" or "picture of". Return only the alt text, no quotes or preamble.';

	public function id(): string {
		return 'anthropic';
	}

	public function is_configured(): bool {
		return '' !== $this->key();
	}

	public function suggest_alt( array $image, array $context = [] ): ProviderResult {
		$model = $this->model();

		$key = $this->key();
		if ( '' === $key ) {
			return ProviderResult::error( 'not_configured', __( 'No vision API key configured.', 'wp-command-center' ), $this->id(), $model );
		}

		$path = (string) ( $image['path'] ?? '' );
		$mime = (string) ( $image['mime'] ?? '' );
		if ( '' === $path || ! is_file( $path ) ) {
			return ProviderResult::error( 'image_unreadable', __( 'Image file is not readable.', 'wp-command-center' ), $this->id(), $model );
		}
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return ProviderResult::error( 'unsupported_type', __( 'Attachment is not an image.', 'wp-command-center' ), $this->id(), $model );
		}
		$size = (int) ( @filesize( $path ) ?: 0 );
		if ( $size <= 0 || $size > self::MAX_IMAGE_BYTES ) {
			return ProviderResult::error( 'image_too_large', __( 'Image exceeds the size limit for suggestion.', 'wp-command-center' ), $this->id(), $model );
		}
		$bytes = @file_get_contents( $path );
		if ( false === $bytes || '' === $bytes ) {
			return ProviderResult::error( 'image_unreadable', __( 'Image file could not be read.', 'wp-command-center' ), $this->id(), $model );
		}

		$prompt = self::PROMPT;
		$hint   = trim( (string) ( $context['title'] ?? '' ) . ' ' . (string) ( $context['filename'] ?? '' ) );
		if ( '' !== $hint ) {
			$prompt .= ' Context hint (may be irrelevant): ' . wp_strip_all_tags( $hint );
		}

		$body = [
			'model'      => $model,
			'max_tokens' => self::MAX_TOKENS,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[
							'type'   => 'image',
							'source' => [ 'type' => 'base64', 'media_type' => $mime, 'data' => base64_encode( $bytes ) ],
						],
						[ 'type' => 'text', 'text' => $prompt ],
					],
				],
			],
		];

		$response = wp_remote_post( self::API_URL, [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return ProviderResult::error( 'request_failed', $this->scrub( $response->get_error_message() ), $this->id(), $model );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== $code ) {
			$msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			return ProviderResult::error( 'api_error_' . $code, $this->scrub( '' !== $msg ? $msg : ( 'HTTP ' . $code ) ), $this->id(), $model );
		}

		$text = '';
		if ( is_array( $data ) && isset( $data['content'][0]['text'] ) ) {
			$text = trim( (string) $data['content'][0]['text'] );
		}
		if ( '' === $text ) {
			return ProviderResult::error( 'empty_response', __( 'The provider returned no suggestion.', 'wp-command-center' ), $this->id(), $model );
		}

		// Anthropic does not return a numeric confidence; leave it null (never faked).
		return ProviderResult::ok( $text, $this->id(), $model, null );
	}

	/** Key: constant first, then option. Never logged or returned. */
	private function key(): string {
		if ( defined( 'WPCC_VISION_API_KEY' ) && '' !== (string) WPCC_VISION_API_KEY ) {
			return (string) WPCC_VISION_API_KEY;
		}
		return (string) get_option( 'wpcc_alt_text_api_key', '' );
	}

	/** Model: constant → option → safe default. */
	private function model(): string {
		if ( defined( 'WPCC_VISION_MODEL' ) && '' !== (string) WPCC_VISION_MODEL ) {
			return (string) WPCC_VISION_MODEL;
		}
		$opt = (string) get_option( 'wpcc_alt_text_model', '' );
		return '' !== $opt ? $opt : self::DEFAULT_MODEL;
	}

	/** Scrub any secret pattern from an error string before it leaves the provider. */
	private function scrub( string $message ): string {
		return ( new Redactor() )->redact( $message )['text'];
	}
}
