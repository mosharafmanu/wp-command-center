<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ACFRuntimeManager {

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	public function run( array $payload, array $context = [] ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return $this->error( 'wpcc_acf_inactive', __( 'Advanced Custom Fields is not active.', 'wp-command-center' ) );
		}
		$a = (string) ( $payload['action'] ?? '' );
		if ( ! in_array( $a, ACFRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_acf_action', __( 'Invalid ACF action.', 'wp-command-center' ) );
		}
		return match ( $a ) {
			ACFRegistry::ACTION_GROUP_LIST       => $this->group_list( $payload ),
			ACFRegistry::ACTION_GROUP_GET        => $this->group_get( $payload ),
			ACFRegistry::ACTION_GROUP_CREATE     => $this->group_create( $payload, $context ),
			ACFRegistry::ACTION_GROUP_UPDATE     => $this->group_update( $payload, $context ),
			ACFRegistry::ACTION_GROUP_DELETE     => $this->group_delete( $payload, $context ),
			ACFRegistry::ACTION_GROUP_DUPLICATE  => $this->group_duplicate( $payload, $context ),
			ACFRegistry::ACTION_GROUP_ACTIVATE   => $this->group_activate( $payload, $context ),
			ACFRegistry::ACTION_GROUP_DEACTIVATE => $this->group_deactivate( $payload, $context ),
			ACFRegistry::ACTION_FIELD_LIST       => $this->field_list( $payload ),
			ACFRegistry::ACTION_FIELD_GET        => $this->field_get( $payload ),
			ACFRegistry::ACTION_FIELD_CREATE     => $this->field_create( $payload, $context ),
			ACFRegistry::ACTION_FIELD_UPDATE     => $this->field_update( $payload, $context ),
			ACFRegistry::ACTION_FIELD_DELETE     => $this->field_delete( $payload, $context ),
			ACFRegistry::ACTION_LOCATION_LIST    => $this->location_list( $payload ),
			ACFRegistry::ACTION_LOCATION_ASSIGN  => $this->location_assign( $payload, $context ),
			ACFRegistry::ACTION_LOCATION_REMOVE  => $this->location_remove( $payload, $context ),
			ACFRegistry::ACTION_JSON_STATUS      => $this->json_status( $payload ),
			ACFRegistry::ACTION_JSON_EXPORT      => $this->json_export( $payload ),
			ACFRegistry::ACTION_JSON_IMPORT      => $this->json_import( $payload, $context ),
			ACFRegistry::ACTION_JSON_SYNC        => $this->json_sync( $payload, $context ),
			ACFRegistry::ACTION_JSON_DIFF        => $this->json_diff( $payload ),
			ACFRegistry::ACTION_VALUE_GET        => $this->value_get( $payload ),
			ACFRegistry::ACTION_VALUE_UPDATE     => $this->value_update( $payload, $context ),
			ACFRegistry::ACTION_BULK_VALUE_UPDATE => $this->bulk_value_update( $payload, $context ),
			ACFRegistry::ACTION_INVENTORY         => $this->inventory( $payload ),
			default => $this->error( 'wpcc_unknown_acf_action', __( 'Unknown ACF action.', 'wp-command-center' ) ),
		};
	}

	private function group_list( array $p ): array {
		$groups = acf_get_field_groups();
		$items = []; foreach ( $groups as $g ) $items[] = $this->summarize_group( $g );
		$this->audit->record( 'acf.group.list', [ 'count' => count( $items ) ] );
		return [ 'action' => 'acf_group_list', 'groups' => $items, 'total' => count( $items ) ];
	}

	private function group_get( array $p ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		$fields = acf_get_fields( $id );
		$this->audit->record( 'acf.group.get', [ 'group_id' => $id ] );
		return [ 'action' => 'acf_group_get', 'group' => $this->summarize_group( $g ), 'fields' => array_map( [ $this, 'summarize_field' ], $fields ?: [] ) ];
	}

	private function group_create( array $p, array $cx ): array {
		$title = sanitize_text_field( (string) ( $p['title'] ?? '' ) );
		if ( '' === $title ) return $this->error( 'wpcc_missing_title', __( 'Title is required.', 'wp-command-center' ) );
		$g = [ 'title' => $title, 'fields' => [], 'location' => [], 'menu_order' => 0, 'position' => 'normal', 'style' => 'default', 'label_placement' => 'top', 'instruction_placement' => 'label', 'hide_on_screen' => '', 'active' => true,
			'key' => 'group_' . uniqid(), ];
		if ( isset( $p['location'] ) ) $g['location'] = (array) $p['location'];
		$result = acf_update_field_group( $g );
		if ( ! $result ) return $this->error( 'wpcc_group_create_failed', __( 'Failed to create field group.', 'wp-command-center' ) );
		$id = $g['key'];
		$this->store_rollback( $id, 'group_create', [], $cx );
		$this->audit->record( 'acf.group.created', [ 'group_id' => $id, 'title' => $title ] );
		return [ 'action' => 'acf_group_create', 'group_id' => $id, 'key' => $g['key'], 'title' => $title ];
	}

	private function group_update( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		$before = $this->summarize_group( $g );
		if ( isset( $p['title'] ) ) $g['title'] = sanitize_text_field( (string) $p['title'] );
		if ( isset( $p['active'] ) ) $g['active'] = (bool) $p['active'];
		$result = acf_update_field_group( array_merge( $g, $p ) );
		if ( ! $result ) return $this->error( 'wpcc_group_update_failed', __( 'Failed to update field group.', 'wp-command-center' ) );
		$this->store_rollback( $id, 'group_update', $before, $cx );
		$this->audit->record( 'acf.group.updated', [ 'group_id' => $id ] );
		return [ 'action' => 'acf_group_update', 'group_id' => $id ];
	}

	private function group_delete( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		$before = $this->summarize_group( $g );
		$this->store_rollback( $id, 'group_delete', $before, $cx );
		acf_delete_field_group( $id );
		$this->audit->record( 'acf.group.deleted', [ 'group_id' => $id ] );
		return [ 'action' => 'acf_group_delete', 'group_id' => $id ];
	}

	private function group_duplicate( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		$new_id = acf_duplicate_field_group( $g );
		$this->store_rollback( $new_id, 'group_create', [], $cx );
		return [ 'action' => 'acf_group_duplicate', 'group_id' => $new_id, 'original_id' => $id ];
	}

	private function group_activate( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		acf_update_field_group( array_merge( $g, [ 'active' => true ] ) );
		return [ 'action' => 'acf_group_activate', 'group_id' => $id ];
	}

	private function group_deactivate( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		acf_update_field_group( array_merge( $g, [ 'active' => false ] ) );
		return [ 'action' => 'acf_group_deactivate', 'group_id' => $id ];
	}

	private function field_list( array $p ): array {
		$group_id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		if ( '' !== $group_id ) {
			$fields = acf_get_fields( $group_id );
		} else {
			$all_groups = acf_get_field_groups();
			$fields = [];
			foreach ( $all_groups as $g ) {
				$f = acf_get_fields( $g['key'] );
				if ( $f ) $fields = array_merge( $fields, $f );
			}
		}
		$items = array_map( [ $this, 'summarize_field' ], $fields ?: [] );
		return [ 'action' => 'acf_field_list', 'fields' => $items, 'total' => count( $items ) ];
	}

	private function field_get( array $p ): array {
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$f = acf_get_field( $key );
		if ( ! $f ) return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'wp-command-center' ) );
		return [ 'action' => 'acf_field_get', 'field' => $this->summarize_field( $f ) ];
	}

	private function field_create( array $p, array $cx ): array {
		$group_id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $group_id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Parent field group not found.', 'wp-command-center' ) );
		$field = [
			'parent' => $group_id, 'key' => 'field_' . uniqid(),
			'label' => sanitize_text_field( (string) ( $p['label'] ?? 'New Field' ) ),
			'name'  => sanitize_title( (string) ( $p['name'] ?? '' ) ),
			'type'  => sanitize_key( (string) ( $p['type'] ?? 'text' ) ),
		];
		$result = acf_update_field( $field );
		if ( ! $result ) return $this->error( 'wpcc_field_create_failed', __( 'Failed to create field.', 'wp-command-center' ) );
		$this->store_rollback( $field['key'], 'field_create', [], $cx );
		$this->audit->record( 'acf.field.created', [ 'field_key' => $field['key'], 'group_id' => $group_id ] );
		return [ 'action' => 'acf_field_create', 'field_key' => $field['key'], 'label' => $field['label'], 'group_id' => $group_id ];
	}

	private function field_update( array $p, array $cx ): array {
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$f = acf_get_field( $key );
		if ( ! $f ) return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'wp-command-center' ) );
		$before = $this->summarize_field( $f );
		if ( isset( $p['label'] ) ) $f['label'] = sanitize_text_field( (string) $p['label'] );
		if ( isset( $p['type'] ) ) $f['type'] = sanitize_key( (string) $p['type'] );
		if ( isset( $p['instructions'] ) ) $f['instructions'] = sanitize_textarea_field( (string) $p['instructions'] );
		acf_update_field( $f );
		$this->store_rollback( $key, 'field_update', $before, $cx );
		$this->audit->record( 'acf.field.updated', [ 'field_key' => $key ] );
		return [ 'action' => 'acf_field_update', 'field_key' => $key ];
	}

	private function field_delete( array $p, array $cx ): array {
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$f = acf_get_field( $key );
		if ( ! $f ) return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'wp-command-center' ) );
		$before = $this->summarize_field( $f );
		$this->store_rollback( $key, 'field_delete', $before, $cx );
		acf_delete_field( $key );
		$this->audit->record( 'acf.field.deleted', [ 'field_key' => $key ] );
		return [ 'action' => 'acf_field_delete', 'field_key' => $key ];
	}

	private function location_list( array $p ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		return [ 'action' => 'acf_location_list', 'group_id' => $id, 'location' => $g['location'] ?? [] ];
	}

	private function location_assign( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		$rules = $g['location'] ?? [];
		$new = array_map( 'sanitize_text_field', (array) ( $p['rules'] ?? [] ) );
		$before = $rules;
		$rules[] = $new;
		acf_update_field_group( array_merge( $g, [ 'location' => $rules ] ) );
		$this->store_rollback( $id, 'location_assign', [ 'location' => $before ], $cx );
		return [ 'action' => 'acf_location_assign', 'group_id' => $id, 'location' => $rules ];
	}

	private function location_remove( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		$idx = (int) ( $p['rule_index'] ?? -1 );
		$rules = $g['location'] ?? [];
		$before = $rules;
		if ( isset( $rules[ $idx ] ) ) { unset( $rules[ $idx ] ); $rules = array_values( $rules ); }
		acf_update_field_group( array_merge( $g, [ 'location' => $rules ] ) );
		$this->store_rollback( $id, 'location_remove', [ 'location' => $before ], $cx );
		return [ 'action' => 'acf_location_remove', 'group_id' => $id, 'location' => $rules ];
	}

	private function json_status( array $p ): array {
		$groups = acf_get_field_groups();
		$synced = $unsynced = 0;
		foreach ( $groups as $g ) { if ( ! empty( $g['local'] ) && 'json' === $g['local'] ) $synced++; else $unsynced++; }
		$json_path = acf_get_setting( 'save_json' );
		return [ 'action' => 'acf_json_status', 'total_groups' => count( $groups ), 'synced' => $synced, 'unsynced' => $unsynced, 'json_path' => $json_path ];
	}

	private function json_export( array $p ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		if ( '' === $id ) return $this->error( 'wpcc_missing_id', __( 'Group ID is required for export.', 'wp-command-center' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		$fields = acf_get_fields( $id );
		$json = wp_json_encode( [ 'key' => $g['key'], 'title' => $g['title'], 'fields' => $fields, 'location' => $g['location'] ?? [] ], JSON_PRETTY_PRINT );
		return [ 'action' => 'acf_json_export', 'group_id' => $id, 'json' => $json ];
	}

	private function json_import( array $p, array $cx ): array {
		$json = (string) ( $p['json'] ?? '' );
		if ( '' === $json ) return $this->error( 'wpcc_missing_json', __( 'JSON content is required.', 'wp-command-center' ) );
		$data = json_decode( $json, true );
		if ( ! $data ) return $this->error( 'wpcc_invalid_json', __( 'Invalid JSON.', 'wp-command-center' ) );
		// Store before import rollback
		$existing = isset( $data['key'] ) ? acf_get_field_group( $data['key'] ) : null;
		if ( $existing ) $this->store_rollback( $data['key'], 'json_import', $this->summarize_group( $existing ), $cx );
		// Import via ACF
		$imported = acf_import_field_group( $data );
		if ( ! $imported ) return $this->error( 'wpcc_import_failed', __( 'Failed to import field group.', 'wp-command-center' ) );
		$this->audit->record( 'acf.json.imported', [ 'group_key' => $data['key'] ?? 'unknown' ] );
		return [ 'action' => 'acf_json_import', 'imported_key' => $data['key'] ?? 'unknown' ];
	}

	private function json_sync( array $p, array $cx ): array {
		$groups     = acf_get_field_groups();
		$json_files = acf_get_local_json_files();
		$synced     = 0;
		foreach ( $groups as $g ) {
			if ( ! empty( $g['local'] ) ) continue;
			$json_file = $json_files[ $g['key'] ] ?? null;
			if ( ! $json_file ) continue;
			$json_data = json_decode( file_get_contents( $json_file ), true );
			if ( $json_data ) {
				acf_import_field_group( $json_data );
				$synced++;
			}
		}
		$this->audit->record( 'acf.json.synced', [ 'count' => $synced ] );
		return [ 'action' => 'acf_json_sync', 'synced_count' => $synced ];
	}

	private function json_diff( array $p ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		if ( '' === $id ) return $this->error( 'wpcc_missing_id', __( 'Group ID required.', 'wp-command-center' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'wp-command-center' ) );
		$json_files = acf_get_local_json_files();
		$json_file  = $json_files[ $g['key'] ?? '' ] ?? null;
		$json_data  = $json_file ? json_decode( file_get_contents( $json_file ), true ) : null;
		return [ 'action' => 'acf_json_diff', 'group_id' => $id, 'db_exists' => true, 'json_exists' => (bool) $json_data, 'json_path' => $json_file ];
	}

	private function value_get( array $p ): array {
		$post_id = (int) ( $p['post_id'] ?? 0 );
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? $p['field_name'] ?? '' ) );
		if ( '' === $key ) return $this->error( 'wpcc_missing_field', __( 'Field key or name is required.', 'wp-command-center' ) );
		$value = get_field( $key, $post_id ?: false );
		return [ 'action' => 'acf_value_get', 'post_id' => $post_id, 'field_key' => $key, 'value' => $value ];
	}

	private function value_update( array $p, array $cx ): array {
		$post_id = (int) ( $p['post_id'] ?? 0 );
		if ( $post_id <= 0 ) return $this->error( 'wpcc_missing_post_id', __( 'Post ID is required.', 'wp-command-center' ) );
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? $p['field_name'] ?? '' ) );
		if ( '' === $key ) return $this->error( 'wpcc_missing_field', __( 'Field key or name is required.', 'wp-command-center' ) );
		$before = get_field( $key, $post_id );
		$value = $p['value'] ?? null;
		update_field( $key, $value, $post_id );
		$this->store_rollback( $post_id . '_' . $key, 'value_update', [ 'post_id' => $post_id, 'key' => $key, 'value' => $before ], $cx );
		$this->audit->record( 'acf.value.updated', [ 'post_id' => $post_id, 'field_key' => $key ] );
		return [ 'action' => 'acf_value_update', 'post_id' => $post_id, 'field_key' => $key ];
	}

	private function bulk_value_update( array $p, array $cx ): array {
		$post_id = (int) ( $p['post_id'] ?? 0 );
		if ( $post_id <= 0 ) return $this->error( 'wpcc_missing_post_id', __( 'Post ID is required.', 'wp-command-center' ) );
		$fields = (array) ( $p['fields'] ?? [] );
		$updated = [];
		foreach ( $fields as $key => $value ) {
			$k = sanitize_text_field( (string) $key );
			update_field( $k, $value, $post_id );
			$updated[] = $k;
		}
		$this->audit->record( 'acf.value.bulk_updated', [ 'post_id' => $post_id, 'count' => count( $updated ) ] );
		return [ 'action' => 'acf_bulk_value_update', 'post_id' => $post_id, 'updated_fields' => $updated ];
	}

	private function inventory( array $p ): array {
		$groups = acf_get_field_groups();
		$total_fields = 0; $type_counts = []; $location_counts = [];
		foreach ( $groups as $g ) {
			$fields = acf_get_fields( $g['key'] );
			$total_fields += count( $fields ?: [] );
			foreach ( $fields ?: [] as $f ) { $t = $f['type'] ?? 'unknown'; $type_counts[ $t ] = ( $type_counts[ $t ] ?? 0 ) + 1; }
			foreach ( $g['location'] ?? [] as $loc ) { $loc_str = wp_json_encode( $loc ); $location_counts[ $loc_str ] = ( $location_counts[ $loc_str ] ?? 0 ) + 1; }
		}
		$json_path = acf_get_setting( 'save_json' );
		$synced = count( array_filter( $groups, fn( $g ) => ! empty( $g['local'] ) && 'json' === $g['local'] ) );
		return [ 'action' => 'acf_inventory', 'groups' => count( $groups ), 'total_fields' => $total_fields, 'synced' => $synced, 'unsynced' => count( $groups ) - $synced, 'field_types' => $type_counts, 'json_path' => $json_path ];
	}

	public function rollback( array $p, array $cx = [] ): array {
		$rid = (string) ( $p['rollback_id'] ?? '' );
		if ( '' === $rid ) return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID required.', 'wp-command-center' ) );
		$rollbacks = get_option( 'wpcc_acf_rollbacks', [] );
		$rec = null; $idx = null;
		foreach ( $rollbacks as $i => $r ) { if ( $r['id'] === $rid ) { $rec = $r; $idx = $i; break; } }
		if ( ! $rec ) return $this->error( 'wpcc_rollback_not_found', __( 'Rollback not found.', 'wp-command-center' ) );
		if ( $rec['rollback_applied'] ) return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'wp-command-center' ) );
		$eid   = $rec['entity_id'];
		$act   = $rec['action'];
		$before = $rec['before_state'];
		if ( in_array( $act, [ 'group_create', 'field_create' ] ) ) {
			if ( str_starts_with( $act, 'group' ) ) acf_delete_field_group( $eid );
			elseif ( str_starts_with( $act, 'field' ) ) acf_delete_field( $eid );
		} elseif ( 'group_delete' === $act ) {
			if ( ! empty( $before ) ) { $before['active'] = true; acf_update_field_group( $before ); }
		} elseif ( 'field_delete' === $act ) {
			if ( ! empty( $before ) ) acf_update_field( $before );
		} elseif ( in_array( $act, [ 'group_update', 'field_update' ] ) ) {
			if ( str_starts_with( $act, 'group' ) ) acf_update_field_group( $before );
			else acf_update_field( $before );
		} elseif ( in_array( $act, [ 'location_assign', 'location_remove' ] ) ) {
			$g = acf_get_field_group( $eid );
			if ( $g ) acf_update_field_group( array_merge( $g, [ 'location' => $before['location'] ?? [] ] ) );
		} elseif ( 'value_update' === $act ) {
			if ( isset( $before['post_id'], $before['key'] ) ) update_field( $before['key'], $before['value'], $before['post_id'] );
		}
		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_acf_rollbacks', $rollbacks );
		return [ 'action' => 'acf_rollback', 'rollback_id' => $rid ];
	}

	private function store_rollback( string $id, string $action, array $before, array $cx ): void {
		if ( ! ACFRegistry::supports_rollback( $action ) ) return;
		$rollbacks = get_option( 'wpcc_acf_rollbacks', [] );
		$rollbacks[] = [ 'id' => wp_generate_uuid4(), 'entity_id' => $id, 'action' => $action, 'before_state' => $before, 'rollback_applied' => false, 'created_at' => time(),
			'session_id' => $cx['session_id'] ?? null, 'task_id' => $cx['task_id'] ?? null ];
		if ( count( $rollbacks ) > 200 ) $rollbacks = array_slice( $rollbacks, -200 );
		update_option( 'wpcc_acf_rollbacks', $rollbacks );
	}

	private function summarize_group( array $g ): array {
		return [ 'key' => $g['key'] ?? '', 'title' => $g['title'] ?? '', 'active' => $g['active'] ?? true, 'location' => count( $g['location'] ?? [] ), 'field_count' => 0 ];
	}

	private function summarize_field( array $f ): array {
		return [ 'key' => $f['key'] ?? '', 'label' => $f['label'] ?? '', 'name' => $f['name'] ?? '', 'type' => $f['type'] ?? 'text', 'required' => $f['required'] ?? false, 'parent' => $f['parent'] ?? '' ];
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
