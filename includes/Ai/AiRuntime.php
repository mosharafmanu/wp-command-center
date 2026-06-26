<?php
/**
 * Phase B — Universal AI Provider Runtime: neutral execution facade.
 *
 * The single neutral entry point feature code uses to run a generation. Feature
 * providers build a provider-agnostic GenerationRequest and call generate();
 * they never construct wire messages, parse transport responses, or know any
 * provider's endpoint/headers/body. AiRuntime owns the "how it runs" so the
 * features own only "what to ask".
 *
 * Phase B scope: execution runs on Anthropic only. AiRuntime therefore delegates
 * to the Anthropic client (credential/model resolution) over the shared Anthropic
 * transport. There is NO provider selection, routing, or capability logic here —
 * that is future work. This class is the seam those phases will extend WITHOUT
 * touching feature code.
 *
 * Strict boundaries — this class NEVER:
 *   - builds provider wire messages or parses provider responses,
 *   - knows endpoints/headers/body structure (the transport owns those),
 *   - writes WordPress data or touches the proposal/operation engine.
 */

namespace WPCommandCenter\Ai;

use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationResult;

defined( 'ABSPATH' ) || exit;

final class AiRuntime {

	private AnthropicClient $client;

	/**
	 * @param AnthropicClient|null $client Injectable for tests; defaults to the real client.
	 */
	public function __construct( ?AnthropicClient $client = null ) {
		$this->client = $client ?? new AnthropicClient();
	}

	/** True when a provider key is configured. No outbound call. */
	public function is_configured(): bool {
		return $this->client->is_configured();
	}

	/** Resolve the model to use, falling back to the caller's feature default. */
	public function model( string $default = '' ): string {
		return $this->client->model( $default );
	}

	/** Execute a neutral request and return the neutral result — errors as DATA, never thrown. */
	public function generate( GenerationRequest $request ): GenerationResult {
		return $this->client->generate( $request );
	}
}
