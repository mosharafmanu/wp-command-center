<?php
/**
 * PROGRAM-5B — AI provider catalogue (read-only, honest).
 *
 * The single source of truth for which AI providers the admin UI shows and which
 * are actually wired. WPCC's only outbound transport today is Anthropic
 * (Ai\AnthropicClient), so Anthropic is the only `configurable` provider; OpenAI
 * and Gemini are listed as `planned` so the UI is future-proof WITHOUT exposing
 * fake controls. When a real transport ships for another provider, flip its
 * `status` to 'supported' and `configurable` to true here — the UI follows.
 *
 * This class performs NO writes, NO network calls, and never touches a key. It
 * adds no routes, operations, capabilities, MCP tools, or schema.
 */

namespace WPCommandCenter\Admin;

defined( 'ABSPATH' ) || exit;

final class ProviderCatalog {

	public const STATUS_SUPPORTED = 'supported'; // wired transport; manageable now.
	public const STATUS_PLANNED   = 'planned';   // shown for transparency; not yet wired.

	/**
	 * Ordered provider list. Only `supported` providers are configurable today.
	 *
	 * @return array<int,array{id:string,name:string,status:string,configurable:bool,note:string}>
	 */
	public static function all(): array {
		return [
			[
				'id'           => 'anthropic',
				'name'         => __( 'Anthropic (Claude)', 'wp-command-center' ),
				'status'       => self::STATUS_SUPPORTED,
				'configurable' => true,
				'note'         => __( 'Fully supported. WP Command Center sends AI requests to Claude using your own key.', 'wp-command-center' ),
			],
			[
				'id'           => 'openai',
				'name'         => __( 'OpenAI (GPT)', 'wp-command-center' ),
				'status'       => self::STATUS_PLANNED,
				'configurable' => false,
				'note'         => __( 'Planned. Not yet available — no key is collected until its connector ships.', 'wp-command-center' ),
			],
			[
				'id'           => 'gemini',
				'name'         => __( 'Google Gemini', 'wp-command-center' ),
				'status'       => self::STATUS_PLANNED,
				'configurable' => false,
				'note'         => __( 'Planned. Not yet available — no key is collected until its connector ships.', 'wp-command-center' ),
			],
		];
	}

	/** The single active/default provider id today (the only wired transport). */
	public static function active_id(): string {
		return 'anthropic';
	}

	/** True if exactly one provider is configurable (no provider-switch UI needed yet). */
	public static function single_provider(): bool {
		$configurable = array_filter( self::all(), static fn ( $p ) => $p['configurable'] );
		return count( $configurable ) <= 1;
	}
}
