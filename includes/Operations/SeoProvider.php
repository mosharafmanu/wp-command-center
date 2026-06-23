<?php
/**
 * STEP 91 — SEO provider abstraction.
 *
 * Presents a single, unified SEO field model regardless of whether Rank Math or
 * Yoast SEO is the active plugin, by mapping each unified field to that plugin's
 * post-meta storage. Read/write is meta-based (no dependency on the plugin's PHP
 * API beyond detection), so it is stable across plugin versions.
 *
 * Unified fields:
 *   title, description, focus_keyword, canonical,
 *   og_title, og_description, og_image,
 *   twitter_title, twitter_description, twitter_image,
 *   robots (array of directives, e.g. ["noindex","nofollow"]).
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SeoProvider {

	const YOAST    = 'yoast';
	const RANKMATH = 'rankmath';
	const NONE     = 'none';

	/** Plain (non-robots) fields shared by both providers. */
	const SCALAR_FIELDS = [
		'title', 'description', 'focus_keyword', 'canonical',
		'og_title', 'og_description', 'og_image',
		'twitter_title', 'twitter_description', 'twitter_image',
	];

	const ALL_FIELDS = [
		'title', 'description', 'focus_keyword', 'canonical',
		'og_title', 'og_description', 'og_image',
		'twitter_title', 'twitter_description', 'twitter_image',
		'robots',
	];

	const ROBOTS_DIRECTIVES = [ 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' ];

	/**
	 * Detect the active SEO plugin.
	 */
	public static function detect(): string {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' ) ) {
			return self::YOAST;
		}
		if ( class_exists( 'RankMath' ) || function_exists( 'rank_math' ) ) {
			return self::RANKMATH;
		}
		return self::NONE;
	}

	public static function label( string $provider ): string {
		return match ( $provider ) {
			self::YOAST    => 'Yoast SEO',
			self::RANKMATH => 'Rank Math',
			default        => 'None',
		};
	}

	public static function is_available(): bool {
		return self::NONE !== self::detect();
	}

	/**
	 * Unified scalar-field → meta-key map for a provider.
	 *
	 * @return array<string,string>
	 */
	private static function scalar_map( string $provider ): array {
		if ( self::YOAST === $provider ) {
			return [
				'title'              => '_yoast_wpseo_title',
				'description'        => '_yoast_wpseo_metadesc',
				'focus_keyword'      => '_yoast_wpseo_focuskw',
				'canonical'          => '_yoast_wpseo_canonical',
				'og_title'           => '_yoast_wpseo_opengraph-title',
				'og_description'     => '_yoast_wpseo_opengraph-description',
				'og_image'           => '_yoast_wpseo_opengraph-image',
				'twitter_title'      => '_yoast_wpseo_twitter-title',
				'twitter_description'=> '_yoast_wpseo_twitter-description',
				'twitter_image'      => '_yoast_wpseo_twitter-image',
			];
		}

		return [
			'title'              => 'rank_math_title',
			'description'        => 'rank_math_description',
			'focus_keyword'      => 'rank_math_focus_keyword',
			'canonical'          => 'rank_math_canonical_url',
			'og_title'           => 'rank_math_facebook_title',
			'og_description'     => 'rank_math_facebook_description',
			'og_image'           => 'rank_math_facebook_image',
			'twitter_title'      => 'rank_math_twitter_title',
			'twitter_description'=> 'rank_math_twitter_description',
			'twitter_image'      => 'rank_math_twitter_image',
		];
	}

	/**
	 * The post-meta key backing a unified scalar field for a provider, or '' when
	 * the field/provider is unknown. Read-only accessor over the existing scalar map
	 * (STEP 111 / GA#2 Slice 1) — lets the read-only SEO audit narrow by the active
	 * provider's keys without duplicating the mapping. Adds no behaviour.
	 */
	public static function meta_key( string $field, string $provider ): string {
		if ( self::NONE === $provider ) {
			return '';
		}
		return self::scalar_map( $provider )[ $field ] ?? '';
	}

	/**
	 * Phase 3 (F-1) — the backing post-meta key(s) for a unified field, provider-
	 * correct. Scalars map to their single key; `robots` expands to the provider's
	 * storage shape (Rank Math: one array meta; Yoast: three split keys). Read-only
	 * accessor over the existing maps — adds no behaviour. Used by the field-scoped
	 * delta rollback to capture/restore exactly the keys an operation touched.
	 *
	 * @return string[]
	 */
	public static function backing_keys( string $field, string $provider ): array {
		if ( self::NONE === $provider ) {
			return [];
		}
		if ( 'robots' === $field ) {
			if ( self::RANKMATH === $provider ) {
				return [ 'rank_math_robots' ];
			}
			return [
				'_yoast_wpseo_meta-robots-noindex',
				'_yoast_wpseo_meta-robots-nofollow',
				'_yoast_wpseo_meta-robots-adv',
			];
		}
		$key = self::meta_key( $field, $provider );
		return '' === $key ? [] : [ $key ];
	}

	/**
	 * Phase 3 (F-1) — read a single unified field's current value (scalar string, or
	 * the normalized robots array). Used for drift detection during field-scoped
	 * rollback: the value is compared, the same way `seo_update` produced it, against
	 * the `after` value recorded when the field was applied.
	 *
	 * @return mixed string for scalar fields, string[] for robots.
	 */
	public static function read_field( int $post_id, string $field, string $provider ) {
		if ( 'robots' === $field ) {
			return self::read_robots( $post_id, $provider );
		}
		$key = self::meta_key( $field, $provider );
		return '' === $key ? '' : (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Read all unified SEO fields for a post.
	 *
	 * @return array<string,mixed>
	 */
	public static function read( int $post_id, string $provider ): array {
		$out = [];

		foreach ( self::scalar_map( $provider ) as $field => $key ) {
			$out[ $field ] = (string) get_post_meta( $post_id, $key, true );
		}

		$out['robots'] = self::read_robots( $post_id, $provider );

		return $out;
	}

	/**
	 * Write the supplied unified SEO fields for a post. Only keys present in
	 * $fields are written. Returns the post's fields after the write.
	 *
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	public static function write( int $post_id, array $fields, string $provider ): array {
		$map = self::scalar_map( $provider );

		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				$value = (string) $fields[ $field ];
				if ( '' === $value ) {
					delete_post_meta( $post_id, $key );
				} else {
					update_post_meta( $post_id, $key, $value );
				}
			}
		}

		if ( array_key_exists( 'robots', $fields ) ) {
			self::write_robots( $post_id, (array) $fields['robots'], $provider );
		}

		return self::read( $post_id, $provider );
	}

	/**
	 * @return string[] Normalized robots directives.
	 */
	private static function read_robots( int $post_id, string $provider ): array {
		if ( self::RANKMATH === $provider ) {
			$raw = get_post_meta( $post_id, 'rank_math_robots', true );
			$raw = is_array( $raw ) ? $raw : [];
			return self::normalize_robots( $raw );
		}

		// Yoast splits robots across several meta keys.
		$directives = [];
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			$directives[] = 'noindex';
		}
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ) ) {
			$directives[] = 'nofollow';
		}
		$adv = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true );
		if ( '' !== $adv ) {
			$directives = array_merge( $directives, array_map( 'trim', explode( ',', $adv ) ) );
		}

		return self::normalize_robots( $directives );
	}

	private static function write_robots( int $post_id, array $directives, string $provider ): void {
		$directives = self::normalize_robots( $directives );

		if ( self::RANKMATH === $provider ) {
			if ( empty( $directives ) ) {
				delete_post_meta( $post_id, 'rank_math_robots' );
			} else {
				update_post_meta( $post_id, 'rank_math_robots', array_values( $directives ) );
			}
			return;
		}

		// Yoast.
		$noindex  = in_array( 'noindex', $directives, true );
		$nofollow = in_array( 'nofollow', $directives, true );
		$adv      = array_values( array_intersect( $directives, [ 'noarchive', 'nosnippet', 'noimageindex' ] ) );

		if ( $noindex ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
		} else {
			delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex' );
		}

		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $nofollow ? '1' : '0' );

		if ( ! empty( $adv ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', implode( ',', $adv ) );
		} else {
			delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv' );
		}
	}

	/**
	 * @param array<int,mixed> $directives
	 * @return string[]
	 */
	private static function normalize_robots( array $directives ): array {
		$clean = [];
		foreach ( $directives as $d ) {
			$d = strtolower( trim( (string) $d ) );
			if ( in_array( $d, self::ROBOTS_DIRECTIVES, true ) && ! in_array( $d, $clean, true ) ) {
				$clean[] = $d;
			}
		}
		sort( $clean );
		return $clean;
	}
}
