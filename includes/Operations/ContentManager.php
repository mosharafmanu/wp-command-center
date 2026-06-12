<?php
/**
 * Step 42 — Content Management Runtime.
 * 10 operations: list, get, create, update, delete, publish,
 * unpublish, schedule, taxonomy_assign, featured_image_assign.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ContentManager {

	private ContentRegistry $registry;

	public function __construct() {
		$this->registry = new ContentRegistry();
	}

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );
		if ( ! in_array( $action, ContentRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_content_action', __( 'Invalid content action.', 'wp-command-center' ) );
		}

		$content_id = (int) ( $params['content_id'] ?? 0 );
		$type       = sanitize_key( $params['type'] ?? 'post' );

		// Add rollback action to dispatch
		if ( ! in_array( $action, [ ContentRegistry::ACTION_LIST, ContentRegistry::ACTION_CREATE ], true ) ) {
			if ( $content_id <= 0 && $action !== 'content_rollback' ) {
				return new \WP_Error( 'wpcc_missing_content_id', __( 'content_id is required.', 'wp-command-center' ) );
			}
			if ( $action !== ContentRegistry::ACTION_CREATE && ! get_post( $content_id ) && $action !== 'content_rollback' ) {
				return new \WP_Error( 'wpcc_content_not_found', __( 'Content not found.', 'wp-command-center' ) );
			}
		}

		if ( ! in_array( $type, ContentRegistry::TYPES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_content_type', __( 'Invalid content type. Use post or page.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			ContentRegistry::ACTION_LIST           => $this->list_content( $params ),
			ContentRegistry::ACTION_GET            => $this->get_content( $content_id ),
			ContentRegistry::ACTION_CREATE         => $this->create_content( $params, $context ),
			ContentRegistry::ACTION_UPDATE         => $this->update_content( $content_id, $params, $context ),
			ContentRegistry::ACTION_DELETE         => $this->delete_content( $content_id, $context ),
			ContentRegistry::ACTION_PUBLISH        => $this->publish_content( $content_id, $context ),
			ContentRegistry::ACTION_UNPUBLISH      => $this->unpublish_content( $content_id, $context ),
			ContentRegistry::ACTION_SCHEDULE       => $this->schedule_content( $content_id, $params, $context ),
			ContentRegistry::ACTION_TAXONOMY_ASSIGN=> $this->taxonomy_assign( $content_id, $params, $context ),
			ContentRegistry::ACTION_FEATURED_IMAGE => $this->featured_image_assign( $content_id, $params, $context ),
			'content_rollback'                     => $this->rollback_content( $params, $context ),
			default => new \WP_Error( 'wpcc_invalid_content_action', __( 'Unknown action.', 'wp-command-center' ) ),
		};
	}

	// ── Rollback ──

	private function rollback_content( array $params, array $context ): array|\WP_Error {
		$rollback_id = sanitize_text_field( $params['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return new \WP_Error( 'wpcc_missing_rollback_id', __( 'rollback_id is required.', 'wp-command-center' ) );
		}

		$records = get_option( 'wpcc_content_rollbacks', [] );
		if ( ! isset( $records[ $rollback_id ] ) ) {
			return new \WP_Error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}

		$record = $records[ $rollback_id ];
		if ( ! empty( $record['rollback_applied'] ) ) {
			return new \WP_Error( 'wpcc_rollback_already_applied', __( 'Rollback has already been applied.', 'wp-command-center' ) );
		}

		$before = $record['before_state'];
		$id     = (int) $record['content_id'];

		$updated = wp_update_post( [
			'ID'          => $id,
			'post_title'  => $before['title'] ?? '',
			'post_status' => $before['status'] ?? 'draft',
			'post_content'=> $before['content'] ?? '',
		], true );

		if ( is_wp_error( $updated ) ) {
			return new \WP_Error( 'wpcc_content_rollback_failed', $updated->get_error_message() );
		}

		$records[ $rollback_id ]['rollback_applied'] = true;
		$records[ $rollback_id ]['applied_at']        = time();
		update_option( 'wpcc_content_rollbacks', $records );

		return [
			'action'      => 'content_rollback',
			'content_id'  => $id,
			'restored_to' => $before,
		];
	}

	// ── List ──

	private function list_content( array $params ): array {
		$type     = sanitize_key( $params['type'] ?? 'post' );
		$status   = sanitize_key( $params['status'] ?? 'any' );
		$search   = sanitize_text_field( $params['search'] ?? '' );
		$per_page = min( (int) ( $params['per_page'] ?? 20 ), 100 );
		$page     = max( (int) ( $params['page'] ?? 1 ), 1 );

		$args = [
			'post_type'      => in_array( $type, ContentRegistry::TYPES, true ) ? $type : 'post',
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		];
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );
		$items = [];
		foreach ( $query->posts as $post ) {
			$items[] = $this->post_summary( $post );
		}

		$this->audit( 'content.list', [ 'type' => $type ], [] );

		return [
			'action'     => 'content_list',
			'total'      => (int) $query->found_posts,
			'page'       => $page,
			'per_page'   => $per_page,
			'items'      => $items,
		];
	}

	// ── Get ──

	private function get_content( int $id ): array {
		$post = get_post( $id );
		return [
			'action'   => 'content_get',
			'content_id' => $id,
			'title'    => $post->post_title,
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'status'   => $post->post_status,
			'type'     => $post->post_type,
			'author'   => $post->post_author,
			'modified' => $post->post_modified,
			'permalink'=> get_permalink( $post ),
			'taxonomies' => $this->get_taxonomies( $post ),
			'featured_image' => get_post_thumbnail_id( $post ) ?: null,
		];
	}

	// ── Create ──

	private function create_content( array $params, array $context ): array|\WP_Error {
		$title   = sanitize_text_field( $params['title'] ?? '' );
		$content = wp_kses_post( $params['content'] ?? '' );
		$excerpt = sanitize_textarea_field( $params['excerpt'] ?? '' );
		$status  = sanitize_key( $params['status'] ?? 'draft' );
		$type    = sanitize_key( $params['type'] ?? 'post' );

		if ( '' === $title ) {
			return new \WP_Error( 'wpcc_missing_content_title', __( 'Title is required.', 'wp-command-center' ) );
		}
		if ( ! in_array( $type, ContentRegistry::TYPES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_content_type', __( 'Invalid content type.', 'wp-command-center' ) );
		}

		$id = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_type'    => $type,
		], true );

		if ( is_wp_error( $id ) ) {
			$this->audit( 'content.create.failed', [ 'title' => $title ], $context );
			return $id;
		}

		$this->audit( 'content.create', [ 'content_id' => $id, 'title' => $title, 'type' => $type ], $context );

		return [
			'action'     => 'content_create',
			'content_id' => $id,
			'title'      => $title,
			'status'     => $status,
			'type'       => $type,
			'permalink'  => get_permalink( $id ),
		];
	}

	// ── Update ──

	private function update_content( int $id, array $params, array $context ): array|\WP_Error {
		$post    = get_post( $id );
		$before  = [ 'title' => $post->post_title, 'status' => $post->post_status, 'content' => $post->post_content ];

		$data = [ 'ID' => $id ];
		if ( isset( $params['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( isset( $params['content'] ) ) {
			$data['post_content'] = wp_kses_post( $params['content'] );
		}
		if ( isset( $params['excerpt'] ) ) {
			$data['post_excerpt'] = sanitize_textarea_field( $params['excerpt'] );
		}
		if ( isset( $params['status'] ) ) {
			$data['post_status'] = sanitize_key( $params['status'] );
		}

		$rollback_id = $this->store_rollback( $id, 'update', $before, $context );
		$result      = wp_update_post( $data, true );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'content.update.failed', [ 'content_id' => $id ], $context );
			return $result;
		}

		$updated = get_post( $id );

		$this->audit( 'content.update', [
			'content_id'   => $result,
			'title'        => $updated->post_title,
			'old_status'   => $before['status'],
			'new_status'   => $updated->post_status,
			'rollback_id'  => $rollback_id,
		], $context );

		return [
			'action'      => 'content_update',
			'content_id'  => $result,
			'title'       => $updated->post_title,
			'status'      => $updated->post_status,
			'rollback_id' => $rollback_id,
		];
	}

	// ── Delete (trash only) ──

	private function delete_content( int $id, array $context ): array|\WP_Error {
		$post   = get_post( $id );
		$before = [ 'title' => $post->post_title, 'status' => $post->post_status, 'type' => $post->post_type ];

		$rollback_id = $this->store_rollback( $id, 'delete', $before, $context );

		$result = wp_trash_post( $id );

		if ( ! $result ) {
			$this->audit( 'content.delete.failed', [ 'content_id' => $id ], $context );
			return new \WP_Error( 'wpcc_content_delete_failed', __( 'Failed to trash content.', 'wp-command-center' ) );
		}

		$this->audit( 'content.delete', [
			'content_id'  => $id,
			'title'       => $before['title'],
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'      => 'content_delete',
			'content_id'  => $id,
			'title'       => $before['title'],
			'status'      => 'trash',
			'rollback_id' => $rollback_id,
		];
	}

	// ── Publish ──

	private function publish_content( int $id, array $context ): array|\WP_Error {
		$post    = get_post( $id );
		$old     = $post->post_status;
		$rollback_id = $this->store_rollback( $id, 'publish', [ 'status' => $old ], $context );

		$result = wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit( 'content.publish', [
			'content_id'  => $id,
			'old_status'  => $old,
			'new_status'  => 'publish',
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'      => 'content_publish',
			'content_id'  => $id,
			'old_status'  => $old,
			'new_status'  => 'publish',
			'rollback_id' => $rollback_id,
		];
	}

	// ── Unpublish ──

	private function unpublish_content( int $id, array $context ): array|\WP_Error {
		$post    = get_post( $id );
		$old     = $post->post_status;
		$new     = 'publish' === $old ? 'draft' : $old;
		$rollback_id = $this->store_rollback( $id, 'unpublish', [ 'status' => $old ], $context );

		$result = wp_update_post( [ 'ID' => $id, 'post_status' => $new ], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit( 'content.unpublish', [
			'content_id'  => $id,
			'old_status'  => $old,
			'new_status'  => $new,
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'      => 'content_unpublish',
			'content_id'  => $id,
			'old_status'  => $old,
			'new_status'  => $new,
			'rollback_id' => $rollback_id,
		];
	}

	// ── Schedule ──

	private function schedule_content( int $id, array $params, array $context ): array|\WP_Error {
		$publish_at = sanitize_text_field( $params['publish_at'] ?? '' );
		if ( '' === $publish_at ) {
			return new \WP_Error( 'wpcc_missing_schedule_time', __( 'publish_at is required.', 'wp-command-center' ) );
		}

		$ts = strtotime( $publish_at );
		if ( false === $ts || $ts <= time() ) {
			return new \WP_Error( 'wpcc_invalid_schedule_time', __( 'publish_at must be a future date/time.', 'wp-command-center' ) );
		}

		$post = get_post( $id );
		$old  = $post->post_status;

		$result = wp_update_post( [
			'ID'          => $id,
			'post_status' => 'future',
			'post_date'   => gmdate( 'Y-m-d H:i:s', $ts ),
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit( 'content.schedule', [
			'content_id' => $id,
			'publish_at' => $publish_at,
			'old_status' => $old,
		], $context );

		return [
			'action'     => 'content_schedule',
			'content_id' => $id,
			'publish_at' => $publish_at,
			'old_status' => $old,
			'new_status' => 'future',
		];
	}

	// ── Taxonomy ──

	private function taxonomy_assign( int $id, array $params, array $context ): array|\WP_Error {
		$taxonomy = sanitize_key( $params['taxonomy'] ?? 'category' );
		$terms    = $params['terms'] ?? [];

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return new \WP_Error( 'wpcc_missing_taxonomy_terms', __( 'terms array is required.', 'wp-command-center' ) );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wpcc_invalid_taxonomy', __( 'Taxonomy does not exist.', 'wp-command-center' ) );
		}

		$post = get_post( $id );
		$old_terms = wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'names' ] );

		$result = wp_set_object_terms( $id, $terms, $taxonomy, false );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->audit( 'taxonomy.assign', [
			'content_id'  => $id,
			'taxonomy'    => $taxonomy,
			'old_terms'   => $old_terms,
			'new_terms'   => $terms,
			'title'       => $post->post_title,
		], $context );

		return [
			'action'    => 'taxonomy_assign',
			'content_id'=> $id,
			'taxonomy'  => $taxonomy,
			'assigned'  => $terms,
		];
	}

	// ── Featured Image ──

	private function featured_image_assign( int $id, array $params, array $context ): array|\WP_Error {
		$attachment_id = (int) ( $params['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return new \WP_Error( 'wpcc_missing_attachment_id', __( 'attachment_id is required.', 'wp-command-center' ) );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error( 'wpcc_invalid_attachment', __( 'Attachment not found.', 'wp-command-center' ) );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new \WP_Error( 'wpcc_not_an_image', __( 'Attachment is not an image.', 'wp-command-center' ) );
		}

		$old_thumb = get_post_thumbnail_id( $id );
		set_post_thumbnail( $id, $attachment_id );

		$post = get_post( $id );

		$this->audit( 'featured_image.assign', [
			'content_id'    => $id,
			'attachment_id' => $attachment_id,
			'old_thumb'     => $old_thumb ?: null,
			'title'         => $post->post_title,
		], $context );

		return [
			'action'        => 'featured_image_assign',
			'content_id'    => $id,
			'attachment_id' => $attachment_id,
		];
	}

	// ── Helpers ──

	private function post_summary( \WP_Post $post ): array {
		return [
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'author'    => $post->post_author,
			'modified'  => $post->post_modified,
			'permalink' => get_permalink( $post ),
		];
	}

	private function get_taxonomies( \WP_Post $post ): array {
		$result = [];
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxonomies as $tax ) {
			if ( $tax->public ) {
				$terms = wp_get_post_terms( $post->ID, $tax->name, [ 'fields' => 'id=>name' ] );
				$result[ $tax->name ] = array_values( $terms );
			}
		}
		return $result;
	}

	private function store_rollback( int $id, string $action, array $before, array $context ): string {
		$rid           = wp_generate_uuid4();
		$records       = get_option( 'wpcc_content_rollbacks', [] );
		$records[ $rid ] = [
			'id'               => $rid,
			'content_id'       => $id,
			'action'           => $action,
			'before_state'     => $before,
			'rollback_applied' => false,
			'created_at'       => time(),
			'session_id'       => $context['session_id'] ?? '',
			'task_id'          => $context['task_id'] ?? '',
		];
		update_option( 'wpcc_content_rollbacks', $records );
		return $rid;
	}

	private function audit( string $event, array $data, array $context = [] ): void {
		$audit  = new AuditLog();
		$actor  = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$action = explode( '.', $event )[1] ?? '';
		$full   = 'content_' . $action;
		if ( in_array( $full, ContentRegistry::ACTIONS, true ) ) {
			$risk = $this->registry->action_risk( $full );
		} else {
			$risk = ContentRegistry::RISK_MEDIUM;
		}
		$audit->record( $event, array_merge( [ 'risk_level' => $risk, 'actor' => $actor ], $data ) );
	}

	public function get_registry(): ContentRegistry {
		return $this->registry;
	}
}
