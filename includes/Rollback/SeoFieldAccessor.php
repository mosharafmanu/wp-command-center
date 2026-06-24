<?php
/**
 * PROGRAM-4 / P4.0 — SEO {@see FieldAccessor}.
 *
 * The only SEO-aware accessor: maps unified SEO fields to their provider-correct
 * backing meta keys and reads the current unified value via the existing
 * {@see \WPCommandCenter\Operations\SeoProvider}. Carries the active provider
 * (rankmath|yoast) so the same record restores under the provider it was captured
 * with. Behaviour is lifted verbatim from SeoRuntimeManager's pre-P4.0 inline
 * delta logic — robots drift compares as an order-insensitive directive set; all
 * other fields compare as strings (the post-meta default).
 */

namespace WPCommandCenter\Rollback;

use WPCommandCenter\Operations\SeoProvider;

defined( 'ABSPATH' ) || exit;

final class SeoFieldAccessor extends PostMetaAccessor {

	private string $provider;

	public function __construct( string $provider ) {
		$this->provider = $provider;
	}

	public function backing_keys( string $field ): array {
		return SeoProvider::backing_keys( $field, $this->provider );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed string for scalar fields, string[] for robots.
	 */
	public function read_field( $entity_id, string $field ) {
		return SeoProvider::read_field( (int) $entity_id, $field, $this->provider );
	}

	/**
	 * robots compares normalized directive sets (order-insensitive); all other
	 * fields use the scalar string comparison from PostMetaAccessor. This is the
	 * exact comparison SeoRuntimeManager::values_equal() performed pre-P4.0.
	 *
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		if ( 'robots' === $field ) {
			$c = is_array( $current ) ? $current : [];
			$e = is_array( $after ) ? $after : [];
			sort( $c );
			sort( $e );
			return $c === $e;
		}
		return parent::equals( $field, $current, $after );
	}
}
