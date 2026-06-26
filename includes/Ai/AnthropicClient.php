<?php
/**
 * STEP 111 — GA#2 Slice 2a: shared Anthropic transport.
 * Phase A — Universal AI Provider Runtime: re-roled as the back-compat FACADE.
 *
 * Historically this class owned the outbound Anthropic call directly. As of
 * Phase A the wire now lives in AnthropicTransport (Anthropic format) over the
 * shared AiHttpClient (one HTTP attempt + redaction), driven by the neutral
 * runtime contract (GenerationRequest/Result). This class is now a thin FACADE
 * that preserves 100% of its previous public surface so every existing caller
 * — the SEO / Alt Text / Content providers, ConnectionTester, AdoptionStatus —
 * keeps working with no edits and the outbound request stays byte-identical.
 *
 * The facade's job, and only job:
 *   1. resolve the key/model exactly as before (canonical → legacy → default),
 *   2. translate the legacy Anthropic-shaped `messages` into the neutral contract,
 *   3. delegate to AnthropicTransport,
 *   4. translate the neutral GenerationResult back into the legacy return array.
 * It surfaces no new fields (no usage / finish reason) in Phase A.
 *
 * Strict boundaries — this class NEVER:
 *   - writes WordPress data (no post/meta/option writes),
 *   - touches ProposalStore / ProposalApplyService / OperationExecutor,
 *   - performs HTTP itself (the transport/HTTP client do, and redact errors).
 *
 * Key resolution (first non-empty wins):
 *   1. WPCC_ANTHROPIC_API_KEY constant   (canonical, shared)
 *   2. wpcc_anthropic_api_key option     (canonical, shared)
 *   3. WPCC_VISION_API_KEY constant      (legacy GA#1 — back-compat)
 *   4. wpcc_alt_text_api_key option      (legacy GA#1 — back-compat)
 *
 * Model resolution (first non-empty wins, else the caller's default):
 *   1. WPCC_ANTHROPIC_MODEL constant / wpcc_anthropic_model option   (canonical)
 *   2. WPCC_VISION_MODEL constant / wpcc_alt_text_model option       (legacy)
 *   3. $default supplied by the caller
 */

namespace WPCommandCenter\Ai;

use WPCommandCenter\Ai\Contract\GenerationImagePart;
use WPCommandCenter\Ai\Contract\GenerationMessage;
use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationResult;
use WPCommandCenter\Ai\Contract\GenerationTextPart;
use WPCommandCenter\Ai\Transport\AnthropicTransport;

defined( 'ABSPATH' ) || exit;

final class AnthropicClient {

	private const DEFAULT_TIMEOUT = 30; // hard request timeout (seconds)

	private AnthropicTransport $transport;

	/**
	 * @param AnthropicTransport|null $transport Injectable for tests; defaults to the
	 *                                           real Anthropic transport. Existing callers
	 *                                           use the no-arg form unchanged.
	 */
	public function __construct( ?AnthropicTransport $transport = null ) {
		$this->transport = $transport ?? new AnthropicTransport();
	}

	/** True when any key (canonical or legacy) is configured. No outbound call. */
	public function is_configured(): bool {
		return '' !== $this->key();
	}

	/**
	 * Non-secret label of which source provided the key (diagnostics/tests):
	 * anthropic_constant | anthropic_option | vision_constant | vision_option | none.
	 * NEVER returns the key itself.
	 */
	public function key_source(): string {
		if ( defined( 'WPCC_ANTHROPIC_API_KEY' ) && '' !== (string) WPCC_ANTHROPIC_API_KEY ) {
			return 'anthropic_constant';
		}
		if ( '' !== (string) get_option( 'wpcc_anthropic_api_key', '' ) ) {
			return 'anthropic_option';
		}
		if ( defined( 'WPCC_VISION_API_KEY' ) && '' !== (string) WPCC_VISION_API_KEY ) {
			return 'vision_constant';
		}
		if ( '' !== (string) get_option( 'wpcc_alt_text_api_key', '' ) ) {
			return 'vision_option';
		}
		return 'none';
	}

