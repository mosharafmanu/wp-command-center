<?php
/**
 * STEP 111 — Governed Action #2 (SEO Meta Generator), Slice 1: read-only SEO audit.
 *
 * A READ-ONLY audit of public content: which posts/pages/CPTs are missing / weak /
 * ok on SEO meta (title + description), per the active SEO plugin. It is the first
 * SEO surface and deliberately does NOTHING but read — mirroring AltTextScanQuery:
 *   - NO writes (wp_postmeta / wp_posts / wp_options / wpcc_* tables).
 *   - NO outbound HTTP (no provider/model call — that is Slice 2).
 *   - NO proposal creation, NO seo_update, NO engine interaction, NO mutation.
 *
 * Plugin abstraction: reuses SeoProvider (Rank Math / Yoast / NONE). When NONE is
 * active the audit returns an empty population with provider_available=false so the
 * Builder shows an empty-state and no generation controls.
 *
 * Classification (mirrors SeoRuntimeManager's thresholds — kept in sync via the
 * constants below; no content read needed for the list):
 *   - missing = empty SEO title OR empty meta description
 *   - weak    = both present but title > TITLE_MAX, or description outside
 *               [DESC_MIN, DESC_MAX], or focus keyword absent
 *   - ok      = both present, within bounds, focus keyword set
 *
 * Cheap + bounded by design: a bounded summary pass (≤ SUMMARY_CAP) + one paginated
 * page. Canonical pagination envelope (items/total_count/returned/has_more/
 * next_cursor/limit/offset/filters), identical to the platform's other list reads.
 */

namespace WPCommandCenter\Seo;

use WPCommandCenter\Operations\SeoProvider;

defined( 'ABSPATH' ) || exit;

final class SeoAuditQuery {

	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT     = 100;
	private const SUMMARY_CAP   = 5000; // bound the population classification pass

	/** Thresholds mirror SeoRuntimeManager (TITLE_MAX / DESC_MIN / DESC_MAX). */
	private const TITLE_MAX = 60;
	private const DESC_MIN  = 120;
	private const DESC_MAX  = 160;

	/**
	 * @param array<string,mixed> $filters state (missing|weak|ok|all).
	 * @return array<string,mixed> Audit envelope: provider + summary + items + pagination.
	 */
	public function audit( array $filters, int $limit, int $offset ): array {
		$limit  = min( self::MAX_LIMIT, max( 1, $limit ) );
		$offset = max( 0, $offset );
		$state  = in_array( $filters['state'] ?? 'all', [ 'missing', 'weak', 'ok', 'all' ], true ) ? (string) $filters['state'] : 'all';

		$provider = SeoProvider::detect();

		// No supported SEO plugin → empty population; the Builder shows an empty-state
		// and hides any generation controls.
		if ( SeoProvider::NONE === $provider ) {
			return $this->envelope( $provider, false, $this->empty_summary(), [], 0, 0, $limit, $offset, $state );
		}

		$title_key = SeoProvider::meta_key( 'title', $provider );
		$desc_key  = SeoProvider::meta_key( 'description', $provider );

		[ $total_count, $rows ] = $this->page( $provider, $title_key, $desc_key, $state, $limit, $offset );

		$items = [];
		foreach ( $rows as $r ) {
			$id    = (int) $r['ID'];
			$seo   = SeoProvider::read( $id, $provider );
			$st    = $this->classify( $seo );

			// For weak/ok the SQL only narrowed to "described"; keep the matching state.
			if ( ( 'weak' === $state || 'ok' === $state ) && $st !== $state ) {
				continue;
			}

			$items[] = [
				'post_id'         => $id,
				'title'           => (string) ( $r['post_title'] ?? '' ),
				'post_type'       => (string) ( $r['post_type'] ?? '' ),
				'edit_link'       => (string) get_edit_post_link( $id, 'raw' ),
				'seo_title'       => (string) $seo['title'],
				'seo_description' => (string) $seo['description'],
				'focus_keyword'   => (string) $seo['focus_keyword'],
				'state'           => $st,
				'score'           => $this->score( $seo ),
			];
		}

		$returned    = count( $items );
		$next_offset = $offset + count( $rows );
		$has_more    = $next_offset < $total_count;

		return $this->envelope( $provider, true, $this->summary( $provider ), $items, $total_count, $returned, $limit, $offset, $state, $has_more, $next_offset );
	}

