<?php
/**
 * ISSUE 11 — Term lookup runtime (read-only).
 *
 * Finding a term_id previously required a safe_search_replace dry-run hack on
 * wp_terms (counts only) or a taxonomy-specific list op. This runtime provides a
 * generic, taxonomy-agnostic way to discover terms — term_list / term_get /
 * term_search — returning the identity fields (term_id, slug, name, taxonomy,
 * parent, count) needed to then target termmeta (e.g. acf_value_set object_type=term).
 *
 * Read-only: no writes, no rollback. Write ops (create/update/delete) can be added
 * later behind a capability.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class TermRuntimeManager {

	public const ACTIONS = [ 'term_list', 'term_get', 'term_search', 'term_describe' ];

	private const MAX_LIMIT = 200;

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function run( array $payload, array $context = [] ): array {
		$action = (string) ( $payload['action'] ?? '' );

		switch ( $action ) {
			case 'term_list':
				return $this->term_list( $payload );
			case 'term_get':
				return $this->term_get( $payload );
			case 'term_search':
				return $this->term_search( $payload );
			case 'term_describe':
				return [ 'action' => 'term_describe', 'runtime' => 'term_manage', 'actions' => self::ACTIONS, 'notes' => __( 'Read-only. term_get accepts term_id OR (slug + taxonomy). term_list/term_search accept taxonomy + filters. Returns term_id, slug, name, taxonomy, parent, count.', 'ai-command-center' ) ];
			default:
				return $this->error(
					'wpcc_invalid_term_action',
					sprintf(
						/* translators: 1: invalid action, 2: valid actions */
						__( 'Invalid term action "%1$s". Valid actions: %2$s.', 'ai-command-center' ),
						$action,
						implode( ', ', self::ACTIONS )
					),
					[ 'valid_actions' => self::ACTIONS ]
				);
		}
	}

	/** @param array<string,mixed> $p */
	private function term_list( array $p ): array {
		$taxonomy = sanitize_key( (string) ( $p['taxonomy'] ?? '' ) );
		if ( '' !== $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
			return $this->error( 'wpcc_invalid_taxonomy', sprintf( /* translators: %s: value */ __( 'Taxonomy "%s" does not exist.', 'ai-command-center' ), $taxonomy ), [ 'valid_taxonomies' => get_taxonomies( [], 'names' ) ] );
		}

		$args = [
			'taxonomy'   => '' !== $taxonomy ? $taxonomy : get_taxonomies( [], 'names' ),
			'hide_empty' => (bool) ( $p['hide_empty'] ?? false ),
			'number'     => min( self::MAX_LIMIT, max( 1, (int) ( $p['number'] ?? 100 ) ) ),
			'offset'     => max( 0, (int) ( $p['offset'] ?? 0 ) ),
		];
		if ( isset( $p['parent'] ) ) {
			$args['parent'] = (int) $p['parent'];
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $this->error( 'wpcc_term_query_failed', $terms->get_error_message() );
		}

		$items = array_map( [ $this, 'shape' ], $terms );
		$this->audit->record( 'term.list', [ 'taxonomy' => $taxonomy, 'count' => count( $items ) ] );
		return [ 'action' => 'term_list', 'taxonomy' => $taxonomy ?: null, 'terms' => $items, 'total' => count( $items ) ];
	}

	/** @param array<string,mixed> $p */
	private function term_get( array $p ): array {
		$term_id = (int) ( $p['term_id'] ?? 0 );
		$term    = null;

		if ( $term_id > 0 ) {
			$term = get_term( $term_id );
		} else {
			$slug     = sanitize_title( (string) ( $p['slug'] ?? '' ) );
			$name     = (string) ( $p['name'] ?? '' );
			$taxonomy = sanitize_key( (string) ( $p['taxonomy'] ?? '' ) );
			if ( '' === $taxonomy || ( '' === $slug && '' === $name ) ) {
				return $this->error( 'wpcc_missing_term_selector', __( 'Provide term_id, or (taxonomy + slug), or (taxonomy + name).', 'ai-command-center' ) );
			}
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return $this->error( 'wpcc_invalid_taxonomy', sprintf( /* translators: %s: value */ __( 'Taxonomy "%s" does not exist.', 'ai-command-center' ), $taxonomy ) );
			}
			$term = '' !== $slug ? get_term_by( 'slug', $slug, $taxonomy ) : get_term_by( 'name', $name, $taxonomy );
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error( 'wpcc_term_not_found', __( 'Term not found.', 'ai-command-center' ) );
		}

		$this->audit->record( 'term.get', [ 'term_id' => $term->term_id ] );
		return [ 'action' => 'term_get', 'term' => $this->shape( $term ) ];
	}

	/** @param array<string,mixed> $p */
	private function term_search( array $p ): array {
		$search = sanitize_text_field( (string) ( $p['search'] ?? $p['query'] ?? $p['name'] ?? '' ) );
		if ( '' === $search ) {
			return $this->error( 'wpcc_missing_search', __( "term_search requires a 'search' parameter (matches term name/slug).", 'ai-command-center' ) );
		}
		$taxonomy = sanitize_key( (string) ( $p['taxonomy'] ?? '' ) );
		if ( '' !== $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
			return $this->error( 'wpcc_invalid_taxonomy', sprintf( /* translators: %s: value */ __( 'Taxonomy "%s" does not exist.', 'ai-command-center' ), $taxonomy ) );
		}

		$terms = get_terms( [
			'taxonomy'   => '' !== $taxonomy ? $taxonomy : get_taxonomies( [], 'names' ),
			'hide_empty' => false,
			'search'     => $search,
			'number'     => self::MAX_LIMIT,
		] );
		if ( is_wp_error( $terms ) ) {
			return $this->error( 'wpcc_term_query_failed', $terms->get_error_message() );
		}

		$items = array_map( [ $this, 'shape' ], $terms );
		$this->audit->record( 'term.search', [ 'search' => $search, 'taxonomy' => $taxonomy, 'count' => count( $items ) ] );
		return [ 'action' => 'term_search', 'search' => $search, 'taxonomy' => $taxonomy ?: null, 'terms' => $items, 'total' => count( $items ) ];
	}

	/** @param \WP_Term $term */
	private function shape( $term ): array {
		return [
			'term_id'  => (int) $term->term_id,
			'slug'     => (string) $term->slug,
			'name'     => (string) $term->name,
			'taxonomy' => (string) $term->taxonomy,
			'parent'   => (int) $term->parent,
			'count'    => (int) $term->count,
		];
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message, array $extra = [] ): array {
		return array_merge( [ 'error' => true, 'code' => $code, 'message' => $message ], $extra );
	}
}
