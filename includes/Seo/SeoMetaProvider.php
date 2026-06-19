<?php
/**
 * STEP 111 — GA#2 Slice 2b: SEO meta provider interface.
 *
 * The single contract every SEO meta suggestion provider implements. A provider is
 * a PURE SUGGESTION SOURCE: given a page's content, it returns a SeoMetaResult. It
 * never writes WordPress data, never touches ProposalStore / OperationExecutor /
 * SeoProvider::write, and never applies anything. AI Engine / Pro proxy adapters
 * slot in behind this same interface.
 */

namespace WPCommandCenter\Seo;

defined( 'ABSPATH' ) || exit;

interface SeoMetaProvider {

	/** Stable provider id (provenance). */
	public function id(): string;

	/** Config-only check (no outbound call). */
	public function is_configured(): bool;

	/**
	 * Produce SEO meta (title + description) suggestions for one page.
	 *
	 * @param array $content {
	 *     @type int    $post_id
	 *     @type string $title            The page/post title.
	 *     @type string $content          Plain-text content excerpt (already bounded).
	 *     @type string $current_title    Existing SEO title (may be empty).
	 *     @type string $current_description Existing meta description (may be empty).
	 *     @type string $lang             Optional language hint.
	 * }
	 * @param array $context Optional hints.
	 * @return SeoMetaResult
	 */
	public function suggest_meta( array $content, array $context = [] ): SeoMetaResult;
}
