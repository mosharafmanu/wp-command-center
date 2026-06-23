<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Rollback\RollbackDelta;
use WPCommandCenter\Rollback\CommentFieldAccessor;
use WPCommandCenter\Rollback\OptionListRollbackStore;

defined( 'ABSPATH' ) || exit;

final class CommentsRuntimeManager {

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $payload, array $context = [] ): array {
		$action = (string) ( $payload['action'] ?? '' );
		if ( ! in_array( $action, CommentsRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_comment_action', __( 'Invalid comment action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			CommentsRegistry::ACTION_LIST      => $this->list_comments( $payload ),
			CommentsRegistry::ACTION_GET       => $this->get_comment( $payload ),
			CommentsRegistry::ACTION_APPROVE   => $this->approve_comment( $payload, $context ),
			CommentsRegistry::ACTION_UNAPPROVE => $this->unapprove_comment( $payload, $context ),
			CommentsRegistry::ACTION_SPAM      => $this->spam_comment( $payload, $context ),
			CommentsRegistry::ACTION_TRASH     => $this->trash_comment( $payload, $context ),
			CommentsRegistry::ACTION_DELETE    => $this->delete_comment( $payload, $context ),
			CommentsRegistry::ACTION_REPLY     => $this->reply_comment( $payload, $context ),
			default => $this->error( 'wpcc_unknown_comment_action', __( 'Unknown comment action.', 'wp-command-center' ) ),
		};
	}

	private function list_comments( array $payload ): array {
		$per_page   = min( 100, max( 1, (int) ( $payload['per_page'] ?? 20 ) ) );
		$page       = max( 1, (int) ( $payload['page'] ?? 1 ) );
		$post_id    = (int) ( $payload['post_id'] ?? 0 );
		$status     = sanitize_text_field( (string) ( $payload['status'] ?? 'all' ) );
		$search     = sanitize_text_field( (string) ( $payload['search'] ?? '' ) );

		$args = [
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		];

		if ( $post_id > 0 ) {
			$args['post_id'] = $post_id;
		}
		if ( 'all' !== $status ) {
			$args['status'] = $status;
		}
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$comments = get_comments( $args );
		$total    = get_comments( array_merge( $args, [ 'number' => 0, 'offset' => 0, 'count' => true ] ) );

		$items = array_map( [ $this, 'format_comment' ], $comments );

		$this->audit->record( 'comment.list', [ 'count' => count( $items ), 'total' => $total ] );

		return [ 'action' => 'comment_list', 'items' => $items, 'total' => (int) $total, 'page' => $page, 'per_page' => $per_page ];
	}

	private function get_comment( array $payload ): array {
		$comment_id = (int) ( $payload['comment_id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment ) {
			return $this->error( 'wpcc_comment_not_found', __( 'Comment not found.', 'wp-command-center' ) );
		}

		$this->audit->record( 'comment.get', [ 'comment_id' => $comment_id ] );

		return [ 'action' => 'comment_get', 'comment' => $this->format_comment( $comment ) ];
	}

	private function approve_comment( array $payload, array $context ): array {
		$comment_id = (int) ( $payload['comment_id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment ) {
			return $this->error( 'wpcc_comment_not_found', __( 'Comment not found.', 'wp-command-center' ) );
		}

		// PROGRAM-4 / P4.4 — capture the prior moderation status BEFORE the change so it is
		// reversible via the field-scoped, drift-aware delta (previously not reversible).
		$accessor = new CommentFieldAccessor();
		$prior    = RollbackDelta::capture( $accessor, $comment_id, [ 'status' ] );

		$result = wp_set_comment_status( $comment_id, 'approve' );
		if ( ! $result ) {
			return $this->error( 'wpcc_comment_approve_failed', __( 'Failed to approve comment.', 'wp-command-center' ) );
		}

		$rollback_id = $this->store_status_delta( $comment_id, $prior, [ 'status' => $accessor->read_field( $comment_id, 'status' ) ], $context );

		$this->audit->record( 'comment.approved', [ 'comment_id' => $comment_id ] );

		return [ 'action' => 'comment_approve', 'comment_id' => $comment_id, 'status' => 'approved', 'rollback_id' => $rollback_id ];
	}

	private function unapprove_comment( array $payload, array $context ): array {
		$comment_id = (int) ( $payload['comment_id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment ) {
			return $this->error( 'wpcc_comment_not_found', __( 'Comment not found.', 'wp-command-center' ) );
		}

		$accessor = new CommentFieldAccessor();
		$prior    = RollbackDelta::capture( $accessor, $comment_id, [ 'status' ] );

		$result = wp_set_comment_status( $comment_id, 'hold' );
		if ( ! $result ) {
			return $this->error( 'wpcc_comment_unapprove_failed', __( 'Failed to unapprove comment.', 'wp-command-center' ) );
		}

		$rollback_id = $this->store_status_delta( $comment_id, $prior, [ 'status' => $accessor->read_field( $comment_id, 'status' ) ], $context );

		$this->audit->record( 'comment.unapproved', [ 'comment_id' => $comment_id ] );

		return [ 'action' => 'comment_unapprove', 'comment_id' => $comment_id, 'status' => 'hold', 'rollback_id' => $rollback_id ];
	}

	private function spam_comment( array $payload, array $context ): array {
		$comment_id = (int) ( $payload['comment_id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment ) {
			return $this->error( 'wpcc_comment_not_found', __( 'Comment not found.', 'wp-command-center' ) );
		}

		$accessor = new CommentFieldAccessor();
		$prior    = RollbackDelta::capture( $accessor, $comment_id, [ 'status' ] );

		$result = wp_spam_comment( $comment_id );
		if ( ! $result ) {
			return $this->error( 'wpcc_comment_spam_failed', __( 'Failed to mark comment as spam.', 'wp-command-center' ) );
		}

		$rollback_id = $this->store_status_delta( $comment_id, $prior, [ 'status' => $accessor->read_field( $comment_id, 'status' ) ], $context );

		$this->audit->record( 'comment.spammed', [ 'comment_id' => $comment_id ] );

		return [ 'action' => 'comment_spam', 'comment_id' => $comment_id, 'status' => 'spam', 'rollback_id' => $rollback_id ];
	}

	private function trash_comment( array $payload, array $context ): array {
		$comment_id = (int) ( $payload['comment_id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment ) {
			return $this->error( 'wpcc_comment_not_found', __( 'Comment not found.', 'wp-command-center' ) );
		}

		$before = $this->format_comment( $comment );
		$this->store_rollback( $comment_id, 'trash', $before, $context );

		$result = wp_trash_comment( $comment_id );
		if ( ! $result ) {
			return $this->error( 'wpcc_comment_trash_failed', __( 'Failed to trash comment.', 'wp-command-center' ) );
		}

		$this->audit->record( 'comment.trashed', [ 'comment_id' => $comment_id ] );

		return [ 'action' => 'comment_trash', 'comment_id' => $comment_id, 'status' => 'trash' ];
	}

	private function delete_comment( array $payload, array $context ): array {
		$comment_id = (int) ( $payload['comment_id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment ) {
			return $this->error( 'wpcc_comment_not_found', __( 'Comment not found.', 'wp-command-center' ) );
		}

		$before = $this->format_comment( $comment );
		$this->store_rollback( $comment_id, 'delete', $before, $context );

		$result = wp_delete_comment( $comment_id, true );
		if ( ! $result ) {
			return $this->error( 'wpcc_comment_delete_failed', __( 'Failed to delete comment.', 'wp-command-center' ) );
		}

		$this->audit->record( 'comment.deleted', [ 'comment_id' => $comment_id ] );

		return [ 'action' => 'comment_delete', 'comment_id' => $comment_id ];
	}

	private function reply_comment( array $payload, array $context ): array {
		$comment_id   = (int) ( $payload['comment_id'] ?? 0 );
		$parent_comment = get_comment( $comment_id );

		if ( ! $parent_comment ) {
			return $this->error( 'wpcc_comment_not_found', __( 'Parent comment not found.', 'wp-command-center' ) );
		}

		$content = sanitize_textarea_field( (string) ( $payload['content'] ?? '' ) );
		if ( '' === $content ) {
			return $this->error( 'wpcc_missing_reply_content', __( 'Reply content is required.', 'wp-command-center' ) );
		}

		$user_id  = (int) ( $payload['user_id'] ?? 0 );
		$author   = sanitize_text_field( (string) ( $payload['author'] ?? '' ) );
		$email    = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$url      = esc_url_raw( (string) ( $payload['url'] ?? '' ) );
		$user     = $user_id ? get_userdata( $user_id ) : false;

		$reply_data = [
			'comment_post_ID'      => $parent_comment->comment_post_ID,
			'comment_parent'       => $comment_id,
			'comment_content'      => $content,
			'comment_approved'     => 1,
			'comment_author'       => '' !== $author ? $author : ( $user ? $user->display_name : 'API' ),
			'comment_author_email' => '' !== $email ? $email : ( $user ? $user->user_email : 'api@example.com' ),
			'comment_author_url'   => $url,
			'user_id'              => $user_id,
		];

		$reply_id = wp_insert_comment( $reply_data );
		if ( ! $reply_id || is_wp_error( $reply_id ) ) {
			return $this->error( 'wpcc_comment_reply_failed', is_wp_error( $reply_id ) ? $reply_id->get_error_message() : __( 'Failed to create reply.', 'wp-command-center' ) );
		}

		$this->audit->record( 'comment.replied', [ 'comment_id' => $comment_id, 'reply_id' => $reply_id ] );

		return [ 'action' => 'comment_reply', 'comment_id' => $comment_id, 'reply_id' => $reply_id ];
	}

	public function rollback( array $payload, array $context = [] ): array {
		$rollback_id = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID is required.', 'wp-command-center' ) );
		}

		$rollbacks = get_option( 'wpcc_comments_rollbacks', [] );
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

		$comment_id = $record['comment_id'];
		$action     = $record['action'];

		// PROGRAM-4 / P4.4 — field-scoped, drift-aware status restore (v2 records).
		// Only complete is terminal; a drifted status reports conflict instead of clobbering.
		if ( isset( $record['fields'] ) && is_array( $record['fields'] ) ) {
			$o = RollbackDelta::restore( new CommentFieldAccessor(), $comment_id, $record['fields'] );
			if ( 'complete' === $o['status'] ) {
				$rollbacks[ $idx ]['rollback_applied'] = true;
				update_option( 'wpcc_comments_rollbacks', $rollbacks );
			}
			$this->audit->record( 'comment.rollback.applied', [ 'rollback_id' => $rollback_id, 'comment_id' => $comment_id, 'action' => 'status', 'status' => $o['status'], 'restored_fields' => $o['restored'], 'skipped_fields' => $o['skipped'] ] );
			return RollbackDelta::result( [ 'action' => 'comment_rollback', 'rollback_id' => $rollback_id, 'comment_id' => $comment_id ], $o );
		}

		switch ( $action ) {
			case 'trash':
				wp_untrash_comment( $comment_id );
				break;
			case 'delete':
				return $this->error( 'wpcc_rollback_unsupported', __( 'This comment was permanently deleted and cannot be restored.', 'wp-command-center' ) );
		}

		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_comments_rollbacks', $rollbacks );

		$this->audit->record( 'comment.rollback.applied', [ 'rollback_id' => $rollback_id, 'comment_id' => $comment_id, 'action' => $action ] );

		return [ 'action' => 'comment_rollback', 'rollback_id' => $rollback_id, 'comment_id' => $comment_id ];
	}

	private function store_rollback( int $comment_id, string $action, array $before, array $context ): void {
		if ( ! CommentsRegistry::supports_rollback( $action ) ) {
			return;
		}

		$rollbacks   = get_option( 'wpcc_comments_rollbacks', [] );
		$rollback_id = wp_generate_uuid4();

		$rollbacks[] = [
			'id'               => $rollback_id,
			'comment_id'       => $comment_id,
			'action'           => $action,
			'before_state'     => $before,
			'rollback_applied' => false,
			'created_at'       => time(),
			'session_id'       => $context['session_id'] ?? null,
			'task_id'          => $context['task_id'] ?? null,
		];

		if ( count( $rollbacks ) > 100 ) {
			$rollbacks = array_slice( $rollbacks, -100 );
		}

		update_option( 'wpcc_comments_rollbacks', $rollbacks );
	}

	/**
	 * PROGRAM-4 / P4.4 — persist one field-scoped v2 status delta record (the touched
	 * `status` field with post-change `after` + prior value) in the existing
	 * wpcc_comments_rollbacks option. Makes approve/unapprove/spam reversible.
	 */
	private function store_status_delta( int $comment_id, array $prior, array $after, array $context ): string {
		$rollback_id = wp_generate_uuid4();
		$record      = RollbackDelta::build_record( [ 'status' ], $prior, $after, $context, [ 'id' => $rollback_id, 'comment_id' => $comment_id, 'action' => 'status' ] );
		( new OptionListRollbackStore( 'wpcc_comments_rollbacks', 100 ) )->persist( $comment_id, $rollback_id, $record );
		return $rollback_id;
	}

	private function format_comment( $comment ): array {
		if ( is_object( $comment ) ) {
			return [
				'id'              => (int) $comment->comment_ID,
				'post_id'         => (int) $comment->comment_post_ID,
				'author'          => $comment->comment_author,
				'author_email'    => $comment->comment_author_email,
				'author_url'      => $comment->comment_author_url,
				'author_ip'       => $comment->comment_author_IP,
				'content'         => $comment->comment_content,
				'status'          => wp_get_comment_status( $comment->comment_ID ) === 'approved' ? 'approved' : 'hold',
				'status_raw'      => $comment->comment_approved,
				'parent'          => (int) $comment->comment_parent,
				'user_id'         => (int) $comment->user_id,
				'type'            => $comment->comment_type,
				'date'            => $comment->comment_date,
				'date_gmt'        => $comment->comment_date_gmt,
				'agent'           => $comment->comment_agent,
			];
		}
		return (array) $comment;
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
