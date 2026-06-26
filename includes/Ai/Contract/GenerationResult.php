<?php
/**
 * Phase A — Universal AI Provider Runtime: neutral runtime contract.
 *
 * The normalized outcome of a generation request — errors as DATA, never thrown.
 * Immutable and I/O-free. In Phase A it surfaces only the fields the current
 * runtime exposes (ok / text / model / error code+message); usage and finish
 * reason are intentionally NOT surfaced yet.
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

	private function __construct( bool $ok, string $text, string $model, string $code, string $message ) {
		$this->ok      = $ok;
		$this->text    = $text;
		$this->model   = $model;
		$this->code    = $code;
		$this->message = $message;
	}

	public static function ok( string $text, string $model ): self {
		return new self( true, $text, $model, '', '' );
	}

	public static function error( string $code, string $message, string $model ): self {
		return new self( false, '', $model, $code, $message );
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
}
