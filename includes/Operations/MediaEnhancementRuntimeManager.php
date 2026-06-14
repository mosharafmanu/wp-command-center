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
	// STEP 100.4 — responsive image audit (read-only).
	const A_SRCSET_VERIFY        = 'srcset_verify';
	const A_RESPONSIVE_AUDIT     = 'responsive_image_audit';
	const A_MISSING_SIZES_AUDIT  = 'missing_sizes_audit';
	const A_SIZE_CONTEXT_AUDIT   = 'image_size_context_audit';

	const ACTIONS = [
		self::A_CAPABILITIES,
		self::A_SIZES_LIST,
		self::A_SIZE_USAGE_AUDIT,
		self::A_SIZE_RECOMMENDATIONS,
		self::A_SIZE_VERIFY,
		self::A_SRCSET_VERIFY,
		self::A_RESPONSIVE_AUDIT,
		self::A_MISSING_SIZES_AUDIT,
		self::A_SIZE_CONTEXT_AUDIT,
	];

	/** Every media_enhance action (STEP 100.3 + 100.4) is read-only. */
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

	/** An original this many × the largest registered display size is "oversized". */
	private const OVERSIZE_FACTOR = 1.5;

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
			MediaEnhancementRegistry::A_SRCSET_VERIFY        => $this->srcset_verify( $p ),
			MediaEnhancementRegistry::A_RESPONSIVE_AUDIT     => $this->responsive_image_audit( $p ),
			MediaEnhancementRegistry::A_MISSING_SIZES_AUDIT  => $this->missing_sizes_audit( $p ),
			MediaEnhancementRegistry::A_SIZE_CONTEXT_AUDIT   => $this->image_size_context_audit( $p ),
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
		$analysis = $this->analyze_sizes( $p );
		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}
		return [ 'image_size_verify' => $analysis ];
	}

	/**
	 * Validate the payload's attachment is an image, then classify its sizes.
	 *
	 * @return array{media_id:int,original:array,registered:int,present:array,missing:array,not_applicable:array,detail:array}|\WP_Error
	 */
	private function analyze_sizes( array $p ): array|\WP_Error {
		$id = $this->resolve_image_id( $p );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return $this->classify_sizes( $id );
	}

	/**
	 * Classify every registered size for a (validated) image attachment as
	 * present / missing / not_applicable. Sizes larger than the original are
	 * `not_applicable` (WordPress never upscales). Shared by size_verify, the
	 * responsive audit, the missing-sizes audit, and the context audit.
	 *
	 * @return array{media_id:int,original:array,registered:int,present:array,missing:array,not_applicable:array,detail:array}
	 */
	private function classify_sizes( int $id ): array {
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

			// A size that exceeds the original in either edge is never generated.
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

		return [
			'media_id'       => $id,
			'original'       => [ 'width' => $orig_w, 'height' => $orig_h ],
			'registered'     => count( $subsizes ),
			'present'        => $present,
			'missing'        => $missing,
			'not_applicable' => $not_applicable,
			'detail'         => $detail,
		];
	}

	// ── STEP 100.4 — Responsive image audit (read-only) ──────────

	/**
	 * WordPress responsive (srcset/sizes) metadata for one attachment: the
	 * generated srcset candidates, the `sizes` attribute, and whether the image
	 * has a meaningful (>1 candidate) srcset for responsive delivery.
	 */
	private function srcset_verify( array $p ): array|\WP_Error {
		$id = $this->resolve_image_id( $p );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return [ 'srcset' => $this->srcset_info( $id ) ];
	}

	/**
	 * Full responsive-readiness report. With `media_id`: a per-attachment report
	 * (size classification + srcset + metadata completeness + recommendations +
	 * a single `responsive_ready` verdict). Without it: a library-wide aggregate
	 * (counts of ready / not-ready / no-srcset / missing-sizes attachments).
	 */
	private function responsive_image_audit( array $p ): array|\WP_Error {
		if ( isset( $p['media_id'] ) || isset( $p['attachment_id'] ) ) {
			$report = $this->responsive_report( $p );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			return [ 'responsive_image_audit' => $report ];
		}

		$limit     = $this->scan_limit( $p );
		$ids       = $this->image_attachment_ids( $limit + 1 );
		$truncated = count( $ids ) > $limit;
		if ( $truncated ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		$scanned = $ready = $no_srcset = $with_missing = $incomplete_meta = 0;
		$samples = [];
		foreach ( $ids as $id ) {
			$scanned++;
			$class  = $this->classify_sizes( $id );
			$srcset = $this->srcset_info( $id );
			$meta_ok = $this->metadata_complete( $id );

			$has_missing = ! empty( $class['missing'] );
			$has_srcset  = (bool) $srcset['has_srcset'];
			if ( $has_missing ) { $with_missing++; }
			if ( ! $has_srcset ) { $no_srcset++; }
			if ( ! $meta_ok['complete'] ) { $incomplete_meta++; }

			$is_ready = $has_srcset && ! $has_missing && $meta_ok['complete'];
			if ( $is_ready ) {
				$ready++;
			} elseif ( count( $samples ) < 25 ) {
				$samples[] = [
					'media_id'        => $id,
					'has_srcset'      => $has_srcset,
					'missing'         => $class['missing'],
					'metadata_complete' => $meta_ok['complete'],
				];
			}
		}

		return [ 'responsive_image_audit' => [
			'scanned'           => $scanned,
			'responsive_ready'  => $ready,
			'not_ready'         => $scanned - $ready,
			'without_srcset'    => $no_srcset,
			'with_missing_sizes' => $with_missing,
			'incomplete_metadata' => $incomplete_meta,
			'truncated'         => $truncated,
			'scan_limit'        => $limit,
			'samples'           => $samples,
		] ];
	}

	/**
	 * Library-wide: image attachments missing one or more *applicable* registered
	 * sizes (i.e. genuinely regenerable — not the upscale-skipped ones). Bounded
	 * by `limit`. The audit a future thumbnail-regenerate step (100.5) consumes.
	 */
	private function missing_sizes_audit( array $p ): array {
		$limit     = $this->scan_limit( $p );
		$ids       = $this->image_attachment_ids( $limit + 1 );
		$truncated = count( $ids ) > $limit;
		if ( $truncated ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		$scanned = 0;
		$with_missing = 0;
		$attachments = [];
		foreach ( $ids as $id ) {
			$scanned++;
			$class = $this->classify_sizes( $id );
			if ( empty( $class['missing'] ) ) {
				continue;
			}
			$with_missing++;
			if ( count( $attachments ) < 100 ) {
				$attachments[] = [
					'media_id' => $id,
					'title'    => get_the_title( $id ),
					'missing'  => $class['missing'],
				];
			}
		}

		return [ 'missing_sizes_audit' => [
			'scanned'      => $scanned,
			'with_missing' => $with_missing,
			'attachments'  => $attachments, // capped at 100
			'truncated'    => $truncated,
			'scan_limit'   => $limit,
		] ];
	}

	/**
	 * Compare an attachment's actual dimensions against the registered sizes that
	 * act as its intended display contexts: flag an original that is oversized
	 * (far larger than any display target → wasted bytes) or undersized (smaller
	 * than registered display sizes it cannot fill, since WP will not upscale).
	 */
	private function image_size_context_audit( array $p ): array|\WP_Error {
		$id = $this->resolve_image_id( $p );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$class    = $this->classify_sizes( $id );
		$orig_w   = (int) $class['original']['width'];
		$orig_h   = (int) $class['original']['height'];
		$subsizes = $this->registered_subsizes();

		$largest_w = 0;
		$largest_name = '';
		foreach ( $subsizes as $name => $dims ) {
			$w = (int) ( $dims['width'] ?? 0 );
			if ( $w > $largest_w ) {
				$largest_w = $w;
				$largest_name = $name;
			}
		}

		$unfillable = count( $class['not_applicable'] );
		$status = 'adequate';
		$recommendations = [];

		if ( $largest_w > 0 && $orig_w > 0 && $orig_w >= (int) round( $largest_w * self::OVERSIZE_FACTOR ) ) {
			$status = 'oversized';
			$recommendations[] = [
				'issue'          => 'oversized_original',
				'detail'         => sprintf( 'Original width %dpx is ≥ %.1f× the largest registered display size (%s, %dpx).', $orig_w, self::OVERSIZE_FACTOR, $largest_name, $largest_w ),
				'recommendation' => 'Consider a max-dimension cap (big_image_size_threshold) or downscaling; the original exceeds every display target.',
			];
		} elseif ( $largest_w > 0 && $orig_w > 0 && $orig_w < $largest_w && $unfillable > 0 ) {
			$status = 'undersized';
			$recommendations[] = [
				'issue'          => 'undersized_original',
				'detail'         => sprintf( 'Original width %dpx cannot fill %d registered display size(s); WordPress will not upscale.', $orig_w, $unfillable ),
				'recommendation' => 'Source a higher-resolution original to serve larger display contexts.',
			];
		}

		return [ 'image_size_context_audit' => [
			'media_id'             => $id,
			'original'             => $class['original'],
			'largest_display'      => [ 'name' => $largest_name, 'width' => $largest_w ],
			'oversize_factor'      => self::OVERSIZE_FACTOR,
			'unfillable_sizes'     => $class['not_applicable'],
			'status'               => $status,
			'recommendations'      => $recommendations,
		] ];
	}

	// ── STEP 100.4 helpers ───────────────────────────────────────

	/**
	 * srcset / sizes metadata for an attachment (computed against the full size).
	 *
	 * @return array{srcset:string,sizes:string,candidate_count:int,has_srcset:bool}
	 */
	private function srcset_info( int $id ): array {
		$srcset = wp_get_attachment_image_srcset( $id, 'full' );
		$sizes  = wp_get_attachment_image_sizes( $id, 'full' );
		$srcset = is_string( $srcset ) ? $srcset : '';
		$sizes  = is_string( $sizes ) ? $sizes : '';
		$count  = '' === $srcset ? 0 : count( array_filter( array_map( 'trim', explode( ',', $srcset ) ) ) );
		return [
			'srcset'          => $srcset,
			'sizes'           => $sizes,
			'candidate_count' => $count,
			// A meaningful srcset needs at least two candidates to be responsive.
			'has_srcset'      => $count >= 2,
		];
	}

	/**
	 * Whether an attachment's core image metadata is complete enough to drive
	 * responsive output (width, height, file, a sizes map, and image_meta) plus
	 * whether alt text is set.
	 *
	 * @return array{complete:bool,missing:array,has_alt:bool}
	 */
	private function metadata_complete( int $id ): array {
		$meta    = wp_get_attachment_metadata( $id );
		$meta    = is_array( $meta ) ? $meta : [];
		$missing = [];
		foreach ( [ 'width', 'height', 'file', 'sizes', 'image_meta' ] as $k ) {
			if ( empty( $meta[ $k ] ) ) {
				$missing[] = $k;
			}
		}
		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		return [
			'complete' => empty( $missing ),
			'missing'  => $missing,
			'has_alt'  => '' !== trim( $alt ),
		];
	}

	/** Per-attachment responsive report (size classification + srcset + meta + verdict). */
	private function responsive_report( array $p ): array|\WP_Error {
		$class = $this->analyze_sizes( $p );
		if ( is_wp_error( $class ) ) {
			return $class;
		}
		$id      = (int) $class['media_id'];
		$srcset  = $this->srcset_info( $id );
		$meta_ok = $this->metadata_complete( $id );

		$recommendations = [];
		if ( ! empty( $class['missing'] ) ) {
			$recommendations[] = [
				'issue'          => 'missing_sizes',
				'detail'         => sprintf( '%d applicable registered size(s) not generated on disk: %s.', count( $class['missing'] ), implode( ', ', $class['missing'] ) ),
				'recommendation' => 'Regenerate thumbnails for this attachment (STEP 100.5).',
			];
		}
		if ( ! $srcset['has_srcset'] ) {
			$recommendations[] = [
				'issue'          => 'no_responsive_srcset',
				'detail'         => sprintf( 'srcset has %d candidate(s); responsive delivery needs ≥2.', $srcset['candidate_count'] ),
				'recommendation' => 'Ensure multiple intermediate sizes exist (the original may be too small, or sizes are missing).',
			];
		}
		if ( ! $meta_ok['complete'] ) {
			$recommendations[] = [
				'issue'          => 'incomplete_metadata',
				'detail'         => 'Attachment metadata missing: ' . implode( ', ', $meta_ok['missing'] ) . '.',
				'recommendation' => 'Regenerate attachment metadata.',
			];
		}

		$responsive_ready = $srcset['has_srcset'] && empty( $class['missing'] ) && $meta_ok['complete'];

		return [
			'media_id'          => $id,
			'original'          => $class['original'],
			'registered'        => $class['registered'],
			'present'           => $class['present'],
			'missing'           => $class['missing'],
			'not_applicable'    => $class['not_applicable'],
			'srcset'            => $srcset,
			'metadata'          => $meta_ok,
			'responsive_ready'  => $responsive_ready,
			'recommendations'   => $recommendations,
		];
	}

	/**
	 * Resolve and validate `media_id` / `attachment_id` as an image attachment.
	 *
	 * @return int|\WP_Error
	 */
	private function resolve_image_id( array $p ): int|\WP_Error {
		$id   = (int) ( $p['media_id'] ?? $p['attachment_id'] ?? 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			return new \WP_Error( 'wpcc_not_an_image', __( 'Attachment is not an image.', 'wp-command-center' ) );
		}
		return $id;
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
