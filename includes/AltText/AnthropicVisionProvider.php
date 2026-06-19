<?php
/**
 * STEP 110 — Phase 2 (AI Alt Text), Task 7B: BYO-key Anthropic vision provider.
 * STEP 111 — GA#2 Slice 2a: transport extracted to the shared AnthropicClient.
 *
 * The first concrete AltTextProvider. It is a pure VISION suggestion source — it
 * owns only the image-specific concerns (validation, the image+text message, alt
 * text parsing) and delegates the outbound call, key/model resolution, timeout,
 * and redaction to the shared AnthropicClient. Behaviour is unchanged from the
 * pre-extraction provider: same success/error results, same image size guard,
 * same legacy key/model names (now honoured by the client for back-compat).
 *
 * Strict boundaries — this class NEVER:
 *   - writes WordPress data (no post/meta/option writes),
 *   - touches ProposalStore / ProposalApplyService / OperationExecutor,
 *   - performs the HTTP call itself (the shared client does, and redacts errors).
 */

namespace WPCommandCenter\AltText;

use WPCommandCenter\Ai\AnthropicClient;

defined( 'ABSPATH' ) || exit;

final class AnthropicVisionProvider implements AltTextProvider {

	private const DEFAULT_MODEL   = 'claude-sonnet-4-6';      // vision-capable; cost/quality balance for per-image alt text
	private const MAX_TOKENS      = 300;
	private const MAX_IMAGE_BYTES = 5242880;                  // 5 MB size guard

	private const PROMPT = 'Write concise, descriptive alt text for this image for accessibility (WCAG). Describe the visible content in one sentence, under 125 characters. Do not start with "image of" or "picture of". Return only the alt text, no quotes or preamble.';

	private AnthropicClient $client;

	public function __construct( ?AnthropicClient $client = null ) {
		$this->client = $client ?? new AnthropicClient();
	}

	public function id(): string {
		return 'anthropic';
	}

	public function is_configured(): bool {
		return $this->client->is_configured();
	}

	public function suggest_alt( array $image, array $context = [] ): ProviderResult {
		$model = $this->client->model( self::DEFAULT_MODEL );

		if ( ! $this->client->is_configured() ) {
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

		$messages = [
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
		];

		$res = $this->client->send( $messages, self::MAX_TOKENS, $model );

		if ( empty( $res['ok'] ) ) {
			return ProviderResult::error( (string) ( $res['code'] ?? 'request_failed' ), (string) ( $res['message'] ?? '' ), $this->id(), $model );
		}

		$text = (string) ( $res['text'] ?? '' );
		if ( '' === $text ) {
			return ProviderResult::error( 'empty_response', __( 'The provider returned no suggestion.', 'wp-command-center' ), $this->id(), $model );
		}

		// Anthropic does not return a numeric confidence; leave it null (never faked).
		return ProviderResult::ok( $text, $this->id(), $model, null );
	}
}
