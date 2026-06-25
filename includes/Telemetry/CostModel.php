<?php
/**
 * PROGRAM-8 — cost model (read-only, honest).
 *
 * Converts measured token counts into an ESTIMATED cost using a per-model price
 * table (USD per 1,000,000 tokens). If the model is not priced, or tokens are
 * unknown, it returns NULL ("unknown") — it NEVER invents a figure. Prices are a
 * static, editable reference (filterable), not live billing; the UI must label
 * cost as "estimated".
 *
 * Provider-agnostic by design: pricing is keyed by model id, so adding a provider
 * is a price-table entry — no telemetry/schema redesign.
 */

namespace WPCommandCenter\Telemetry;

defined( 'ABSPATH' ) || exit;

final class CostModel {

	/**
	 * USD per 1,000,000 tokens, [input, output]. Reference values; override via the
	 * `wpcc_telemetry_prices` filter. Unlisted models → unknown (NULL cost).
	 *
	 * @return array<string,array{0:float,1:float}>
	 */
	public static function prices(): array {
		$prices = [
			// Anthropic
			'claude-sonnet-4-6'         => [ 3.0, 15.0 ],
			'claude-opus-4-8'           => [ 15.0, 75.0 ],
			'claude-haiku-4-5-20251001' => [ 1.0, 5.0 ],
			// OpenAI (reference)
			'gpt-5'      => [ 5.0, 15.0 ],
			'gpt-5-mini' => [ 0.6, 2.4 ],
			// Gemini (reference)
			'gemini-2.5-pro'   => [ 1.25, 10.0 ],
			'gemini-2.5-flash' => [ 0.3, 2.5 ],
		];
		/** @var array<string,array{0:float,1:float}> */
		return (array) apply_filters( 'wpcc_telemetry_prices', $prices );
	}

	/** True when a model has a known price. */
	public static function is_priced( string $model ): bool {
		return isset( self::prices()[ $model ] );
	}

	/**
	 * Estimated cost in micro-USD (1e-6 USD) for a model + token counts, or NULL
	 * when the model is unpriced or either token count is unknown. Never invents.
	 *
	 * @param int|null $tokens_in
	 * @param int|null $tokens_out
	 */
	public static function estimate_micros( string $model, ?int $tokens_in, ?int $tokens_out ): ?int {
		$prices = self::prices();
		if ( ! isset( $prices[ $model ] ) ) {
			return null; // unknown model → unknown cost.
		}
		if ( null === $tokens_in && null === $tokens_out ) {
			return null; // no measured tokens → unknown cost.
		}
		[ $in_per_m, $out_per_m ] = $prices[ $model ];
		$ti = (int) ( $tokens_in ?? 0 );
		$to = (int) ( $tokens_out ?? 0 );
		// USD = tokens/1e6 * price; micro-USD = USD * 1e6 = tokens * price.
		$micros = ( $ti * $in_per_m ) + ( $to * $out_per_m );
		return (int) round( $micros );
	}

	/** Human "$0.00" formatting from micro-USD, or "—" for unknown. */
	public static function format_micros( ?int $micros ): string {
		if ( null === $micros ) {
			return '—';
		}
		return '$' . number_format( $micros / 1000000, ( $micros < 10000 ) ? 4 : 2 );
	}
}
