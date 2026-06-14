<?php
/**
 * STEP 100.3 — Media Enhancement Runtime (foundation).
 *
 * Stands up the new `media_enhance` operation with its first, read-only
 * diagnostics: an image-library capability probe (GD / Imagick, WebP / AVIF
 * encode support) plus image-size inventory and audit actions. These reads are
 * the fail-closed signal and the inventory that the later (write/destructive)
 * sub-steps — thumbnail regeneration, WebP generation, optimization, cleanup —
 * will depend on. Every action here is diagnostic: no writes, no rollback.
 *
 * Capability: `media_enhance → media.manage` (CapabilityRegistry::OPERATION_MAP).
 * One shared runtime; REST + MCP call the same handler (no transport logic).
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class MediaEnhancementRegistry {

	const A_CAPABILITIES         = 'media_enhance_capabilities';
	const A_SIZES_LIST           = 'image_sizes_list';
	const A_SIZE_USAGE_AUDIT     = 'image_size_usage_audit';
	const A_SIZE_RECOMMENDATIONS = 'image_size_recommendations';
	const A_SIZE_VERIFY          = 'image_size_verify';

	const ACTIONS = [
		self::A_CAPABILITIES,
		self::A_SIZES_LIST,
		self::A_SIZE_USAGE_AUDIT,
		self::A_SIZE_RECOMMENDATIONS,
		self::A_SIZE_VERIFY,
	];

	/** Every STEP 100.3 action is read-only. */
	public static function get_risk( string $a ): string {
		return 'diagnostic';
	}
}

final class MediaEnhancementRuntimeManager {

	/** Default / max attachments scanned by library-wide audits. */
	private const SCAN_DEFAULT = 500;
	private const SCAN_MAX     = 5000;

	/** A registered size whose largest edge exceeds this is flagged "oversized". */
	private const OVERSIZED_EDGE = 2048;

