<?php
/**
 * Phase B — Universal AI Provider Runtime: neutral execution facade.
 * Phase D — multi-dialect dispatch (Anthropic + OpenAI-compatible).
 *
 * The single neutral entry point feature code uses to run a generation. Feature
 * providers build a provider-agnostic GenerationRequest and call generate(); they
 * never construct wire messages, parse transport responses, or know any provider's
 * endpoint/headers/body. AiRuntime owns the "how it runs" so the features own only
 * "what to ask".
 *
 * Dispatch (Phase D): the runtime honours the existing configured default
 * connection. When that default is a KEYED openai-compatible connection, execution
 * runs on the OpenAI-compatible transport with the connection's key/endpoint/model;
 * otherwise — Anthropic default, no connections, an unkeyed connection, or any
 * other dialect — it runs the unchanged Anthropic path. This is NOT routing,
 * selection, or fallback: it reads the one configured default, nothing more.
 *
 * Strict boundaries — this class NEVER:
 *   - builds provider wire messages or parses provider responses,
 *   - knows endpoints/headers/body structure (the transports own those),
 *   - selects among providers, falls back, or applies routing policy,
 *   - writes WordPress data or touches the proposal/operation engine.
 */

namespace WPCommandCenter\Ai;

use WPCommandCenter\Ai\Contract\GenerationRequest;
use WPCommandCenter\Ai\Contract\GenerationResult;
use WPCommandCenter\Ai\Platform\ConnectionStore;
use WPCommandCenter\Ai\Platform\Dialect;
use WPCommandCenter\Ai\Transport\OpenAiCompatibleTransport;

defined( 'ABSPATH' ) || exit;

final class AiRuntime {

	private AnthropicClient $client;
	private OpenAiCompatibleTransport $openai;

	/** Memoized execution target: null = Anthropic path; array = openai-compatible target. */
	private $target = false;

	/**
	 * @param AnthropicClient|null            $client Injectable for tests; defaults to the real client.
	 * @param OpenAiCompatibleTransport|null  $openai Injectable for tests; defaults to the real transport.
	 */
	public function __construct( ?AnthropicClient $client = null, ?OpenAiCompatibleTransport $openai = null ) {
		$this->client = $client ?? new AnthropicClient();
		$this->openai = $openai ?? new OpenAiCompatibleTransport();
	}

	/** True when a provider key is configured. No outbound call. */
	public function is_configured(): bool {
		$target = $this->target();
		return null !== $target ? true : $this->client->is_configured();
	}

	/** Resolve the model to use: the active connection's model, or the Anthropic resolution. */
	public function model( string $default = '' ): string {
		$target = $this->target();
		if ( null !== $target ) {
			return '' !== $target['model'] ? $target['model'] : $default;
		}
		return $this->client->model( $default );
	}

	/** Execute a neutral request and return the neutral result — errors as DATA, never thrown. */
	public function generate( GenerationRequest $request ): GenerationResult {
		$target = $this->target();
		if ( null !== $target ) {
			return $this->openai->generate( $request, $target['key'], $target['endpoint'], $target['provider'], $target['deployment'] );
		}
		return $this->client->generate( $request );
	}

	/**
	 * Resolve the execution target from the EXPLICITLY-set default connection
	 * (memoized). Reads OPT_DEFAULT directly — the admin's deliberate choice — and
	 * NEVER ConnectionStore::default_id(), whose first-usable fallback would be
	 * auto-selection. Returns an openai-compatible target ONLY when the explicit
	 * default is an enabled openai-compatible connection with a stored key;
	 * otherwise null (the unchanged Anthropic path).
	 *
	 * @return array{provider:string,key:string,endpoint:string,model:string,deployment:string}|null
	 */
	private function target(): ?array {
		if ( false !== $this->target ) {
			return $this->target;
		}
		$this->target = null;

		// Only an EXPLICIT admin default activates a non-Anthropic backend — never
		// an inferred/first-usable connection (that would be auto-selection).
		$id = (string) get_option( ConnectionStore::OPT_DEFAULT, '' );
		if ( '' === $id ) {
			return $this->target;
		}
		$store = new ConnectionStore();
		$conn  = $store->get( $id );
		if ( null === $conn || empty( $conn['enabled'] ) || Dialect::OPENAI !== (string) ( $conn['dialect'] ?? '' ) ) {
			return $this->target; // Anthropic / Gemini / disabled / missing → unchanged Anthropic path.
		}

		$key = $store->credentials()->secret( $conn );
		if ( '' === $key ) {
			return $this->target; // openai-compatible default without a usable key → Anthropic path.
		}

		$this->target = [
			'provider'   => (string) $conn['provider'],
			'key'        => $key,
			'endpoint'   => (string) $conn['endpoint'],
			'model'      => (string) $conn['model'],
			'deployment' => (string) ( $conn['deployment'] ?? '' ),
		];
		return $this->target;
	}
}
