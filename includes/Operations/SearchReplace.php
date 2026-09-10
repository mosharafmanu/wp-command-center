<?php
/**
 * Step 26 — Safe Search & Replace Operation.
 *
 * Safe database search and replace operation with serialized data handling.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SearchReplace {

	/** ISSUE 16 — default cap on the return_matches row list. */
	private const DEFAULT_MAX_MATCHES = 25;

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
		$return_matches = filter_var( $params['return_matches'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$max_matches    = isset( $params['max_matches'] ) ? max( 1, (int) $params['max_matches'] ) : self::DEFAULT_MAX_MATCHES;

		if ( '' === $search ) {
			return new \WP_Error( 'wpcc_empty_search', __( 'Search string cannot be empty.', 'action-steward' ) );
		}

		if ( $search === $replace ) {
			return new \WP_Error( 'wpcc_search_equals_replace', __( 'Search and replace strings cannot be identical.', 'action-steward' ) );
		}

		if ( empty( $tables ) ) {
			return new \WP_Error( 'wpcc_no_tables_selected', __( 'No tables selected for search and replace.', 'action-steward' ) );
		}

		// Validate tables
		foreach ( $tables as $table ) {
			if ( ! str_starts_with( $table, $wpdb->prefix ) ) {
				return new \WP_Error( 'wpcc_invalid_table_prefix', sprintf( /* translators: %s: value */ __( 'Table %s does not start with the required WordPress prefix.', 'action-steward' ), $table ) );
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return new \WP_Error( 'wpcc_invalid_table', sprintf( /* translators: %s: value */ __( 'Table %s does not exist.', 'action-steward' ), $table ) );
			}
		}

		$tables_checked   = 0;
		$matches_found    = 0;
		$rows_affected    = 0;
		$tables_affected  = [];
		$matches_detail   = [];
		$matches_omitted  = 0;

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

						if ( $return_matches ) {
							if ( count( $matches_detail ) < $max_matches ) {
								$matches_detail[] = [
									'table'              => $table,
									'primary_key_column' => $primary_key,
									'primary_key_value'  => $row[ $primary_key ],
									'column'             => $col,
									'excerpt'            => $this->build_excerpt( (string) $val, $search, $case_sensitive ),
								];
							} else {
								++$matches_omitted;
							}
						}
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

		$result = [
			'dry_run'         => $dry_run,
			'tables_checked'  => $tables_checked,
			'tables_affected' => $tables_affected,
			'matches_found'   => $matches_found,
			'rows_affected'   => $rows_affected,
			'warning'         => __( 'External database backup is strongly recommended before running a live search and replace.', 'action-steward' ),
		];

		if ( $return_matches ) {
			$result['matches']          = $matches_detail;
			$result['matches_returned'] = count( $matches_detail );
			$result['matches_omitted']  = $matches_omitted;
		}

		return $result;
	}

	/**
	 * ISSUE 16 — a short excerpt of the original column value centered on the
	 * search term, so return_matches results are readable without dumping full
	 * (possibly serialized) row contents.
	 */
	private function build_excerpt( string $haystack, string $search, bool $case_sensitive ): string {
		$pos = $case_sensitive ? strpos( $haystack, $search ) : stripos( $haystack, $search );
		if ( false === $pos ) {
			// Match was inside a serialized/nested value rather than the raw
			// column string itself — fall back to a plain leading excerpt.
			return mb_substr( $haystack, 0, 80 );
		}

		$start   = max( 0, $pos - 40 );
		$length  = strlen( $search ) + 80;
		$excerpt = substr( $haystack, $start, $length );

		return ( $start > 0 ? '…' : '' ) . $excerpt . ( ( $start + $length ) < strlen( $haystack ) ? '…' : '' );
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
