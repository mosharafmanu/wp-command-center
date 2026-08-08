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
 * on a 6.6+ site matches almost nothing. This site is a working example: 460 rows and
 * ~167 KB genuinely autoload, spread across 'on' and 'auto', and not one row says
 * 'yes'. So the Diagnostics health check reported "Total size of autoloaded options:
 * 0 B" and marked it Good, and `db_autoload_analysis` told a connected AI assistant
 * the same thing. A performance diagnostic that always answers zero is worse than
 * absent: it is a confident all-clear that cannot fail.
 *
 * The list is stated here rather than read from core's own
 * wp_autoload_values_to_autoload(). That function is the natural source, but it was
 * added in 6.6 and this plugin supports 6.4 — calling it, even behind function_exists(),
 * is a Plugin Check compliance error against the declared "Requires at least". The
 * values below are core's, and a `wp_options` value is not something WordPress can
 * change without a migration, so a static list is safe. If core ever adds one, add it
 * here.
 */

namespace WPCommandCenter\Core;

defined( 'ABSPATH' ) || exit;

final class OptionsAutoload {

	/**
	 * The autoload column values that mean "this row loads on every request".
	 *
	 * 'yes' is pre-6.6. 'on' is an explicit post-6.6 opt-in. 'auto' and 'auto-on' are
	 * core's own size-based decisions. 'no', 'off' and 'auto-off' are excluded.
	 */
	private const AUTOLOAD_VALUES = [ 'yes', 'on', 'auto-on', 'auto' ];

	/**
	 * @return array<int,string>
	 */
	public static function values(): array {
		return self::AUTOLOAD_VALUES;
	}

	/**
	 * A `%s, %s, …` placeholder list matching values(), for use inside an IN () clause.
	 *
	 * Returned separately from the values so every call site can hand the whole query
	 * to `$wpdb->prepare()` in one piece. An earlier version built the finished
	 * `autoload IN ('yes',…)` fragment here and interpolated it; each value was escaped,
	 * but a query assembled outside prepare() cannot be verified by a reader — or by
	 * Plugin Check, which flagged all seven call sites. Placeholders in, values bound.
	 */
	public static function placeholders(): string {
		return implode( ', ', array_fill( 0, count( self::AUTOLOAD_VALUES ), '%s' ) );
	}
}
