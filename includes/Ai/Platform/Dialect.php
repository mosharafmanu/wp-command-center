<?php
/**
 * PROGRAM-6R — AI API dialects (read-only).
 *
 * The architecture is built around DIALECTS, not providers. Most AI providers
 * speak one of a few wire protocols; a single transport/tester per dialect serves
 * unlimited providers. Adding "Groq" or "a local Ollama" is a ProviderCatalog
 * entry that points at an existing dialect — never new dialect/transport code.
 *
 *   anthropic         → Anthropic Messages API
 *   openai-compatible → OpenAI Chat Completions shape (OpenAI, OpenRouter, Groq,
 *                       Together, Fireworks, DeepInfra, Mistral, Perplexity, xAI,
 *                       LM Studio, Ollama, vLLM, internal gateways…)
 *   gemini            → Google Generative Language API
 *
 * `runtime_supported` is honest: only the Anthropic dialect is wired to WPCC's
 * feature generators today. The others are CONFIGURABLE + (where a tester exists)
 * TESTABLE, but not used by the runtime — never faked.
 */

namespace WPCommandCenter\Ai\Platform;

defined( 'ABSPATH' ) || exit;

final class Dialect {

	public const ANTHROPIC = 'anthropic';
	public const OPENAI    = 'openai-compatible';
	public const GEMINI    = 'gemini';

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return [
			self::ANTHROPIC => [
				'label'             => __( 'Anthropic Messages', 'wp-command-center' ),
				'auth'              => 'x-api-key',
				'endpoint_editable' => false, // fixed SDK endpoint.
				'default_endpoint'  => 'https://api.anthropic.com',
				'test_supported'    => true,
				'runtime_supported' => true,  // the ONLY runtime-wired dialect today.
			],
			self::OPENAI => [
				'label'             => __( 'OpenAI-compatible', 'wp-command-center' ),
				'auth'              => 'bearer',
				'endpoint_editable' => true,  // base_url — enables OpenRouter/Groq/Ollama/LM Studio/self-hosted.
				'default_endpoint'  => 'https://api.openai.com/v1',
				'test_supported'    => true,
				'runtime_supported' => false, // configurable + testable; not used by runtime yet.
			],
			self::GEMINI => [
				'label'             => __( 'Google Gemini', 'wp-command-center' ),
				'auth'              => 'query-key',
				'endpoint_editable' => false,
				'default_endpoint'  => 'https://generativelanguage.googleapis.com/v1beta',
				'test_supported'    => true,
				'runtime_supported' => false,
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

	public static function runtime_supported( string $id ): bool {
		$d = self::get( $id );
		return null !== $d && ! empty( $d['runtime_supported'] );
	}

	public static function test_supported( string $id ): bool {
		$d = self::get( $id );
		return null !== $d && ! empty( $d['test_supported'] );
	}

	public static function endpoint_editable( string $id ): bool {
		$d = self::get( $id );
		return null !== $d && ! empty( $d['endpoint_editable'] );
	}
}