	/** WordPress core default registered subsizes (everything else is theme/plugin). */
	private const CORE_SIZES = [ 'thumbnail', 'medium', 'medium_large', 'large' ];

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $p, array $cx = [] ): array|\WP_Error {
		$a = (string) ( $p['action'] ?? '' );
		if ( ! in_array( $a, MediaEnhancementRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_media_enhance_action', __( 'Invalid media enhance action.', 'wp-command-center' ) );
		}

		$report = match ( $a ) {
			MediaEnhancementRegistry::A_CAPABILITIES         => $this->capabilities(),
			MediaEnhancementRegistry::A_SIZES_LIST           => $this->sizes_list(),
			MediaEnhancementRegistry::A_SIZE_USAGE_AUDIT     => $this->size_usage_audit( $p ),
			MediaEnhancementRegistry::A_SIZE_RECOMMENDATIONS => $this->size_recommendations( $p ),
			MediaEnhancementRegistry::A_SIZE_VERIFY          => $this->size_verify( $p ),
		};

		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$this->audit->record( 'media_enhance.' . $a, [ 'generated_at' => time() ] );
		return array_merge( [ 'action' => $a, 'generated_at' => time() ], $report );
	}

	// ── Capability probe ─────────────────────────────────────────

	/**
	 * Detect which in-PHP image library is available and what it can encode.
	 * GD / Imagick only (no shell binaries). Fail-closed booleans the later
	 * WebP / optimization sub-steps gate on.
	 */
	private function capabilities(): array {
		$gd      = extension_loaded( 'gd' );
		$gd_info = ( $gd && function_exists( 'gd_info' ) ) ? gd_info() : [];

		$imagick = extension_loaded( 'imagick' ) && class_exists( '\Imagick' );
		$imagick_formats = [];
		$imagick_version = null;
		if ( $imagick ) {
			try { $imagick_formats = \Imagick::queryFormats(); } catch ( \Throwable $e ) { $imagick_formats = []; }
			try {
				$v = \Imagick::getVersion();
				$imagick_version = is_array( $v ) ? ( $v['versionString'] ?? null ) : null;
			} catch ( \Throwable $e ) { $imagick_version = null; }
		}
		$im_supports = static function ( string $fmt ) use ( $imagick_formats ): bool {
			return in_array( strtoupper( $fmt ), array_map( 'strtoupper', (array) $imagick_formats ), true );
		};

		$webp_gd     = $gd && function_exists( 'imagewebp' ) && ! empty( $gd_info['WebP Support'] );
		$webp_im     = $imagick && $im_supports( 'WEBP' );
		$avif_gd     = $gd && function_exists( 'imageavif' ) && ! empty( $gd_info['AVIF Support'] );
		$avif_im     = $imagick && $im_supports( 'AVIF' );

		$wp_resize = function_exists( 'wp_image_editor_supports' )
			? wp_image_editor_supports( [ 'methods' => [ 'resize' ] ] )
			: ( $gd || $imagick );
		$wp_webp = function_exists( 'wp_image_editor_supports' )
			? wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] )
			: ( $webp_gd || $webp_im );

		return [ 'capabilities' => [
			'gd' => [
				'available' => $gd,
				'version'   => $gd_info['GD Version'] ?? null,
				'webp'      => $webp_gd,
				'avif'      => $avif_gd,
			],
			'imagick' => [
				'available' => $imagick,
				'version'   => $imagick_version,
				'webp'      => $webp_im,
				'avif'      => $avif_im,
			],
			'image_library'    => $imagick ? 'imagick' : ( $gd ? 'gd' : 'none' ),
			'resize'           => (bool) $wp_resize,
			'webp_encode'      => $webp_gd || $webp_im,
			'avif_encode'      => $avif_gd || $avif_im,
			'wp_supports_webp' => (bool) $wp_webp,
		] ];
	}

	// ── Image-size inventory ─────────────────────────────────────

	/**
	 * Every registered intermediate image size — core defaults plus any added by
	 * a theme/plugin via `add_image_size()` — with dimensions and crop.
	 */
	private function sizes_list(): array {
		$subsizes = $this->registered_subsizes();
		$sizes    = [];
		foreach ( $subsizes as $name => $dims ) {
			$sizes[] = [
				'name'   => $name,
				'width'  => (int) ( $dims['width'] ?? 0 ),
				'height' => (int) ( $dims['height'] ?? 0 ),
				'crop'   => (bool) ( $dims['crop'] ?? false ),
				'source' => in_array( $name, self::CORE_SIZES, true ) ? 'core' : 'additional',
			];
		}
		return [ 'image_sizes' => $sizes, 'total' => count( $sizes ) ];
	}

	/**
	 * For each registered size, count how many image attachments carry that size
	 * in their metadata and how many of those files actually exist on disk.
	 * Bounded by `limit` (default 500, max 5000) so the scan stays cheap.
	 */
	private function size_usage_audit( array $p ): array {
		$subsizes = $this->registered_subsizes();
		$names    = array_keys( $subsizes );
		$limit    = $this->scan_limit( $p );

		$ids   = $this->image_attachment_ids( $limit + 1 );
		$truncated = count( $ids ) > $limit;
		if ( $truncated ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		$counts = [];
		foreach ( $names as $n ) {
			$counts[ $n ] = [ 'in_metadata' => 0, 'on_disk' => 0 ];
		}

		$scanned = 0;
		$with_sizes = 0;
		foreach ( $ids as $id ) {
			$scanned++;
			$meta = wp_get_attachment_metadata( $id );
			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
				continue;
			}
			$with_sizes++;
			$base_dir = $this->attachment_base_dir( $id );
			foreach ( $names as $n ) {
				if ( ! isset( $meta['sizes'][ $n ] ) ) {
					continue;
				}
				$counts[ $n ]['in_metadata']++;
				$file = (string) ( $meta['sizes'][ $n ]['file'] ?? '' );
				if ( '' !== $file && '' !== $base_dir && file_exists( $base_dir . '/' . $file ) ) {
					$counts[ $n ]['on_disk']++;
				}
			}
		}

		$usage = [];
		foreach ( $names as $n ) {
			$usage[] = [
				'name'        => $n,
				'in_metadata' => $counts[ $n ]['in_metadata'],
				'on_disk'     => $counts[ $n ]['on_disk'],
			];
		}

		return [ 'image_size_usage' => [
			'scanned'        => $scanned,
			'with_sizes'     => $with_sizes,
			'truncated'      => $truncated,
			'scan_limit'     => $limit,
			'registered'     => count( $names ),
			'usage'          => $usage,
		] ];
	}

	/**
	 * Flag registered sizes that are unused across the scanned library or
	 * dimensionally oversized — candidates for removal or review. Reuses the
	 * usage audit so the recommendation is data-backed, not heuristic-only.
	 */
	private function size_recommendations( array $p ): array {
		$subsizes = $this->registered_subsizes();
		$audit    = $this->size_usage_audit( $p )['image_size_usage'];
		$usage_by = [];
		foreach ( $audit['usage'] as $u ) {
			$usage_by[ $u['name'] ] = $u;
		}

		$recommendations = [];
		foreach ( $subsizes as $name => $dims ) {
			$w   = (int) ( $dims['width'] ?? 0 );
			$h   = (int) ( $dims['height'] ?? 0 );
			$in_meta = (int) ( $usage_by[ $name ]['in_metadata'] ?? 0 );

			if ( 0 === $in_meta && $audit['with_sizes'] > 0 ) {
				$recommendations[] = [
					'name'   => $name,
					'issue'  => 'unused',
					'detail' => sprintf( 'No scanned attachment (of %d with sizes) carries the "%s" size.', $audit['with_sizes'], $name ),
				];
			}

			if ( max( $w, $h ) > self::OVERSIZED_EDGE ) {
				$recommendations[] = [
					'name'   => $name,
					'issue'  => 'oversized',
					'detail' => sprintf( 'Registered at %d×%d; largest edge exceeds %dpx.', $w, $h, self::OVERSIZED_EDGE ),
				];
			}
		}

		return [ 'image_size_recommendations' => [
			'recommendations' => $recommendations,
			'total'           => count( $recommendations ),
			'scanned'         => $audit['scanned'],
			'truncated'       => $audit['truncated'],
		] ];
	}

	/**
	 * For one attachment, report which registered sizes are present (metadata +
	 * on disk) and which are genuinely missing. Sizes larger than the original
	 * are reported as `not_applicable` (WordPress never upscales) rather than as
	 * a defect, so the audit doesn't false-positive.
	 */
	private function size_verify( array $p ): array|\WP_Error {
		$id   = (int) ( $p['media_id'] ?? $p['attachment_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			return new \WP_Error( 'wpcc_not_an_image', __( 'Attachment is not an image.', 'wp-command-center' ) );
		}

		$meta     = wp_get_attachment_metadata( $id );
		$meta     = is_array( $meta ) ? $meta : [];
		$base_dir = $this->attachment_base_dir( $id );
		$orig_w   = (int) ( $meta['width'] ?? 0 );
		$orig_h   = (int) ( $meta['height'] ?? 0 );
		$subsizes = $this->registered_subsizes();

		$present = [];
		$missing = [];
		$not_applicable = [];
		$detail  = [];

		foreach ( $subsizes as $name => $dims ) {
			$w = (int) ( $dims['width'] ?? 0 );
			$h = (int) ( $dims['height'] ?? 0 );

			$in_meta = isset( $meta['sizes'][ $name ] );
			$on_disk = false;
			if ( $in_meta ) {
				$file    = (string) ( $meta['sizes'][ $name ]['file'] ?? '' );
				$on_disk = ( '' !== $file && '' !== $base_dir && file_exists( $base_dir . '/' . $file ) );
			}

			// A size that exceeds the original in both edges is never generated.
			$too_large = ( $orig_w > 0 && $orig_h > 0 )
				&& ( ( $w > 0 && $w > $orig_w ) || ( $h > 0 && $h > $orig_h ) )
				&& ! $in_meta;

			$state = $on_disk ? 'present' : ( $too_large ? 'not_applicable' : 'missing' );
			$detail[ $name ] = [
				'state'       => $state,
				'in_metadata' => $in_meta,
				'on_disk'     => $on_disk,
			];

			if ( 'present' === $state ) {
				$present[] = $name;
			} elseif ( 'not_applicable' === $state ) {
				$not_applicable[] = $name;
			} else {
				$missing[] = $name;
			}
		}

		return [ 'image_size_verify' => [
			'media_id'        => $id,
			'original'        => [ 'width' => $orig_w, 'height' => $orig_h ],
			'registered'      => count( $subsizes ),
			'present'         => $present,
			'missing'         => $missing,
			'not_applicable'  => $not_applicable,
			'detail'          => $detail,
		] ];
	}

	// ── Helpers ──────────────────────────────────────────────────

	/**
	 * Registered intermediate sizes as name => { width, height, crop }. Uses the
	 * core API when available (WP 5.3+), with a fallback built from
	 * `get_intermediate_image_sizes()` + option/global dimensions.
	 *
	 * @return array<string, array{width:int,height:int,crop:bool}>
	 */
	private function registered_subsizes(): array {
		if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
			$sizes = wp_get_registered_image_subsizes();
			if ( is_array( $sizes ) ) {
				return $sizes;
			}
		}

		// Fallback for very old cores.
		global $_wp_additional_image_sizes;
		$out   = [];
		$names = function_exists( 'get_intermediate_image_sizes' ) ? get_intermediate_image_sizes() : [];
		foreach ( $names as $name ) {
			if ( isset( $_wp_additional_image_sizes[ $name ] ) ) {
				$out[ $name ] = [
					'width'  => (int) $_wp_additional_image_sizes[ $name ]['width'],
					'height' => (int) $_wp_additional_image_sizes[ $name ]['height'],
					'crop'   => (bool) $_wp_additional_image_sizes[ $name ]['crop'],
				];
			} else {
				$out[ $name ] = [
					'width'  => (int) get_option( "{$name}_size_w" ),
					'height' => (int) get_option( "{$name}_size_h" ),
					'crop'   => (bool) get_option( "{$name}_crop" ),
				];
			}
		}
		return $out;
	}

	/**
	 * IDs of image attachments, newest first, capped at $limit.
	 *
	 * @return int[]
	 */
	private function image_attachment_ids( int $limit ): array {
		$q = new \WP_Query( [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
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

	/** Directory holding an attachment's files, or '' if unresolved. */
	private function attachment_base_dir( int $id ): string {
		$file = get_attached_file( $id );
		return $file ? dirname( $file ) : '';
	}

	private function scan_limit( array $p ): int {
		$n = isset( $p['limit'] ) ? (int) $p['limit'] : self::SCAN_DEFAULT;
		return max( 1, min( self::SCAN_MAX, $n ) );
	}
}
