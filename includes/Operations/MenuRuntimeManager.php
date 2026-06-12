<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class MenuRuntimeManager {

	private AuditLog $audit;

	public function __construct() { $this->audit = new AuditLog(); }

	public function run( array $payload, array $context = [] ): array {
		$a = (string) ( $payload['action'] ?? '' );
		if ( ! in_array( $a, MenuRegistry::ACTIONS, true ) ) {
			return $this->err( 'wpcc_invalid_menu_action', __( 'Invalid menu action.', 'wp-command-center' ) );
		}
		return match ( $a ) {
			MenuRegistry::A_MENU_LIST       => $this->menu_list(),
			MenuRegistry::A_MENU_GET        => $this->menu_get( $payload ),
			MenuRegistry::A_MENU_CREATE     => $this->menu_create( $payload, $context ),
			MenuRegistry::A_MENU_UPDATE     => $this->menu_update( $payload, $context ),
			MenuRegistry::A_MENU_DELETE     => $this->menu_delete( $payload, $context ),
			MenuRegistry::A_MENU_DUPLICATE  => $this->menu_duplicate( $payload, $context ),
			MenuRegistry::A_MENU_EXPORT     => $this->menu_export( $payload ),
			MenuRegistry::A_MENU_IMPORT     => $this->menu_import( $payload, $context ),
			MenuRegistry::A_MENU_ITEM_LIST  => $this->menu_item_list( $payload ),
			MenuRegistry::A_MENU_ITEM_GET   => $this->menu_item_get( $payload ),
			MenuRegistry::A_MENU_ITEM_ADD   => $this->menu_item_add( $payload, $context ),
			MenuRegistry::A_MENU_ITEM_UPDATE => $this->menu_item_update( $payload, $context ),
			MenuRegistry::A_MENU_ITEM_REMOVE => $this->menu_item_remove( $payload, $context ),
			MenuRegistry::A_MENU_ITEM_MOVE   => $this->menu_item_move( $payload, $context ),
			MenuRegistry::A_MENU_ITEM_REORDER => $this->menu_item_reorder( $payload, $context ),
			MenuRegistry::A_LOCATION_LIST    => $this->location_list(),
			MenuRegistry::A_LOCATION_ASSIGN  => $this->location_assign( $payload, $context ),
			MenuRegistry::A_LOCATION_REMOVE  => $this->location_remove( $payload, $context ),
			MenuRegistry::A_LOCATION_SYNC    => $this->location_sync(),
			MenuRegistry::A_TREE_GET         => $this->tree_get( $payload ),
			MenuRegistry::A_TREE_VALIDATE    => $this->tree_validate( $payload ),
			MenuRegistry::A_TREE_REPAIR      => $this->tree_repair( $payload, $context ),
			MenuRegistry::A_MENU_ANALYZE     => $this->menu_analyze( $payload ),
			MenuRegistry::A_MENU_INVENTORY   => $this->menu_inventory(),
			default => $this->err( 'unknown', 'Unknown.' ),
		};
	}

	private function menu_list(): array {
		$menus = wp_get_nav_menus();
		$items = []; foreach ( $menus as $m ) $items[] = $this->summarize( $m );
		return [ 'action' => 'menu_list', 'menus' => $items, 'total' => count( $items ) ];
	}

	private function menu_get( array $p ): array {
		$menu = wp_get_nav_menu_object( (int) ( $p['menu_id'] ?? 0 ) );
		if ( ! $menu ) return $this->err( 'wpcc_menu_not_found', __( 'Menu not found.', 'wp-command-center' ) );
		$items = wp_get_nav_menu_items( $menu->term_id );
		return [ 'action' => 'menu_get', 'menu' => $this->summarize( $menu ), 'items' => array_map( [ $this, 'summarize_item' ], $items ?: [] ), 'total_items' => count( $items ?: [] ) ];
	}

	private function menu_create( array $p, array $cx ): array {
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		if ( '' === $name ) return $this->err( 'wpcc_missing_name', __( 'Menu name is required.', 'wp-command-center' ) );
		$id = wp_create_nav_menu( $name );
		if ( is_wp_error( $id ) ) return $this->err( 'wpcc_menu_create_failed', $id->get_error_message() );
		$loc = sanitize_key( (string) ( $p['location'] ?? '' ) );
		if ( '' !== $loc ) { $locs = get_theme_mod( 'nav_menu_locations' ) ?: []; $locs[ $loc ] = $id; set_theme_mod( 'nav_menu_locations', $locs ); }
		$this->store_rollback( (string) $id, 'menu_create', [], $cx );
		$this->audit->record( 'menu.created', [ 'menu_id' => $id, 'name' => $name ] );
		return [ 'action' => 'menu_create', 'menu_id' => $id, 'name' => $name ];
	}

	private function menu_update( array $p, array $cx ): array {
		$id = (int) ( $p['menu_id'] ?? 0 );
		$m = wp_get_nav_menu_object( $id );
		if ( ! $m ) return $this->err( 'wpcc_menu_not_found', __( 'Menu not found.', 'wp-command-center' ) );
		$before = $this->summarize( $m );
		if ( isset( $p['name'] ) ) wp_update_term( $id, 'nav_menu', [ 'name' => sanitize_text_field( (string) $p['name'] ) ] );
		$this->store_rollback( (string) $id, 'menu_update', $before, $cx );
		$this->audit->record( 'menu.updated', [ 'menu_id' => $id ] );
		return [ 'action' => 'menu_update', 'menu_id' => $id ];
	}

	private function menu_delete( array $p, array $cx ): array {
		$id = (int) ( $p['menu_id'] ?? 0 );
		$m = wp_get_nav_menu_object( $id );
		if ( ! $m ) return $this->err( 'wpcc_menu_not_found', __( 'Menu not found.', 'wp-command-center' ) );
		$before = [ 'name' => $m->name, 'items' => array_map( [ $this, 'summarize_item' ], wp_get_nav_menu_items( $id ) ?: [] ) ];
		$this->store_rollback( (string) $id, 'menu_delete', $before, $cx );
		wp_delete_nav_menu( $id );
		$this->audit->record( 'menu.deleted', [ 'menu_id' => $id ] );
		return [ 'action' => 'menu_delete', 'menu_id' => $id, 'name' => $before['name'] ];
	}

	private function menu_duplicate( array $p, array $cx ): array {
		$id = (int) ( $p['menu_id'] ?? 0 );
		$m = wp_get_nav_menu_object( $id );
		if ( ! $m ) return $this->err( 'wpcc_menu_not_found', __( 'Menu not found.', 'wp-command-center' ) );
		$new_id = wp_create_nav_menu( $m->name . ' (Copy)' );
		$items = wp_get_nav_menu_items( $id );
		$id_map = [];
		foreach ( $items ?: [] as $item ) {
			$args = [ 'menu-item-title' => $item->title, 'menu-item-url' => $item->url, 'menu-item-status' => 'publish',
				'menu-item-type' => $item->type, 'menu-item-object' => $item->object, 'menu-item-object-id' => $item->object_id,
				'menu-item-parent-id' => $id_map[ $item->menu_item_parent ] ?? 0 ];
			$new_item_id = wp_update_nav_menu_item( $new_id, 0, $args );
			if ( ! is_wp_error( $new_item_id ) ) $id_map[ $item->ID ] = $new_item_id;
		}
		$this->store_rollback( (string) $new_id, 'menu_create', [], $cx );
		$this->audit->record( 'menu.duplicated', [ 'original_id' => $id, 'new_id' => $new_id ] );
		return [ 'action' => 'menu_duplicate', 'menu_id' => $new_id, 'original_id' => $id ];
	}

	private function menu_export( array $p ): array {
		$id = (int) ( $p['menu_id'] ?? 0 );
		$items = wp_get_nav_menu_items( $id );
		return [ 'action' => 'menu_export', 'menu_id' => $id, 'items' => array_map( [ $this, 'summarize_item' ], $items ?: [] ), 'total' => count( $items ?: [] ) ];
	}

	private function menu_import( array $p, array $cx ): array {
		$name = sanitize_text_field( (string) ( $p['name'] ?? 'Imported Menu' ) );
		$items = (array) ( $p['items'] ?? [] );
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) return $this->err( 'wpcc_import_failed', $menu_id->get_error_message() );
		foreach ( $items as $item ) {
			wp_update_nav_menu_item( $menu_id, 0, [ 'menu-item-title' => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'menu-item-url' => esc_url_raw( (string) ( $item['url'] ?? '' ) ), 'menu-item-status' => 'publish' ] );
		}
		$this->store_rollback( (string) $menu_id, 'menu_import', [], $cx );
		$this->audit->record( 'menu.imported', [ 'menu_id' => $menu_id, 'item_count' => count( $items ) ] );
		return [ 'action' => 'menu_import', 'menu_id' => $menu_id, 'item_count' => count( $items ) ];
	}

	private function menu_item_list( array $p ): array {
		$id = (int) ( $p['menu_id'] ?? 0 );
		$items = wp_get_nav_menu_items( $id );
		$result = []; foreach ( $items ?: [] as $i ) $result[] = $this->summarize_item( $i );
		return [ 'action' => 'menu_item_list', 'menu_id' => $id, 'items' => $result, 'total' => count( $result ) ];
	}

	private function menu_item_get( array $p ): array {
		$item = get_post( (int) ( $p['item_id'] ?? 0 ) );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) return $this->err( 'wpcc_item_not_found', __( 'Menu item not found.', 'wp-command-center' ) );
		return [ 'action' => 'menu_item_get', 'item' => $this->summarize_item( $item ) ];
	}

	private function menu_item_add( array $p, array $cx ): array {
		$menu_id = (int) ( $p['menu_id'] ?? 0 ); $title = sanitize_text_field( (string) ( $p['title'] ?? '' ) ); $url = esc_url_raw( (string) ( $p['url'] ?? '' ) );
		if ( '' === $title ) return $this->err( 'wpcc_missing_title', __( 'Title is required.', 'wp-command-center' ) );
		$parent = (int) ( $p['parent_id'] ?? 0 );
		$args = [ 'menu-item-title' => $title, 'menu-item-url' => $url ?: '#', 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent ];
		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( is_wp_error( $item_id ) ) return $this->err( 'wpcc_item_add_failed', $item_id->get_error_message() );
		$this->store_rollback( (string) $item_id, 'menu_item_add', [], $cx );
		$this->audit->record( 'menu.item.added', [ 'item_id' => $item_id, 'menu_id' => $menu_id ] );
		return [ 'action' => 'menu_item_add', 'item_id' => $item_id, 'menu_id' => $menu_id, 'title' => $title ];
	}

	private function menu_item_update( array $p, array $cx ): array {
		$item = get_post( (int) ( $p['item_id'] ?? 0 ) );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) return $this->err( 'wpcc_item_not_found', __( 'Menu item not found.', 'wp-command-center' ) );
		$before = $this->summarize_item( $item );
		$menu_terms = wp_get_object_terms( $item->ID, 'nav_menu' );
		$menu_id = ( ! is_wp_error( $menu_terms ) && ! empty( $menu_terms ) ) ? (int) $menu_terms[0]->term_id : 0;
		$args = [ 'menu-item-title' => sanitize_text_field( (string) ( $p['title'] ?? $item->post_title ) ),
			'menu-item-url' => esc_url_raw( (string) ( $p['url'] ?? get_post_meta( $item->ID, '_menu_item_url', true ) ) ), 'menu-item-status' => 'publish',
			'menu-item-parent-id' => (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true ) ];
		$result = wp_update_nav_menu_item( $menu_id, $item->ID, $args );
		if ( is_wp_error( $result ) ) return $this->err( 'wpcc_item_update_failed', $result->get_error_message() );
		$this->store_rollback( (string) $item->ID, 'menu_item_update', $before, $cx );
		$this->audit->record( 'menu.item.updated', [ 'item_id' => $item->ID ] );
		return [ 'action' => 'menu_item_update', 'item_id' => $item->ID ];
	}

	private function menu_item_remove( array $p, array $cx ): array {
		$item = get_post( (int) ( $p['item_id'] ?? 0 ) );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) return $this->err( 'wpcc_item_not_found', __( 'Menu item not found.', 'wp-command-center' ) );
		$before = $this->summarize_item( $item );
		$this->store_rollback( (string) $item->ID, 'menu_item_remove', $before, $cx );
		wp_delete_post( $item->ID, true );
		$this->audit->record( 'menu.item.removed', [ 'item_id' => $before['id'] ] );
		return [ 'action' => 'menu_item_remove', 'item_id' => $before['id'] ];
	}

	private function menu_item_move( array $p, array $cx ): array {
		$item_id = (int) ( $p['item_id'] ?? 0 ); $new_parent = (int) ( $p['new_parent_id'] ?? 0 );
		$item = get_post( $item_id );
		if ( ! $item ) return $this->err( 'wpcc_item_not_found', __( 'Menu item not found.', 'wp-command-center' ) );
		$old_parent = (int) get_post_meta( $item_id, '_menu_item_menu_item_parent', true );
		update_post_meta( $item_id, '_menu_item_menu_item_parent', $new_parent );
		$this->store_rollback( (string) $item_id, 'menu_item_move', [ 'old_parent' => $old_parent, 'new_parent' => $new_parent ], $cx );
		return [ 'action' => 'menu_item_move', 'item_id' => $item_id, 'old_parent' => $old_parent, 'new_parent' => $new_parent ];
	}

	private function menu_item_reorder( array $p, array $cx ): array {
		$order = (array) ( $p['order'] ?? [] );
		foreach ( $order as $idx => $item_id ) wp_update_post( [ 'ID' => (int) $item_id, 'menu_order' => $idx ] );
		$this->audit->record( 'menu.item.reordered', [ 'count' => count( $order ) ] );
		return [ 'action' => 'menu_item_reorder', 'reordered_count' => count( $order ) ];
	}

	private function location_list(): array {
		$locs = get_registered_nav_menus();
		$assigned = get_theme_mod( 'nav_menu_locations' ) ?: [];
		$items = []; foreach ( $locs as $slug => $label ) $items[] = [ 'slug' => $slug, 'label' => $label, 'menu_id' => $assigned[ $slug ] ?? null ];
		return [ 'action' => 'menu_location_list', 'locations' => $items, 'total' => count( $items ) ];
	}

	private function location_assign( array $p, array $cx ): array {
		$menu_id = (int) ( $p['menu_id'] ?? 0 ); $loc = sanitize_key( (string) ( $p['location'] ?? '' ) );
		$locs = get_theme_mod( 'nav_menu_locations' ) ?: [];
		$before = $locs[ $loc ] ?? null;
		$locs[ $loc ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locs );
		$this->store_rollback( $loc, 'location_assign', [ 'menu_id' => $before ], $cx );
		$this->audit->record( 'menu.location.assigned', [ 'location' => $loc, 'menu_id' => $menu_id ] );
		return [ 'action' => 'menu_location_assign', 'location' => $loc, 'menu_id' => $menu_id ];
	}

	private function location_remove( array $p, array $cx ): array {
		$loc = sanitize_key( (string) ( $p['location'] ?? '' ) );
		$locs = get_theme_mod( 'nav_menu_locations' ) ?: [];
		$before = $locs[ $loc ] ?? null;
		unset( $locs[ $loc ] );
		set_theme_mod( 'nav_menu_locations', $locs );
		$this->store_rollback( $loc, 'location_remove', [ 'menu_id' => $before ], $cx );
		return [ 'action' => 'menu_location_remove', 'location' => $loc ];
	}

	private function location_sync(): array {
		$registered = get_registered_nav_menus(); $assigned = get_theme_mod( 'nav_menu_locations' ) ?: [];
		$unassigned = array_diff_key( $registered, $assigned );
		return [ 'action' => 'menu_location_sync', 'registered' => count( $registered ), 'assigned' => count( $assigned ), 'unassigned' => count( $unassigned ) ];
	}

	private function tree_get( array $p ): array {
		$id = (int) ( $p['menu_id'] ?? 0 );
		$items = wp_get_nav_menu_items( $id );
		$tree = $this->build_tree( $items ?: [] );
		return [ 'action' => 'menu_tree_get', 'menu_id' => $id, 'tree' => $tree, 'total_items' => count( $items ?: [] ), 'depth' => $this->max_depth( $tree ) ];
	}

	private function tree_validate( array $p ): array {
		$id = (int) ( $p['menu_id'] ?? 0 );
		$items = wp_get_nav_menu_items( $id ) ?: [];
		$issues = []; $ids = array_column( $items, 'ID' );
		foreach ( $items as $i ) { if ( $i->menu_item_parent > 0 && ! in_array( $i->menu_item_parent, $ids ) ) $issues[] = [ 'item_id' => $i->ID, 'type' => 'orphan', 'message' => 'Parent item not found' ]; }
		return [ 'action' => 'menu_tree_validate', 'menu_id' => $id, 'issues' => $issues, 'is_valid' => empty( $issues ) ];
	}

	private function tree_repair( array $p, array $cx ): array {
		$id = (int) ( $p['menu_id'] ?? 0 );
		$items = wp_get_nav_menu_items( $id ) ?: []; $ids = array_column( $items, 'ID' ); $repaired = 0;
		foreach ( $items as $i ) { if ( $i->menu_item_parent > 0 && ! in_array( $i->menu_item_parent, $ids ) ) { update_post_meta( $i->ID, '_menu_item_menu_item_parent', 0 ); $repaired++; } }
		$this->store_rollback( (string) $id, 'tree_repair', [ 'repaired' => $repaired ], $cx );
		return [ 'action' => 'menu_tree_repair', 'menu_id' => $id, 'repaired_count' => $repaired ];
	}

	private function menu_analyze( array $p ): array {
		$menus = wp_get_nav_menus(); $issues = [];
		foreach ( $menus as $m ) {
			$items = wp_get_nav_menu_items( $m->term_id ) ?: [];
			if ( empty( $items ) ) $issues[] = [ 'menu_id' => $m->term_id, 'menu' => $m->name, 'type' => 'empty', 'severity' => 'medium', 'message' => 'Menu has no items' ];
			foreach ( $items as $i ) {
				if ( 'custom' === $i->type && ( empty( $i->url ) || '#' === $i->url ) ) $issues[] = [ 'menu_id' => $m->term_id, 'item_id' => $i->ID, 'type' => 'broken_link', 'severity' => 'high', 'message' => 'Custom link with no URL' ];
			}
		}
		$this->audit->record( 'menu.analyzed', [ 'issue_count' => count( $issues ) ] );
		return [ 'action' => 'menu_analyze', 'issue_count' => count( $issues ), 'issues' => $issues ];
	}

	private function menu_inventory(): array {
		$menus = wp_get_nav_menus(); $total_items = 0; $locs = get_registered_nav_menus(); $assigned = get_theme_mod( 'nav_menu_locations' ) ?: [];
		foreach ( $menus as $m ) $total_items += count( wp_get_nav_menu_items( $m->term_id ) ?: [] );
		return [ 'action' => 'menu_inventory', 'menus' => count( $menus ), 'items' => $total_items, 'registered_locations' => count( $locs ), 'assigned_locations' => count( $assigned ) ];
	}

	public function rollback( array $p, array $cx = [] ): array {
		$rid = (string) ( $p['rollback_id'] ?? '' );
		if ( '' === $rid ) return $this->err( 'wpcc_missing_rb_id', __( 'Rollback ID required.', 'wp-command-center' ) );
		$rollbacks = get_option( 'wpcc_menu_rollbacks', [] ); $rec = null; $idx = null;
		foreach ( $rollbacks as $i => $r ) { if ( $r['id'] === $rid ) { $rec = $r; $idx = $i; break; } }
		if ( ! $rec ) return $this->err( 'wpcc_rb_not_found', __( 'Not found.', 'wp-command-center' ) );
		if ( $rec['rollback_applied'] ) return $this->err( 'wpcc_rb_already', __( 'Already applied.', 'wp-command-center' ) );
		$act = $rec['action']; $eid = $rec['entity_id'];
		if ( in_array( $act, [ 'menu_create', 'menu_import' ] ) ) wp_delete_nav_menu( (int) $eid );
		elseif ( 'menu_delete' === $act ) { $b = $rec['before_state']; $mid = wp_create_nav_menu( $b['name'] ?? 'Restored' ); foreach ( $b['items'] ?? [] as $item ) wp_update_nav_menu_item( $mid, 0, [ 'menu-item-title' => $item['title'], 'menu-item-url' => $item['url'] ?? '#', 'menu-item-status' => 'publish' ] ); }
		elseif ( in_array( $act, [ 'menu_item_add', 'menu_item_remove' ] ) ) { $b = $rec['before_state']; if ( ! empty( $b['id'] ) ) wp_delete_post( (int) $rec['entity_id'], true ); }
		elseif ( in_array( $act, [ 'location_assign', 'location_remove' ] ) ) { $locs = get_theme_mod( 'nav_menu_locations' ) ?: []; $b = $rec['before_state']; if ( isset( $b['menu_id'] ) ) { if ( $b['menu_id'] ) $locs[ $eid ] = (int) $b['menu_id']; else unset( $locs[ $eid ] ); set_theme_mod( 'nav_menu_locations', $locs ); } }
		$rollbacks[ $idx ]['rollback_applied'] = true; update_option( 'wpcc_menu_rollbacks', $rollbacks );
		return [ 'action' => 'menu_rollback', 'rollback_id' => $rid ];
	}

	private function store_rollback( string $id, string $action, array $before, array $cx ): void {
		if ( ! MenuRegistry::supports_rollback( $action ) ) return;
		$rb = get_option( 'wpcc_menu_rollbacks', [] );
		$rb[] = [ 'id' => wp_generate_uuid4(), 'entity_id' => $id, 'action' => $action, 'before_state' => $before, 'rollback_applied' => false, 'created_at' => time(), 'session_id' => $cx['session_id'] ?? null, 'task_id' => $cx['task_id'] ?? null ];
		if ( count( $rb ) > 200 ) $rb = array_slice( $rb, -200 );
		update_option( 'wpcc_menu_rollbacks', $rb );
	}

	private function build_tree( array $items, int $parent = 0 ): array {
		$branch = [];
		foreach ( $items as $i ) { if ( (int) $i->menu_item_parent === $parent ) { $i->children = $this->build_tree( $items, $i->ID ); $branch[] = $this->summarize_item( $i ); } }
		return $branch;
	}

	private function max_depth( array $tree, int $d = 0 ): int { $max = $d; foreach ( $tree as $node ) { $cd = $this->max_depth( $node['children'] ?? [], $d + 1 ); if ( $cd > $max ) $max = $cd; } return $max; }

	private function summarize( \WP_Term $m ): array { return [ 'id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug, 'count' => $m->count ]; }
	private function summarize_item( \WP_Post $i ): array { return [ 'id' => $i->ID, 'title' => $i->post_title, 'url' => $i->url, 'type' => $i->type, 'object' => $i->object, 'parent' => (int) $i->menu_item_parent, 'order' => $i->menu_order ]; }
	private function err( string $code, string $msg ): array { return [ 'error' => true, 'code' => $code, 'message' => $msg ]; }
}
