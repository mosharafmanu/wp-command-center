<?php
/**
 * STEP 100.8 — Media Usage Resolver.
 *
 * Answers "where is this media actually used?" by scanning every place an
 * attachment can be referenced across a WordPress install, and classifies each
 * item so a (future) AI agent can decide whether it is actively used, indirectly
 * referenced, orphaned, or a safe cleanup candidate.
 *
 * READ-ONLY. This is *cleanup intelligence*, not optimization — it never mutates
 * anything. It is the audit-first prerequisite for STEP 100.9 cleanup, which must
 * re-check usage at execution time before trashing anything.
 *
 * Sources scanned: WordPress core (featured image, post content), Gutenberg
 * blocks, WooCommerce (featured + product gallery), ACF fields, ACF options
 * pages, Elementor (`_elementor_data`), theme_mods, and common option storage
 * (site_icon / site_logo / generic URL references). A reference is "active" when
 * its host is live (published/private/future post, or live site config) and
 * "indirect" when it only appears in non-published content or as a loose
 * URL/meta string. Detection is deliberately conservative: when in doubt an item
 * is treated as referenced, never as unused, so cleanup stays safe.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class MediaUsageResolver {

	/** Post statuses that count as a live/active reference host. */
	private const ACTIVE_STATUSES = [ 'publish', 'private', 'future' ];

	/**
	 * All references to an attachment, across every scanned source.
	 *
	 * @return array<int,array<string,mixed>> Each: { source, status, ... context }
	 */
	public function references( int $id ): array {
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return [];
		}

		$basenames = $this->attachment_basenames( $id );
		$refs      = [];

		// 1. Featured image (_thumbnail_id) — core + WooCommerce products.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_status, p.post_type FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d",
			$id
		) );
		foreach ( (array) $rows as $r ) {
			$refs[] = $this->post_ref( 'woocommerce' === $this->maybe_product( $r->post_type ) ? 'woocommerce_featured' : 'featured_image', $r );
		}

		// 2. WooCommerce product gallery (_product_image_gallery is a CSV of IDs).
		if ( class_exists( 'WooCommerce' ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, p.post_status, p.post_type FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE pm.meta_key = '_product_image_gallery' AND FIND_IN_SET(%d, pm.meta_value)",
				$id
			) );
			foreach ( (array) $rows as $r ) {
				$refs[] = $this->post_ref( 'woocommerce_gallery', $r );
			}
		}

		// 3. Elementor data (_elementor_data JSON contains "id":N for image widgets).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_status, p.post_type FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_elementor_data' AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )",
			'%"id":' . $id . ',%',
			'%"id":' . $id . '}%'
		) );
		foreach ( (array) $rows as $r ) {
			$refs[] = $this->post_ref( 'elementor', $r );
		}

		// 4. Post content — classic <img class="wp-image-N"> + Gutenberg blocks
		//    (including legacy `core/gallery` "ids":[…] arrays that carry no inner
		//    image markup) + any referenced file URL/basename. The SQL is a
		//    superset; content_references_id() confirms each row (and labels block
		//    vs classic) so a loose array match never becomes a false reference.
		list( $where, $params ) = $this->content_match_clauses( $id, $basenames );
		$sql  = "SELECT p.ID, p.post_status, p.post_type, p.post_content FROM {$wpdb->posts} p
				 WHERE p.post_status NOT IN ( 'inherit', 'auto-draft' ) AND ( " . implode( ' OR ', $where ) . " ) LIMIT 200";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		foreach ( (array) $rows as $r ) {
			$is_block = false;
			if ( ! $this->content_references_id( (string) $r->post_content, $id, $basenames, $is_block ) ) {
				continue;
			}
			$refs[] = [
				'source'      => $is_block ? 'block' : 'content',
				'post_id'     => (int) $r->ID,
				'post_type'   => $r->post_type,
				'post_status' => $r->post_status,
				'status'      => in_array( $r->post_status, self::ACTIVE_STATUSES, true ) ? 'active' : 'indirect',
			];
		}

		// 4b. Revisions — a reference that survives only in an old revision must
		// protect the attachment (restoring that revision would break the image).
		// Revisions are post_type 'revision' / post_status 'inherit', so the main
		// content query (which excludes 'inherit') never sees them — scan them here.
		// Always 'indirect': a revision is never the live document.
		list( $rwhere, $rparams ) = $this->content_match_clauses( $id, $basenames );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_parent, p.post_content FROM {$wpdb->posts} p
			 WHERE p.post_type = 'revision' AND ( " . implode( ' OR ', $rwhere ) . " ) LIMIT 200",
			$rparams
		) );
		foreach ( (array) $rows as $r ) {
			$is_block = false;
			if ( ! $this->content_references_id( (string) $r->post_content, $id, $basenames, $is_block ) ) {
				continue;
			}
			$refs[] = [ 'source' => 'revision', 'revision_id' => (int) $r->ID, 'parent_id' => (int) $r->post_parent, 'status' => 'indirect' ];
		}

		// 5. ACF fields — postmeta whose value is the ID (image) or a serialized
		//    array containing it (gallery), identified by the ACF `_{key}` => field_…
		//    companion meta. Excludes the core keys already handled above.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_key, p.post_status, p.post_type FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 INNER JOIN {$wpdb->postmeta} pmk ON pmk.post_id = pm.post_id
			   AND pmk.meta_key = CONCAT( '_', pm.meta_key ) AND pmk.meta_value LIKE 'field\_%'
			 WHERE ( pm.meta_value = %d OR pm.meta_value LIKE %s )",
			$id,
			'%"' . $id . '"%'
		) );
		foreach ( (array) $rows as $r ) {
			$refs[] = [
				'source'      => 'acf_field',
				'post_id'     => (int) $r->post_id,
				'post_type'   => $r->post_type,
				'post_status' => $r->post_status,
				'meta_key'    => $r->meta_key,
				'status'      => in_array( $r->post_status, self::ACTIVE_STATUSES, true ) ? 'active' : 'indirect',
			];
		}

		// 6. ACF options pages — options_… values (with _options_… => field_… companion).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.option_name FROM {$wpdb->options} o
			 INNER JOIN {$wpdb->options} ok ON ok.option_name = CONCAT( '_', o.option_name ) AND ok.option_value LIKE 'field\_%'
			 WHERE o.option_name LIKE 'options\_%' AND ( o.option_value = %d OR o.option_value LIKE %s )",
			$id,
			'%"' . $id . '"%'
		) );
		foreach ( (array) $rows as $r ) {
			$refs[] = [ 'source' => 'acf_options', 'option_name' => $r->option_name, 'status' => 'active' ];
		}

		// 7. Site identity options (live site config).
		if ( (int) get_option( 'site_icon' ) === $id ) {
			$refs[] = [ 'source' => 'option', 'option_name' => 'site_icon', 'status' => 'active' ];
		}
		if ( (int) get_option( 'site_logo' ) === $id ) {
			$refs[] = [ 'source' => 'option', 'option_name' => 'site_logo', 'status' => 'active' ];
		}

		// 8. theme_mods (custom_logo is an ID; header/background are URLs).
		$mods = get_option( 'theme_mods_' . get_stylesheet() );
		if ( is_array( $mods ) ) {
			if ( (int) ( $mods['custom_logo'] ?? 0 ) === $id ) {
				$refs[] = [ 'source' => 'theme_mods', 'key' => 'custom_logo', 'status' => 'active' ];
			}
			$blob = wp_json_encode( $mods );
			if ( is_string( $blob ) ) {
				foreach ( $basenames as $bn ) {
					if ( '' !== $bn && false !== strpos( $blob, $bn ) ) {
						$refs[] = [ 'source' => 'theme_mods', 'key' => 'url_reference', 'status' => 'active' ];
						break;
					}
				}
			}
		}

		// 9. Generic option storage — any option value containing a file basename
		//    (widgets, customizer extras, plugin settings, hardcoded URLs). URL-based,
		//    so treated as an indirect reference. Excludes options already matched.
		$seen_opts = array_filter( array_map( static fn( $r ) => $r['option_name'] ?? null, $refs ) );
		foreach ( $basenames as $bn ) {
			if ( '' === $bn ) {
				continue;
			}
			$names = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 20",
				'%' . $wpdb->esc_like( $bn ) . '%'
			) );
			foreach ( (array) $names as $name ) {
				$name = (string) $name;
				// Skip already-counted options, transients, and WPCC's OWN internal
				// stores (snapshot/rollback options embed file basenames — counting
				// them would make any snapshotted attachment look "referenced").
				if ( in_array( $name, $seen_opts, true )
					|| 0 === strpos( $name, '_transient' )
					|| 0 === strpos( $name, '_site_transient' )
					|| 0 === strpos( $name, 'wpcc_' ) ) {
					continue;
				}
				$refs[]      = [ 'source' => 'option', 'option_name' => $name, 'status' => 'indirect', 'match' => 'url' ];
				$seen_opts[] = $name;
			}
		}

		return $refs;
	}

	/**
	 * Classify an attachment from its references + on-disk state.
	 *
	 * @return array{media_id:int,status:string,orphaned:bool,cleanup_candidate:bool,reference_count:int,by_source:array,references:array}
	 */
	public function classify( int $id, ?array $refs = null ): array {
		$refs = null === $refs ? $this->references( $id ) : $refs;
		$file = get_attached_file( $id );
		$orphaned = ! ( $file && is_file( $file ) );

		$has_active   = false;
		$has_indirect = false;
		$by_source    = [];
		foreach ( $refs as $r ) {
			$by_source[ $r['source'] ] = ( $by_source[ $r['source'] ] ?? 0 ) + 1;
			if ( 'active' === ( $r['status'] ?? '' ) ) {
				$has_active = true;
			} elseif ( 'indirect' === ( $r['status'] ?? '' ) ) {
				$has_indirect = true;
			}
		}

		$status = $has_active ? 'active' : ( $has_indirect ? 'indirect' : 'unused' );

		return [
			'media_id'          => $id,
			'status'            => $status,
			'orphaned'          => $orphaned,
			'cleanup_candidate' => 'unused' === $status, // never delete still-referenced media
			'reference_count'   => count( $refs ),
			'by_source'         => $by_source,
			'references'        => $refs,
		];
	}

	/**
	 * Attachment IDs (any mime), newest first, capped at $limit.
	 *
	 * @return int[]
	 */
	public function attachment_ids( int $limit ): array {
		$q = new \WP_Query( [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => max( 1, $limit ),
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );
		return array_map( 'intval', $q->posts );
	}

	// ── Helpers ──────────────────────────────────────────────────

	/** @return array<int,string> Basenames of the original + every size file. */
	private function attachment_basenames( int $id ): array {
		$out  = [];
		$file = get_attached_file( $id );
		if ( $file ) {
			$out[] = wp_basename( $file );
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$out[] = wp_basename( (string) $s['file'] );
				}
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	private function post_ref( string $source, object $row ): array {
		return [
			'source'      => $source,
			'post_id'     => (int) $row->ID,
			'post_type'   => $row->post_type,
			'post_status' => $row->post_status,
			'status'      => in_array( $row->post_status, self::ACTIVE_STATUSES, true ) ? 'active' : 'indirect',
		];
	}

	private function maybe_product( string $post_type ): string {
		return 'product' === $post_type ? 'woocommerce' : 'core';
	}

	/**
	 * LIKE clauses + params that pre-select any post whose content could reference
	 * this attachment: classic `wp-image-N`, a direct `"id":N` (single image block
	 * / shortcode), a Gutenberg gallery `"ids":[…N…]` array (the legacy flat format
	 * that carries no inner image markup, at any position in the array), or any
	 * size-file basename. Deliberately a superset — `content_references_id()`
	 * confirms each returned row so a loose array match never becomes a false
	 * reference.
	 *
	 * @param array<int,string> $basenames
	 * @return array{0:string[],1:array<int,string>}
	 */
	private function content_match_clauses( int $id, array $basenames ): array {
		global $wpdb;
		$where = [
			'p.post_content LIKE %s', // classic wp-image-N
			'p.post_content LIKE %s', // direct "id":N
			'p.post_content LIKE %s', // gallery "ids":[N,…  (first element)
			'p.post_content LIKE %s', // gallery "ids":[N]   (sole element)
			'p.post_content LIKE %s', // gallery …,N,…       (middle element)
			'p.post_content LIKE %s', // gallery …,N]        (last element)
		];
		$params = [
			'%wp-image-' . $id . '%',
			'%"id":' . $id . '%',
			'%"ids":[' . $id . ',%',
			'%"ids":[' . $id . ']%',
			'%,' . $id . ',%',
			'%,' . $id . ']%',
		];
		foreach ( $basenames as $bn ) {
			$where[]  = 'p.post_content LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $bn ) . '%';
		}
		return [ $where, $params ];
	}

	/**
	 * Whether a post's content genuinely references this attachment (not just a
	 * loose SQL pre-match). Sets $is_block when a Gutenberg block attribute
	 * (id / ids / mediaId) carries the reference; otherwise it is a classic/textual
	 * (wp-image-N, direct "id":N, or file-basename) match.
	 *
	 * @param array<int,string> $basenames
	 */
	private function content_references_id( string $content, int $id, array $basenames, bool &$is_block ): bool {
		$is_block = $this->content_has_block_ref( $content, $id );
		if ( $is_block ) {
			return true;
		}
		if ( false !== strpos( $content, 'wp-image-' . $id ) || false !== strpos( $content, '"id":' . $id ) ) {
			return true;
		}
		foreach ( $basenames as $bn ) {
			if ( '' !== $bn && false !== strpos( $content, $bn ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a Gutenberg block in the content references this attachment ID. */
	private function content_has_block_ref( string $content, int $id ): bool {
		if ( ! function_exists( 'has_blocks' ) || ! has_blocks( $content ) ) {
			return false;
		}
		return $this->blocks_reference_id( parse_blocks( $content ), $id );
	}

	private function blocks_reference_id( array $blocks, int $id ): bool {
		foreach ( $blocks as $block ) {
			$attrs = $block['attrs'] ?? [];
			if ( (int) ( $attrs['id'] ?? 0 ) === $id || (int) ( $attrs['mediaId'] ?? 0 ) === $id ) {
				return true;
			}
			if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) && in_array( $id, array_map( 'intval', $attrs['ids'] ), true ) ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && $this->blocks_reference_id( $block['innerBlocks'], $id ) ) {
				return true;
			}
		}
		return false;
	}
}
