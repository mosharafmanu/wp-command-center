<?php
/**
 * Universal AI Provider Runtime: token usage reported by a provider.
 *
 * The count of tokens one generation actually consumed, as the provider stated it —
 * never estimated, never inferred from the text. Providers disagree about the field
 * names (Anthropic says input_tokens/output_tokens, OpenAI-compatible endpoints say
 * prompt_tokens/completion_tokens) and some return nothing at all, so this object
 * normalizes what was given and, crucially, keeps "the provider did not tell us"
 * distinguishable from "the provider told us zero". `unknown()` is not `0`.
 *
 * It carries COUNTS and an opaque provider request id — never a prompt, never the
 * generated text, never a credential. Nothing here is derived from message content.
 *
 * Strict boundaries — this value object NEVER:
 *   - reads WordPress options or constants,
 *   - performs HTTP or any I/O,
 *   - mutates after construction,
 *   - estimates or fabricates a number the provider did not return.
 */

namespace WPCommandCenter\Ai\Contract;

defined( 'ABSPATH' ) || exit;

final class GenerationUsage {

	private bool $reported;
	private int $input;
	private int $output;
	private int $cached;
	private string $request_id;

	private function __construct( bool $reported, int $input, int $output, int $cached, string $request_id ) {
		$this->reported   = $reported;
		$this->input      = $input;
		$this->output     = $output;
		$this->cached     = $cached;
		$this->request_id = $request_id;
	}

	/** The provider returned no usage block — the honest "we don't know" state. */
	public static function unknown(): self {
		return new self( false, 0, 0, 0, '' );
	}

	/** The provider reported usage. Counts are clamped to non-negative integers. */
	public static function reported( int $input, int $output, int $cached = 0, string $request_id = '' ): self {
		return new self( true, max( 0, $input ), max( 0, $output ), max( 0, $cached ), $request_id );
	}

	/**
	 * Normalize a provider's raw usage block.
	 *
	 * Accepts either vocabulary and tolerates junk: a non-array, a missing block, or
	 * fields holding strings/nulls/negatives all degrade to unknown() or to zero rather
	 * than propagating a nonsense total. A block that is present but contains no
	 * recognizable numeric field is treated as NOT reported — a provider that sends
	 * `usage: {}` has told us nothing, and recording that as a real zero would quietly
	 * understate a customer's consumption.
	 *
	 * @param mixed  $usage      The decoded `usage` value from a provider response.
	 * @param string $request_id Opaque provider request/message id, if the response had one.
	 */
	public static function from_provider( $usage, string $request_id = '' ): self {
		if ( ! is_array( $usage ) ) {
			return self::unknown();
		}

		$input  = self::pick( $usage, [ 'input_tokens', 'prompt_tokens' ] );
		$output = self::pick( $usage, [ 'output_tokens', 'completion_tokens' ] );
		$cached = self::pick( $usage, [ 'cache_read_input_tokens', 'cached_tokens' ] );

		if ( null === $input && null === $output ) {
			return self::unknown();
		}

		return self::reported( (int) ( $input ?? 0 ), (int) ( $output ?? 0 ), (int) ( $cached ?? 0 ), $request_id );
	}

	/**
	 * First numerically-usable value among $keys, or null when none is present.
	 *
	 * @param array<string,mixed> $usage
	 * @param string[]            $keys
	 */
	private static function pick( array $usage, array $keys ): ?int {
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $usage ) && is_numeric( $usage[ $k ] ) ) {
				return (int) $usage[ $k ];
			}
		}
		return null;
	}

	/** Whether the provider actually reported usage for this call. */
	public function is_reported(): bool {
		return $this->reported;
	}

	public function input_tokens(): int {
		return $this->input;
	}

	public function output_tokens(): int {
		return $this->output;
	}

	/** Tokens served from the provider's prompt cache, when it says so (0 otherwise). */
	public function cached_tokens(): int {
		return $this->cached;
	}

	/** Input + output. Cached tokens are already counted within input, so not added again. */
	public function total_tokens(): int {
		return $this->input + $this->output;
	}

	/** Opaque provider request/message id — provenance only, never displayed as content. */
	public function request_id(): string {
		return $this->request_id;
	}
}
