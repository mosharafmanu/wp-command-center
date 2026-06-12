<?php
/**
 * Step 43 — Database Inspection Runtime.
 * Read-only. No INSERT/UPDATE/DELETE/DROP/ALTER/TRUNCATE/CREATE.
 * No arbitrary SQL. No raw table access.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class DatabaseInspector {

	private DatabaseRegistry $registry;

	public function __construct() {
		$this->registry = new DatabaseRegistry();
	}

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );
		if ( ! in_array( $action, DatabaseRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_db_action', __( 'Invalid database inspection action.', 'wp-command-center' ) );
		}

		// Block write keywords in any input
		$input_str = wp_json_encode( $params );
		if ( false !== $input_str && $this->registry->contains_write_keywords( $input_str ) ) {
			$this->audit( 'database.inspect.blocked', [ 'action' => $action, 'reason' => 'write_keyword_detected' ], $context );
			return new \WP_Error( 'wpcc_db_write_blocked', __( 'Write keywords are not allowed in database inspection.', 'wp-command-center' ) );
		}

		$table_raw = sanitize_text_field( $params['table'] ?? '' );

		if ( ! in_array( $action, [ DatabaseRegistry::ACTION_TABLE_LIST, DatabaseRegistry::ACTION_TABLE_SIZE, DatabaseRegistry::ACTION_AUTOLOAD_ANALYSIS, DatabaseRegistry::ACTION_OPTIONS_HEALTH, DatabaseRegistry::ACTION_ORPHAN_DETECTION, DatabaseRegistry::ACTION_HEALTH_SUMMARY, DatabaseRegistry::ACTION_ROW_COUNTS, DatabaseRegistry::ACTION_TABLE_STATS, DatabaseRegistry::ACTION_INDEX_ANALYSIS ], true ) ) {
			$table = $this->registry->sanitize_table( $table_raw );
			if ( null === $table ) {
				return new \WP_Error( 'wpcc_invalid_db_table', __( 'Table not in the allowed core table list.', 'wp-command-center' ) );
			}
		} else {
			$table = $table_raw ? $this->registry->sanitize_table( $table_raw ) : null;
		}

		$this->audit( 'database.inspect.started', [ 'action' => $action, 'table' => $table ?: 'all' ], $context );

		$start  = microtime( true );
		$result = match ( $action ) {
			DatabaseRegistry::ACTION_TABLE_LIST        => $this->table_list(),
			DatabaseRegistry::ACTION_TABLE_STATS       => $this->table_stats( $table ),
			DatabaseRegistry::ACTION_TABLE_SIZE        => $this->table_size(),
			DatabaseRegistry::ACTION_ROW_COUNTS        => $this->row_counts( $table ),
			DatabaseRegistry::ACTION_AUTOLOAD_ANALYSIS => $this->autoload_analysis(),
			DatabaseRegistry::ACTION_OPTIONS_HEALTH    => $this->options_health(),
			DatabaseRegistry::ACTION_INDEX_ANALYSIS    => $this->index_analysis( $table ),
			DatabaseRegistry::ACTION_ORPHAN_DETECTION  => $this->orphan_detection(),
			DatabaseRegistry::ACTION_HEALTH_SUMMARY    => $this->health_summary(),
			default => new \WP_Error( 'wpcc_invalid_db_action', __( 'Unknown action.', 'wp-command-center' ) ),
		};

		$duration = (int) ( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'database.inspect.failed', [ 'action' => $action, 'error' => $result->get_error_message(), 'duration_ms' => $duration ], $context );
			return $result;
		}

		$this->audit( 'database.inspect.completed', [ 'action' => $action, 'duration_ms' => $duration ], $context );

		$result['duration_ms'] = $duration;
		return $result;
	}

	// ── Table List ──

	private function table_list(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, ROUND((DATA_LENGTH + INDEX_LENGTH)/1024, 2) AS size_kb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$wpdb->prefix}%' ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC", ARRAY_A );

		$tables = [];
		foreach ( (array) $rows as $r ) {
			$short = str_replace( $wpdb->prefix, '', $r['name'] );
			$tables[] = [
				'name'       => $short,
				'full_name'  => $r['name'],
				'row_count'  => (int) ( $r['row_count'] ?? 0 ),
				'size_kb'    => (float) ( $r['size_kb'] ?? 0 ),
				'core'       => in_array( $short, DatabaseRegistry::CORE_TABLES, true ),
			];
		}

		return [ 'action' => 'db_table_list', 'tables' => $tables, 'count' => count( $tables ) ];
	}

	// ── Table Stats ──

	private function table_stats( ?string $table ): array|\WP_Error {
		if ( ! $table ) {
			return new \WP_Error( 'wpcc_missing_db_table', __( 'table is required.', 'wp-command-center' ) );
		}
		global $wpdb;
		$safe = esc_sql( $table );
		$row  = $wpdb->get_row( "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, ROUND(DATA_LENGTH/1024,2) AS data_kb, ROUND(INDEX_LENGTH/1024,2) AS index_kb, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024,2) AS total_kb FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$safe'", ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'wpcc_db_table_not_found', __( 'Table not found.', 'wp-command-center' ) );
		}
		return [
			'action'     => 'db_table_stats',
			'table'      => str_replace( $wpdb->prefix, '', $row['TABLE_NAME'] ),
			'engine'     => $row['ENGINE'] ?? 'unknown',
			'collation'  => $row['TABLE_COLLATION'] ?? 'unknown',
			'rows'       => (int) ( $row['TABLE_ROWS'] ?? 0 ),
			'data_kb'    => (float) ( $row['data_kb'] ?? 0 ),
			'index_kb'   => (float) ( $row['index_kb'] ?? 0 ),
			'total_kb'   => (float) ( $row['total_kb'] ?? 0 ),
		];
	}

	// ── Table Size ──

	private function table_size(): array {
		global $wpdb;
		$total = $wpdb->get_var( "SELECT ROUND(SUM(DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()" );
		$rows  = $wpdb->get_results( "SELECT TABLE_NAME, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$wpdb->prefix}%' ORDER BY (DATA_LENGTH+INDEX_LENGTH) DESC LIMIT 10", ARRAY_A );

		$largest = [];
		foreach ( (array) $rows as $r ) {
			$largest[] = [ 'name' => str_replace( $wpdb->prefix, '', $r['TABLE_NAME'] ), 'size_mb' => (float) $r['size_mb'] ];
		}

		return [ 'action' => 'db_table_size', 'total_db_mb' => (float) ( $total ?? 0 ), 'largest_tables' => $largest ];
	}

	// ── Row Counts ──

	private function row_counts( ?string $table ): array {
		global $wpdb;
		$counts = [];
		$targets = $table ? [ $table ] : array_map( fn( $t ) => $wpdb->prefix . $t, DatabaseRegistry::CORE_TABLES );
		foreach ( $targets as $t ) {
			if ( $table && $this->registry->sanitize_table( $t ) === null ) continue;
			$short = str_replace( $wpdb->prefix, '', $t );
			$cnt   = $wpdb->get_var( "SELECT COUNT(*) FROM `" . esc_sql( $t ) . "`" );
			$counts[] = [ 'table' => $short, 'rows' => (int) $cnt ];
		}
		return [ 'action' => 'db_row_counts', 'counts' => $counts ];
	}

	// ── Autoload Analysis ──

	private function autoload_analysis(): array {
		global $wpdb;
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload='yes'" );
		$size  = $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload='yes'" );
		$large = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS size_bytes, autoload FROM {$wpdb->options} WHERE autoload='yes' ORDER BY LENGTH(option_value) DESC LIMIT 20", ARRAY_A );

		$items = [];
		$redactor = new Redactor();
		foreach ( (array) $large as $r ) {
			$name = $r['option_name'];
			$items[] = [
				'option_name'    => $this->redact_sensitive( $name ),
				'size_bytes'     => (int) $r['size_bytes'],
				'is_sensitive'   => $this->registry->is_sensitive_option( $name ),
			];
		}

		return [
			'action'              => 'db_autoload_analysis',
			'autoloaded_count'    => (int) $total,
			'autoloaded_size_bytes' => (int) ( $size ?? 0 ),
			'largest_autoloaded'  => $items,
			'warning'             => ( (int) ( $size ?? 0 ) > 1048576 ) ? 'Autoloaded data exceeds 1MB. Consider reducing autoloaded options.' : null,
		];
	}

	// ── Options Health ──

	private function options_health(): array {
		global $wpdb;
		$total_options   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$autoloaded      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload='yes'" );
		$autoload_size   = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload='yes'" );
		$transients      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%_transient_%'" );
		$expired_est     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );

		return [
			'action'              => 'db_options_health',
			'total_options'       => $total_options,
			'autoloaded'          => $autoloaded,
			'autoloaded_size_bytes' => $autoload_size,
			'transient_count'     => $transients,
			'expired_transients'  => $expired_est,
			'warnings'            => array_values( array_filter( [
				$autoload_size > 1048576 ? 'Autoloaded options exceed 1MB' : null,
				$expired_est > 100 ? 'Over 100 expired transients detected' : null,
			] ) ),
		];
	}

	// ── Index Analysis ──

	private function index_analysis( ?string $table ): array|\WP_Error {
		global $wpdb;
		$targets = $table ? [ $table ] : array_map( fn( $t ) => $wpdb->prefix . $t, DatabaseRegistry::CORE_TABLES );
		$result  = [];

		foreach ( $targets as $t ) {
			$short = str_replace( $wpdb->prefix, '', $t );
			$indexes = $wpdb->get_results( "SHOW INDEX FROM `" . esc_sql( $t ) . "`", ARRAY_A );
			$ix_list = [];
			foreach ( (array) $indexes as $ix ) {
				$ix_list[] = [ 'name' => $ix['Key_name'], 'column' => $ix['Column_name'], 'unique' => ( 0 === (int) $ix['Non_unique'] ) ];
			}
			$result[ $short ] = [ 'indexes' => $ix_list, 'count' => count( $ix_list ) ];
		}

		return [ 'action' => 'db_index_analysis', 'tables' => $result ];
	}

	// ── Orphan Detection ──

	private function orphan_detection(): array {
		global $wpdb;
		$orphan_postmeta   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id=p.ID WHERE p.ID IS NULL" );
		$orphan_termrel    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON tr.object_id=p.ID WHERE p.ID IS NULL" );
		$orphan_commentmeta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta} cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id=c.comment_ID WHERE c.comment_ID IS NULL" );

		return [
			'action'              => 'db_orphan_detection',
			'orphan_postmeta'     => $orphan_postmeta,
			'orphan_term_relationships' => $orphan_termrel,
			'orphan_commentmeta'  => $orphan_commentmeta,
			'total_orphans'       => $orphan_postmeta + $orphan_termrel + $orphan_commentmeta,
			'warning'             => ( $orphan_postmeta + $orphan_termrel + $orphan_commentmeta > 100 ) ? 'Significant orphan records detected.' : null,
		];
	}

	// ── Health Summary ──

	private function health_summary(): array {
		global $wpdb;
		$db_size     = (float) $wpdb->get_var( "SELECT ROUND(SUM(DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()" );
		$autoload_sz = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload='yes'" );
		$orphan_pm   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id=p.ID WHERE p.ID IS NULL" );
		$largest     = $wpdb->get_row( "SELECT TABLE_NAME, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,2) AS size_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$wpdb->prefix}%' ORDER BY (DATA_LENGTH+INDEX_LENGTH) DESC LIMIT 1", ARRAY_A );
		$expired_tr  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );

		$warnings = [];
		if ( $autoload_sz > 1048576 ) $warnings[] = 'autoload_size_high';
		if ( $orphan_pm > 100 ) $warnings[] = 'orphan_records';
		if ( $expired_tr > 100 ) $warnings[] = 'expired_transients';

		return [
			'action'            => 'db_health_summary',
			'db_size_mb'        => $db_size,
			'largest_table'     => $largest ? [ 'name' => str_replace( $wpdb->prefix, '', $largest['TABLE_NAME'] ), 'size_mb' => (float) $largest['size_mb'] ] : null,
			'autoload_size_bytes' => $autoload_sz,
			'orphan_records'    => $orphan_pm,
			'expired_transients'=> $expired_tr,
			'warnings'          => $warnings,
			'warning_count'     => count( $warnings ),
		];
	}

	// ── Helpers ──

	private function redact_sensitive( string $name ): string {
		return $this->registry->is_sensitive_option( $name ) ? '[REDACTED_SECRET]' : $name;
	}

	private function audit( string $event, array $data, array $context = [] ): void {
		$audit = new AuditLog();
		$risk  = DatabaseRegistry::RISK_LOW;
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$audit->record( $event, array_merge( [ 'risk_level' => $risk, 'actor' => $actor ], $data ) );
	}

	public function get_registry(): DatabaseRegistry {
		return $this->registry;
	}
}
