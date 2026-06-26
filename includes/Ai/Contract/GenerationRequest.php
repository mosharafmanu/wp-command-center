<?php
/**
 * Phase A — Universal AI Provider Runtime: neutral runtime contract.
 *
 * A provider-agnostic generation request: the model, max tokens, the ordered
 * messages, the request timeout, an (empty in Phase A) provider-options
 * passthrough, and optional metadata. Immutable and I/O-free.
 *
 * Deliberately minimal for Phase A: it carries ONLY what the current runtime
 * sends. It has NO system / temperature / stop-sequence fields, because the
 * current Anthropic request body does not emit them and Phase A must preserve
 * that body exactly. It also never carries the API key — credentials are passed
 * to the transport separately, never through the neutral contract.
 *
 * Strict boundaries — this value object NEVER:
 *   - reads WordPress options or constants,
 *   - performs HTTP or any I/O,
 *   - knows provider endpoints, headers, or API keys,
 *   - mutates after construction.
 */

namespace WPCommandCenter\Ai\Contract;

defined( 'ABSPATH' ) || exit;

final class GenerationRequest {

	private string $model;
	private int $max_tokens;

	/** @var array<int, GenerationMessage> */
	private array $messages;

	private int $timeout;

	/** @var array<string, mixed> */
	private array $provider_options;

	/** @var array<string, mixed> */
	private array $meta;

	/**
	 * @param string                        $model
	 * @param int                           $max_tokens
	 * @param array<int, GenerationMessage> $messages
	 * @param int                           $timeout          Request timeout in seconds.
	 * @param array<string, mixed>          $provider_options Opaque passthrough (empty in Phase A).
	 * @param array<string, mixed>          $meta             Optional, non-wire metadata.
	 */
	public function __construct( string $model, int $max_tokens, array $messages, int $timeout, array $provider_options = [], array $meta = [] ) {
		$this->model            = $model;
		$this->max_tokens       = $max_tokens;
		$this->messages         = array_values( $messages );
		$this->timeout          = $timeout;
		$this->provider_options = $provider_options;
		$this->meta             = $meta;
	}

	public function model(): string {
		return $this->model;
	}

	public function max_tokens(): int {
		return $this->max_tokens;
	}

	/** @return array<int, GenerationMessage> */
	public function messages(): array {
		return $this->messages;
	}

	public function timeout(): int {
		return $this->timeout;
	}

	/** @return array<string, mixed> */
	public function provider_options(): array {
		return $this->provider_options;
	}

	/** @return array<string, mixed> */
	public function meta(): array {
		return $this->meta;
	}
}
