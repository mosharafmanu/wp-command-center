<?php
/**
 * Content-field AI generation — BYO-key content-field provider.
 * Phase B — Universal AI Provider Runtime: provider-neutral prompt builder.
 *
 * The concrete ContentFieldProvider. It is a pure TEXT suggestion source: it owns
 * the per-kind content prompts + the JSON response parsing, and delegates
 * execution to the neutral AiRuntime. It builds a provider-agnostic
 * GenerationRequest and reads a neutral GenerationResult — it does NOT construct
 * wire messages, parse transport responses, or know any endpoint/header/body. It
 * returns a ContentFieldResult and never throws.
 *
 * Strict boundaries — this class NEVER:
 *   - writes WordPress data (no post/meta/option writes),
 *   - touches ProposalStore / ProposalApplyService / OperationExecutor / ContentManager,
 *   - performs the HTTP call or knows provider wire format (the runtime/transport do).
 */

namespace WPCommandCenter\Content;

use WPCommandCenter\Ai\AiRuntime;
use WPCommandCenter\Ai\Contract\GenerationMessage;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationTextPart;
use WPCommandCenter\Ai\JsonObjectExtractor;

defined( 'ABSPATH' ) || exit;

final class AnthropicContentProvider implements ContentFieldProvider {

	private const DEFAULT_MODEL = 'claude-sonnet-4-6'; // short structured output; cost/quality balance
	private const MAX_TOKENS    = 300;

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

	public function suggest( string $kind, array $content, array $context = [] ): ContentFieldResult {
		$model = $this->runtime->model( self::DEFAULT_MODEL );

		if ( 'title' !== $kind && 'excerpt' !== $kind ) {
			return ContentFieldResult::error( 'invalid_kind', __( 'Unknown content field kind.', 'wp-command-center' ), $this->id(), $model );
		}

		if ( ! $this->runtime->is_configured() ) {
			return ContentFieldResult::error( 'not_configured', __( 'No Anthropic API key configured.', 'wp-command-center' ), $this->id(), $model );
		}

		$request = new GenerationRequest(
			$model,
			self::MAX_TOKENS,
			[ new GenerationMessage( 'user', [ new GenerationTextPart( $this->prompt( $kind, $content ) ) ] ) ]
		);

		$result = $this->runtime->generate( $request );

		if ( ! $result->is_ok() ) {
			return ContentFieldResult::error( $result->code(), $result->message(), $this->id(), $model );
		}

		$value = self::extract_field( $result->text(), $kind );
		if ( null === $value ) {
			return ContentFieldResult::error( 'invalid_response', __( 'The provider did not return a valid content field JSON.', 'wp-command-center' ), $this->id(), $model );
		}

		return ContentFieldResult::ok( $value, $this->id(), $model );
	}

	/**
	 * Tolerant JSON extraction (shared decoder + content key-shape validation):
	 * returns the trimmed, non-empty string for the given key ('title'|'excerpt');
	 * returns null otherwise (never fabricates).
	 */
	public static function extract_field( string $text, string $key ): ?string {
		$decoded = JsonObjectExtractor::to_array( $text );
		if ( null === $decoded ) {
			return null;
		}

		$value = isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ? trim( $decoded[ $key ] ) : '';
		if ( '' === $value ) {
			return null;
		}

		return $value;
	}

	/** Build the grounded, JSON-only per-kind prompt for one post. */
	private function prompt( string $kind, array $content ): string {
		$title   = wp_strip_all_tags( (string) ( $content['title'] ?? '' ) );
		$excerpt = wp_strip_all_tags( (string) ( $content['content'] ?? '' ) );
		$current = wp_strip_all_tags( (string) ( $content['current'] ?? '' ) );

		if ( 'title' === $kind ) {
			$rules = 'You are a content assistant. Write a single compelling, accurate post title for the content below. Rules: at most 60 characters; describe ONLY what the content actually says (do not invent facts, prices, or claims); match the content language; no clickbait; no surrounding quotes. Return ONLY a JSON object of the exact shape {"title":"..."} with no markdown and no preamble.';

			return $rules
				. "\n\nCurrent title: " . $current
				. "\nContent:\n" . $excerpt;
		}

		// excerpt
		$rules = 'You are a content assistant. Write a concise post excerpt/summary for the content below. Rules: 1-2 sentences, roughly 25-55 words, at most ~320 characters; describe ONLY what the content actually says (do not invent facts, prices, or claims); match the content language; no clickbait; no surrounding quotes. Return ONLY a JSON object of the exact shape {"excerpt":"..."} with no markdown and no preamble.';

		return $rules
			. "\n\nPost title: " . $title
			. "\nCurrent excerpt: " . $current
			. "\nContent:\n" . $excerpt;
	}
}
