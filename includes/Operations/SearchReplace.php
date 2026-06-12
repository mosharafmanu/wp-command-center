<?php
/**
 * Step 26 — Safe Search & Replace Operation.
 *
 * Safe database search and replace operation with serialized data handling.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SearchReplace {

	/**
	 * Run the search and replace operation.
	 *
	 * @param array{
	 *     search: string,
	 *     replace: string,
	 *     dry_run?: bool,
	 *     tables: string[],
	 *     case_sensitive?: bool
	 * } $params
	 * @param array $context
	 *
	 * @return array|\WP_Error Result summary or error.
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		global $wpdb;

		$search         = (string) ( $params['search'] ?? '' );
		$replace        = (string) ( $params['replace'] ?? '' );
		$dry_run        = filter_var( $params['dry_run'] ?? true, FILTER_VALIDATE_BOOLEAN );
		$case_sensitive = filter_var( $params['case_sensitive'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$tables         = (array) ( $params['tables'] ?? [] );

		if ( '' === $search ) {
			return new \WP_Error( 'wpcc_empty_search', __( 'Search string cannot be empty.', 'wp-command-center' ) );
		}

		if ( $search === $replace ) {
			return new \WP_Error( 'wpcc_search_equals_replace', __( 'Search and replace strings cannot be identical.', 'wp-command-center' ) );
		}

		if ( empty( $tables ) ) {
			return new \WP_Error( 'wpcc_no_tables_selected', __( 'No tables selected for search and replace.', 'wp-command-center' ) );
		}

		// Validate tables
		foreach ( $tables as $table ) {
			if ( ! str_starts_with( $table, $wpdb->prefix ) ) {
				return new \WP_Error( 'wpcc_invalid_table_prefix', sprintf( __( 'Table %s does not start with the required WordPress prefix.', 'wp-command-center' ), $table ) );
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return new \WP_Error( 'wpcc_invalid_table', sprintf( __( 'Table %s does not exist.', 'wp-command-center' ), $table ) );
			}
		}

		$tables_checked  = 0;
		$matches_found   = 0;
		$rows_affected   = 0;
		$tables_affected = [];

		foreach ( $tables as $table ) {
			$tables_checked++;
			$table_has_match = false;

			// Get primary key
			$primary_key = $wpdb->get_var( $wpdb->prepare( "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = %s AND TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY'", $table ) );

			if ( ! $primary_key ) {
				continue;
			}

			// Get columns
			$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );
			if ( empty( $columns ) ) {
				continue;
			}

			// Build LIKE query to fetch potential matches efficiently
			$where = [];
			foreach ( $columns as $col ) {
				$where[] = $wpdb->prepare( "{$col} LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
			}
			$where_sql = implode( ' OR ', $where );

			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where_sql}", ARRAY_A );

			foreach ( $rows as $row ) {
				$updated_row = [];
				$row_matches = 0;

				foreach ( $row as $col => $val ) {
					if ( $col === $primary_key || null === $val || '' === $val ) {
						continue;
					}

					$new_val = $this->recursive_unserialize_replace( $val, $search, $replace, $case_sensitive, $row_matches );

					if ( $new_val !== $val ) {
						$updated_row[ $col ] = $new_val;
					}
				}

				if ( ! empty( $updated_row ) ) {
					$matches_found += $row_matches;
					$rows_affected++;
					$table_has_match = true;

					if ( ! $dry_run ) {
						$wpdb->update( $table, $updated_row, [ $primary_key => $row[ $primary_key ] ] );
					}
				}
			}

			if ( $table_has_match ) {
				$tables_affected[] = $table;
			}
		}

		return [
			'dry_run'         => $dry_run,
			'tables_checked'  => $tables_checked,
			'tables_affected' => $tables_affected,
			'matches_found'   => $matches_found,
			'rows_affected'   => $rows_affected,
			'warning'         => __( 'External database backup is strongly recommended before running a live search and replace.', 'wp-command-center' ),
		];
	}

	/**
	 * Safely replace strings inside serialized data.
	 */
	private function recursive_unserialize_replace( mixed $data, string $search, string $replace, bool $case_sensitive, int &$matches_found ): mixed {
		if ( is_string( $data ) ) {
			$unserialized = @unserialize( $data );
			if ( false !== $unserialized || 'b:0;' === $data ) {
				$replaced = $this->recursive_unserialize_replace( $unserialized, $search, $replace, $case_sensitive, $matches_found );
				return serialize( $replaced );
			}

			$count = 0;
			if ( $case_sensitive ) {
				$result = str_replace( $search, $replace, $data, $count );
			} else {
				$result = str_ireplace( $search, $replace, $data, $count );
			}
			$matches_found += $count;
			return $result;

		} elseif ( is_array( $data ) ) {
			$new_data = [];
			foreach ( $data as $key => $value ) {
				$new_data[ $key ] = $this->recursive_unserialize_replace( $value, $search, $replace, $case_sensitive, $matches_found );
			}
			return $new_data;

		} elseif ( is_object( $data ) ) {
			$new_data = clone $data;
			foreach ( $data as $key => $value ) {
				$new_data->$key = $this->recursive_unserialize_replace( $value, $search, $replace, $case_sensitive, $matches_found );
			}
			return $new_data;
		}

		return $data;
	}
}
