<?php
/**
 * STEP 95 — Site Builder Runtime.
 *
 * Lets AI agents construct WordPress sites over REST + MCP: pages (with parent,
 * order, status, template), page templates, block patterns (reusable blocks),
 * block-theme navigation, and menus (delegated to the existing menu_manage
 * runtime — not duplicated). Writes are rollback-capable and audited.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class SiteBuilderRuntimeManager {

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $payload, array $context = [] ): array {
		$action = (string) ( $payload['action'] ?? '' );
		if ( ! in_array( $action, SiteBuilderRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_site_builder_action', __( 'Invalid site builder action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			SiteBuilderRegistry::ACTION_PAGE_LIST       => $this->page_list( $payload ),
			SiteBuilderRegistry::ACTION_PAGE_GET        => $this->page_get( $payload ),
			SiteBuilderRegistry::ACTION_PAGE_CREATE     => $this->page_create( $payload, $context ),
			SiteBuilderRegistry::ACTION_PAGE_UPDATE     => $this->page_update( $payload, $context ),
			SiteBuilderRegistry::ACTION_PAGE_DELETE     => $this->page_delete( $payload, $context ),
			SiteBuilderRegistry::ACTION_TEMPLATE_LIST   => $this->template_list(),
			SiteBuilderRegistry::ACTION_TEMPLATE_ASSIGN => $this->template_assign( $payload, $context ),
			SiteBuilderRegistry::ACTION_PATTERN_CREATE  => $this->pattern_create( $payload, $context ),
			SiteBuilderRegistry::ACTION_PATTERN_LIST    => $this->pattern_list( $payload ),
			SiteBuilderRegistry::ACTION_NAVIGATION_MANAGE => $this->navigation_manage( $payload, $context ),
			SiteBuilderRegistry::ACTION_MENU_CREATE     => $this->delegate_menu( 'menu_create', $payload, $context ),
			SiteBuilderRegistry::ACTION_MENU_UPDATE     => $this->delegate_menu( 'menu_update', $payload, $context ),
			SiteBuilderRegistry::ACTION_MENU_ASSIGN     => $this->delegate_menu( 'menu_location_assign', $payload, $context ),
			default => $this->error( 'wpcc_invalid_site_builder_action', __( 'Invalid site builder action.', 'wp-command-center' ) ),
		};
	}

	// ── Pages ────────────────────────────────────────────────────

	private function page_list( array $p ): array {
		$q = new \WP_Query( [
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => min( 100, max( 1, (int) ( $p['per_page'] ?? 20 ) ) ),
			'paged'          => max( 1, (int) ( $p['page'] ?? 1 ) ),
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		] );
		$items = array_map( [ $this, 'format_page' ], $q->posts );
		return [ 'action' => 'page_list', 'pages' => $items, 'total' => (int) $q->found_posts ];
	}

	private function page_get( array $p ): array {
		$page = $this->get_page( $p );
		if ( is_string( $page ) ) return $this->error( $page, __( 'Page not found.', 'wp-command-center' ) );
		return [ 'action' => 'page_get', 'page' => $this->format_page( $page ) ];
	}

	private function page_create( array $p, array $cx ): array {
		$title = sanitize_text_field( (string) ( $p['title'] ?? '' ) );
		if ( '' === $title ) return $this->error( 'wpcc_missing_title', __( 'Page title is required.', 'wp-command-center' ) );

		$postarr = [
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_content' => isset( $p['content'] ) ? wp_kses_post( (string) $p['content'] ) : '',
			'post_status'  => $this->valid_status( $p['status'] ?? 'draft' ),
			'post_parent'  => (int) ( $p['parent'] ?? 0 ),
			'menu_order'   => (int) ( $p['menu_order'] ?? 0 ),
		];
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) return $this->error( 'wpcc_page_create_failed', $id->get_error_message() );

		if ( ! empty( $p['template'] ) ) {
			update_post_meta( $id, '_wp_page_template', sanitize_text_field( (string) $p['template'] ) );
		}

		$rollback_id = $this->store_rollback( $id, 'page_create', [], $cx );
		$this->audit->record( 'sitebuilder.page.created', [ 'page_id' => $id, 'title' => $title ] );
		return [ 'action' => 'page_create', 'page_id' => $id, 'title' => $title, 'permalink' => get_permalink( $id ), 'rollback_id' => $rollback_id ];
	}

	private function page_update( array $p, array $cx ): array {
		$page = $this->get_page( $p );
		if ( is_string( $page ) ) return $this->error( $page, __( 'Page not found.', 'wp-command-center' ) );

		$before = [
			'post_title'   => $page->post_title,
			'post_content' => $page->post_content,
			'post_status'  => $page->post_status,
			'post_parent'  => $page->post_parent,
			'menu_order'   => $page->menu_order,
			'template'     => get_post_meta( $page->ID, '_wp_page_template', true ),
		];

		$postarr = [ 'ID' => $page->ID ];
		if ( isset( $p['title'] ) )      $postarr['post_title']   = sanitize_text_field( (string) $p['title'] );
		if ( isset( $p['content'] ) )    $postarr['post_content'] = wp_kses_post( (string) $p['content'] );
		if ( isset( $p['status'] ) )     $postarr['post_status']  = $this->valid_status( $p['status'] );
		if ( isset( $p['parent'] ) )     $postarr['post_parent']  = (int) $p['parent'];
		if ( isset( $p['menu_order'] ) ) $postarr['menu_order']   = (int) $p['menu_order'];
		$res = wp_update_post( $postarr, true );
		if ( is_wp_error( $res ) ) return $this->error( 'wpcc_page_update_failed', $res->get_error_message() );

		if ( isset( $p['template'] ) ) {
			update_post_meta( $page->ID, '_wp_page_template', sanitize_text_field( (string) $p['template'] ) );
		}

		$rollback_id = $this->store_rollback( $page->ID, 'page_update', $before, $cx );
		$this->audit->record( 'sitebuilder.page.updated', [ 'page_id' => $page->ID ] );
		return [ 'action' => 'page_update', 'page_id' => $page->ID, 'permalink' => get_permalink( $page->ID ), 'rollback_id' => $rollback_id ];
	}

	private function page_delete( array $p, array $cx ): array {
		$page = $this->get_page( $p );
		if ( is_string( $page ) ) return $this->error( $page, __( 'Page not found.', 'wp-command-center' ) );
		$force  = ! empty( $p['force'] );
		$before = [ 'post_status' => $page->post_status ];
		$rollback_id = $force ? '' : $this->store_rollback( $page->ID, 'page_delete', $before, $cx );
		wp_delete_post( $page->ID, $force );
		$this->audit->record( 'sitebuilder.page.deleted', [ 'page_id' => $page->ID, 'force' => $force ] );
		return [ 'action' => 'page_delete', 'page_id' => $page->ID, 'force' => $force, 'rollback_id' => $rollback_id ];
	}

	// ── Templates ────────────────────────────────────────────────

	private function template_list(): array {
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		return [ 'action' => 'template_list', 'templates' => array_merge( [ 'default' => __( 'Default Template', 'wp-command-center' ) ], $templates ) ];
	}

	private function template_assign( array $p, array $cx ): array {
		$page = $this->get_page( $p );
		if ( is_string( $page ) ) return $this->error( $page, __( 'Page not found.', 'wp-command-center' ) );
		$template = sanitize_text_field( (string) ( $p['template'] ?? '' ) );
		if ( '' === $template ) return $this->error( 'wpcc_missing_template', __( 'A template is required.', 'wp-command-center' ) );

		$available = array_merge( [ 'default' ], array_keys( wp_get_theme()->get_page_templates( null, 'page' ) ) );
		if ( ! in_array( $template, $available, true ) ) {
			return $this->error( 'wpcc_invalid_template', sprintf( __( 'Template not available in the active theme: %s', 'wp-command-center' ), esc_html( $template ) ) );
		}

		$before = [ 'template' => get_post_meta( $page->ID, '_wp_page_template', true ) ];
		if ( 'default' === $template ) {
			delete_post_meta( $page->ID, '_wp_page_template' );
		} else {
			update_post_meta( $page->ID, '_wp_page_template', $template );
		}

		$rollback_id = $this->store_rollback( $page->ID, 'template_assign', $before, $cx );
		$this->audit->record( 'sitebuilder.template.assigned', [ 'page_id' => $page->ID, 'template' => $template ] );
		return [ 'action' => 'template_assign', 'page_id' => $page->ID, 'template' => $template, 'rollback_id' => $rollback_id ];
	}

	// ── Patterns (reusable blocks) ───────────────────────────────

	private function pattern_create( array $p, array $cx ): array {
		$title = sanitize_text_field( (string) ( $p['title'] ?? '' ) );
		if ( '' === $title ) return $this->error( 'wpcc_missing_title', __( 'Pattern title is required.', 'wp-command-center' ) );
		$content = isset( $p['content'] ) ? (string) $p['content'] : '';

		$id = wp_insert_post( [
			'post_type'    => 'wp_block',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
		], true );
		if ( is_wp_error( $id ) ) return $this->error( 'wpcc_pattern_create_failed', $id->get_error_message() );

		$this->audit->record( 'sitebuilder.pattern.created', [ 'pattern_id' => $id, 'title' => $title ] );
		return [ 'action' => 'pattern_create', 'pattern_id' => $id, 'title' => $title ];
	}

	private function pattern_list( array $p ): array {
		$q = new \WP_Query( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'posts_per_page' => 50 ] );
		$items = array_map( static fn( $post ) => [ 'id' => $post->ID, 'title' => $post->post_title ], $q->posts );
		return [ 'action' => 'pattern_list', 'patterns' => $items, 'total' => count( $items ) ];
	}

	// ── Block-theme navigation (wp_navigation) ───────────────────

	private function navigation_manage( array $p, array $cx ): array {
		$op = sanitize_key( (string) ( $p['op'] ?? 'create' ) );

		if ( 'get' === $op ) {
			$nav = get_post( (int) ( $p['navigation_id'] ?? 0 ) );
			if ( ! $nav || 'wp_navigation' !== $nav->post_type ) return $this->error( 'wpcc_navigation_not_found', __( 'Navigation not found.', 'wp-command-center' ) );
			return [ 'action' => 'navigation_manage', 'op' => 'get', 'navigation' => [ 'id' => $nav->ID, 'title' => $nav->post_title, 'content' => $nav->post_content ] ];
		}

		if ( 'update' === $op ) {
			$nav = get_post( (int) ( $p['navigation_id'] ?? 0 ) );
			if ( ! $nav || 'wp_navigation' !== $nav->post_type ) return $this->error( 'wpcc_navigation_not_found', __( 'Navigation not found.', 'wp-command-center' ) );
			$before  = [ 'post_title' => $nav->post_title, 'post_content' => $nav->post_content ];
			$arr     = [ 'ID' => $nav->ID ];
			if ( isset( $p['title'] ) )   $arr['post_title']   = sanitize_text_field( (string) $p['title'] );
			if ( isset( $p['content'] ) ) $arr['post_content'] = (string) $p['content'];
			wp_update_post( $arr );
			$rollback_id = $this->store_rollback( $nav->ID, 'navigation_manage', $before, $cx );
			$this->audit->record( 'sitebuilder.navigation.updated', [ 'navigation_id' => $nav->ID ] );
			return [ 'action' => 'navigation_manage', 'op' => 'update', 'navigation_id' => $nav->ID, 'rollback_id' => $rollback_id ];
		}

		// create
		$title = sanitize_text_field( (string) ( $p['title'] ?? 'Navigation' ) );
		$id = wp_insert_post( [
			'post_type'    => 'wp_navigation',
			'post_title'   => $title,
			'post_content' => isset( $p['content'] ) ? (string) $p['content'] : '',
			'post_status'  => 'publish',
		], true );
		if ( is_wp_error( $id ) ) return $this->error( 'wpcc_navigation_create_failed', $id->get_error_message() );
		$rollback_id = $this->store_rollback( $id, 'navigation_manage', [ 'created' => true ], $cx );
		$this->audit->record( 'sitebuilder.navigation.created', [ 'navigation_id' => $id ] );
		return [ 'action' => 'navigation_manage', 'op' => 'create', 'navigation_id' => $id, 'title' => $title, 'rollback_id' => $rollback_id ];
	}

	// ── Menus (delegated to menu_manage — no duplication) ────────

	private function delegate_menu( string $menu_action, array $payload, array $context ): array {
		$payload['action'] = $menu_action;
		$result = ( new MenuRuntimeManager() )->run( $payload, $context );
		if ( is_array( $result ) && isset( $result['action'] ) ) {
			$result['delegated_from'] = 'site_builder_manage';
		}
		return $result;
	}

	// ── Rollback ─────────────────────────────────────────────────

	public function rollback( array $payload, array $context = [] ): array {
		$rid = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rid ) return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID required.', 'wp-command-center' ) );
		$rollbacks = get_option( 'wpcc_sitebuilder_rollbacks', [] );
		$idx = null;
		foreach ( $rollbacks as $i => $r ) { if ( $r['id'] === $rid ) { $idx = $i; break; } }
		if ( null === $idx ) return $this->error( 'wpcc_rollback_not_found', __( 'Rollback not found.', 'wp-command-center' ) );
		if ( ! empty( $rollbacks[ $idx ]['rollback_applied'] ) ) return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'wp-command-center' ) );

		$rec = $rollbacks[ $idx ];
		$eid = (int) $rec['entity_id'];
		$act = $rec['action'];
		$b   = $rec['before_state'];

		switch ( $act ) {
			case 'page_create':
				wp_delete_post( $eid, true );
				break;
			case 'page_delete':
				wp_untrash_post( $eid );
				break;
			case 'page_update':
				wp_update_post( [ 'ID' => $eid, 'post_title' => $b['post_title'] ?? '', 'post_content' => $b['post_content'] ?? '', 'post_status' => $b['post_status'] ?? 'draft', 'post_parent' => $b['post_parent'] ?? 0, 'menu_order' => $b['menu_order'] ?? 0 ] );
				if ( ! empty( $b['template'] ) ) update_post_meta( $eid, '_wp_page_template', $b['template'] );
				else delete_post_meta( $eid, '_wp_page_template' );
				break;
			case 'template_assign':
				if ( ! empty( $b['template'] ) ) update_post_meta( $eid, '_wp_page_template', $b['template'] );
				else delete_post_meta( $eid, '_wp_page_template' );
				break;
			case 'navigation_manage':
				if ( ! empty( $b['created'] ) ) { wp_delete_post( $eid, true ); }
				else { wp_update_post( [ 'ID' => $eid, 'post_title' => $b['post_title'] ?? '', 'post_content' => $b['post_content'] ?? '' ] ); }
				break;
		}

		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_sitebuilder_rollbacks', $rollbacks );
		$this->audit->record( 'sitebuilder.rollback', [ 'rollback_id' => $rid, 'action' => $act ] );
		return [ 'action' => 'site_builder_rollback', 'rollback_id' => $rid, 'restored' => true ];
	}

	// ── Helpers ──────────────────────────────────────────────────

	/** @return \WP_Post|string */
	private function get_page( array $p ) {
		$id = (int) ( $p['page_id'] ?? $p['content_id'] ?? 0 );
		if ( $id <= 0 ) return 'wpcc_missing_page_id';
		$post = get_post( $id );
		if ( ! $post || 'page' !== $post->post_type ) return 'wpcc_page_not_found';
		return $post;
	}

	private function valid_status( $status ): string {
		$status = sanitize_key( (string) $status );
		return in_array( $status, [ 'publish', 'draft', 'pending', 'private', 'future' ], true ) ? $status : 'draft';
	}

	private function format_page( \WP_Post $post ): array {
		return [
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'parent'     => $post->post_parent,
			'menu_order' => $post->menu_order,
			'template'   => get_post_meta( $post->ID, '_wp_page_template', true ) ?: 'default',
			'permalink'  => get_permalink( $post->ID ),
		];
	}

	private function store_rollback( int $id, string $action, array $before, array $context ): string {
		if ( ! SiteBuilderRegistry::supports_rollback( $action ) ) return '';
		$rollbacks = get_option( 'wpcc_sitebuilder_rollbacks', [] );
		$rid = wp_generate_uuid4();
		$rollbacks[] = [ 'id' => $rid, 'entity_id' => $id, 'action' => $action, 'before_state' => $before, 'rollback_applied' => false, 'created_at' => time(),
			'session_id' => $context['session_id'] ?? null, 'task_id' => $context['task_id'] ?? null ];
		if ( count( $rollbacks ) > 100 ) $rollbacks = array_slice( $rollbacks, -100 );
		update_option( 'wpcc_sitebuilder_rollbacks', $rollbacks );
		return $rid;
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
