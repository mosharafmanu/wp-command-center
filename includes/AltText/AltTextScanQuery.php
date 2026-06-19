<?php
/**
 * STEP 110 — Phase 2 (AI Alt Text), Task 7A: read-only Media Library alt-text scan.
 *
 * A READ-ONLY audit: which image attachments are missing / weak / ok on alt text.
 * It is the first AI-Alt-Text surface and deliberately does NOTHING but read:
 *   - NO writes (wp_postmeta / wp_posts / wp_options / wpcc_* tables).
 *   - NO outbound HTTP (no provider/model call — that is Task 7B).
 *   - NO proposal creation, NO engine interaction, NO mutation of any kind.
 *
 * Classification (conservative):
 *   - missing = empty/whitespace alt
 *   - weak    = alt equals the file name (with/without extension) OR shorter than
 *               WEAK_MIN_LENGTH
 *   - ok      = everything else
 *
 * Cheap by design: a bounded summary pass (≤ SUMMARY_CAP) + one paginated page
 * query. `used_in` enrichment (MediaUsageResolver) is OPT-IN because it is
 * per-item expensive. Open-proposal detection reads through ProposalStore (its
 * read API), never a direct table write.
 */

namespace WPCommandCenter\AltText;

use WPCommandCenter\Operations\MediaUsageResolver;
use WPCommandCenter\Proposals\ProposalStore;

defined( 'ABSPATH' ) || exit;

final class AltTextScanQuery {

	private const DEFAULT_LIMIT    = 20;
	private const MAX_LIMIT        = 100;
	private const SUMMARY_CAP      = 5000; // bound the population classification pass
	private const WEAK_MIN_LENGTH  = 6;    // conservative: flag stubs like "img", "logo"

	private ProposalStore $store;

	public function __construct( ?ProposalStore $store = null ) {
		$this->store = $store ?? new ProposalStore();
	}

	/**
	 * @param array<string,mixed> $filters state (missing|weak|ok|all), with_usage (bool).
	 * @return array<string,mixed> Scan envelope: summary + items + pagination.
	 */
	public function audit( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$limit  = min( self::MAX_LIMIT, max( 1, $limit ) );
		$offset = max( 0, $offset );

		$state      = in_array( $filters['state'] ?? 'all', [ 'missing', 'weak', 'ok', 'all' ], true ) ? (string) $filters['state'] : 'all';
		$with_usage = ! empty( $filters['with_usage'] );

		[ $where, $params ]              = $this->image_where();
		[ $state_sql, $state_params ]    = $this->state_clause( $state );
		$page_where  = $where . $state_sql;
		$page_params = array_merge( $params, $state_params );

		// total_count is scoped to the filtered population (so pagination is meaningful per state).
		$total_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p {$this->join_sql()} WHERE {$page_where}", $page_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_mime_type, am.meta_value AS alt, fm.meta_value AS file
				 FROM {$wpdb->posts} p {$this->join_sql()}
				 WHERE {$page_where}
				 ORDER BY p.ID DESC LIMIT %d OFFSET %d",
				array_merge( $page_params, [ $limit, $offset ] )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows = is_array( $rows ) ? $rows : [];

		$items = [];
		foreach ( $rows as $r ) {
			$id    = (int) $r['ID'];
			$alt   = (string) ( $r['alt'] ?? '' );
			$state_of = $this->classify_state( $alt, (string) ( $r['file'] ?? '' ) );

			// For weak/ok the SQL only narrowed to "described"; keep only the matching state.
			if ( 'all' !== $state && 'missing' !== $state && $state_of !== $state ) {
				continue;
			}

			$item = [
				'attachment_id'     => $id,
				'title'             => (string) ( $r['post_title'] ?? '' ),
				'url'               => (string) wp_get_attachment_url( $id ),
				'mime'              => (string) ( $r['post_mime_type'] ?? '' ),
				'alt'               => $alt,
				'state'             => $state_of,
				'has_open_proposal' => $this->open_proposal( $id ),
			];
			if ( $with_usage ) {
				$item['used_in'] = $this->used_in( $id );
			}
			$items[] = $item;
		}

		$returned    = count( $items );
		$next_offset = $offset + count( $rows );
		$has_more    = $next_offset < $total_count;

		return [
			'action'      => 'alt_text_scan',
			'summary'     => $this->summary(),
			'items'       => $items,
			'total_count' => $total_count,
			'returned'    => $returned,
			'has_more'    => $has_more,
			'next_cursor' => $has_more ? base64_encode( (string) wp_json_encode( [ 'offset' => $next_offset ] ) ) : null,
			'limit'       => $limit,
			'offset'      => $offset,
			'filters'     => (object) [ 'state' => $state, 'with_usage' => $with_usage ],
		];
	}

