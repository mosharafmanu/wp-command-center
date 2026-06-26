<?php
/**
 * Phase A — Universal AI Provider Runtime: neutral runtime contract.
 *
 * A single image segment of a generation message, carried as a media type plus
 * already-base64-encoded data. Immutable, I/O-free, and provider-agnostic: it
 * does NOT read the file, encode bytes, or know any provider's image wire shape
 * — the caller supplies the media type and base64 payload.
 *
 * Strict boundaries — this value object NEVER:
 *   - reads WordPress options or constants,
 *   - performs HTTP or any I/O (including filesystem reads),
 *   - mutates after construction.
 */

namespace WPCommandCenter\Ai\Contract;

defined( 'ABSPATH' ) || exit;

final class GenerationImagePart {

	private string $media_type;
	private string $base64_data;

	public function __construct( string $media_type, string $base64_data ) {
		$this->media_type  = $media_type;
		$this->base64_data = $base64_data;
	}

	public function media_type(): string {
		return $this->media_type;
	}

	public function base64_data(): string {
		return $this->base64_data;
	}
}
