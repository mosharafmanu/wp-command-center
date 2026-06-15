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
	// STEP 100.5 — thumbnail regeneration (reversible writes + read verify).
	const A_THUMB_REGENERATE      = 'thumbnail_regenerate';
	const A_THUMB_REGENERATE_ATT  = 'thumbnail_regenerate_attachment';
	const A_THUMB_REGENERATE_BATCH = 'thumbnail_regenerate_batch';
	const A_THUMB_VERIFY          = 'thumbnail_verify';
	// STEP 100.6 — WebP audit + additive generation (reversible writes + reads).
	const A_WEBP_AUDIT            = 'webp_audit';
	const A_WEBP_VERIFY           = 'webp_verify';
	const A_WEBP_GENERATE         = 'webp_generate';
	const A_WEBP_GENERATE_BATCH   = 'webp_generate_batch';

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
		self::A_THUMB_REGENERATE,
		self::A_THUMB_REGENERATE_ATT,
		self::A_THUMB_REGENERATE_BATCH,
		self::A_THUMB_VERIFY,
		self::A_WEBP_AUDIT,
		self::A_WEBP_VERIFY,
		self::A_WEBP_GENERATE,
		self::A_WEBP_GENERATE_BATCH,
	];

	/** Reversible write actions (snapshot-backed); everything else is read-only. */
	const WRITE_ACTIONS = [
		self::A_THUMB_REGENERATE,
		self::A_THUMB_REGENERATE_ATT,
		self::A_THUMB_REGENERATE_BATCH,
		self::A_WEBP_GENERATE,
		self::A_WEBP_GENERATE_BATCH,
	];

	/** Per-action risk: STEP 100.3/100.4 reads = diagnostic; 100.5 regen writes = medium. */
	public static function get_risk( string $a ): string {
		return in_array( $a, self::WRITE_ACTIONS, true ) ? 'medium' : 'diagnostic';
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

	/** STEP 100.6 — source mime types we can encode to WebP (GD/Imagick); others skipped. */
	private const WEBP_SOURCE_MIMES = [ 'image/jpeg', 'image/png' ];

	/** STEP 100.5 — rollback record store + batch chunk bounds. */
	private const ROLLBACK_STORE = 'wpcc_media_enhance_rollbacks';
	private const ROLLBACK_MAX    = 100;
	private const BATCH_DEFAULT   = 20;
	private const BATCH_MAX       = 50;

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
			MediaEnhancementRegistry::A_THUMB_REGENERATE     => $this->thumbnail_regenerate( $p, $cx ),
			MediaEnhancementRegistry::A_THUMB_REGENERATE_ATT => $this->thumbnail_regenerate( $p, $cx ),
			MediaEnhancementRegistry::A_THUMB_REGENERATE_BATCH => $this->thumbnail_regenerate_batch( $p, $cx ),
			MediaEnhancementRegistry::A_THUMB_VERIFY         => $this->thumbnail_verify( $p ),
			MediaEnhancementRegistry::A_WEBP_AUDIT           => $this->webp_audit( $p ),
			MediaEnhancementRegistry::A_WEBP_VERIFY          => $this->webp_verify( $p ),
			MediaEnhancementRegistry::A_WEBP_GENERATE        => $this->webp_generate( $p, $cx ),
			MediaEnhancementRegistry::A_WEBP_GENERATE_BATCH  => $this->webp_generate_batch( $p, $cx ),
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

	// ── STEP 100.5 — Thumbnail regeneration (reversible write) ───

	/**
	 * Regenerate intermediate size files for one attachment. `mode`:
	 *  - `missing` (default): generate only the missing *applicable* sizes; a
	 *    no-op (no snapshot, no write) when nothing is missing.
	 *  - `all`: rebuild every registered size from the original.
	 * Snapshot-backed (STEP 100.1): a byte-for-byte snapshot is captured BEFORE
	 * any write; if it cannot be captured the operation aborts without mutating.
	 * Sizes larger than the original are never generated (WordPress no-upscale).
	 * The result is verified; on failure the snapshot is restored and an error
	 * returned (no partial state).
	 */
	private function thumbnail_regenerate( array $p, array $cx = [] ): array|\WP_Error {
		$id = $this->resolve_image_id( $p );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$mode = ( 'all' === ( $p['mode'] ?? 'missing' ) ) ? 'all' : 'missing';
		$res  = $this->do_regenerate( $id, $mode, '', $cx );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return [ 'thumbnail_regenerate' => $res ];
	}

	/**
	 * Cursor-based batch regeneration over image attachments (or an explicit
	 * `media_ids` list). Each item is independently snapshot-backed and reversible;
	 * all items share a `batch_id`. Bounded by `limit` (default 20, max 50);
	 * returns `next_cursor` until the candidate set is exhausted.
	 */
	private function thumbnail_regenerate_batch( array $p, array $cx = [] ): array {
		$mode  = ( 'all' === ( $p['mode'] ?? 'missing' ) ) ? 'all' : 'missing';
		$limit = max( 1, min( self::BATCH_MAX, isset( $p['limit'] ) ? (int) $p['limit'] : self::BATCH_DEFAULT ) );
		$cursor = max( 0, isset( $p['cursor'] ) ? (int) $p['cursor'] : 0 );

		if ( ! empty( $p['media_ids'] ) && is_array( $p['media_ids'] ) ) {
			$candidates = array_values( array_map( 'intval', $p['media_ids'] ) );
		} else {
			$candidates = $this->image_attachment_ids( self::SCAN_MAX );
		}
		$total = count( $candidates );
		$chunk = array_slice( $candidates, $cursor, $limit );

		$batch_id = wp_generate_uuid4();
		$results  = [];
		$regenerated = $skipped = $failed = 0;
		foreach ( $chunk as $id ) {
			$one = $this->do_regenerate( (int) $id, $mode, $batch_id, $cx );
			if ( is_wp_error( $one ) ) {
				$failed++;
				$results[] = [ 'media_id' => (int) $id, 'status' => 'failed', 'code' => $one->get_error_code(), 'message' => $one->get_error_message() ];
			} elseif ( ! empty( $one['no_action'] ) ) {
				$skipped++;
				$results[] = [ 'media_id' => (int) $id, 'status' => 'no_action', 'rollback_id' => null ];
			} else {
				$regenerated++;
				$results[] = [ 'media_id' => (int) $id, 'status' => 'regenerated', 'rollback_id' => $one['rollback_id'], 'regenerated' => $one['regenerated'] ];
			}
		}

		$next = $cursor + $limit;
		$next_cursor = $next < $total ? $next : null;

		return [ 'thumbnail_regenerate_batch' => [
			'batch_id'     => $batch_id,
			'mode'         => $mode,
			'total'        => $total,
			'cursor'       => $cursor,
			'processed'    => count( $chunk ),
			'regenerated'  => $regenerated,
			'no_action'    => $skipped,
			'failed'       => $failed,
			'next_cursor'  => $next_cursor,
			'results'      => $results,
		] ];
	}

	/**
	 * Read-only post-regeneration verification: are all *applicable* registered
	 * sizes present on disk, and is the attachment metadata complete?
	 */
	private function thumbnail_verify( array $p ): array|\WP_Error {
		$id = $this->resolve_image_id( $p );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$class   = $this->classify_sizes( $id );
		$meta_ok = $this->metadata_complete( $id );
		$applicable_present = $class['present'];
		$applicable_missing = $class['missing'];
		return [ 'thumbnail_verify' => [
			'media_id'           => $id,
			'applicable_present' => $applicable_present,
			'applicable_missing' => $applicable_missing,
			'not_applicable'     => $class['not_applicable'],
			'metadata_complete'  => $meta_ok['complete'],
			'complete'           => empty( $applicable_missing ) && $meta_ok['complete'],
		] ];
	}

	/**
	 * Core regeneration routine shared by the single and batch actions.
	 * Returns the success payload, a `no_action` payload, or a WP_Error (after
	 * restoring the pre-write snapshot on any post-snapshot failure).
	 *
	 * @return array|\WP_Error
	 */
	private function do_regenerate( int $id, string $mode, string $batch_id, array $cx ) {
		$original = get_attached_file( $id );
		if ( ! $original || ! is_file( $original ) ) {
			return new \WP_Error( 'wpcc_media_no_files', __( 'Attachment has no original file on disk.', 'wp-command-center' ) );
		}

		$before    = $this->classify_sizes( $id );
		$subsizes  = $this->registered_subsizes();
		$applicable = array_merge( $before['present'], $before['missing'] ); // excludes not_applicable
		$targets   = ( 'all' === $mode ) ? $applicable : $before['missing'];

		// Audit-first: nothing to do → no snapshot, no write, no rollback record.
		if ( empty( $targets ) ) {
			return [
				'media_id'    => $id,
				'mode'        => $mode,
				'no_action'   => true,
				'regenerated' => [],
				'skipped'     => $before['not_applicable'],
				'message'     => __( 'No regeneration required; all applicable sizes are present.', 'wp-command-center' ),
			];
		}

		// Snapshot BEFORE any mutation; abort if it cannot be captured.
		$snapshot = ( new MediaSnapshot() )->capture( $id, 'thumbnail_regenerate' );
		if ( is_wp_error( $snapshot ) ) {
			return new \WP_Error( 'wpcc_thumbnail_snapshot_failed', sprintf( __( 'Could not snapshot the attachment before regeneration: %s', 'wp-command-center' ), $snapshot->get_error_message() ) );
		}
		$snapshot_id = $snapshot['id'];
		$before_files = $this->size_files_abs( $id );

		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( 'all' === $mode ) {
			$new_meta = wp_generate_attachment_metadata( $id, $original );
			if ( is_wp_error( $new_meta ) || ! is_array( $new_meta ) ) {
				( new MediaSnapshot() )->restore( $snapshot_id );
				( new MediaSnapshot() )->delete( $snapshot_id );
				return new \WP_Error( 'wpcc_thumbnail_regenerate_failed', __( 'Regeneration failed to produce metadata; restored pre-regeneration state.', 'wp-command-center' ) );
			}
			wp_update_attachment_metadata( $id, $new_meta );
		} else {
			$editor = wp_get_image_editor( $original );
			if ( is_wp_error( $editor ) ) {
				( new MediaSnapshot() )->restore( $snapshot_id );
				( new MediaSnapshot() )->delete( $snapshot_id );
				return new \WP_Error( 'wpcc_thumbnail_regenerate_failed', sprintf( __( 'No usable image editor: %s; restored pre-regeneration state.', 'wp-command-center' ), $editor->get_error_message() ) );
			}
			$to_make = [];
			foreach ( $targets as $name ) {
				if ( isset( $subsizes[ $name ] ) ) {
					$to_make[ $name ] = [
						'width'  => (int) $subsizes[ $name ]['width'],
						'height' => (int) $subsizes[ $name ]['height'],
						'crop'   => (bool) $subsizes[ $name ]['crop'],
					];
				}
			}
			$resized = $editor->multi_resize( $to_make );
			$meta    = wp_get_attachment_metadata( $id );
			$meta    = is_array( $meta ) ? $meta : [];
			if ( is_array( $resized ) ) {
				$meta['sizes'] = array_merge( $meta['sizes'] ?? [], $resized );
				wp_update_attachment_metadata( $id, $meta );
			}
		}

		// Verify: every targeted size must now be present on disk.
		$after  = $this->classify_sizes( $id );
		$still_missing = array_values( array_intersect( $targets, $after['missing'] ) );
		if ( ! empty( $still_missing ) ) {
			( new MediaSnapshot() )->restore( $snapshot_id );
			$this->delete_created_files( $before_files, $id );
			( new MediaSnapshot() )->delete( $snapshot_id );
			return new \WP_Error( 'wpcc_thumbnail_regenerate_failed', sprintf( __( 'Regeneration did not produce: %s. Restored pre-regeneration state.', 'wp-command-center' ), implode( ', ', $still_missing ) ) );
		}

		$created     = array_values( array_diff( $this->size_files_abs( $id ), $before_files ) );
		$rollback_id = $this->store_rollback( $id, $snapshot_id, $created, $mode, $targets, $batch_id, $cx );

		$this->audit->record( 'media_enhance.thumbnail_regenerated', [
			'media_id' => $id, 'mode' => $mode, 'regenerated' => $targets, 'rollback_id' => $rollback_id, 'batch_id' => $batch_id ?: null,
		] );

		return [
			'media_id'    => $id,
			'mode'        => $mode,
			'regenerated' => array_values( $targets ),
			'skipped'     => $after['not_applicable'],
			'present'     => $after['present'],
			'verified'    => true,
			'snapshot_id' => $snapshot_id,
			'rollback_id' => $rollback_id,
			'batch_id'    => $batch_id ?: null,
		];
	}

	/**
	 * Reverse a regeneration: delete any files created by it, then restore the
	 * pre-regeneration snapshot byte-for-byte (original + prior size files +
	 * metadata). Routed here by OperationExecutor::rollback and the REST
	 * /media_enhance/rollback route. Returns the in-band error convention on
	 * failure (`{error:true,code,message}`).
	 */
	public function rollback( array $payload, array $context = [] ): array {
		$rollback_id = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID is required.', 'wp-command-center' ) );
		}

		$store = get_option( self::ROLLBACK_STORE, [] );
		$record = null;
		$idx    = null;
		foreach ( $store as $i => $r ) {
			if ( ( $r['id'] ?? '' ) === $rollback_id ) {
				$record = $r;
				$idx    = $i;
				break;
			}
		}
		if ( null === $record ) {
			return $this->error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}
		if ( ! empty( $record['rollback_applied'] ) ) {
			return $this->error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		}

		// Delete files created by the regeneration (not part of the snapshot)…
		foreach ( (array) ( $record['created_files'] ?? [] ) as $abs ) {
			if ( is_string( $abs ) && is_file( $abs ) ) {
				@unlink( $abs );
			}
		}
		// …then restore the pre-regeneration bytes + metadata.
		$restore = ( new MediaSnapshot() )->restore( (string) ( $record['snapshot_id'] ?? '' ) );
		$verified = is_array( $restore ) && ! empty( $restore['verified'] );

		$store[ $idx ]['rollback_applied'] = true;
		update_option( self::ROLLBACK_STORE, $store );

		$this->audit->record( 'media_enhance.rollback.applied', [
			'rollback_id' => $rollback_id, 'media_id' => (int) $record['media_id'], 'verified' => $verified,
		] );

		return [
			'action'      => 'thumbnail_regenerate_rollback',
			'rollback_id' => $rollback_id,
			'media_id'    => (int) $record['media_id'],
			'verified'    => $verified,
		];
	}

	/** Persist a rollback record; returns its id (capped FIFO). */
	private function store_rollback( int $media_id, string $snapshot_id, array $created_files, string $mode, array $regenerated, string $batch_id, array $context ): string {
		$store = get_option( self::ROLLBACK_STORE, [] );
		$id    = wp_generate_uuid4();
		$store[] = [
			'id'               => $id,
			'media_id'         => $media_id,
			'snapshot_id'      => $snapshot_id,
			'created_files'    => array_values( $created_files ),
			'mode'             => $mode,
			'regenerated'      => array_values( $regenerated ),
			'batch_id'         => $batch_id ?: null,
			'rollback_applied' => false,
			'created_at'       => time(),
			'session_id'       => $context['session_id'] ?? null,
			'task_id'          => $context['task_id'] ?? null,
		];
		while ( count( $store ) > self::ROLLBACK_MAX ) {
			array_shift( $store );
		}
		update_option( self::ROLLBACK_STORE, $store );
		return $id;
	}

	/** Absolute paths of an attachment's current intermediate size files (excludes original). */
	private function size_files_abs( int $id ): array {
		$meta = wp_get_attachment_metadata( $id );
		$base = $this->attachment_base_dir( $id );
		$out  = [];
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && '' !== $base ) {
			foreach ( $meta['sizes'] as $s ) {
				$file = (string) ( $s['file'] ?? '' );
				if ( '' !== $file ) {
					$out[] = $base . '/' . $file;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Delete size files present now but absent from $before (used on failed-regen cleanup). */
	private function delete_created_files( array $before_files, int $id ): void {
		foreach ( array_diff( $this->size_files_abs( $id ), $before_files ) as $abs ) {
			if ( is_string( $abs ) && is_file( $abs ) ) {
				@unlink( $abs );
			}
		}
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}

	// ── STEP 100.6 — WebP audit + additive generation ────────────

	/**
	 * Library-wide WebP coverage report + capability probe. Read-only. Counts how
	 * many image files (original + sizes) already have a `.webp` sibling.
	 */
	private function webp_audit( array $p ): array {
		$cap   = $this->webp_capability();
		$limit = $this->scan_limit( $p );
		$ids   = $this->image_attachment_ids( $limit + 1 );
		$truncated = count( $ids ) > $limit;
		if ( $truncated ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		$scanned = $full = $partial = $none = 0;
		$total_files = $webp_present = 0;
		foreach ( $ids as $id ) {
			$scanned++;
			$files = $this->attachment_image_files( $id );
			if ( empty( $files ) ) {
				$none++;
				continue;
			}
			$have = 0;
			foreach ( $files as $f ) {
				$total_files++;
				if ( is_file( $f['file'] . '.webp' ) ) {
					$have++;
					$webp_present++;
				}
			}
			if ( 0 === $have ) {
				$none++;
			} elseif ( $have === count( $files ) ) {
				$full++;
			} else {
				$partial++;
			}
		}

		return [ 'webp_audit' => [
			'capability'      => $cap,
			'scanned'         => $scanned,
			'fully_covered'   => $full,
			'partially_covered' => $partial,
			'no_webp'         => $none,
			'image_files'     => $total_files,
			'webp_present'    => $webp_present,
			'coverage_percent' => $total_files > 0 ? (int) round( $webp_present / $total_files * 100 ) : 0,
			'truncated'       => $truncated,
			'scan_limit'      => $limit,
		] ];
	}

	/**
	 * Per-attachment WebP verification: for each image file, whether a `.webp`
	 * sibling exists and whether it is smaller-or-equal than its source.
	 */
	private function webp_verify( array $p ): array|\WP_Error {
		$id = $this->resolve_image_id( $p );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$mime  = (string) get_post_mime_type( $id );
		$files = $this->attachment_image_files( $id );

		$out = [];
		$with = 0;
		foreach ( $files as $f ) {
			$webp = $f['file'] . '.webp';
			$exists = is_file( $webp );
			$src_bytes  = is_file( $f['file'] ) ? (int) filesize( $f['file'] ) : 0;
			$webp_bytes = $exists ? (int) filesize( $webp ) : 0;
			if ( $exists ) {
				$with++;
			}
			$out[] = [
				'role'            => $f['role'],
				'source'          => basename( $f['file'] ),
				'webp'            => basename( $webp ),
				'webp_exists'     => $exists,
				'source_bytes'    => $src_bytes,
				'webp_bytes'      => $webp_bytes,
				'smaller_or_equal' => $exists && $src_bytes > 0 ? ( $webp_bytes <= $src_bytes ) : null,
			];
		}

		return [ 'webp_verify' => [
			'media_id'      => $id,
			'mime'          => $mime,
			'supported'     => in_array( $mime, self::WEBP_SOURCE_MIMES, true ),
			'total'         => count( $files ),
			'with_webp'     => $with,
			'missing_webp'  => count( $files ) - $with,
			'fully_covered' => count( $files ) > 0 && $with === count( $files ),
			'files'         => $out,
		] ];
	}

	/**
	 * Generate `.webp` sidecars for one attachment's image files (original + each
	 * size). Additive: originals are never modified, replaced, or deleted — only
	 * new `<file>.webp` files are written. Capability-gated (fail closed); skips
	 * files that already have a `.webp`; snapshot-backed; reversible (rollback
	 * deletes the generated `.webp`). Returns structured generated/skipped/failed.
	 */
	private function webp_generate( array $p, array $cx = [] ): array|\WP_Error {
		if ( ! $this->webp_encode_available() ) {
			return new \WP_Error( 'wpcc_image_lib_unavailable', __( 'WebP encoding is not available on this server (no GD/Imagick WebP support).', 'wp-command-center' ) );
		}
		$id = $this->resolve_image_id( $p );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$res = $this->do_webp_generate( $id, '', $cx );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return [ 'webp_generate' => $res ];
	}

	/**
	 * Cursor batch WebP generation with partial-success reporting. Capability is
	 * checked once up front (fail closed). Each item is independently snapshot-
	 * backed and reversible; unsupported / missing-source / failed items are
	 * counted and reported without aborting the batch.
	 */
	private function webp_generate_batch( array $p, array $cx = [] ): array|\WP_Error {
		if ( ! $this->webp_encode_available() ) {
			return new \WP_Error( 'wpcc_image_lib_unavailable', __( 'WebP encoding is not available on this server (no GD/Imagick WebP support).', 'wp-command-center' ) );
		}

		$limit  = max( 1, min( self::BATCH_MAX, isset( $p['limit'] ) ? (int) $p['limit'] : self::BATCH_DEFAULT ) );
		$cursor = max( 0, isset( $p['cursor'] ) ? (int) $p['cursor'] : 0 );
		if ( ! empty( $p['media_ids'] ) && is_array( $p['media_ids'] ) ) {
			$candidates = array_values( array_map( 'intval', $p['media_ids'] ) );
		} else {
			$candidates = $this->image_attachment_ids( self::SCAN_MAX );
		}
		$total = count( $candidates );
		$chunk = array_slice( $candidates, $cursor, $limit );

		$batch_id = wp_generate_uuid4();
		$results  = [];
		$generated = $no_action = $unsupported = $failed = 0;
		foreach ( $chunk as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( (int) $id ) ) {
				$failed++;
				$results[] = [ 'media_id' => (int) $id, 'status' => 'failed', 'code' => 'wpcc_media_not_found' ];
				continue;
			}
			$one = $this->do_webp_generate( (int) $id, $batch_id, $cx );
			if ( is_wp_error( $one ) ) {
				$code = $one->get_error_code();
				if ( 'wpcc_webp_unsupported_mime' === $code ) {
					$unsupported++;
					$results[] = [ 'media_id' => (int) $id, 'status' => 'unsupported', 'code' => $code ];
				} else {
					$failed++;
					$results[] = [ 'media_id' => (int) $id, 'status' => 'failed', 'code' => $code ];
				}
			} elseif ( ! empty( $one['no_action'] ) ) {
				$no_action++;
				$results[] = [ 'media_id' => (int) $id, 'status' => 'no_action', 'rollback_id' => null ];
			} else {
				$generated++;
				$results[] = [ 'media_id' => (int) $id, 'status' => 'generated', 'count_generated' => $one['count_generated'], 'rollback_id' => $one['rollback_id'] ];
			}
		}

		$next = $cursor + $limit;
		return [ 'webp_generate_batch' => [
			'batch_id'    => $batch_id,
			'total'       => $total,
			'cursor'      => $cursor,
			'processed'   => count( $chunk ),
			'generated'   => $generated,
			'no_action'   => $no_action,
			'unsupported' => $unsupported,
			'failed'      => $failed,
			'next_cursor' => $next < $total ? $next : null,
			'results'     => $results,
		] ];
	}

	/**
	 * Core additive WebP generation for one attachment. Returns the structured
	 * payload, a `no_action` payload (all sidecars already present — no snapshot,
	 * no write), or a WP_Error (unsupported mime / missing source / snapshot
	 * failure / nothing-could-be-generated, after cleanup).
	 *
	 * @return array|\WP_Error
	 */
	private function do_webp_generate( int $id, string $batch_id, array $cx ) {
		$mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, self::WEBP_SOURCE_MIMES, true ) ) {
			return new \WP_Error( 'wpcc_webp_unsupported_mime', sprintf( __( 'WebP cannot be generated from this mime type (%s); JPEG/PNG only.', 'wp-command-center' ), $mime ?: 'unknown' ) );
		}

		$files = $this->attachment_image_files( $id );
		$original = get_attached_file( $id );
		if ( empty( $files ) || ! $original || ! is_file( $original ) ) {
			return new \WP_Error( 'wpcc_media_no_files', __( 'Attachment has no source files on disk to convert.', 'wp-command-center' ) );
		}

		// Targets = image files without an existing .webp sibling (no duplicates).
		$targets = [];
		$existing = [];
		foreach ( $files as $f ) {
			if ( is_file( $f['file'] . '.webp' ) ) {
				$existing[] = $f['role'];
			} else {
				$targets[] = $f;
			}
		}
		if ( empty( $targets ) ) {
			return [
				'media_id'         => $id,
				'no_action'        => true,
				'generated'        => [],
				'skipped_existing' => $existing,
				'count_generated'  => 0,
				'message'          => __( 'WebP already present for all image files.', 'wp-command-center' ),
			];
		}

		// Snapshot before any write (defensive; originals are never modified).
		$snapshot = ( new MediaSnapshot() )->capture( $id, 'webp_generate' );
		if ( is_wp_error( $snapshot ) ) {
			return new \WP_Error( 'wpcc_webp_snapshot_failed', sprintf( __( 'Could not snapshot the attachment before WebP generation: %s', 'wp-command-center' ), $snapshot->get_error_message() ) );
		}
		$snapshot_id = $snapshot['id'];

		$generated = [];
		$created   = [];
		$failed    = [];
		foreach ( $targets as $f ) {
			$dest   = $f['file'] . '.webp';
			$editor = wp_get_image_editor( $f['file'] );
			if ( is_wp_error( $editor ) ) {
				$failed[] = [ 'role' => $f['role'], 'error' => $editor->get_error_message() ];
				continue;
			}
			$saved = $editor->save( $dest, 'image/webp' );
			if ( is_wp_error( $saved ) || ! is_file( $dest ) ) {
				$failed[] = [ 'role' => $f['role'], 'error' => is_wp_error( $saved ) ? $saved->get_error_message() : 'not written' ];
				continue;
			}
			$generated[] = [ 'role' => $f['role'], 'webp' => basename( $dest ), 'bytes' => (int) filesize( $dest ), 'source_bytes' => (int) filesize( $f['file'] ) ];
			$created[]   = $dest;
		}

		// If nothing could be generated, restore + clean and report failure.
		if ( empty( $generated ) ) {
			( new MediaSnapshot() )->restore( $snapshot_id );
			foreach ( $created as $c ) {
				if ( is_file( $c ) ) { @unlink( $c ); }
			}
			( new MediaSnapshot() )->delete( $snapshot_id );
			return new \WP_Error( 'wpcc_webp_generate_failed', __( 'No WebP files could be generated; pre-generation state restored.', 'wp-command-center' ) );
		}

		$rollback_id = $this->store_rollback( $id, $snapshot_id, $created, 'webp_generate', array_column( $generated, 'role' ), $batch_id, $cx );

		$this->audit->record( 'media_enhance.webp_generated', [
			'media_id' => $id, 'generated' => count( $generated ), 'failed' => count( $failed ), 'rollback_id' => $rollback_id, 'batch_id' => $batch_id ?: null,
		] );

		return [
			'media_id'         => $id,
			'generated'        => $generated,
			'skipped_existing' => $existing,
			'failed'           => $failed,
			'count_generated'  => count( $generated ),
			'count_skipped'    => count( $existing ),
			'count_failed'     => count( $failed ),
			'verified'         => $this->all_files_exist( $created ),
			'snapshot_id'      => $snapshot_id,
			'rollback_id'      => $rollback_id,
			'batch_id'         => $batch_id ?: null,
		];
	}

	/** Whether WebP encoding is available (GD or Imagick), filterable for ops/testing. */
	private function webp_encode_available(): bool {
		$gd      = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$gd_info = $gd ? gd_info() : [];
		$gd_webp = $gd && function_exists( 'imagewebp' ) && ! empty( $gd_info['WebP Support'] );

		$imagick  = extension_loaded( 'imagick' ) && class_exists( '\Imagick' );
		$im_webp  = false;
		if ( $imagick ) {
			try { $im_webp = in_array( 'WEBP', array_map( 'strtoupper', (array) \Imagick::queryFormats() ), true ); } catch ( \Throwable $e ) { $im_webp = false; }
		}
		/** Allow operators/tests to force-disable WebP encoding (fail closed). */
		return (bool) apply_filters( 'wpcc_media_webp_encode_available', $gd_webp || $im_webp );
	}

	/** Capability detail used by webp_audit. */
	private function webp_capability(): array {
		$gd      = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$gd_info = $gd ? gd_info() : [];
		$gd_webp = $gd && function_exists( 'imagewebp' ) && ! empty( $gd_info['WebP Support'] );
		$imagick = extension_loaded( 'imagick' ) && class_exists( '\Imagick' );
		$im_webp = false;
		if ( $imagick ) {
			try { $im_webp = in_array( 'WEBP', array_map( 'strtoupper', (array) \Imagick::queryFormats() ), true ); } catch ( \Throwable $e ) { $im_webp = false; }
		}
		return [
			'gd_webp'      => $gd_webp,
			'imagick_webp' => $im_webp,
			'webp_encode'  => $this->webp_encode_available(),
		];
	}

	/**
	 * An attachment's convertible image files (original + each existing size) with
	 * a role label.
	 *
	 * @return array<int,array{role:string,file:string}>
	 */
	private function attachment_image_files( int $id ): array {
		$out      = [];
		$original = get_attached_file( $id );
		if ( $original && is_file( $original ) ) {
			$out[] = [ 'role' => 'original', 'file' => $original ];
		}
		$meta = wp_get_attachment_metadata( $id );
		$base = $this->attachment_base_dir( $id );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && '' !== $base ) {
			foreach ( $meta['sizes'] as $name => $s ) {
				$file = (string) ( $s['file'] ?? '' );
				if ( '' !== $file && is_file( $base . '/' . $file ) ) {
					$out[] = [ 'role' => (string) $name, 'file' => $base . '/' . $file ];
				}
			}
		}
		return $out;
	}

	private function all_files_exist( array $paths ): bool {
		foreach ( $paths as $pth ) {
			if ( ! is_file( $pth ) ) {
				return false;
			}
		}
		return true;
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
