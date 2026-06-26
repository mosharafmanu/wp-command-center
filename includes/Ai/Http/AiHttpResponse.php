<?php
/**
 * Phase A — Universal AI Provider Runtime: shared HTTP layer.
 *
 * The normalized outcome of one outbound HTTP attempt — errors as DATA, never
 * thrown. Either a transport failure (is_error, with an already-redacted
 * message) or a completed HTTP exchange (status + raw body). Immutable, I/O-free.
 * It does NOT interpret the body (no JSON parse, no provider shape) — that is the
 * transport's job.
 *
 * Strict boundaries — this value object NEVER:
 *   - reads WordPress options or constants,
 *   - performs HTTP itself,
 *   - mutates after construction.
 */

namespace WPCommandCenter\Ai\Http;

defined( 'ABSPATH' ) || exit;

final class AiHttpResponse {

	private bool $is_error;
	private string $error_message;
	private int $status;
	private string $body;

	private function __construct( bool $is_error, string $error_message, int $status, string $body ) {
		$this->is_error      = $is_error;
		$this->error_message = $error_message;
		$this->status        = $status;
		$this->body          = $body;
	}

	/** A transport-level failure (e.g. timeout / DNS). Message must already be redacted. */
	public static function failure( string $redacted_message ): self {
		return new self( true, $redacted_message, 0, '' );
	}

	/** A completed HTTP exchange — any status code, raw (unparsed) body. */
	public static function success( int $status, string $body ): self {
		return new self( false, '', $status, $body );
	}

	public function is_error(): bool {
		return $this->is_error;
	}

	public function error_message(): string {
		return $this->error_message;
	}

	public function status(): int {
		return $this->status;
	}

	public function body(): string {
		return $this->body;
	}
}
