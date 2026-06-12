<?php
/**
 * Step 17 — ACF Seeder Operation.
 *
 * Populates existing ACF fields on existing WordPress content using native ACF APIs.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class AcfSeed {

	public const ALLOWED_FIELD_TYPES = [
		'text',
		'textarea',
		'wysiwyg',
		'url',
		'number',
		'select',
		'true_false',
	];

	/**
	 * Run the ACF seeding operation.
	 *
	 * @param array{
	 *     post_id: int,
	 *     fields: array<string, mixed>
	 * } $params
	 * @param array $context Optional metadata.
	 *
	 * @return array|\WP_Error Result summary or error.
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'update_field' ) ) {
			return new \WP_Error( 'wpcc_acf_inactive', __( 'Advanced Custom Fields is not active.', 'wp-command-center' ) );
		}

		$post_id = (int) ( $params['post_id'] ?? 0 );
		$fields  = (array) ( $params['fields'] ?? [] );

		if ( 0 === $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'wpcc_invalid_post_id', __( 'Invalid post ID.', 'wp-command-center' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'wpcc_insufficient_permissions', __( 'You do not have permission to edit this post.', 'wp-command-center' ) );
		}

		if ( empty( $fields ) ) {
			return new \WP_Error( 'wpcc_no_fields_supplied', __( 'No fields supplied for seeding.', 'wp-command-center' ) );
		}

		$execution_result = [];

		foreach ( $fields as $field_key => $value ) {
			// acf_get_field can take a field name or key.
			$field_object = acf_get_field( $field_key );

			if ( ! $field_object ) {
				return new \WP_Error( 'wpcc_unknown_acf_field', sprintf( __( 'ACF field "%s" not found.', 'wp-command-center' ), $field_key ) );
			}

			if ( ! in_array( $field_object['type'], self::ALLOWED_FIELD_TYPES, true ) ) {
				return new \WP_Error(
					'wpcc_unsupported_acf_field_type',
					sprintf( __( 'ACF field type "%s" is not supported for seeding.', 'wp-command-center' ), $field_object['type'] )
				);
			}

			// Sanitize based on type.
			$sanitized_value = $this->sanitize_value( $value, $field_object['type'] );

			$updated = update_field( $field_key, $sanitized_value, $post_id );

			if ( false === $updated ) {
				// update_field returns false if the value is the same, so we should check
				// if the value actually changed or if it was already that value.
				$current_value = get_field( $field_key, $post_id, false );
				if ( $current_value !== $sanitized_value ) {
					return new \WP_Error( 'wpcc_acf_update_failed', sprintf( __( 'Failed to update ACF field "%s".', 'wp-command-center' ), $field_key ) );
				}
			}

			$execution_result[ $field_key ] = 'updated';
		}

		return [
			'post_id'          => $post_id,
			'field_count'      => count( $fields ),
			'execution_result' => $execution_result,
		];
	}

	/**
	 * Sanitize field value based on ACF type.
	 */
	private function sanitize_value( mixed $value, string $type ): mixed {
		switch ( $type ) {
			case 'number':
				return is_numeric( $value ) ? $value + 0 : 0;
			case 'true_false':
				return (bool) $value;
			case 'url':
				return esc_url_raw( (string) $value );
			case 'wysiwyg':
				return wp_kses_post( (string) $value );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
