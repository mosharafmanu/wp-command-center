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
use WPCommandCenter\Ai\Platform\UsageLedger;
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
			$result = $this->openai->generate( $request, $target['key'], $target['endpoint'], $target['provider'], $target['deployment'] );
			$this->meter( $request, $result, $target['provider'] );
			return $result;
		}

		$result = $this->client->generate( $request );
		$this->meter( $request, $result, 'anthropic' );
		return $result;
	}

	/**
	 * Record what the call consumed.
	 *
	 * This is the one place every Built-in AI generation passes through, whichever
	 * transport runs it, which is why the meter lives here rather than in each feature
	 * provider. The request's `meta['feature']` carries the attribution — it is non-wire
	 * metadata the contract already supported, so nothing about the outbound request
	 * changed to make this possible.
	 *
	 * Only SUCCESSFUL generations are metered.
	 *
	 * The first version metered anything that reached the wire, on the theory that a
	 * request which left the building might have cost something. On a real site that
	 * read as nonsense: the alt-text and SEO suites drive the transport against a mocked
	 * 401, and the panel duly reported "31 generations, 26 of which reported no usage" —
	 * a number dominated by calls that generated nothing and were billed nothing. A
	 * rejected key, a rate-limit, a blocked endpoint and a timeout all produce no output
	 * and no charge, so counting them makes "generations" mean something other than what
	 * the customer would call a generation, and turns the honest "the provider did not
	 * report usage" caveat into noise that hides the real cases.
	 *
	 * A successful call with no usage block IS still metered as unreported — that is the
	 * genuine gap this distinction exists to surface.
	 *
	 * Metering never affects the result: a ledger write failure must not turn a
	 * successful generation into a failed one.
	 */
	private function meter( GenerationRequest $request, GenerationResult $result, string $provider ): void {
		if ( ! $result->is_ok() ) {
			return; // nothing was generated, and nothing was billed.
		}

		$meta    = $request->meta();
		$feature = isset( $meta['feature'] ) ? (string) $meta['feature'] : '';
		if ( '' === $feature ) {
			return; // unattributed (a connection test ping) — not customer feature usage.
		}

		UsageLedger::record(
			$result->usage(),
			$feature,
			$provider,
			'' !== $result->model() ? $result->model() : $request->model(),
			(string) get_option( ConnectionStore::OPT_DEFAULT, '' )
		);
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
