<?php
/**
 * Which `wp_options` rows actually autoload — correct on every supported WordPress.
 *
 * WordPress 6.6 replaced the two-value `autoload` column ('yes'/'no') with a set of
 * values that also records HOW the decision was made: 'on' and 'off' for an explicit
 * choice, 'auto-on', 'auto-off' and 'auto' for one core made by size. Both the old
 * and the new values exist in the wild, because 6.6 did not rewrite existing rows.
 *
 * Everything here that reported on autoloaded data asked for `autoload = 'yes'`, which
 * on a 6.6+ site matches almost nothing. This site is a working example: 464 rows and
 * ~158 KB genuinely autoload, spread across 'on' and 'auto', and not one row says
 * 'yes'. So the Diagnostics health check reported "Total size of autoloaded options:
 * 0 B" and marked it Good, and `db_autoload_analysis` told a connected AI assistant
 * the same thing. A performance diagnostic that always answers zero is worse than
 * absent: it is a confident all-clear that cannot fail.
 *
 * Core answers this question itself via wp_autoload_values_to_autoload() (6.6+). That
 * is preferred over a hard-coded list so a future core change is picked up without an
 * edit here. The plugin supports 6.4, where the function does not exist, so the
 * fallback is the pre-6.6 truth: 'yes' alone.
 */

namespace WPCommandCenter\Core;

defined( 'ABSPATH' ) || exit;

final class OptionsAutoload {

	/**
	 * The autoload column values that mean "this row loads on every request".
	 *
	 * @return array<int,string>
	 */
	public static function values(): array {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			$values = wp_autoload_values_to_autoload();
			if ( is_array( $values ) && [] !== $values ) {
				return array_values( array_map( 'strval', $values ) );
			}
		}

		return [ 'yes' ];
	}

	/**
	 * A ready-to-embed SQL condition, e.g. `autoload IN ('yes','on','auto-on','auto')`.
	 *
	 * The values come from core (or the constant fallback), never from a request, and
	 * each is still escaped before it is embedded — a column whose contents decide
	 * query text should not rely on provenance alone.
	 */
	public static function sql_condition( string $column = 'autoload' ): string {
		global $wpdb;

		$values = self::values();
		$quoted = [];
		foreach ( $values as $value ) {
			$quoted[] = $wpdb->prepare( '%s', $value );
		}

		return $column . ' IN (' . implode( ',', $quoted ) . ')';
	}
}
