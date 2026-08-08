<?php
/**
 * Phase A — Universal AI Provider Runtime: neutral runtime contract.
 *
 * The normalized outcome of a generation request — errors as DATA, never thrown.
 * Immutable and I/O-free. It surfaces ok / text / model / error code+message, plus the
 * token usage the provider reported (GenerationUsage; `unknown()` when the provider
 * said nothing, which is deliberately not the same as zero). Finish reason is still
 * not surfaced.
 *
 * Strict boundaries — this value object NEVER:
 *   - reads WordPress options or constants,
 *   - performs HTTP or any I/O,
 *   - mutates after construction.
 */

namespace WPCommandCenter\Ai\Contract;

defined( 'ABSPATH' ) || exit;

final class GenerationResult {

	private bool $ok;
	private string $text;
	private string $model;
	private string $code;
	private string $message;
	private GenerationUsage $usage;

	private function __construct( bool $ok, string $text, string $model, string $code, string $message, ?GenerationUsage $usage = null ) {
		$this->ok      = $ok;
		$this->text    = $text;
		$this->model   = $model;
		$this->code    = $code;
		$this->message = $message;
		$this->usage   = $usage ?? GenerationUsage::unknown();
	}

	/**
	 * @param GenerationUsage|null $usage Usage the provider reported, when it did. Optional
	 *                                    so every existing two-argument caller is unchanged.
	 */
	public static function ok( string $text, string $model, ?GenerationUsage $usage = null ): self {
		return new self( true, $text, $model, '', '', $usage );
	}

	public static function error( string $code, string $message, string $model, ?GenerationUsage $usage = null ): self {
		return new self( false, '', $model, $code, $message, $usage );
	}

	public function is_ok(): bool {
		return $this->ok;
	}

	public function text(): string {
		return $this->text;
	}

	public function model(): string {
		return $this->model;
	}

	public function code(): string {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	/** Token usage as the provider reported it; unknown() when it reported none. */
	public function usage(): GenerationUsage {
		return $this->usage;
	}
}
