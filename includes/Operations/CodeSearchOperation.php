<?php
/**
 * STEP 87 — code_search operation handler.
 *
 * Bridges the existing CodeSearch service to the Operations framework so REST
 * (/operations/code_search/run) and MCP (code_search tool) share one search
 * engine. Read-only: search_text, search_symbol, search_file. Secrets in match
 * text are redacted, mirroring the REST /search guarantee.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\AiAgent\CodeSearch;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class CodeSearchOperation {

	const ACTIONS = [ 'search_text', 'search_symbol', 'search_file' ];

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_search_action', sprintf( __( 'Invalid action: %s. Use search_text, search_symbol, or search_file.', 'wp-command-center' ), esc_html( $action ) ) );
		}

		$query = isset( $params['query'] ) ? (string) $params['query'] : (string) ( $params['q'] ?? '' );
		$args  = [
			'path'        => (string) ( $params['path'] ?? '' ),
			'max_results' => isset( $params['max_results'] ) ? (int) $params['max_results'] : null,
		];
		$args = array_filter( $args, static fn( $v ) => null !== $v && '' !== $v );

		$search = new CodeSearch();

		$result = match ( $action ) {
			'search_text'   => $search->search( $query, $args + [ 'type' => 'text' ] ),
			'search_symbol' => $this->search_symbol( $search, $query, $args ),
			'search_file'   => $search->find_files( $query, $args ),
		};

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Redact secrets that may appear in match text (file search has none).
		if ( isset( $result['matches'] ) && 'search_file' !== $action ) {
			$redactor = new Redactor();
			foreach ( $result['matches'] as $i => $match ) {
				if ( isset( $match['text'] ) ) {
					$result['matches'][ $i ]['text'] = $redactor->redact( (string) $match['text'] )['text'];
				}
			}
		}

		$result['action'] = $action;
		$this->audit( 'code.search', [
			'action'      => $action,
			'query'       => $query,
			'path'        => $args['path'] ?? '',
			'match_count' => $result['match_count'] ?? 0,
		], $context );

		return $result;
	}

	/**
	 * Symbol search = function + class + hook definitions, merged and de-duped.
	 */
	private function search_symbol( CodeSearch $search, string $query, array $args ): array|\WP_Error {
		$merged   = [];
		$scanned  = 0;
		$skipped  = [];
		$truncate = false;

		foreach ( [ 'function', 'class', 'hook' ] as $type ) {
			$res = $search->search( $query, $args + [ 'type' => $type ] );

			if ( is_wp_error( $res ) ) {
				return $res;
			}

			foreach ( $res['matches'] as $m ) {
				$key = ( $m['file'] ?? '' ) . ':' . ( $m['line'] ?? '' );
				$merged[ $key ] = $m + [ 'symbol_type' => $type ];
			}

			$scanned  = max( $scanned, $res['files_searched'] ?? $res['files_scanned'] ?? 0 );
			$truncate = $truncate || ! empty( $res['truncated'] );
			foreach ( (array) ( $res['skipped'] ?? [] ) as $s ) {
				$skipped[ $s['file'] ?? '' ] = $s; // de-dup across the 3 passes
			}
		}

		$skipped = array_values( $skipped );

		return [
			'query'          => $query,
			'path'           => $args['path'] ?? '',
			'type'           => 'symbol',
			'matches'        => array_values( $merged ),
			'match_count'    => count( $merged ),
			'files_searched' => $scanned,
			'files_skipped'  => count( $skipped ),
			'skipped'        => $skipped,
			'complete'       => 0 === count( $skipped ) && ! $truncate,
			'truncated'      => $truncate,
			'files_scanned'  => $scanned,
		];
	}

	private function audit( string $event, array $data, array $context ): void {
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		( new AuditLog() )->record( $event, array_merge( [ 'actor' => $actor ], $data ) );
	}
}
