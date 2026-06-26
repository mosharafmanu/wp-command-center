<?php
/**
 * STEP 110 — Phase 2 (AI Alt Text), Task 7B: BYO-key vision provider.
 * Phase B — Universal AI Provider Runtime: provider-neutral prompt builder.
 *
 * The concrete AltTextProvider. It owns only the image-specific concerns
 * (validation, the image+text request, alt-text reading) and delegates execution
 * to the neutral AiRuntime. It builds a provider-agnostic GenerationRequest
 * (image part + text part) and reads a neutral GenerationResult — it does NOT
 * construct wire messages, parse transport responses, or know any endpoint/
 * header/body. Behaviour is unchanged: same success/error results, same image
 * size guard.
 *
 * Strict boundaries — this class NEVER:
 *   - writes WordPress data (no post/meta/option writes),
 *   - touches ProposalStore / ProposalApplyService / OperationExecutor,
 *   - performs the HTTP call or knows provider wire format (the runtime/transport do).
 */

namespace WPCommandCenter\AltText;

use WPCommandCenter\Ai\AiRuntime;
use WPCommandCenter\Ai\Contract\GenerationImagePart;
use WPCommandCenter\Ai\Contract\GenerationMessage;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationTextPart;

defined( 'ABSPATH' ) || exit;

final class AnthropicVisionProvider implements AltTextProvider {

	private const DEFAULT_MODEL   = 'claude-sonnet-4-6';      // vision-capable; cost/quality balance for per-image alt text
	private const MAX_TOKENS      = 300;
	private const MAX_IMAGE_BYTES = 5242880;                  // 5 MB size guard

	private const PROMPT = 'Write concise, descriptive alt text for this image for accessibility (WCAG). Describe the visible content in one sentence, under 125 characters. Do not start with "image of" or "picture of". Return only the alt text, no quotes or preamble.';

	private AiRuntime $runtime;

	public function __construct( ?AiRuntime $runtime = null ) {
		$this->runtime = $runtime ?? new AiRuntime();
	}

	public function id(): string {
		return 'anthropic';
	}

	public function is_configured(): bool {
		return $this->runtime->is_configured();
	}

	public function suggest_alt( array $image, array $context = [] ): ProviderResult {
		$model = $this->runtime->model( self::DEFAULT_MODEL );

		if ( ! $this->runtime->is_configured() ) {
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

		$request = new GenerationRequest(
			$model,
			self::MAX_TOKENS,
			[
				new GenerationMessage(
					'user',
					[
						new GenerationImagePart( $mime, base64_encode( $bytes ) ),
						new GenerationTextPart( $prompt ),
					]
				),
			]
		);

		$result = $this->runtime->generate( $request );

		if ( ! $result->is_ok() ) {
			return ProviderResult::error( $result->code(), $result->message(), $this->id(), $model );
		}

		$text = $result->text();
		if ( '' === $text ) {
			return ProviderResult::error( 'empty_response', __( 'The provider returned no suggestion.', 'wp-command-center' ), $this->id(), $model );
		}

		// Anthropic does not return a numeric confidence; leave it null (never faked).
		return ProviderResult::ok( $text, $this->id(), $model, null );
	}
}
