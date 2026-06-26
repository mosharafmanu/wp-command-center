<?php
/**
 * Phase C — Universal AI Provider Runtime: capability gate.
 *
 * The validation seam between a feature's capability NEEDS and a provider's
 * DECLARED capabilities. Given a feature and the active provider, it answers one
 * question: may this feature generate with this provider? It is pure validation —
 * it never selects, ranks, or routes providers, and it never calls anything.
 *
 * Data vs resolver: the declared capability DATA lives in Ai\Platform\Capabilities
 * (per-dialect baseline + per-provider overrides). This class is the RESOLVER: it
 * holds the feature→requirement map (the feature-needs taxonomy) and matches those
 * requirements against the declared data.
 *
 * Matching is conservative by design:
 *   - a required capability declared 'yes'  → satisfied,
 *   - declared 'no'                          → BLOCKED (the only thing that closes the gate),
 *   - 'model' | 'sep' | missing / unknown    → ALLOWED (uncertainty never blocks).
 *
 * Behaviour today is inert: the only runtime provider is Anthropic, whose declared
 * vision capability is 'yes', so every feature's gate is open and generation is
 * identical to before this gate existed.
 *
 * Strict boundaries — this class NEVER:
 *   - performs I/O, reads options, or calls a provider,
 *   - selects/ranks/routes providers (no autonomy),
 *   - hard-blocks on uncertainty,
 *   - touches proposals, approval, audit, or rollback.
 */

namespace WPCommandCenter\Ai;

use WPCommandCenter\Ai\Platform\Capabilities;

defined( 'ABSPATH' ) || exit;

final class CapabilityGate {

	/** Version of the feature-needs taxonomy (forward-compatibility marker). */
	public const VERSION = 1;

	/**
	 * Feature key → required capability keys. Keys are the existing feature keys
	 * (seo_meta · alt_text · ai_content); capabilities are drawn from
	 * Capabilities::keys(). Empty = basic text generation only, which any chat
	 * model provides (no constraint) — stated explicitly, not by omission.
	 *
	 * @var array<string, array<int,string>>
	 */
	private const REQUIREMENTS = [
		'seo_meta'   => [],
		'alt_text'   => [ 'vision' ],
		'ai_content' => [],
	];

	/**
	 * The required capability keys for a feature.
	 *
	 * @return array<int,string>
	 */
	public static function requirements( string $feature ): array {
		return self::REQUIREMENTS[ $feature ] ?? [];
	}

	/**
	 * Resolve whether a provider supports a capability against the declared data.
	 * Returns 'yes' | 'no' | 'unknown' ('model'/'sep'/missing collapse to 'unknown').
	 */
	public static function supports( string $provider, string $capability ): string {
		$caps = Capabilities::for_provider( $provider );
		$value = (string) ( $caps[ $capability ] ?? '' );

		if ( 'yes' === $value ) {
			return 'yes';
		}
		if ( 'no' === $value ) {
			return 'no';
		}
		return 'unknown';
	}

	/**
	 * Gate a feature for a provider. The gate closes ONLY when a required
	 * capability is declared 'no'. Returns:
	 *   [ 'ok' => bool, 'missing' => string, 'message' => string (redacted-safe) ]
	 */
	public static function check( string $feature, string $provider ): array {
		foreach ( self::requirements( $feature ) as $capability ) {
			if ( 'no' === self::supports( $provider, $capability ) ) {
				return [
					'ok'      => false,
					'missing' => $capability,
					'message' => sprintf(
						/* translators: 1: provider id, 2: capability key */
						__( 'The connected provider “%1$s” does not support “%2$s”, which this feature requires.', 'wp-command-center' ),
						$provider,
						$capability
					),
				];
			}
		}

		return [ 'ok' => true, 'missing' => '', 'message' => '' ];
	}
}
