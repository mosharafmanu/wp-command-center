<?php
/**
 * Phase A — Universal AI Provider Runtime: neutral runtime contract.
 *
 * A single text segment of a generation message. Immutable, I/O-free, and
 * provider-agnostic: it carries text only and knows nothing of endpoints,
 * headers, keys, options, or any provider's wire format.
 *
 * Strict boundaries — this value object NEVER:
 *   - reads WordPress options or constants,
 *   - performs HTTP or any I/O,
 *   - mutates after construction.
 */

namespace WPCommandCenter\Ai\Contract;

defined( 'ABSPATH' ) || exit;

final class GenerationTextPart {

	private string $text;

	public function __construct( string $text ) {
		$this->text = $text;
	}

	public function text(): string {
		return $this->text;
	}
}
