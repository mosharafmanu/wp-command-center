<?php
/**
 * Step 27 — Media Import Operation.
 *
 * Safe Media Library import operation using WordPress native media APIs.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class MediaImport {

	private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
	private const ALLOWED_EXTS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' ];

	/**
	 * Run the media import operation.
	 *
	 * @param array{
	 *     source_url: string,
	 *     title?: string,
	 *     alt?: string,
	 *     caption?: string,
	 *     description?: string,
	 *     attach_to_post_id?: int
	 * } $params
	 * @param array $context
	 *
	 * @return array|\WP_Error Result summary or error.
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		$url         = esc_url_raw( $params['source_url'] ?? '' );
		$title       = sanitize_text_field( $params['title'] ?? '' );
		$alt         = sanitize_text_field( $params['alt'] ?? '' );
		$caption     = wp_kses_post( $params['caption'] ?? '' );
		$description = wp_kses_post( $params['description'] ?? '' );
		$post_id     = (int) ( $params['attach_to_post_id'] ?? 0 );

		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'wpcc_invalid_url', __( 'Invalid or unsafe source URL.', 'wp-command-center' ) );
		}

		$parsed = wp_parse_url( $url );
		if ( ! in_array( $parsed['scheme'] ?? '', [ 'http', 'https' ], true ) ) {
			return new \WP_Error( 'wpcc_invalid_url_scheme', __( 'Only HTTP and HTTPS URLs are supported.', 'wp-command-center' ) );
		}

		$path_parts = pathinfo( $parsed['path'] ?? '' );
		$ext        = strtolower( $path_parts['extension'] ?? '' );

		if ( ! in_array( $ext, self::ALLOWED_EXTS, true ) ) {
			return new \WP_Error( 'wpcc_unsupported_file_extension', sprintf( __( 'Unsupported file extension: %s.', 'wp-command-center' ), $ext ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'wpcc_insufficient_permissions', __( 'You do not have permission to upload files.', 'wp-command-center' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = download_url( $url, 300 ); // 5 minute timeout for safety

		if ( is_wp_error( $tmp_file ) ) {
			return new \WP_Error( 'wpcc_download_failed', $tmp_file->get_error_message() );
		}

		$filesize = filesize( $tmp_file );
		if ( $filesize > self::MAX_FILE_SIZE ) {
			@unlink( $tmp_file );
			return new \WP_Error( 'wpcc_file_too_large', __( 'File exceeds the maximum allowed size of 10MB.', 'wp-command-center' ) );
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$real_mime = mime_content_type( $tmp_file );
			if ( ! $real_mime || ( ! str_starts_with( $real_mime, 'image/' ) && 'application/pdf' !== $real_mime ) ) {
				@unlink( $tmp_file );
				return new \WP_Error( 'wpcc_invalid_mime_type', __( 'Invalid or unsafe file content detected.', 'wp-command-center' ) );
			}
		}

		$file_array = [
			'name'     => basename( $parsed['path'] ),
			'tmp_name' => $tmp_file,
		];

		if ( empty( $file_array['name'] ) ) {
			$file_array['name'] = 'imported-media.' . $ext;
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id, $description );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
			return new \WP_Error( 'wpcc_sideload_failed', $attachment_id->get_error_message() );
		}

		$update_args = [
			'ID' => $attachment_id,
		];

		if ( $title ) {
			$update_args['post_title'] = $title;
		}
		if ( $caption ) {
			$update_args['post_excerpt'] = $caption;
		}

		if ( count( $update_args ) > 1 ) {
			wp_update_post( $update_args );
		}

		if ( $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		return [
			'id'                => $attachment_id,
			'source_url'        => $url,
			'attach_to_post_id' => $post_id ?: null,
		];
	}
}
