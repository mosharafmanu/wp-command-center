<?php
/**
 * Phase A — Universal AI Provider Runtime: neutral runtime contract.
 *
 * One conversation turn: a role plus an ordered list of parts. In Phase A the
 * parts are a sealed set of exactly GenerationTextPart and GenerationImagePart,
 * and the role is 'user' or 'assistant'. Immutable, I/O-free, provider-agnostic.
 *
 * Content form. A message's content can be supplied two ways, and the original
 * form must be preserved for byte-identical output:
 *   - PARTS (default): an ordered list of parts (the generation call shape).
 *   - SCALAR TEXT: a single plain text string (e.g. a connection-test "ping").
 * When $scalar_text is true the message carries exactly one GenerationTextPart
 * and is serialized as scalar content, not a parts array. This is a neutral
 * distinction (plain-text vs structured content), not a wire detail.
 *
 * Strict boundaries — this value object NEVER:
 *   - reads WordPress options or constants,
 *   - performs HTTP or any I/O,
 *   - mutates after construction.
 */

namespace WPCommandCenter\Ai\Contract;

defined( 'ABSPATH' ) || exit;

final class GenerationMessage {

	private string $role;

	/** @var array<int, GenerationTextPart|GenerationImagePart> */
	private array $parts;

	private bool $scalar_text;

	/**
	 * @param string                                              $role        'user' | 'assistant'
	 * @param array<int, GenerationTextPart|GenerationImagePart>  $parts       Ordered parts.
	 * @param bool                                                $scalar_text True when content was a single plain text string.
	 */
	public function __construct( string $role, array $parts, bool $scalar_text = false ) {
		$this->role        = $role;
		$this->parts       = array_values( $parts );
		$this->scalar_text = $scalar_text;
	}

	public function role(): string {
		return $this->role;
	}

	/** @return array<int, GenerationTextPart|GenerationImagePart> */
	public function parts(): array {
		return $this->parts;
	}

	/** True when content is a single plain text string (serialize as scalar, not a parts array). */
	public function is_scalar_text(): bool {
		return $this->scalar_text;
	}
}