	/**
	 * Resolve the model: canonical constant/option → legacy constant/option →
	 * the caller-supplied default (preserves each feature's own default).
	 */
	public function model( string $default = '' ): string {
		if ( defined( 'WPCC_ANTHROPIC_MODEL' ) && '' !== (string) WPCC_ANTHROPIC_MODEL ) {
			return (string) WPCC_ANTHROPIC_MODEL;
		}
		$canon = (string) get_option( 'wpcc_anthropic_model', '' );
		if ( '' !== $canon ) {
			return $canon;
		}
		if ( defined( 'WPCC_VISION_MODEL' ) && '' !== (string) WPCC_VISION_MODEL ) {
			return (string) WPCC_VISION_MODEL;
		}
		$legacy = (string) get_option( 'wpcc_alt_text_model', '' );
		if ( '' !== $legacy ) {
			return $legacy;
		}
		return $default;
	}

	/**
	 * Send a Messages request. Caller supplies the already-built `messages` content
	 * (text and/or image blocks), max_tokens, and the resolved model. Returns a
	 * normalized array — never throws:
	 *   success:  [ 'ok' => true,  'text' => string, 'model' => string ]
	 *   failure:  [ 'ok' => false, 'code' => string, 'message' => string (redacted), 'model' => string ]
	 *
	 * @param array<int,array<string,mixed>> $messages Anthropic `messages` array.
	 * @param array<string,mixed>            $opts     { timeout?: int }
	 * @return array<string,mixed>
	 */
	public function send( array $messages, int $max_tokens, string $model, array $opts = [] ): array {
		$timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : self::DEFAULT_TIMEOUT;

		$request = new GenerationRequest( $model, $max_tokens, $this->neutral_messages( $messages ), $timeout );
		$result  = $this->generate( $request );

		if ( $result->is_ok() ) {
			return [ 'ok' => true, 'text' => $result->text(), 'model' => $result->model() ];
		}

		return [ 'ok' => false, 'code' => $result->code(), 'message' => $result->message(), 'model' => $result->model() ];
	}

	/**
	 * Execute a neutral GenerationRequest and return the neutral GenerationResult.
	 * Resolves the key and delegates to the transport — the single execution path
	 * shared by the legacy send() above and the AiRuntime used by feature code.
	 */
	public function generate( GenerationRequest $request ): GenerationResult {
		return $this->transport->generate( $request, $this->key() );
	}

	/**
	 * Translate legacy Anthropic-shaped `messages` into neutral GenerationMessages.
	 * The transport re-serializes these back to the identical wire shape, so the
	 * round-trip is lossless for every live call shape:
	 *   - content as a parts array  → text / image parts (generation calls),
	 *   - content as a plain string → a single scalar-text message (test pings).
	 *
	 * @param array<int,array<string,mixed>> $messages
	 * @return array<int, GenerationMessage>
	 */
	private function neutral_messages( array $messages ): array {
		$out = [];

		foreach ( $messages as $message ) {
			$role    = (string) ( $message['role'] ?? 'user' );
			$content = $message['content'] ?? [];

			// Bare-string content (e.g. a connection-test "ping") — preserve as scalar text.
			if ( is_string( $content ) ) {
				$out[] = new GenerationMessage( $role, [ new GenerationTextPart( $content ) ], true );
				continue;
			}

			$parts = [];

			if ( is_array( $content ) ) {
				foreach ( $content as $block ) {
					$type = is_array( $block ) ? (string) ( $block['type'] ?? '' ) : '';
					if ( 'text' === $type ) {
						$parts[] = new GenerationTextPart( (string) ( $block['text'] ?? '' ) );
					} elseif ( 'image' === $type ) {
						$source  = isset( $block['source'] ) && is_array( $block['source'] ) ? $block['source'] : [];
						$parts[] = new GenerationImagePart( (string) ( $source['media_type'] ?? '' ), (string) ( $source['data'] ?? '' ) );
					}
				}
			}

			$out[] = new GenerationMessage( $role, $parts );
		}

		return $out;
	}

	/** Key: canonical constant/option → legacy constant/option. Never logged/returned. */
	private function key(): string {
		if ( defined( 'WPCC_ANTHROPIC_API_KEY' ) && '' !== (string) WPCC_ANTHROPIC_API_KEY ) {
			return (string) WPCC_ANTHROPIC_API_KEY;
		}
		$canon = (string) get_option( 'wpcc_anthropic_api_key', '' );
		if ( '' !== $canon ) {
			return $canon;
		}
		if ( defined( 'WPCC_VISION_API_KEY' ) && '' !== (string) WPCC_VISION_API_KEY ) {
			return (string) WPCC_VISION_API_KEY;
		}
		return (string) get_option( 'wpcc_alt_text_api_key', '' );
	}
}
