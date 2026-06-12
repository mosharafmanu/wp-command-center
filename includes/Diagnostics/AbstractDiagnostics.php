<?php
/**
 * Layer 2 — shared structure for diagnostics checks. Each check is
 * a verdict (good/recommended/critical/info) over facts gathered by
 * the Site Intelligence Engine (Layer 1).
 */

namespace WPCommandCenter\Diagnostics;

defined( 'ABSPATH' ) || exit;

abstract class AbstractDiagnostics {

	public const STATUS_GOOD        = 'good';
	public const STATUS_RECOMMENDED = 'recommended';
	public const STATUS_CRITICAL    = 'critical';
	public const STATUS_INFO        = 'info';

	/**
	 * @return array<int, array{id: string, label: string, status: string, description: string}>
	 */
	abstract public function analyze(): array;

	protected function check( string $id, string $label, string $status, string $description ): array {
		return compact( 'id', 'label', 'status', 'description' );
	}
}
