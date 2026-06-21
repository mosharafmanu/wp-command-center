<?php
/**
 * Content-field AI generation — provider interface.
 *
 * The single contract every content-field suggestion provider implements. A provider
 * is a PURE SUGGESTION SOURCE: given a post's content, it returns a ContentFieldResult
 * for one core field (post_title or post_excerpt). It never writes WordPress data,
 * never touches ProposalStore / OperationExecutor / ContentManager, and never applies
 * anything. AI Engine / Pro proxy adapters slot in behind this same interface.
 */

namespace WPCommandCenter\Content;

defined( 'ABSPATH' ) || exit;

interface ContentFieldProvider {

	/** Stable provider id (provenance). */
	public function id(): string;

	/** Config-only check (no outbound call). */
	public function is_configured(): bool;

	/**
	 * Produce a suggestion for one core content field of one post.
	 *
	 * @param string $kind    The field to generate: 'title' or 'excerpt'.
	 * @param array  $content {
	 *     @type string $title   The current post title.
	 *     @type string $content Plain-text content excerpt (already bounded).
	 *     @type string $current The current value of the field being generated.
	 * }
	 * @param array  $context Optional hints.
	 * @return ContentFieldResult
	 */
	public function suggest( string $kind, array $content, array $context = [] ): ContentFieldResult;
}
