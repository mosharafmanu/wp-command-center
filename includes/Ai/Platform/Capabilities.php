<?php
/**
 * PROGRAM-6S — declared capability + model metadata (read-only, honest).
 *
 * DECLARED metadata (what each provider/dialect documents), NOT live-detected —
 * the UI labels it as such and never claims to have tested it. It performs no
 * calls and reads no options — pure data. Originally display-only; as of Phase C
 * it is also read (read-only) by Ai\CapabilityGate to validate a feature's
 * required capabilities against the active provider before generation.
 *
 * Capability values: 'yes' | 'no' | 'model' (model-dependent) | 'sep' (separate models).
 */

namespace WPCommandCenter\Ai\Platform;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	/** Ordered capability keys → human label. */
	public static function keys(): array {
		return [
			'streaming'   => __( 'Streaming', 'wp-command-center' ),
			'tools'       => __( 'Tool calling', 'wp-command-center' ),
			'json'        => __( 'JSON / structured output', 'wp-command-center' ),
			'vision'      => __( 'Vision (images)', 'wp-command-center' ),
			'reasoning'   => __( 'Reasoning', 'wp-command-center' ),
			'embeddings'  => __( 'Embeddings', 'wp-command-center' ),
			'audio'       => __( 'Audio', 'wp-command-center' ),
		];
	}

	/** Per-dialect baseline, overridden per provider where known. */
	private static function baseline(): array {
		return [
			Dialect::ANTHROPIC => [ 'streaming' => 'yes', 'tools' => 'yes', 'json' => 'yes', 'vision' => 'yes', 'reasoning' => 'yes', 'embeddings' => 'no', 'audio' => 'no' ],
			Dialect::OPENAI    => [ 'streaming' => 'yes', 'tools' => 'model', 'json' => 'model', 'vision' => 'model', 'reasoning' => 'model', 'embeddings' => 'sep', 'audio' => 'no' ],
			Dialect::GEMINI    => [ 'streaming' => 'yes', 'tools' => 'yes', 'json' => 'yes', 'vision' => 'yes', 'reasoning' => 'yes', 'embeddings' => 'sep', 'audio' => 'yes' ],
		];
	}

	private static function overrides(): array {
		return [
			'openai' => [ 'tools' => 'yes', 'json' => 'yes', 'vision' => 'yes', 'reasoning' => 'yes', 'embeddings' => 'sep', 'audio' => 'sep' ],
			'ollama'   => [ 'tools' => 'model', 'json' => 'model', 'vision' => 'model', 'reasoning' => 'model', 'embeddings' => 'sep' ],
			'lmstudio' => [ 'tools' => 'model', 'json' => 'model', 'vision' => 'model', 'reasoning' => 'model', 'embeddings' => 'sep' ],
			'vllm'     => [ 'tools' => 'model', 'json' => 'model', 'vision' => 'model', 'reasoning' => 'model' ],
		];
	}

	/** Resolve declared capabilities for a provider: dialect baseline + override. */
	public static function for_provider( string $provider ): array {
		$dialect = ProviderCatalog::dialect_of( $provider );
		$base    = self::baseline()[ $dialect ] ?? [];
		$over    = self::overrides()[ $provider ] ?? [];
		return array_merge( $base, $over );
	}

	/** A short human label for a capability value. */
	public static function value_label( string $v ): string {
		switch ( $v ) {
			case 'yes':   return __( 'Yes', 'wp-command-center' );
			case 'no':    return __( 'No', 'wp-command-center' );
			case 'sep':   return __( 'Separate models', 'wp-command-center' );
			case 'model': return __( 'Model-dependent', 'wp-command-center' );
			default:      return $v;
		}
	}

	/**
	 * Honest model tags for the catalogue presets (recommended/fast/cheap/capable…).
	 * @return array<string,array<int,string>> model_id => tags
	 */
	public static function model_tags( string $provider ): array {
		$tags = [
			'anthropic' => [
				'claude-sonnet-4-6'         => [ 'recommended', 'balanced', 'vision' ],
				'claude-opus-4-8'           => [ 'most-capable', 'reasoning' ],
				'claude-haiku-4-5-20251001' => [ 'fastest', 'cheapest' ],
			],
			'openai' => [
				'gpt-5'      => [ 'most-capable', 'reasoning', 'vision' ],
				'gpt-5-mini' => [ 'fastest', 'cheapest' ],
			],
			'gemini' => [
				'gemini-2.5-pro'   => [ 'most-capable', 'reasoning', 'vision' ],
				'gemini-2.5-flash' => [ 'fastest', 'cheapest', 'large-context' ],
			],
		];
		return $tags[ $provider ] ?? [];
	}

	/** Color hint for a model tag (for badge styling). */
	public static function tag_color( string $tag ): string {
		switch ( $tag ) {
			case 'recommended': return '#00a32a';
			case 'fastest':
			case 'cheapest':    return '#2271b1';
			case 'most-capable':
			case 'reasoning':   return '#8c5e00';
			case 'vision':      return '#7b3fbf';
			default:            return '#50575e';
		}
	}
}
