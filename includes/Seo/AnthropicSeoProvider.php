<?php
/**
 * STEP 111 — GA#2 Slice 2b: BYO-key SEO meta provider.
 * Phase B — Universal AI Provider Runtime: provider-neutral prompt builder.
 *
 * The concrete SeoMetaProvider. It is a pure TEXT suggestion source: it owns the
 * SEO prompt + the JSON response parsing, and delegates execution to the neutral
 * AiRuntime. It builds a provider-agnostic GenerationRequest and reads a neutral
 * GenerationResult — it does NOT construct wire messages, parse transport
 * responses, or know any endpoint/header/body. It returns a SeoMetaResult and
 * never throws.
 *
 * Strict boundaries — this class NEVER:
 *   - writes WordPress data (no post/meta/option writes),
 *   - touches ProposalStore / ProposalApplyService / OperationExecutor / SeoProvider::write,
 *   - performs the HTTP call or knows provider wire format (the runtime/transport do).
 */

namespace WPCommandCenter\Seo;

use WPCommandCenter\Ai\AiRuntime;
use WPCommandCenter\Ai\Contract\GenerationMessage;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationTextPart;
use WPCommandCenter\Ai\JsonObjectExtractor;

defined( 'ABSPATH' ) || exit;

final class AnthropicSeoProvider implements SeoMetaProvider {

	private const DEFAULT_MODEL = 'claude-sonnet-4-6'; // short structured output; cost/quality balance
	private const MAX_TOKENS    = 400;

	/** Advisory length guidance in the prompt (mirrors SeoRuntimeManager thresholds). */
	private const TITLE_MAX = 60;
	private const DESC_MIN  = 120;
	private const DESC_MAX  = 160;

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

	public function suggest_meta( array $content, array $context = [] ): SeoMetaResult {
		$model = $this->runtime->model( self::DEFAULT_MODEL );

		if ( ! $this->runtime->is_configured() ) {
			return SeoMetaResult::error( 'not_configured', __( 'No Anthropic API key configured.', 'wp-command-center' ), $this->id(), $model );
		}

		$request = new GenerationRequest(
			$model,
			self::MAX_TOKENS,
			[ new GenerationMessage( 'user', [ new GenerationTextPart( $this->prompt( $content ) ) ] ) ]
		);

		$result = $this->runtime->generate( $request );

		if ( ! $result->is_ok() ) {
			return SeoMetaResult::error( $result->code(), $result->message(), $this->id(), $model );
		}

		$parsed = self::extract_meta( $result->text() );
		if ( null === $parsed ) {
			return SeoMetaResult::error( 'invalid_response', __( 'The provider did not return valid SEO meta JSON.', 'wp-command-center' ), $this->id(), $model );
		}

		return SeoMetaResult::ok( $parsed['meta_title'], $parsed['meta_description'], $this->id(), $model );
	}

	/**
	 * Tolerant JSON extraction (shared decoder + SEO key-shape validation): requires
	 * both keys to be non-empty strings; returns null otherwise (never fabricates).
	 *
	 * @return array{meta_title:string,meta_description:string}|null
	 */
	public static function extract_meta( string $text ): ?array {
		$decoded = JsonObjectExtractor::to_array( $text );
		if ( null === $decoded ) {
			return null;
		}

		$title = isset( $decoded['meta_title'] ) && is_string( $decoded['meta_title'] ) ? trim( $decoded['meta_title'] ) : '';
		$desc  = isset( $decoded['meta_description'] ) && is_string( $decoded['meta_description'] ) ? trim( $decoded['meta_description'] ) : '';
		if ( '' === $title || '' === $desc ) {
			return null;
		}

		return [ 'meta_title' => $title, 'meta_description' => $desc ];
	}

	/** Build the grounded, JSON-only prompt for one page. */
	private function prompt( array $content ): string {
		$title        = wp_strip_all_tags( (string) ( $content['title'] ?? '' ) );
		$excerpt      = wp_strip_all_tags( (string) ( $content['content'] ?? '' ) );
		$cur_title    = wp_strip_all_tags( (string) ( $content['current_title'] ?? '' ) );
		$cur_desc     = wp_strip_all_tags( (string) ( $content['current_description'] ?? '' ) );

		$rules = sprintf(
			'You are an SEO assistant. Write an SEO meta title and meta description for the page below. Rules: meta_title at most %1$d characters; meta_description between %2$d and %3$d characters; describe ONLY what the content actually says (do not invent facts, prices, or claims); match the content language; no clickbait; no surrounding quotes. Return ONLY a JSON object of the exact shape {"meta_title": "...", "meta_description": "..."} with no markdown and no preamble.',
			self::TITLE_MAX,
			self::DESC_MIN,
			self::DESC_MAX
		);

		return $rules
			. "\n\nPage title: " . $title
			. "\nExisting SEO title: " . $cur_title
			. "\nExisting meta description: " . $cur_desc
			. "\nContent:\n" . $excerpt;
	}
}
