<?php
/**
 * Step 16 — Content Seeder Operation.
 *
 * Creates WordPress posts and pages using native WordPress APIs.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class ContentSeed {

	public const MAX_RECORDS = 100;
	public const ALLOWED_TYPES = [ 'post', 'page' ];
	public const ALLOWED_STATUSES = [ 'draft', 'publish' ];

	/**
	 * Run the content seeding operation.
	 *
	 * @param array{
	 *     type: string,
	 *     count: int,
	 *     status: string,
	 *     title_pattern?: string,
	 *     content_template?: string
	 * } $params
	 * @param array $context Optional metadata.
	 *
	 * @return array|\WP_Error Result summary or error.
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		/*
		 * `type` is declared REQUIRED in the operation catalogue, but this runtime
		 * used to default it to 'post'. The catalogue and the runtime disagreeing
		 * is not academic here: this operation WRITES. A call carrying no usable
		 * parameters at all — a malformed tool call, a hallucinated argument name,
		 * a retry that dropped its body — silently created five posts instead of
		 * being rejected. Every other operation with a required parameter enforces
		 * it (option_id, query, title, user fields, term selector); this one did
		 * not. Seeding demo content is only safe when someone actually asked for it.
		 */
		if ( ! isset( $params['type'] ) || '' === trim( (string) $params['type'] ) ) {
			return new \WP_Error(
				'wpcc_missing_seed_type',
				__( 'type is required. Supported: post, page.', 'action-steward' )
			);
		}

		$type             = sanitize_key( $params['type'] );
		$count            = min( self::MAX_RECORDS, max( 1, (int) ( $params['count'] ?? 5 ) ) );
		$status           = sanitize_key( $params['status'] ?? 'draft' );
		$title_pattern    = sanitize_text_field( $params['title_pattern'] ?? 'Demo {n}' );
		$content_template = wp_kses_post( $params['content_template'] ?? 'Sample content' );

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_post_type', __( 'Invalid post type. Supported: post, page.', 'action-steward' ) );
		}

		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_post_status', __( 'Invalid post status. Supported: draft, publish.', 'action-steward' ) );
		}

		$created_ids = [];

		for ( $i = 1; $i <= $count; $i++ ) {
			$title = str_replace( '{n}', (string) $i, $title_pattern );

			$post_id = wp_insert_post( [
				'post_type'    => $type,
				'post_title'   => $title,
				'post_content' => $content_template,
				'post_status'  => $status,
			] );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			if ( 0 === $post_id ) {
				return new \WP_Error( 'wpcc_content_seed_failed', __( 'Failed to create post.', 'action-steward' ) );
			}

			$created_ids[] = $post_id;
		}

		return [
			'type'        => $type,
			'count'       => count( $created_ids ),
			'status'      => $status,
			'created_ids' => $created_ids,
		];
	}
}
