<?php
/**
 * PROGRAM-6R — provider catalogue (read-only). Providers are thin entries that
 * POINT AT A DIALECT; the dialect (not the provider) owns transport/test/runtime
 * behaviour. Adding a provider = adding one entry here. No transport code.
 *
 * Each entry: label, dialect, default_endpoint (overridable when the dialect's
 * endpoint is editable), models (suggested), allow_custom_model, key help, and a
 * flag for whether the provider needs extra fields (e.g. Azure deployment).
 */

namespace WPCommandCenter\Ai\Platform;

defined( 'ABSPATH' ) || exit;

final class ProviderCatalog {

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$openai_models = [
			'gpt-5'      => 'GPT-5',
			'gpt-5-mini' => 'GPT-5 mini',
		];
		return [
			// --- Anthropic dialect (runtime-wired) ---
			'anthropic' => [
				'label' => 'Anthropic (Claude)', 'dialect' => Dialect::ANTHROPIC,
				'models' => [
					'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 (recommended)',
					'claude-opus-4-8'           => 'Claude Opus 4.8 (highest capability)',
					'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (fastest)',
				],
				'default_model' => 'claude-sonnet-4-6', 'allow_custom_model' => true,
				'key_help' => 'console.anthropic.com → API Keys (sk-ant-…)', 'needs_endpoint' => false,
			],
			// --- Gemini dialect ---
			'gemini' => [
				'label' => 'Google Gemini', 'dialect' => Dialect::GEMINI,
				'models' => [ 'gemini-2.5-pro' => 'Gemini 2.5 Pro', 'gemini-2.5-flash' => 'Gemini 2.5 Flash' ],
				'default_model' => 'gemini-2.5-flash', 'allow_custom_model' => true,
				'key_help' => 'aistudio.google.com → Get API key', 'needs_endpoint' => false,
			],
			// --- OpenAI-compatible dialect: one transport, many providers ---
			'openai' => [
				'label' => 'OpenAI (GPT)', 'dialect' => Dialect::OPENAI, 'models' => $openai_models,
				'default_model' => 'gpt-5-mini', 'allow_custom_model' => true,
				'key_help' => 'platform.openai.com → API keys (sk-…)', 'needs_endpoint' => false,
			],
			'azure-openai' => [
				'label' => 'Azure OpenAI', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_model' => '', 'allow_custom_model' => true, 'needs_endpoint' => true,
				'needs_deployment' => true, 'key_help' => 'Azure portal → your OpenAI resource → Keys & Endpoint',
			],
			'openrouter' => [
				'label' => 'OpenRouter', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'https://openrouter.ai/api/v1', 'default_model' => '', 'allow_custom_model' => true,
				'key_help' => 'openrouter.ai → Keys', 'needs_endpoint' => false,
			],
			'groq' => [
				'label' => 'Groq', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'https://api.groq.com/openai/v1', 'default_model' => '', 'allow_custom_model' => true,
				'key_help' => 'console.groq.com → API Keys', 'needs_endpoint' => false,
			],
			'together' => [
				'label' => 'Together AI', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'https://api.together.xyz/v1', 'default_model' => '', 'allow_custom_model' => true,
				'key_help' => 'api.together.ai → Settings → API Keys', 'needs_endpoint' => false,
			],
			'fireworks' => [
				'label' => 'Fireworks AI', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'https://api.fireworks.ai/inference/v1', 'default_model' => '', 'allow_custom_model' => true,
				'key_help' => 'fireworks.ai → API Keys', 'needs_endpoint' => false,
			],
			'deepinfra' => [
				'label' => 'DeepInfra', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'https://api.deepinfra.com/v1/openai', 'default_model' => '', 'allow_custom_model' => true,
				'key_help' => 'deepinfra.com → API Tokens', 'needs_endpoint' => false,
			],
			'mistral' => [
				'label' => 'Mistral', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'https://api.mistral.ai/v1', 'default_model' => '', 'allow_custom_model' => true,
				'key_help' => 'console.mistral.ai → API Keys', 'needs_endpoint' => false,
			],
			'perplexity' => [
				'label' => 'Perplexity', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'https://api.perplexity.ai', 'default_model' => '', 'allow_custom_model' => true,
				'key_help' => 'perplexity.ai → Settings → API', 'needs_endpoint' => false,
			],
			'xai' => [
				'label' => 'xAI (Grok)', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'https://api.x.ai/v1', 'default_model' => '', 'allow_custom_model' => true,
				'key_help' => 'console.x.ai → API Keys', 'needs_endpoint' => false,
			],
			// --- Local / self-hosted (OpenAI-compatible over base_url) ---
			'ollama' => [
				'label' => 'Ollama (local)', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'http://localhost:11434/v1', 'default_model' => '', 'allow_custom_model' => true,
				'needs_endpoint' => true, 'local' => true, 'key_optional' => true,
				'key_help' => 'Local — usually no key. Set the base URL to your Ollama server.',
			],
			'lmstudio' => [
				'label' => 'LM Studio (local)', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => 'http://localhost:1234/v1', 'default_model' => '', 'allow_custom_model' => true,
				'needs_endpoint' => true, 'local' => true, 'key_optional' => true,
				'key_help' => 'Local — usually no key. Set the base URL to LM Studio’s server.',
			],
			'vllm' => [
				'label' => 'vLLM (self-hosted)', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => '', 'default_model' => '', 'allow_custom_model' => true,
				'needs_endpoint' => true, 'local' => true, 'key_optional' => true,
				'key_help' => 'Self-hosted — set your vLLM OpenAI-compatible base URL.',
			],
			'custom-openai' => [
				'label' => 'Custom (OpenAI-compatible)', 'dialect' => Dialect::OPENAI, 'models' => [],
				'default_endpoint' => '', 'default_model' => '', 'allow_custom_model' => true,
				'needs_endpoint' => true, 'key_optional' => true,
				'key_help' => 'Any OpenAI-compatible endpoint or gateway (LiteLLM, Portkey, internal proxy).',
			],
		];
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	public static function is_valid( string $id ): bool {
		return null !== self::get( $id );
	}

