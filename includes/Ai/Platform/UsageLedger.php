<?php
/**
 * Built-in AI token usage ledger — counts the provider actually reported.
 *
 * The Built-in AI screen has always carried a "Token usage & cost" panel, and it always
 * read "Not tracked yet" — including after a customer had run real generations and been
 * billed for them. The number was never missing from the provider: Anthropic returns a
 * `usage` block on every Messages response and OpenAI-compatible endpoints return one
 * too. The transports decoded the response, read `content[0].text`, and dropped the rest
 * on the floor. This ledger is where the number now lands.
 *
 * What it stores: COUNTS ONLY — input/output/cached token totals and a call count,
 * bucketed by feature + provider + model, with the connection id for attribution and a
 * timestamp. It NEVER stores a prompt, a generated title/excerpt/alt text, any post
 * content, or an API key. Nothing written here could reconstruct what was generated.
 *
 * What it does NOT do: cost. The product maintains no versioned provider price list, and
 * inventing one would mean printing a currency figure that drifts silently out of date
 * and that a customer might reconcile against a real invoice. Tokens are a fact the
 * provider stated; a price would be a guess this code is not entitled to make. The panel
 * says so in as many words.
 *
 * Honesty about gaps: a provider that returns no usage block is recorded as a call with
 * UNKNOWN usage, counted separately, never as a call that consumed zero tokens. A total
 * of "1,240 tokens across 8 calls (2 not reported)" is a true statement; silently
 * treating those two as zero would not be.
 *
 * Bounded by construction: a single non-autoloaded option holding aggregates, with the
 * bucket map capped. It stores no per-call row, so it cannot grow with usage.
 */

namespace WPCommandCenter\Ai\Platform;

use WPCommandCenter\Ai\Contract\GenerationUsage;

defined( 'ABSPATH' ) || exit;

final class UsageLedger {

	/** Aggregates only; autoload=no. */
	public const OPTION = 'wpcc_ai_usage';

	/**
	 * Maximum feature|provider|model buckets retained. Reached only by a site that
	 * switches models repeatedly; the running totals stay complete either way, because
	 * an evicted bucket's numbers were already added to them.
	 */
	private const MAX_BUCKETS = 60;

	/** The features that can attribute usage (mirrors ConnectionStore::FEATURES). */
	public const FEATURES = [ 'seo_meta', 'alt_text', 'ai_content' ];

	/**
	 * Record one completed generation.
	 *
	 * Called for every attempt that reached a provider, whether or not the provider
	 * reported usage, so the call count is a true count of calls.
	 *
	 * @param GenerationUsage $usage      What the provider reported (may be unknown()).
	 * @param string          $feature    Feature key, e.g. 'ai_content'.
	 * @param string          $provider   Provider id, e.g. 'anthropic'.
	 * @param string          $model      Model id as resolved for the call.
	 * @param string          $connection Connection id the call was routed through ('' when none).
	 */
	public static function record( GenerationUsage $usage, string $feature, string $provider, string $model, string $connection = '' ): void {
		$data = self::read();
		$now  = time();

		$reported = $usage->is_reported();
		$in       = $reported ? $usage->input_tokens() : 0;
		$out      = $reported ? $usage->output_tokens() : 0;
		$cached   = $reported ? $usage->cached_tokens() : 0;

		$data['totals']['calls']       = (int) $data['totals']['calls'] + 1;
		$data['totals']['input']      += $in;
		$data['totals']['output']     += $out;
		$data['totals']['cached']     += $cached;
		if ( $reported ) {
			$data['totals']['reported_calls'] = (int) $data['totals']['reported_calls'] + 1;
		} else {
			$data['totals']['unreported_calls'] = (int) $data['totals']['unreported_calls'] + 1;
		}

		$key = self::bucket_key( $feature, $provider, $model );
		if ( ! isset( $data['buckets'][ $key ] ) ) {
			// Evict the least recently used bucket rather than letting the map grow.
			// The totals above already carry every evicted bucket's numbers.
			if ( count( $data['buckets'] ) >= self::MAX_BUCKETS ) {
				uasort( $data['buckets'], static fn( $a, $b ) => (int) ( $a['last'] ?? 0 ) <=> (int) ( $b['last'] ?? 0 ) );
				array_shift( $data['buckets'] );
			}
			$data['buckets'][ $key ] = [
				'feature'    => $feature,
				'provider'   => $provider,
				'model'      => $model,
				'connection' => $connection,
				'calls'      => 0,
				'input'      => 0,
				'output'     => 0,
				'cached'     => 0,
				'unreported' => 0,
				'first'      => $now,
				'last'       => $now,
			];
		}

		$b                = &$data['buckets'][ $key ];
		$b['calls']       = (int) $b['calls'] + 1;
		$b['input']      += $in;
		$b['output']     += $out;
		$b['cached']     += $cached;
		$b['last']        = $now;
		$b['connection']  = '' !== $connection ? $connection : (string) ( $b['connection'] ?? '' );
		if ( ! $reported ) {
			$b['unreported'] = (int) $b['unreported'] + 1;
		}
		unset( $b );

		if ( 0 === (int) $data['first_at'] ) {
			$data['first_at'] = $now;
		}
		$data['last_at'] = $now;

		update_option( self::OPTION, $data, false );
	}

