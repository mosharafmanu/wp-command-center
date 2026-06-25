<?php
/**
 * PROGRAM-6 — AI provider catalogue (read-only type definitions, honest).
 *
 * The single source of truth for the provider TYPES WP Command Center can store
 * and how honestly to label each. It defines, per type: display label, help, the
 * model menu, whether a custom model is allowed, whether a live connection test
 * exists, and whether WPCC's runtime can actually CALL the provider yet.
 *
 * Honesty contract (no lying):
 *   - runtime = 'supported'   → WPCC's feature generators can use it now (Anthropic only).
 *   - runtime = 'config_only' → it can be stored (and maybe tested) but WPCC's runtime
 *                               does NOT call it yet ("Stored — not used by WPCC runtime yet").
 *   - connection_test = 'supported' → a real minimal test call is implemented.
 *   - connection_test = 'unsupported' → stored for future use; we never fake a pass.
 *
 * Read-only: no writes, no network, never a key. No routes/operations/caps/MCP/schema.
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class ProviderCatalog {

	// Runtime usability.
	public const RUNTIME_SUPPORTED = 'supported';
	public const RUNTIME_CONFIG    = 'config_only';

	// Connection-test capability.
	public const TEST_SUPPORTED   = 'supported';
	public const TEST_UNSUPPORTED = 'unsupported';

	// Back-compat status constants (Program-5B referenced these).
	public const STATUS_SUPPORTED = 'supported';
	public const STATUS_PLANNED   = 'planned';

	/**
	 * All provider TYPES, keyed by stable id (no duplicates by construction).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function types(): array {
		return [
			'anthropic' => [
				'label'             => __( 'Anthropic (Claude)', 'wp-command-center' ),
				'description'       => __( 'Claude models. Fully wired: WP Command Center uses this provider for its AI features.', 'wp-command-center' ),
				'key_help'          => __( 'Create a key at console.anthropic.com → API Keys. It looks like sk-ant-…', 'wp-command-center' ),
				'key_prefix_hint'   => 'sk-ant-',
				'models'            => [
					'claude-sonnet-4-6'         => __( 'Claude Sonnet 4.6 (recommended — balanced)', 'wp-command-center' ),
					'claude-opus-4-8'           => __( 'Claude Opus 4.8 (highest capability)', 'wp-command-center' ),
					'claude-haiku-4-5-20251001' => __( 'Claude Haiku 4.5 (fastest / lowest cost)', 'wp-command-center' ),
				],
				'default_model'     => 'claude-sonnet-4-6',
				'allow_custom_model'=> true,
				'connection_test'   => self::TEST_SUPPORTED,
				'runtime'           => self::RUNTIME_SUPPORTED,
			],
			'openai' => [
				'label'             => __( 'OpenAI (GPT)', 'wp-command-center' ),
				'description'       => __( 'GPT models. You can store a key, choose a model and test the connection now. WPCC does not yet route its AI features through OpenAI.', 'wp-command-center' ),
				'key_help'          => __( 'Create a key at platform.openai.com → API keys. It looks like sk-…', 'wp-command-center' ),
				'key_prefix_hint'   => 'sk-',
				'models'            => [
					'gpt-5'      => __( 'GPT-5 (highest capability)', 'wp-command-center' ),
					'gpt-5-mini' => __( 'GPT-5 mini (faster / lower cost)', 'wp-command-center' ),
				],
				'default_model'     => 'gpt-5-mini',
				'allow_custom_model'=> true,
				'connection_test'   => self::TEST_SUPPORTED,
				'runtime'           => self::RUNTIME_CONFIG,
			],
			'gemini' => [
				'label'             => __( 'Google Gemini', 'wp-command-center' ),
				'description'       => __( 'Gemini models. You can store a key, choose a model and test the connection now. WPCC does not yet route its AI features through Gemini.', 'wp-command-center' ),
				'key_help'          => __( 'Create a key at aistudio.google.com → Get API key.', 'wp-command-center' ),
				'key_prefix_hint'   => '',
				'models'            => [
					'gemini-2.5-pro'   => __( 'Gemini 2.5 Pro (higher capability)', 'wp-command-center' ),
					'gemini-2.5-flash' => __( 'Gemini 2.5 Flash (faster / lower cost)', 'wp-command-center' ),
				],
				'default_model'     => 'gemini-2.5-flash',
				'allow_custom_model'=> true,
				'connection_test'   => self::TEST_SUPPORTED,
				'runtime'           => self::RUNTIME_CONFIG,
			],
			'openrouter' => [
				'label'             => __( 'OpenRouter', 'wp-command-center' ),
				'description'       => __( 'A router across many models. Stored for future use — WPCC cannot call it or test it yet.', 'wp-command-center' ),
				'key_help'          => __( 'Create a key at openrouter.ai → Keys.', 'wp-command-center' ),
				'key_prefix_hint'   => 'sk-or-',
				'models'            => [],
				'default_model'     => '',
				'allow_custom_model'=> true,
				'connection_test'   => self::TEST_UNSUPPORTED,
				'runtime'           => self::RUNTIME_CONFIG,
			],
			'azure-openai' => [
				'label'             => __( 'Azure OpenAI', 'wp-command-center' ),
				'description'       => __( 'OpenAI models hosted on Azure. Stored for future use — WPCC cannot call it or test it yet.', 'wp-command-center' ),
				'key_help'          => __( 'From your Azure OpenAI resource → Keys and Endpoint.', 'wp-command-center' ),
				'key_prefix_hint'   => '',
				'models'            => [],
				'default_model'     => '',
				'allow_custom_model'=> true,
				'connection_test'   => self::TEST_UNSUPPORTED,
				'runtime'           => self::RUNTIME_CONFIG,
			],
			'mistral' => [
				'label'             => __( 'Mistral', 'wp-command-center' ),
				'description'       => __( 'Mistral models. Stored for future use — WPCC cannot call it or test it yet.', 'wp-command-center' ),
				'key_help'          => __( 'Create a key at console.mistral.ai → API Keys.', 'wp-command-center' ),
				'key_prefix_hint'   => '',
				'models'            => [],
				'default_model'     => '',
				'allow_custom_model'=> true,
				'connection_test'   => self::TEST_UNSUPPORTED,
				'runtime'           => self::RUNTIME_CONFIG,
			],
			'perplexity' => [
				'label'             => __( 'Perplexity', 'wp-command-center' ),
				'description'       => __( 'Perplexity models. Stored for future use — WPCC cannot call it or test it yet.', 'wp-command-center' ),
				'key_help'          => __( 'Create a key at perplexity.ai → Settings → API.', 'wp-command-center' ),
				'key_prefix_hint'   => 'pplx-',
				'models'            => [],
				'default_model'     => '',
				'allow_custom_model'=> true,
				'connection_test'   => self::TEST_UNSUPPORTED,
				'runtime'           => self::RUNTIME_CONFIG,
			],
			'xai' => [
				'label'             => __( 'xAI (Grok)', 'wp-command-center' ),
				'description'       => __( 'Grok models. Stored for future use — WPCC cannot call it or test it yet.', 'wp-command-center' ),
				'key_help'          => __( 'Create a key at console.x.ai.', 'wp-command-center' ),
				'key_prefix_hint'   => 'xai-',
				'models'            => [],
				'default_model'     => '',
				'allow_custom_model'=> true,
				'connection_test'   => self::TEST_UNSUPPORTED,
				'runtime'           => self::RUNTIME_CONFIG,
			],
		];
	}

	/** A single type definition, or null. */
	public static function type( string $id ): ?array {
		$types = self::types();
		return $types[ $id ] ?? null;
	}

	/** True if $id is a known provider type. */
	public static function is_valid_type( string $id ): bool {
		return null !== self::type( $id );
	}

	/** Ordered list of [id => label] for a type selector. */
	public static function type_choices(): array {
		$out = [];
		foreach ( self::types() as $id => $def ) {
			$out[ $id ] = $def['label'];
		}
		return $out;
	}

	/** The one runtime-wired provider type today. */
	public static function active_runtime_type(): string {
		return 'anthropic';
	}

	/** True when a provider type's runtime is wired (WPCC can actually call it). */
	public static function runtime_usable( string $id ): bool {
		$def = self::type( $id );
		return null !== $def && self::RUNTIME_SUPPORTED === $def['runtime'];
	}

	/** True when a live connection test exists for the type. */
	public static function test_supported( string $id ): bool {
		$def = self::type( $id );
		return null !== $def && self::TEST_SUPPORTED === $def['connection_test'];
	}

	/* ---- Back-compat shims for Program-5B references (kept stable) ---- */

	/** @deprecated 6.0 — use types(). Returns the legacy {id,name,status,configurable,note} shape. */
	public static function all(): array {
		$out = [];
		foreach ( self::types() as $id => $def ) {
			$out[] = [
				'id'           => $id,
				'name'         => $def['label'],
				'status'       => self::runtime_usable( $id ) ? self::STATUS_SUPPORTED : self::STATUS_PLANNED,
				'configurable' => true,
				'note'         => $def['description'],
			];
		}
		return $out;
	}

	public static function active_id(): string {
		return self::active_runtime_type();
	}

	public static function single_provider(): bool {
		// Multiple types are configurable; runtime-usable is still one.
		return false;
	}
}