	public static function dialect_of( string $provider ): string {
		$p = self::get( $provider );
		return $p ? (string) $p['dialect'] : '';
	}

	/** Default endpoint for a provider (provider override → dialect default). */
	public static function default_endpoint( string $provider ): string {
		$p = self::get( $provider );
		if ( ! $p ) {
			return '';
		}
		if ( isset( $p['default_endpoint'] ) ) {
			return (string) $p['default_endpoint'];
		}
		$d = Dialect::get( (string) $p['dialect'] );
		return $d ? (string) $d['default_endpoint'] : '';
	}

	/** True when WPCC's runtime can actually use this provider (via its dialect). */
	public static function runtime_usable( string $provider ): bool {
		return Dialect::runtime_supported( self::dialect_of( $provider ) );
	}

	/** True for a declared local provider (Ollama/LM Studio/vLLM) — may use loopback/private endpoints. */
	public static function is_local( string $provider ): bool {
		$p = self::get( $provider );
		return null !== $p && ! empty( $p['local'] );
	}

	public static function test_supported( string $provider ): bool {
		return Dialect::test_supported( self::dialect_of( $provider ) );
	}

	/** Ordered [id => label] for a provider selector, grouped sensibly. */
	public static function choices(): array {
		$out = [];
		foreach ( self::all() as $id => $def ) {
			$out[ $id ] = (string) $def['label'];
		}
		return $out;
	}

	/** Above this many models the wizard offers a search/filter box. */
	public const SEARCH_THRESHOLD = 8;

	/**
	 * Provider-driven UI metadata — the single descriptor the connection wizard
	 * renders from (no provider-specific conditionals in the view). Pure derivation
	 * from the catalog definition; NO provider execution, runtime, key-storage, or
	 * API-contract behavior.
	 *
	 * `supports_discovery` is **false for every provider today**: WPCC has no
	 * browser-facing model-listing endpoint (the connection test only *counts*
	 * models server-side). This flag is the honest seam a future discovery endpoint
	 * would flip on — until then the wizard uses `recommended_models` / free text and
	 * never fabricates a model list.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function metadata( string $id ): ?array {
		$def = self::get( $id );
		if ( ! $def ) {
			return null;
		}
		$recommended = ( isset( $def['models'] ) && is_array( $def['models'] ) ) ? $def['models'] : [];
		return [
			'id'                    => $id,
			'label'                 => (string) ( $def['label'] ?? $id ),
			'dialect'               => (string) ( $def['dialect'] ?? '' ),
			'requires_endpoint'     => ! empty( $def['needs_endpoint'] ),
			'default_endpoint'      => self::default_endpoint( $id ),
			'supports_discovery'    => false, // honest: no UI-facing model-listing endpoint exists yet.
			'recommended_models'    => $recommended,
			'default_model'         => (string) ( $def['default_model'] ?? '' ),
			'supports_custom_model' => ! empty( $def['allow_custom_model'] ),
			'supports_search'       => count( $recommended ) > self::SEARCH_THRESHOLD,
			'supports_testing'      => self::test_supported( $id ),
			'needs_deployment'      => ! empty( $def['needs_deployment'] ),
			'key_optional'          => ! empty( $def['key_optional'] ),
		];
	}

	/** @return array<string,array<string,mixed>> metadata for every provider, keyed by id. */
	public static function metadata_all(): array {
		$out = [];
		foreach ( array_keys( self::all() ) as $id ) {
			$meta = self::metadata( $id );
			if ( null !== $meta ) {
				$out[ $id ] = $meta;
			}
		}
		return $out;
	}
}