	/**
	 * The whole ledger, normalized.
	 *
	 * @return array{totals:array<string,int>,buckets:array<string,array<string,mixed>>,first_at:int,last_at:int}
	 */
	public static function read(): array {
		$raw = get_option( self::OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];

		$totals  = isset( $raw['totals'] ) && is_array( $raw['totals'] ) ? $raw['totals'] : [];
		$buckets = isset( $raw['buckets'] ) && is_array( $raw['buckets'] ) ? $raw['buckets'] : [];

		return [
			'totals'   => [
				'calls'            => max( 0, (int) ( $totals['calls'] ?? 0 ) ),
				'reported_calls'   => max( 0, (int) ( $totals['reported_calls'] ?? 0 ) ),
				'unreported_calls' => max( 0, (int) ( $totals['unreported_calls'] ?? 0 ) ),
				'input'            => max( 0, (int) ( $totals['input'] ?? 0 ) ),
				'output'           => max( 0, (int) ( $totals['output'] ?? 0 ) ),
				'cached'           => max( 0, (int) ( $totals['cached'] ?? 0 ) ),
			],
			'buckets'  => $buckets,
			'first_at' => max( 0, (int) ( $raw['first_at'] ?? 0 ) ),
			'last_at'  => max( 0, (int) ( $raw['last_at'] ?? 0 ) ),
		];
	}

	/**
	 * Summary for the Built-in AI panel.
	 *
	 * `cost` is deliberately absent and `cost_available` is deliberately false: see the
	 * class docblock. `partial` says out loud that some calls returned no usage, so the
	 * total is a floor rather than a complete figure.
	 *
	 * @return array{tracked:bool,total_tokens:int,input:int,output:int,cached:int,calls:int,unreported_calls:int,partial:bool,cost_available:bool,last_at:int}
	 */
	public static function summary(): array {
		$d = self::read();
		$t = $d['totals'];

		return [
			'tracked'          => $t['calls'] > 0,
			'total_tokens'     => $t['input'] + $t['output'],
			'input'            => $t['input'],
			'output'           => $t['output'],
			'cached'           => $t['cached'],
			'calls'            => $t['calls'],
			'unreported_calls' => $t['unreported_calls'],
			'partial'          => $t['unreported_calls'] > 0,
			'cost_available'   => false, // no versioned pricing source ships with the product.
			'last_at'          => $d['last_at'],
		];
	}

	/**
	 * Per-feature/provider/model rows, busiest first — so two models on one provider,
	 * or the same model used by two features, stay separable.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function breakdown(): array {
		$rows = array_values( self::read()['buckets'] );
		foreach ( $rows as &$r ) {
			$r['total'] = (int) ( $r['input'] ?? 0 ) + (int) ( $r['output'] ?? 0 );
		}
		unset( $r );
		usort( $rows, static fn( $a, $b ) => ( (int) $b['total'] ) <=> ( (int) $a['total'] ) );
		return $rows;
	}

	/** Discard all recorded usage (a counter reset; it holds nothing else). */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	private static function bucket_key( string $feature, string $provider, string $model ): string {
		return $feature . '|' . $provider . '|' . $model;
	}
}
