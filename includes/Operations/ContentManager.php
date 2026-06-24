<?php
/**
 * Step 42 — Content Management Runtime.
 * 10 operations: list, get, create, update, delete, publish,
 * unpublish, schedule, taxonomy_assign, featured_image_assign.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Rollback\RollbackDelta;
use WPCommandCenter\Rollback\ContentFieldAccessor;
use WPCommandCenter\Rollback\OptionKeyedRollbackStore;

defined( 'ABSPATH' ) || exit;

final class ContentManager {

	/** PROGRAM-4 / P4.3 — unified content fields for field-scoped rollback. */
	private const CONTENT_FIELDS = [ 'title', 'status', 'content', 'excerpt' ];

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

		// PROGRAM-4B — resolve via the shared keyed RollbackStore (consistent storage API).
		$store    = new OptionKeyedRollbackStore( 'wpcc_content_rollbacks' );
		$resolved = $store->resolve( $rollback_id );
		if ( null === $resolved ) {
			return new \WP_Error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}
		$record = $resolved['record'];
		if ( ! empty( $record['rollback_applied'] ) ) {
			return new \WP_Error( 'wpcc_rollback_already_applied', __( 'Rollback has already been applied.', 'wp-command-center' ) );
		}

		// PROGRAM-4 / P4.3 — field-scoped, drift-aware delta restore for v2 update records.
		// Only complete is terminal; partial/conflict stay retryable and report truthfully.
		if ( isset( $record['fields'] ) && is_array( $record['fields'] ) ) {
			$id = (int) $record['content_id'];
			$o  = RollbackDelta::restore( new ContentFieldAccessor(), $id, $record['fields'] );
			if ( 'complete' === $o['status'] ) {
				$record['rollback_applied'] = true;
				$record['applied_at']       = time();
				$store->mark_applied( $id, $rollback_id, $record );
			}
			$this->audit( 'content.rollback', [ 'content_id' => $id, 'status' => $o['status'], 'restored_fields' => $o['restored'], 'skipped_fields' => $o['skipped'] ], $context );
			return RollbackDelta::result( [ 'action' => 'content_rollback', 'content_id' => $id ], $o );
		}

		// Legacy full-object update records + action-based delete records — unchanged.
		$before = $record['before_state'];
		$id     = (int) $record['content_id'];

		$restore = [
			'ID'          => $id,
			'post_title'  => $before['title'] ?? '',
			'post_status' => $before['status'] ?? 'draft',
			'post_content'=> $before['content'] ?? '',
		];
		// Only restore the excerpt when the snapshot actually captured it. Records
		// written before the excerpt snapshot (and non-update reversals that never
		// carry an excerpt) are left untouched — never wipe an excerpt we did not save.
		if ( array_key_exists( 'excerpt', $before ) ) {
			$restore['post_excerpt'] = $before['excerpt'];
		}

		$updated = wp_update_post( $restore, true );

		if ( is_wp_error( $updated ) ) {
			return new \WP_Error( 'wpcc_content_rollback_failed', $updated->get_error_message() );
		}

		$record['rollback_applied'] = true;
		$record['applied_at']       = time();
		$store->mark_applied( $id, $rollback_id, $record );

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
		// PROGRAM-4 / P4.3 — capture the prior state of ONLY the fields this call touches,
		// BEFORE the write (field-scoped, drift-aware delta via the RollbackDelta core).
		// Replaces the full-object {title,status,content,excerpt} snapshot.
		$accessor = new ContentFieldAccessor();
		$touched  = [];
		foreach ( self::CONTENT_FIELDS as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$touched[] = $field;
			}
		}
		$prior = RollbackDelta::capture( $accessor, $id, $touched );
		// P4 RC (D2): capture the prior status for the audit's old_status. The P4.3 delta
		// migration removed the full-object $before snapshot but left the audit referencing
		// $before['status'] (undefined → warning + null). This restores the intended value.
		$old_status = (string) get_post_field( 'post_status', $id );

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

		$result = wp_update_post( $data, true );

		if ( is_wp_error( $result ) ) {
			$this->audit( 'content.update.failed', [ 'content_id' => $id ], $context );
			return $result;
		}

		// Post-write after-values for drift detection, then persist the field-scoped delta.
		$after = [];
		foreach ( $touched as $field ) {
			$after[ $field ] = $accessor->read_field( $id, $field );
		}
		$rollback_id = $this->store_content_delta( $id, $touched, $prior, $after, $context );

		$updated = get_post( $id );

		$this->audit( 'content.update', [
			'content_id'   => $result,
			'title'        => $updated->post_title,
			'old_status'   => $old_status,
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

	/**
	 * PROGRAM-4 / P4.3 — persist one field-scoped v2 content delta record (touched
	 * fields only, each with post-write `after` + prior existence/value) in the existing
	 * wpcc_content_rollbacks option. Legacy full-object before_state records remain readable.
	 */
	private function store_content_delta( int $id, array $touched, array $prior, array $after, array $context ): string {
		$rid    = wp_generate_uuid4();
		$record = RollbackDelta::build_record( $touched, $prior, $after, $context, [ 'id' => $rid, 'content_id' => $id, 'action' => 'update' ] );
		( new OptionKeyedRollbackStore( 'wpcc_content_rollbacks' ) )->persist( $id, $rid, $record );
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