	/** Population-level counts over a bounded classification pass (read-only). */
	private function summary(): array {
		global $wpdb;
		[ $where, $params ] = $this->image_where();

		$total_images = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT am.meta_value AS alt, fm.meta_value AS file
				 FROM {$wpdb->posts} p {$this->join_sql()}
				 WHERE {$where} ORDER BY p.ID DESC LIMIT %d",
				array_merge( $params, [ self::SUMMARY_CAP ] )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows = is_array( $rows ) ? $rows : [];

		$missing = 0;
		$weak    = 0;
		$ok      = 0;
		foreach ( $rows as $r ) {
			switch ( $this->classify_state( (string) ( $r['alt'] ?? '' ), (string) ( $r['file'] ?? '' ) ) ) {
				case 'missing': ++$missing; break;
				case 'weak':    ++$weak;    break;
				default:        ++$ok;      break;
			}
		}

		$scanned   = count( $rows );
		$described = $weak + $ok;
		$pct       = $scanned > 0 ? round( ( $described / $scanned ) * 100, 1 ) : 0.0;

		return [
			'total_images'  => $total_images,
			'scanned'       => $scanned,
			'capped'        => $total_images > $scanned,
			'missing'       => $missing,
			'weak'          => $weak,
			'described'     => $described,
			'described_pct' => $pct,
		];
	}

	/** Conservative state classification. */
	private function classify_state( string $alt, string $file ): string {
		$a = trim( $alt );
		if ( '' === $a ) {
			return 'missing';
		}
		$lower    = strtolower( $a );
		$base_ext = strtolower( basename( $file ) );
		$base     = strtolower( pathinfo( $file, PATHINFO_FILENAME ) );
		if ( ( '' !== $base && $lower === $base ) || ( '' !== $base_ext && $lower === $base_ext ) || mb_strlen( $a ) < self::WEAK_MIN_LENGTH ) {
			return 'weak';
		}
		return 'ok';
	}

	/** Open-proposal detection via the ProposalStore READ API (no writes). */
	private function open_proposal( int $id ): bool {
		$tid = (string) $id;
		if ( $this->store->count( [ 'target_id' => $tid, 'status' => ProposalStore::STATUS_DRAFT ] ) > 0 ) {
			return true;
		}
		return $this->store->count( [ 'target_id' => $tid, 'status' => ProposalStore::STATUS_PENDING_APPROVAL ] ) > 0;
	}

	/** Opt-in usage enrichment (per-item expensive — only when requested). */
	private function used_in( int $id ): array {
		$c = ( new MediaUsageResolver() )->classify( $id );
		return [
			'count'     => (int) ( $c['reference_count'] ?? 0 ),
			'status'    => (string) ( $c['status'] ?? 'unused' ),
			'by_source' => (array) ( $c['by_source'] ?? [] ),
		];
	}

	/** Shared image-attachment predicate. @return array{0:string,1:array<int,mixed>} */
	private function image_where(): array {
		global $wpdb;
		return [
			"p.post_type = %s AND p.post_status = %s AND p.post_mime_type LIKE %s",
			[ 'attachment', 'inherit', $wpdb->esc_like( 'image/' ) . '%' ],
		];
	}

	/** LEFT JOINs for alt + file metadata, shared by every query. */
	private function join_sql(): string {
		global $wpdb;
		return "LEFT JOIN {$wpdb->postmeta} am ON am.post_id = p.ID AND am.meta_key = '_wp_attachment_image_alt'
		        LEFT JOIN {$wpdb->postmeta} fm ON fm.post_id = p.ID AND fm.meta_key = '_wp_attached_file'";
	}

	/** SQL narrowing for the items page by state. @return array{0:string,1:array<int,mixed>} */
	private function state_clause( string $state ): array {
		if ( 'missing' === $state ) {
			return [ " AND ( am.meta_value IS NULL OR am.meta_value = '' )", [] ];
		}
		if ( 'weak' === $state || 'ok' === $state ) {
			return [ " AND am.meta_value IS NOT NULL AND am.meta_value <> ''", [] ];
		}
		return [ '', [] ];
	}
}
