<?php
/**
 * V1 Phase 5 — canonical parameter vocabulary.
 *
 * The catalogue names the same idea differently depending on which runtime you are
 * talking to. Identifying a post is `content_id` for content_manage and seo_manage,
 * `page_id` for site_builder_manage and elementor_manage, `media_id` for media
 * operations, `object_id` for ACF values, `post_id` for the seeders. Each is
 * reasonable on its own; together they cost an assistant a wrong first call.
 *
 * The fix is deliberately compatibility-first. The name an operation already
 * DECLARES stays canonical — that is the documented contract and nothing about it
 * changes. What is added is a set of accepted aliases, normalised into the canonical
 * name before validation runs, so a caller who reaches for the wrong-but-reasonable
 * synonym is understood instead of refused.
 *
 * Two rules keep this from becoming its own hazard:
 *
 *   - Aliases only ever map names that mean THE SAME THING. `page_id` is a post
 *     identifier for site_builder_manage; it is not silently accepted anywhere the
 *     word would mean something else.
 *   - If a caller supplies both the canonical name and an alias with DIFFERENT
 *     values, the request is refused rather than guessed at. Picking one would be a
 *     coin flip over which object the customer meant to change.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class ParameterVocabulary {

	/**
	 * operation id => [ canonical parameter => [ accepted aliases ] ].
	 *
	 * @var array<string,array<string,array<int,string>>>
	 */
	private const ALIASES = [
		'content_manage'      => [ 'content_id' => [ 'post_id', 'page_id', 'object_id', 'id' ] ],
		'seo_manage'          => [ 'content_id' => [ 'post_id', 'page_id', 'object_id', 'id' ] ],
		'site_builder_manage' => [ 'page_id'    => [ 'post_id', 'content_id', 'id' ] ],
		'elementor_manage'    => [ 'page_id'    => [ 'post_id', 'content_id', 'id' ] ],
		'media_manage'        => [ 'media_id'   => [ 'attachment_id', 'post_id', 'id' ] ],
		'media_enhance'       => [ 'media_id'   => [ 'attachment_id', 'post_id', 'id' ] ],
		'acf_manage'          => [ 'object_id'  => [ 'content_id' ] ],
		'woocommerce_manage'  => [ 'product_id' => [ 'id' ] ],
		'comments_manage'     => [ 'comment_id' => [ 'id' ] ],
		'term_manage'         => [ 'term_id'    => [ 'id' ] ],
		'user_manage'         => [ 'user_id'    => [ 'id' ] ],
		'option_manage'       => [ 'option_id'  => [ 'option', 'option_name' ] ],
		'safe_updates'        => [ 'type'       => [ 'update_type' ] ],
	];

	/**
	 * Normalise a payload into canonical parameter names.
	 *
	 * @param array<string,mixed> $payload
	 * @return array{payload:array<string,mixed>,applied:array<string,string>,conflict:?array{canonical:string,alias:string}}
	 */
	public static function normalize( string $operation_id, array $payload ): array {
		$map     = self::ALIASES[ $operation_id ] ?? [];
		$applied = [];

		foreach ( $map as $canonical => $aliases ) {
			foreach ( $aliases as $alias ) {
				if ( ! array_key_exists( $alias, $payload ) ) {
					continue;
				}

				$alias_value = $payload[ $alias ];

				// Both supplied and disagreeing: refuse rather than pick one.
				if ( array_key_exists( $canonical, $payload )
					&& '' !== (string) $payload[ $canonical ]
					&& (string) $payload[ $canonical ] !== (string) $alias_value ) {
					return [
						'payload'  => $payload,
						'applied'  => $applied,
						'conflict' => [ 'canonical' => $canonical, 'alias' => $alias ],
					];
				}

				if ( ! array_key_exists( $canonical, $payload ) || '' === (string) $payload[ $canonical ] ) {
					$payload[ $canonical ] = $alias_value;
					$applied[ $alias ]     = $canonical;
				}
			}
		}

		return [ 'payload' => $payload, 'applied' => $applied, 'conflict' => null ];
	}

	/**
	 * The canonical name and its accepted aliases, for error messages and describe.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function for_operation( string $operation_id ): array {
		return self::ALIASES[ $operation_id ] ?? [];
	}

	/**
	 * A human sentence naming what this operation calls things, for error messages.
	 */
	public static function hint( string $operation_id ): string {
		$map = self::for_operation( $operation_id );
		if ( [] === $map ) {
			return '';
		}

		$bits = [];
		foreach ( $map as $canonical => $aliases ) {
			$bits[] = sprintf(
				/* translators: 1: canonical parameter name, 2: comma-separated accepted aliases */
				__( '%1$s (also accepted: %2$s)', 'wp-command-center' ),
				$canonical,
				implode( ', ', $aliases )
			);
		}

		/* translators: %s: list of canonical parameter names with their aliases */
		return sprintf( __( 'This operation identifies its target with %s.', 'wp-command-center' ), implode( '; ', $bits ) );
	}
}
