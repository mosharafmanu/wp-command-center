<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class MediaRuntimeManager {

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $payload, array $context = [] ): array {
		$action = (string) ( $payload['action'] ?? '' );
		if ( ! in_array( $action, MediaRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_media_action', __( 'Invalid media action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			MediaRegistry::ACTION_LIST                => $this->list_media( $payload ),
			MediaRegistry::ACTION_GET                 => $this->get_media( $payload ),
			MediaRegistry::ACTION_SEARCH              => $this->search_media( $payload ),
			MediaRegistry::ACTION_UPLOAD              => $this->upload_media( $payload, $context ),
			MediaRegistry::ACTION_UPDATE              => $this->update_media( $payload, $context ),
			MediaRegistry::ACTION_REPLACE             => $this->replace_media( $payload, $context ),
			MediaRegistry::ACTION_REPLACE_VERIFY      => $this->replace_verify( $payload ),
			MediaRegistry::ACTION_DELETE              => $this->delete_media( $payload, $context ),
			MediaRegistry::ACTION_RESTORE             => $this->restore_media( $payload, $context ),
			MediaRegistry::ACTION_FEATURED_ASSIGN,
			MediaRegistry::ACTION_SET_FEATURED        => $this->featured_assign( $payload, $context ),
			MediaRegistry::ACTION_FEATURED_REMOVE,
			MediaRegistry::ACTION_REMOVE_FEATURED     => $this->featured_remove( $payload, $context ),
			MediaRegistry::ACTION_REGENERATE_METADATA => $this->regenerate_metadata( $payload, $context ),
			MediaRegistry::ACTION_SNAPSHOT_CREATE     => $this->snapshot_create( $payload, $context ),
			MediaRegistry::ACTION_SNAPSHOT_RESTORE    => $this->snapshot_restore( $payload, $context ),
			MediaRegistry::ACTION_SNAPSHOT_VERIFY     => $this->snapshot_verify( $payload ),
			MediaRegistry::ACTION_SNAPSHOT_LIST       => $this->snapshot_list( $payload ),
			default => $this->error( 'wpcc_unknown_media_action', __( 'Unknown media action.', 'wp-command-center' ) ),
		};
	}

	// ── STEP 100.1 — file-level snapshot primitives ──────────────

	private function snapshot_create( array $payload, array $context ): array {
		$media_id = (int) ( $payload['media_id'] ?? 0 );
		$label    = sanitize_text_field( (string) ( $payload['label'] ?? '' ) );
		$result   = ( new MediaSnapshot() )->capture( $media_id, $label );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}
		return [ 'action' => 'media_snapshot_create', 'snapshot_id' => $result['id'], 'media_id' => $result['attachment_id'], 'files' => $result['files'] ];
	}

	private function snapshot_restore( array $payload, array $context ): array {
		$snapshot_id = (string) ( $payload['snapshot_id'] ?? '' );
		if ( '' === $snapshot_id ) {
			return $this->error( 'wpcc_missing_snapshot_id', __( 'snapshot_id is required.', 'wp-command-center' ) );
		}
		$result = ( new MediaSnapshot() )->restore( $snapshot_id );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}
		return array_merge( [ 'action' => 'media_snapshot_restore', 'snapshot_id' => $snapshot_id ], $result );
	}

	private function snapshot_verify( array $payload ): array {
		$snapshot_id = (string) ( $payload['snapshot_id'] ?? '' );
		if ( '' === $snapshot_id ) {
			return $this->error( 'wpcc_missing_snapshot_id', __( 'snapshot_id is required.', 'wp-command-center' ) );
		}
		$result = ( new MediaSnapshot() )->verify( $snapshot_id );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}
		return array_merge( [ 'action' => 'media_snapshot_verify', 'snapshot_id' => $snapshot_id ], $result );
	}

	private function snapshot_list( array $payload ): array {
		$media_id = (int) ( $payload['media_id'] ?? 0 );
		return [ 'action' => 'media_snapshot_list', 'snapshots' => ( new MediaSnapshot() )->list( $media_id ) ];
	}

	// ── STEP 100.2 — replace verification + abort cleanup ────────

	/**
	 * Report the current live file state of an attachment — used to confirm a
	 * media_replace took effect, and that a rollback restored the original.
	 */
	private function replace_verify( array $payload ): array {
		$media_id = (int) ( $payload['media_id'] ?? 0 );
		$post     = get_post( $media_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}
		$file = get_attached_file( $media_id );
		$meta = wp_get_attachment_metadata( $media_id );
		$exists = $file && is_file( $file );
		return [
			'action'      => 'media_replace_verify',
			'media_id'    => $media_id,
			'file'        => $file ? wp_basename( $file ) : '',
			'file_exists' => (bool) $exists,
			'file_size'   => $exists ? ( filesize( $file ) ?: 0 ) : 0,
			'hash'        => $exists ? md5_file( $file ) : '',
			'mime_type'   => get_post_mime_type( $media_id ),
			'width'       => is_array( $meta ) ? ( $meta['width'] ?? 0 ) : 0,
			'height'      => is_array( $meta ) ? ( $meta['height'] ?? 0 ) : 0,
			'sizes'       => is_array( $meta ) && ! empty( $meta['sizes'] ) ? array_keys( $meta['sizes'] ) : [],
			'url'         => wp_get_attachment_url( $media_id ),
		];
	}

	/** Discard a rollback record + its snapshot when a replace aborts before mutating. */
	private function discard_replace_snapshot( string $rollback_id, string $snapshot_id ): void {
		if ( '' !== $snapshot_id ) {
			( new MediaSnapshot() )->delete( $snapshot_id );
		}
		if ( '' === $rollback_id ) {
			return;
		}
		$rollbacks = get_option( 'wpcc_media_rollbacks', [] );
		$kept      = array_values( array_filter( $rollbacks, static fn( $r ) => ( $r['id'] ?? '' ) !== $rollback_id ) );
		if ( count( $kept ) !== count( $rollbacks ) ) {
			update_option( 'wpcc_media_rollbacks', $kept );
		}
	}

	private function list_media( array $payload ): array {
		$per_page = min( 100, max( 1, (int) ( $payload['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $payload['page'] ?? 1 ) );
		$mime     = sanitize_text_field( (string) ( $payload['mime_type'] ?? '' ) );

		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ( '' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}

		$query = new \WP_Query( $args );
		$items = [];
		foreach ( $query->posts as $post ) {
			$items[] = $this->format_media( $post );
		}

		$this->audit->record( 'media.list', [ 'count' => count( $items ), 'total' => $query->found_posts ] );

		return [ 'action' => 'media_list', 'items' => $items, 'total' => (int) $query->found_posts, 'page' => $page, 'per_page' => $per_page ];
	}

	private function get_media( array $payload ): array {
		$media_id = (int) ( $payload['media_id'] ?? 0 );
		$post     = get_post( $media_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}

		$this->audit->record( 'media.get', [ 'media_id' => $media_id ] );

		return [ 'action' => 'media_get', 'media' => $this->format_media( $post ) ];
	}

	private function search_media( array $payload ): array {
		$search = sanitize_text_field( (string) ( $payload['search'] ?? '' ) );
		if ( '' === $search ) {
			return $this->error( 'wpcc_media_empty_search', __( 'Search term is required.', 'wp-command-center' ) );
		}

		$query = new \WP_Query( [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => $search,
			'posts_per_page' => 50,
		] );

		$items = [];
		foreach ( $query->posts as $post ) {
			$items[] = $this->format_media( $post );
		}

		$this->audit->record( 'media.search', [ 'search' => $search, 'results' => count( $items ) ] );

		return [ 'action' => 'media_search', 'items' => $items, 'total' => count( $items ) ];
	}

	private function upload_media( array $payload, array $context ): array {
		$source_url = esc_url_raw( (string) ( $payload['source_url'] ?? '' ) );
		$title      = sanitize_text_field( (string) ( $payload['title'] ?? '' ) );
		$alt        = sanitize_text_field( (string) ( $payload['alt'] ?? '' ) );
		$caption    = sanitize_text_field( (string) ( $payload['caption'] ?? '' ) );
		$post_id    = (int) ( $payload['attach_to_post_id'] ?? 0 );

		if ( '' === $source_url ) {
			return $this->error( 'wpcc_missing_url', __( 'Source URL is required.', 'wp-command-center' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $source_url );
		if ( is_wp_error( $tmp ) ) {
			return $this->error( 'wpcc_download_failed', $tmp->get_error_message() );
		}

		$file_array = [
			'name'     => basename( wp_parse_url( $source_url, PHP_URL_PATH ) ?: 'upload' ),
			'tmp_name' => $tmp,
		];

		$attach_id = media_handle_sideload( $file_array, $post_id, $title );
		if ( is_wp_error( $attach_id ) ) {
			@unlink( $tmp );
			return $this->error( 'wpcc_upload_failed', $attach_id->get_error_message() );
		}

		$description = sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) );

		if ( '' !== $alt ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
		}
		$post_update = [];
		if ( '' !== $caption ) {
			$post_update['post_excerpt'] = $caption;
		}
		if ( '' !== $description ) {
			$post_update['post_content'] = $description;
		}
		if ( ! empty( $post_update ) ) {
			$post_update['ID'] = $attach_id;
			wp_update_post( $post_update );
		}

		$rollback_id = $this->store_rollback( $attach_id, 'upload', [], $context );

		$this->audit->record( 'media.uploaded', [ 'media_id' => $attach_id, 'source' => $source_url ] );

		return [ 'action' => 'media_upload', 'media_id' => $attach_id, 'url' => wp_get_attachment_url( $attach_id ), 'rollback_id' => $rollback_id ];
	}

	/**
	 * STEP 90 — update metadata (title, alt, caption, description) on an existing
	 * attachment. Rollback-capable: the full prior metadata is snapshotted first.
	 */
	private function update_media( array $payload, array $context ): array {
		$media_id = (int) ( $payload['media_id'] ?? 0 );
		$post     = get_post( $media_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}

		// At least one updatable field must be supplied.
		$has_field = false;
		foreach ( [ 'title', 'alt', 'caption', 'description' ] as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$has_field = true;
				break;
			}
		}
		if ( ! $has_field ) {
			return $this->error( 'wpcc_media_no_fields', __( 'Provide at least one of: title, alt, caption, description.', 'wp-command-center' ) );
		}

		$before      = $this->format_media( $post );
		$rollback_id = $this->store_rollback( $media_id, 'update', $before, $context );

		$post_update = [ 'ID' => $media_id ];
		if ( array_key_exists( 'title', $payload ) ) {
			$post_update['post_title'] = sanitize_text_field( (string) $payload['title'] );
		}
		if ( array_key_exists( 'caption', $payload ) ) {
			$post_update['post_excerpt'] = sanitize_text_field( (string) $payload['caption'] );
		}
		if ( array_key_exists( 'description', $payload ) ) {
			$post_update['post_content'] = sanitize_textarea_field( (string) $payload['description'] );
		}
		if ( count( $post_update ) > 1 ) {
			$result = wp_update_post( $post_update, true );
			if ( is_wp_error( $result ) ) {
				return $this->error( 'wpcc_media_update_failed', $result->get_error_message() );
			}
		}
		if ( array_key_exists( 'alt', $payload ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $payload['alt'] ) );
		}

		$updated = $this->format_media( get_post( $media_id ) );

		$this->audit->record( 'media.updated', [
			'media_id' => $media_id,
			'fields'   => array_values( array_intersect( [ 'title', 'alt', 'caption', 'description' ], array_keys( $payload ) ) ),
		] );

		return [ 'action' => 'media_update', 'media_id' => $media_id, 'media' => $updated, 'rollback_id' => $rollback_id ];
	}

	private function replace_media( array $payload, array $context ): array {
		$media_id   = (int) ( $payload['media_id'] ?? 0 );
		$source_url = esc_url_raw( (string) ( $payload['source_url'] ?? '' ) );
		$post       = get_post( $media_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}
		if ( '' === $source_url ) {
			return $this->error( 'wpcc_missing_url', __( 'Source URL is required.', 'wp-command-center' ) );
		}

		$before = $this->format_media( $post );

		// STEP 100.2 — capture a byte-level snapshot (original file + every size +
		// metadata) BEFORE the destructive sideload, so rollback can restore the
		// attachment exactly. If the snapshot cannot be taken, abort rather than
		// perform an irreversible replace.
		$snapshot = ( new MediaSnapshot() )->capture( $media_id, 'media_replace' );
		if ( is_wp_error( $snapshot ) ) {
			return $this->error( $snapshot->get_error_code(), $snapshot->get_error_message() );
		}
		$before['snapshot_id'] = $snapshot['id'];
		$rollback_id           = $this->store_rollback( $media_id, 'replace', $before, $context );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $source_url );
		if ( is_wp_error( $tmp ) ) {
			// Replace never happened — discard the snapshot + its rollback record.
			$this->discard_replace_snapshot( $rollback_id, $snapshot['id'] );
			return $this->error( 'wpcc_download_failed', $tmp->get_error_message() );
		}

		// The downloaded source must be a real image.
		if ( false === getimagesize( $tmp ) ) {
			@unlink( $tmp );
			$this->discard_replace_snapshot( $rollback_id, $snapshot['id'] );
			return $this->error( 'wpcc_replace_not_image', __( 'The source file is not a valid image.', 'wp-command-center' ) );
		}

		// Replace IN PLACE at the original path so the attachment ID and URL are
		// preserved, then regenerate sizes. NOTE: media_handle_sideload ignores an
		// `ID` passed in post_data and instead creates a *new* orphan attachment —
		// so we write the bytes to the existing file ourselves.
		$orig_path = get_attached_file( $media_id );
		$old_meta  = wp_get_attachment_metadata( $media_id );

		if ( ! $orig_path || ! @copy( $tmp, $orig_path ) ) {
			@unlink( $tmp );
			$this->discard_replace_snapshot( $rollback_id, $snapshot['id'] );
			return $this->error( 'wpcc_replace_failed', __( 'Failed to write the replacement file.', 'wp-command-center' ) );
		}
		@unlink( $tmp );

		// Remove the previous generated size files, then regenerate from new bytes.
		if ( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) ) {
			$dir = trailingslashit( dirname( $orig_path ) );
			foreach ( $old_meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					@unlink( $dir . $size['file'] );
				}
			}
		}
		wp_update_attachment_metadata( $media_id, wp_generate_attachment_metadata( $media_id, $orig_path ) );

		// Keep the recorded mime type accurate (the original extension is retained).
		$filetype = wp_check_filetype( $orig_path );
		if ( ! empty( $filetype['type'] ) && $filetype['type'] !== get_post_mime_type( $media_id ) ) {
			wp_update_post( [ 'ID' => $media_id, 'post_mime_type' => $filetype['type'] ] );
		}
		clean_post_cache( $media_id );

		$this->audit->record( 'media.replaced', [ 'media_id' => $media_id, 'source' => $source_url, 'snapshot_id' => $snapshot['id'] ] );

		return [ 'action' => 'media_replace', 'media_id' => $media_id, 'url' => wp_get_attachment_url( $media_id ), 'rollback_id' => $rollback_id ];
	}

	private function delete_media( array $payload, array $context ): array {
		$media_id = (int) ( $payload['media_id'] ?? 0 );
		$force    = ! empty( $payload['force'] );
		$post     = get_post( $media_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}

		$before      = $this->format_media( $post );
		// A force delete bypasses the trash and cannot be restored from a rollback
		// record, so only a soft (trash) delete is rollback-capable.
		$rollback_id = $force ? '' : $this->store_rollback( $media_id, 'delete', $before, $context );

		$result = wp_delete_attachment( $media_id, $force );
		if ( ! $result ) {
			return $this->error( 'wpcc_media_delete_failed', __( 'Failed to delete media.', 'wp-command-center' ) );
		}

		$this->audit->record( 'media.deleted', [ 'media_id' => $media_id, 'title' => $before['title'] ?? '', 'force' => $force ] );

		return [ 'action' => 'media_delete', 'media_id' => $media_id, 'title' => $before['title'] ?? '', 'force' => $force, 'rollback_id' => $rollback_id ];
	}

	private function restore_media( array $payload, array $context ): array {
		$rollback_id = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID is required.', 'wp-command-center' ) );
		}

		$rollbacks = get_option( 'wpcc_media_rollbacks', [] );
		$record    = null;
		$idx       = null;

		foreach ( $rollbacks as $i => $r ) {
			if ( $r['id'] === $rollback_id ) {
				$record = $r;
				$idx    = $i;
				break;
			}
		}

		if ( null === $record ) {
			return $this->error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}
		if ( $record['rollback_applied'] ) {
			return $this->error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		}

		$media_id = $record['media_id'];
		$before   = $record['before_state'];

		// Restore from trash if deleted
		if ( 'delete' === $record['action'] && isset( $before['status'] ) && 'trash' === get_post_status( $media_id ) ) {
			wp_untrash_post( $media_id );
		}

		// Restore prior metadata after an update.
		if ( 'update' === $record['action'] ) {
			$this->restore_metadata( $media_id, $before );
		}

		// STEP 100.2 — restore original file bytes + sizes + metadata after a replace.
		if ( 'replace' === $record['action'] && ! empty( $before['snapshot_id'] ) ) {
			( new MediaSnapshot() )->restore( (string) $before['snapshot_id'] );
		}

		// Restore featured image
		if ( in_array( $record['action'], [ 'featured_image_assign', 'featured_image_remove' ], true ) && isset( $before['post_id'] ) ) {
			$parent = $before['post_id'];
			delete_post_thumbnail( $parent );
			if ( 'featured_image_assign' === $record['action'] && isset( $before['thumbnail_id'] ) && $before['thumbnail_id'] > 0 ) {
				set_post_thumbnail( $parent, $before['thumbnail_id'] );
			}
		}

		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_media_rollbacks', $rollbacks );

		$this->audit->record( 'media.restored', [ 'rollback_id' => $rollback_id, 'media_id' => $media_id ] );

		return [ 'action' => 'media_restore', 'rollback_id' => $rollback_id, 'media_id' => $media_id ];
	}

	private function featured_assign( array $payload, array $context ): array {
		$media_id = (int) ( $payload['media_id'] ?? 0 );
		$post_id  = (int) ( $payload['post_id'] ?? 0 );

		if ( ! get_post( $post_id ) ) {
			return $this->error( 'wpcc_post_not_found', __( 'Post not found.', 'wp-command-center' ) );
		}

		$existing_thumb = get_post_thumbnail_id( $post_id );
		$rollback_id    = $this->store_rollback( $media_id, 'featured_image_assign', [
			'post_id'      => $post_id,
			'thumbnail_id' => $existing_thumb ?: 0,
		], $context );

		set_post_thumbnail( $post_id, $media_id );

		$this->audit->record( 'featured_image.assigned', [ 'media_id' => $media_id, 'post_id' => $post_id ] );

		return [ 'action' => 'featured_image_assign', 'media_id' => $media_id, 'post_id' => $post_id, 'rollback_id' => $rollback_id ];
	}

	private function featured_remove( array $payload, array $context ): array {
		$post_id  = (int) ( $payload['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'wpcc_post_not_found', __( 'Post not found.', 'wp-command-center' ) );
		}

		$thumb_id    = get_post_thumbnail_id( $post_id );
		$rollback_id = $this->store_rollback( $thumb_id ?: 0, 'featured_image_remove', [
			'post_id'      => $post_id,
			'thumbnail_id' => $thumb_id ?: 0,
		], $context );

		delete_post_thumbnail( $post_id );

		$this->audit->record( 'featured_image.removed', [ 'post_id' => $post_id, 'previous_thumb' => $thumb_id ] );

		return [ 'action' => 'featured_image_remove', 'post_id' => $post_id, 'rollback_id' => $rollback_id ];
	}

	private function regenerate_metadata( array $payload, array $context ): array {
		$media_id = (int) ( $payload['media_id'] ?? 0 );
		$post     = get_post( $media_id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error( 'wpcc_media_not_found', __( 'Media not found.', 'wp-command-center' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$meta = wp_generate_attachment_metadata( $media_id, get_attached_file( $media_id ) );
		wp_update_attachment_metadata( $media_id, $meta );

		$this->audit->record( 'media.metadata_regenerated', [ 'media_id' => $media_id ] );

		return [ 'action' => 'media_regenerate_metadata', 'media_id' => $media_id ];
	}

	public function rollback( array $payload, array $context = [] ): array {
		$rollback_id = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID is required.', 'wp-command-center' ) );
		}

		$rollbacks = get_option( 'wpcc_media_rollbacks', [] );
		$record    = null;
		$idx       = null;

		foreach ( $rollbacks as $i => $r ) {
			if ( $r['id'] === $rollback_id ) {
				$record = $r;
				$idx    = $i;
				break;
			}
		}

		if ( null === $record ) {
			return $this->error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}
		if ( $record['rollback_applied'] ) {
			return $this->error( 'wpcc_rollback_already_applied', __( 'Rollback already applied.', 'wp-command-center' ) );
		}

		$media_id = $record['media_id'];
		$action   = $record['action'];
		$before   = $record['before_state'];

		switch ( $action ) {
			case 'upload':
				wp_delete_attachment( $media_id, true );
				break;
			case 'update':
				$this->restore_metadata( $media_id, $before );
				break;
			case 'replace':
				// STEP 100.2 — restore original bytes + sizes + metadata from the
				// pre-replace snapshot (was previously a silent no-op).
				if ( ! empty( $before['snapshot_id'] ) ) {
					( new MediaSnapshot() )->restore( (string) $before['snapshot_id'] );
				}
				break;
			case 'delete':
				wp_untrash_post( $media_id );
				break;
			case 'featured_image_assign':
				if ( isset( $before['post_id'] ) ) {
					delete_post_thumbnail( $before['post_id'] );
					if ( $before['thumbnail_id'] > 0 ) {
						set_post_thumbnail( $before['post_id'], $before['thumbnail_id'] );
					}
				}
				break;
			case 'featured_image_remove':
				if ( isset( $before['post_id'] ) && $before['thumbnail_id'] > 0 ) {
					set_post_thumbnail( $before['post_id'], $before['thumbnail_id'] );
				}
				break;
		}

		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_media_rollbacks', $rollbacks );

		$this->audit->record( 'media.rollback.applied', [ 'rollback_id' => $rollback_id, 'media_id' => $media_id, 'action' => $action ] );

		return [ 'action' => 'media_rollback', 'rollback_id' => $rollback_id, 'media_id' => $media_id ];
	}

	/**
	 * Restore an attachment's title/caption/description/alt from a snapshotted
	 * before-state (used by both rollback paths for the media_update action).
	 */
	private function restore_metadata( int $media_id, array $before ): void {
		if ( ! get_post( $media_id ) ) {
			return;
		}

		wp_update_post( [
			'ID'           => $media_id,
			'post_title'   => (string) ( $before['title'] ?? '' ),
			'post_excerpt' => (string) ( $before['caption'] ?? '' ),
			'post_content' => (string) ( $before['description'] ?? '' ),
		] );

		update_post_meta( $media_id, '_wp_attachment_image_alt', (string) ( $before['alt'] ?? '' ) );
	}

	/**
	 * Internal rollback-action names used in the stored records (and the
	 * rollback()/restore_media() switches). Distinct from the public operation
	 * action names (media_upload, …) which MediaRegistry::supports_rollback maps.
	 */
	private const ROLLBACKABLE = [ 'upload', 'update', 'replace', 'delete', 'featured_image_assign', 'featured_image_remove' ];

	private function store_rollback( int $media_id, string $action, array $before, array $context ): string {
		if ( ! in_array( $action, self::ROLLBACKABLE, true ) ) {
			return '';
		}

		$rollbacks = get_option( 'wpcc_media_rollbacks', [] );
		$rollback_id = wp_generate_uuid4();

		$rollbacks[] = [
			'id'              => $rollback_id,
			'media_id'        => $media_id,
			'action'          => $action,
			'before_state'    => $before,
			'rollback_applied' => false,
			'created_at'      => time(),
			'session_id'      => $context['session_id'] ?? null,
			'task_id'         => $context['task_id'] ?? null,
		];

		if ( count( $rollbacks ) > 100 ) {
			$rollbacks = array_slice( $rollbacks, -100 );
		}

		update_option( 'wpcc_media_rollbacks', $rollbacks );

		return $rollback_id;
	}

	private function format_media( \WP_Post $post ): array {
		$meta    = wp_get_attachment_metadata( $post->ID );
		$src     = wp_get_attachment_url( $post->ID );
		$mime    = get_post_mime_type( $post->ID );

		return [
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'alt'         => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ?? '',
			'url'         => $src,
			'mime_type'   => $mime,
			'file_size'   => filesize( get_attached_file( $post->ID ) ) ?: 0,
			'width'       => $meta['width'] ?? 0,
			'height'      => $meta['height'] ?? 0,
			'uploaded'    => $post->post_date,
			'modified'    => $post->post_modified,
		];
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
