<?php
/**
 * STEP 110 — Phase 2 (AI Alt Text), Task 7B: provider interface.
 *
 * The single contract every alt-text suggestion provider implements. A provider
 * is a PURE SUGGESTION SOURCE: given an image, it returns a ProviderResult. It
 * MUST NOT mutate WordPress, write the Proposal Store, call the engine, or have
 * any side effect other than its own outbound model call. Errors are returned as
 * data (never thrown). Implementations: AnthropicVisionProvider (Task 7B); future
 * AI Engine / Pro proxy adapters slot in behind this same interface.
 */

namespace WPCommandCenter\AltText;

defined( 'ABSPATH' ) || exit;

interface AltTextProvider {

	/** Stable provider id, e.g. 'anthropic'. */
	public function id(): string;

	/** True when the provider has the configuration (key) it needs to run. */
	public function is_configured(): bool;

	/**
	 * Produce an alt-text suggestion for one image.
	 *
	 * @param array $image {
	 *     @type int    $attachment_id The attachment id (provenance only).
	 *     @type string $path          Absolute file path to the image bytes.
	 *     @type string $mime          Image MIME type (e.g. image/jpeg).
	 *     @type int    $width         Optional pixel width.
	 *     @type int    $height        Optional pixel height.
	 * }
	 * @param array $context Optional prompt hints { title?, filename? }.
	 * @return ProviderResult ok() with text/model, or error() — never throws.
	 */
	public function suggest_alt( array $image, array $context = [] ): ProviderResult;
}
