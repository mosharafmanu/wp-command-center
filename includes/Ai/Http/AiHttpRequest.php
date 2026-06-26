<?php
/**
 * Phase A — Universal AI Provider Runtime: shared HTTP layer.
 *
 * A generic outbound HTTP POST descriptor: URL, headers, body, timeout. It is
 * provider-agnostic — it knows nothing of Anthropic, model names, or wire bodies;
 * a transport builds it. Immutable, I/O-free.
 *
 * Strict boundaries — this value object NEVER:
 *   - reads WordPress options or constants,
 *   - performs HTTP itself,
 *   - mutates after construction.
 */

namespace WPCommandCenter\Ai\Http;

defined( 'ABSPATH' ) || exit;

final class AiHttpRequest {

	private string $url;

	/** @var array<string, string> */
	private array $headers;

	private string $body;
	private int $timeout;

	/**
	 * @param string                 $url
	 * @param array<string, string>  $headers
	 * @param string                 $body    Already-encoded request body.
	 * @param int                    $timeout Seconds.
	 */
	public function __construct( string $url, array $headers, string $body, int $timeout ) {
		$this->url     = $url;
		$this->headers = $headers;
		$this->body    = $body;
		$this->timeout = $timeout;
	}

	public function url(): string {
		return $this->url;
	}

	/** @return array<string, string> */
	public function headers(): array {
		return $this->headers;
	}

	public function body(): string {
		return $this->body;
	}

	public function timeout(): int {
		return $this->timeout;
	}
}
