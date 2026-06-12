<?php
/**
 * Step 43 — Database Registry.
 * Defines allowed inspection operations, tables, and security boundaries.
 * Read-only. No arbitrary table access.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class DatabaseRegistry {

	const RISK_LOW    = 'low';
	const RISK_MEDIUM = 'medium';

	const ACTION_TABLE_LIST        = 'db_table_list';
	const ACTION_TABLE_STATS       = 'db_table_stats';
	const ACTION_TABLE_SIZE        = 'db_table_size';
	const ACTION_ROW_COUNTS        = 'db_row_counts';
	const ACTION_AUTOLOAD_ANALYSIS = 'db_autoload_analysis';
	const ACTION_OPTIONS_HEALTH    = 'db_options_health';
	const ACTION_INDEX_ANALYSIS    = 'db_index_analysis';
	const ACTION_ORPHAN_DETECTION  = 'db_orphan_detection';
	const ACTION_HEALTH_SUMMARY    = 'db_health_summary';

	const ACTIONS = [
		'db_table_list','db_table_stats','db_table_size','db_row_counts',
		'db_autoload_analysis','db_options_health','db_index_analysis',
		'db_orphan_detection','db_health_summary',
	];

	const CORE_TABLES = [
		'posts','postmeta','options','users','usermeta',
		'terms','termmeta','term_taxonomy','term_relationships',
		'comments','commentmeta',
	];

	const WRITE_KEYWORDS = [
		'INSERT','UPDATE','DELETE','DROP','ALTER','TRUNCATE','CREATE','REPLACE',
		'RENAME','LOCK','GRANT','REVOKE','EXEC','EXECUTE','CALL',
		'INTO OUTFILE','INTO DUMPFILE','BENCHMARK','SLEEP',
	];

	const SENSITIVE_OPTIONS = [
		'secret','password','token','key','api_key','private','hash','salt',
		'auth','credential','license','nonce',
	];

	public function action_risk( string $action ): string {
		return in_array( $action, [ self::ACTION_TABLE_LIST, self::ACTION_ROW_COUNTS, self::ACTION_HEALTH_SUMMARY ], true )
			? self::RISK_LOW : self::RISK_MEDIUM;
	}

	public function validate_table( string $table ): ?\WP_Error {
		$clean = $this->sanitize_table( $table );
		if ( null === $clean ) {
			return new \WP_Error( 'wpcc_invalid_db_table', __( 'Table not in the allowed core table list.', 'wp-command-center' ) );
		}
		return null;
	}

	public function sanitize_table( string $table ): ?string {
		global $wpdb;
		$clean = str_replace( $wpdb->prefix, '', sanitize_key( $table ) );
		if ( in_array( $clean, self::CORE_TABLES, true ) ) {
			return $wpdb->prefix . $clean;
		}
		$clean = sanitize_key( $table );
		if ( in_array( $clean, self::CORE_TABLES, true ) ) {
			return $wpdb->prefix . $clean;
		}
		return null;
	}

	public function contains_write_keywords( string $input ): bool {
		$upper = strtoupper( $input );
		foreach ( self::WRITE_KEYWORDS as $kw ) {
			if ( str_contains( $upper, $kw ) ) {
				return true;
			}
		}
		return false;
	}

	public function is_sensitive_option( string $name ): bool {
		$lower = strtolower( $name );
		foreach ( self::SENSITIVE_OPTIONS as $s ) {
			if ( str_contains( $lower, $s ) ) {
				return true;
			}
		}
		return false;
	}
}
