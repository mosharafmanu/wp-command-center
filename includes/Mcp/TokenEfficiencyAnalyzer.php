<?php
/**
 * Deterministic payload-size estimator used by Step 76 validation/reporting.
 */

namespace WPCommandCenter\Mcp;

defined( 'ABSPATH' ) || exit;

final class TokenEfficiencyAnalyzer {

	public function analyze( mixed $payload ): array {
		$json       = (string) wp_json_encode( $payload );
		$bytes      = strlen( $json );
		$complexity = $this->complexity( $payload );

		return [
			'payload_bytes'    => $bytes,
			'estimated_tokens' => (int) ceil( $bytes / 4 ),
			'complexity'       => $complexity,
		];
	}

	public function compare( mixed $before, mixed $after ): array {
		$before_metrics = $this->analyze( $before );
		$after_metrics  = $this->analyze( $after );
		$before_bytes   = max( 1, $before_metrics['payload_bytes'] );

		return [
			'before'              => $before_metrics,
			'after'               => $after_metrics,
			'reduction_percentage' => round( ( 1 - ( $after_metrics['payload_bytes'] / $before_bytes ) ) * 100, 1 ),
		];
	}

	private function complexity( mixed $value ): int {
		if ( ! is_array( $value ) ) {
			return 1;
		}

		$total = 1;
		foreach ( $value as $item ) {
			$total += $this->complexity( $item );
		}

		return $total;
	}
}
