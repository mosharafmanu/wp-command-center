<?php
/**
 * Phase D — Universal AI Provider Runtime: OpenAI-compatible provider profiles.
 *
 * Per-provider compatibility knobs for the OpenAI-compatible dialect. The
 * "OpenAI-compatible" wire is a family, not a single API: providers differ on the
 * auth header, the token parameter name, deployment-in-path (Azure), and an
 * api-version query (Azure). This is PURE DATA — it reads nothing, calls nothing,
 * and the transport (not the provider) consumes it. Every value defaults to the
 * standard OpenAI Chat Completions shape; only genuine divergences are overridden.
 *
 * Knobs:
 *   auth         'bearer' (Authorization: Bearer KEY) | 'api-key' (api-key: KEY, Azure)
 *   chat_path    path appended to the connection endpoint
 *   token_param  'max_tokens' | 'max_completion_tokens'
 *   deploy_path  true → Azure-style /openai/deployments/{deployment}{chat_path}
 *   api_version  non-empty → appended as ?api-version=… (Azure)
 *   headers      extra static headers (provider attribution, etc.)
 */

namespace WPCommandCenter\Ai\Transport;

defined( 'ABSPATH' ) || exit;

final class OpenAiCompatProfiles {

	/** The standard OpenAI Chat Completions profile — the default for every provider. */
	private static function defaults(): array {
		return [
			'auth'        => 'bearer',
			'chat_path'   => '/chat/completions',
			'token_param' => 'max_tokens',
			'deploy_path' => false,
			'api_version' => '',
			'headers'     => [],
		];
	}

	/** Genuine per-provider divergences from the standard shape. */
	private static function overrides(): array {
		return [
			// Azure OpenAI: api-key header, deployment in path, api-version query.
			'azure-openai' => [
				'auth'        => 'api-key',
				'deploy_path' => true,
				'api_version' => '2024-02-15-preview',
			],
		];
	}

	/**
	 * The resolved profile for a provider (defaults + override).
	 *
	 * @return array<string,mixed>
	 */
	public static function for_provider( string $provider ): array {
		$over = self::overrides()[ $provider ] ?? [];
		return array_merge( self::defaults(), $over );
	}
}