	/**
	 * One population page + its filtered total. Mirrors AltTextScanQuery: 'missing'
	 * narrows in SQL on the active provider's title/description keys (exact total);
	 * 'weak'/'ok' narrow to "described" then refine in PHP (total = described count,
	 * a documented over-count); 'all' is the full population.
	 *
	 * @return array{0:int,1:array<int,array<string,mixed>>}
	 */
	private function page( string $provider, string $title_key, string $desc_key, string $state, int $limit, int $offset ): array {
		global $wpdb;

		[ $where, $params ] = $this->population_where();
		$join = $this->meta_join( $title_key, $desc_key );

		if ( 'missing' === $state ) {
			$where .= " AND ( tm.meta_value IS NULL OR tm.meta_value = '' OR dm.meta_value IS NULL OR dm.meta_value = '' )";
		} elseif ( 'weak' === $state || 'ok' === $state ) {
			$where .= " AND tm.meta_value IS NOT NULL AND tm.meta_value <> '' AND dm.meta_value IS NOT NULL AND dm.meta_value <> ''";
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p {$join} WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type
				 FROM {$wpdb->posts} p {$join}
				 WHERE {$where}
				 ORDER BY p.ID DESC LIMIT %d OFFSET %d",
				array_merge( $params, [ $limit, $offset ] )
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL

		return [ $total, is_array( $rows ) ? $rows : [] ];
	}

	/** Bounded population counts (read-only). */
	private function summary( string $provider ): array {
		global $wpdb;
		[ $where, $params ] = $this->population_where();

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} ORDER BY p.ID DESC LIMIT %d",
				array_merge( $params, [ self::SUMMARY_CAP ] )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : [];

		$missing = 0; $weak = 0; $ok = 0;
		foreach ( $ids as $id ) {
			switch ( $this->classify( SeoProvider::read( $id, $provider ) ) ) {
				case 'missing': ++$missing; break;
				case 'weak':    ++$weak;    break;
				default:        ++$ok;      break;
			}
		}

		$scanned   = count( $ids );
		$optimized = $ok;
		$pct       = $scanned > 0 ? round( ( $optimized / $scanned ) * 100, 1 ) : 0.0;

		return [
			'total_content'  => $total,
			'scanned'        => $scanned,
			'capped'         => $total > $scanned,
			'missing'        => $missing,
			'weak'           => $weak,
			'ok'             => $ok,
			'optimized_pct'  => $pct,
		];
	}

	private function empty_summary(): array {
		return [
			'total_content' => 0, 'scanned' => 0, 'capped' => false,
			'missing' => 0, 'weak' => 0, 'ok' => 0, 'optimized_pct' => 0.0,
		];
	}

	/** Meta-only state classification (no content read). */
	private function classify( array $seo ): string {
		$title = trim( (string) ( $seo['title'] ?? '' ) );
		$desc  = trim( (string) ( $seo['description'] ?? '' ) );
		if ( '' === $title || '' === $desc ) {
			return 'missing';
		}
		$kw      = trim( (string) ( $seo['focus_keyword'] ?? '' ) );
		$desc_len = mb_strlen( $desc );
		if ( mb_strlen( $title ) > self::TITLE_MAX || $desc_len < self::DESC_MIN || $desc_len > self::DESC_MAX || '' === $kw ) {
			return 'weak';
		}
		return 'ok';
	}

	/** Lightweight 0–100 meta score (mirrors the meta-only seo_analyze checks). */
	private function score( array $seo ): int {
		$title = trim( (string) ( $seo['title'] ?? '' ) );
		$desc  = trim( (string) ( $seo['description'] ?? '' ) );
		$kw    = trim( (string) ( $seo['focus_keyword'] ?? '' ) );
		$len   = mb_strlen( $desc );

		$checks = [
			'' !== $title,
			'' !== $title && mb_strlen( $title ) <= self::TITLE_MAX,
			'' !== $desc,
			$len >= self::DESC_MIN && $len <= self::DESC_MAX,
			'' !== $kw,
			'' !== (string) ( $seo['canonical'] ?? '' ),
		];
		$passed = count( array_filter( $checks ) );
		return (int) round( ( $passed / count( $checks ) ) * 100 );
	}

	/**
	 * Public-content predicate: public post types (excluding attachments) that are
	 * published. @return array{0:string,1:array<int,mixed>}
	 */
	private function population_where(): array {
		$types = array_values( array_diff( get_post_types( [ 'public' => true ], 'names' ), [ 'attachment' ] ) );
		if ( empty( $types ) ) {
			$types = [ 'post', 'page' ];
		}
		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$where        = "p.post_status = %s AND p.post_type IN ({$placeholders})";
		return [ $where, array_merge( [ 'publish' ], $types ) ];
	}

	/** LEFT JOINs for the active provider's title + description meta. */
	private function meta_join( string $title_key, string $desc_key ): string {
		global $wpdb;
		$tk = esc_sql( $title_key );
		$dk = esc_sql( $desc_key );
		return "LEFT JOIN {$wpdb->postmeta} tm ON tm.post_id = p.ID AND tm.meta_key = '{$tk}'
		        LEFT JOIN {$wpdb->postmeta} dm ON dm.post_id = p.ID AND dm.meta_key = '{$dk}'";
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return array<string,mixed>
	 */
	private function envelope( string $provider, bool $available, array $summary, array $items, int $total_count, int $returned, int $limit, int $offset, string $state, bool $has_more = false, int $next_offset = 0 ): array {
		return [
			'action'             => 'seo_audit',
			'provider'           => $provider,
			'provider_label'     => SeoProvider::label( $provider ),
			'provider_available' => $available,
			'summary'            => $summary,
			'items'              => $items,
			'total_count'        => $total_count,
			'returned'           => $returned,
			'has_more'           => $has_more,
			'next_cursor'        => $has_more ? base64_encode( (string) wp_json_encode( [ 'offset' => $next_offset ] ) ) : null,
			'limit'              => $limit,
			'offset'             => $offset,
			'filters'            => (object) [ 'state' => $state ],
		];
	}
}
