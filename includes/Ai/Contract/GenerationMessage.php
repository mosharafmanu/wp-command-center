<?php
/**
 * Phase A — Universal AI Provider Runtime: neutral runtime contract.
 *
 * One conversation turn: a role plus an ordered list of parts. In Phase A the
 * parts are a sealed set of exactly GenerationTextPart and GenerationImagePart,
 * and the role is 'user' or 'assistant'. Immutable, I/O-free, provider-agnostic.
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

	/**
	 * @param string                                              $role  'user' | 'assistant'
	 * @param array<int, GenerationTextPart|GenerationImagePart>  $parts Ordered parts.
	 */
	public function __construct( string $role, array $parts ) {
		$this->role  = $role;
		$this->parts = array_values( $parts );
	}

	public function role(): string {
		return $this->role;
	}

	/** @return array<int, GenerationTextPart|GenerationImagePart> */
	public function parts(): array {
		return $this->parts;
	}
}
