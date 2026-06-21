<?php
/**
 * Content-field AI generation — BYO-key Anthropic content-field provider.
 *
 * The first concrete ContentFieldProvider. It is a pure TEXT suggestion source: it
 * owns the per-kind content prompts + the JSON response parsing, and delegates the
 * outbound call, key/model resolution, timeout, and redaction to the shared
 * AnthropicClient. It returns suggestions as a ContentFieldResult — never throws.
 *
 * Strict boundaries — this class NEVER:
 *   - writes WordPress data (no post/meta/option writes),
 *   - touches ProposalStore / ProposalApplyService / OperationExecutor / ContentManager,
 *   - performs the HTTP call itself (the shared client does, and redacts errors).
 */

namespace WPCommandCenter\Content;

use WPCommandCenter\Ai\AnthropicClient;

defined( 'ABSPATH' ) || exit;

final class AnthropicContentProvider implements ContentFieldProvider {

	private const DEFAULT_MODEL = 'claude-sonnet-4-6'; // short structured output; cost/quality balance
	private const MAX_TOKENS    = 300;

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

	public function suggest( string $kind, array $content, array $context = [] ): ContentFieldResult {
		$model = $this->client->model( self::DEFAULT_MODEL );

		if ( 'title' !== $kind && 'excerpt' !== $kind ) {
			return ContentFieldResult::error( 'invalid_kind', __( 'Unknown content field kind.', 'wp-command-center' ), $this->id(), $model );
		}

		if ( ! $this->client->is_configured() ) {
			return ContentFieldResult::error( 'not_configured', __( 'No Anthropic API key configured.', 'wp-command-center' ), $this->id(), $model );
		}

		$messages = [
			[
				'role'    => 'user',
				'content' => [
					[ 'type' => 'text', 'text' => $this->prompt( $kind, $content ) ],
				],
			],
		];

		$res = $this->client->send( $messages, self::MAX_TOKENS, $model );

		if ( empty( $res['ok'] ) ) {
			return ContentFieldResult::error( (string) ( $res['code'] ?? 'request_failed' ), (string) ( $res['message'] ?? '' ), $this->id(), $model );
		}

		$value = self::extract_field( (string) ( $res['text'] ?? '' ), $kind );
		if ( null === $value ) {
			return ContentFieldResult::error( 'invalid_response', __( 'The provider did not return a valid content field JSON.', 'wp-command-center' ), $this->id(), $model );
		}

		return ContentFieldResult::ok( $value, $this->id(), $model );
	}

	/**
	 * Tolerant JSON extraction: accepts a bare JSON object, a ```json fenced block,
	 * or JSON embedded in prose (first "{" … last "}"). Returns the trimmed,
	 * non-empty string for the given key ('title'|'excerpt'); returns null otherwise
	 * (never fabricates).
	 */
	public static function extract_field( string $text, string $key ): ?string {
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}

		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) ) {
			// Strip a leading/trailing markdown fence, then try the first {...} span.
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false === $start || false === $end || $end <= $start ) {
				return null;
			}
			$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}
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
