<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Rollback\RollbackDelta;
use WPCommandCenter\Rollback\PostMetaRollbackStore;
use WPCommandCenter\Rollback\AcfValueAccessor;

defined( 'ABSPATH' ) || exit;

final class ACFRuntimeManager {

	/** F3.2 — recursion guard for nested-field serialization. */
	private const MAX_FIELD_DEPTH = 10;

	/** PROGRAM-4.9 — postmeta-per-record store prefix for value_update delta records. */
	private const VALUE_RB_PREFIX = '_wpcc_acf_rb_';

	/** PROGRAM-4.9 — definition update-in-place actions that get a fingerprint drift guard. */
	private const FP_GUARDED = [ 'group_update', 'field_update', 'location_assign', 'location_remove', 'layout_update' ];

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
			ACFRegistry::ACTION_LAYOUT_CREATE     => $this->layout_create( $payload, $context ),
			ACFRegistry::ACTION_LAYOUT_UPDATE     => $this->layout_update( $payload, $context ),
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
		return [ 'action' => 'acf_group_get', 'group' => $this->summarize_group( $g ), 'fields' => array_map( [ $this, 'detail_field' ], $fields ?: [] ) ];
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
		// STEP 102.6 (F-4): store the COMPLETE original group as the rollback before-state.
		// summarize_group() was lossy (location collapsed to an int count, no post ID),
		// so rollback()'s acf_update_field_group( $before ) could not faithfully restore.
		// $g is the unmutated original here (the title/active edits below copy-on-write
		// into $g, not $before), so this preserves the full group for exact restoration.
		$before = $g;
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
		$key = (string) ( $g['key'] ?? '' );

		// F3.1 — capture the FULL group definition (not the stripped summary, which
		// stored `location` as a count and could not be restored) so a rollback can
		// recreate it. `ID` is dropped so the restore re-creates a fresh post by key.
		$before = $g;
		unset( $before['ID'] );
		$rollback_id = $this->store_rollback( $id, 'group_delete', $before, $cx );

		// Delete the DB post, then remove any runtime-owned acf-json file in ACF's
		// writable save path so acf-json sync cannot silently re-register the group
		// on the next request (the production false-success cause). Read-only
		// theme/plugin load paths are intentionally left untouched.
		acf_delete_field_group( $id );
		$this->purge_owned_local_json( $key );

		// F3.1 — NEVER report success unless the group is actually gone. It persists
		// when it is resolvable in-memory (a purely-local group with no DB post) or
		// a JSON definition remains in a load path that would resurrect it next
		// request. Previously this handler returned success unconditionally.
		if ( $this->group_will_persist( $key, $id ) ) {
			return $this->error(
				'wpcc_acf_group_delete_failed',
				__( 'Field group still exists after delete — it is defined in a read-only local JSON/PHP source (e.g. theme acf-json) and was not removed.', 'wp-command-center' )
			);
		}

		$this->audit->record( 'acf.group.deleted', [ 'group_id' => $id, 'key' => $key ] );
		return [ 'action' => 'acf_group_delete', 'group_id' => $id, 'key' => $key, 'deleted' => true, 'rollback_id' => $rollback_id ];
	}

	/** Delete a runtime/UI-owned acf-json file in ACF's configured save path (only). */
	private function purge_owned_local_json( string $key ): void {
		if ( '' === $key || ! function_exists( 'acf_get_setting' ) ) return;
		$save = acf_get_setting( 'save_json' );
		if ( ! is_string( $save ) || '' === $save ) return;
		$file = untrailingslashit( wp_normalize_path( $save ) ) . '/' . $key . '.json';
		if ( is_file( $file ) && is_writable( $file ) ) {
			@unlink( $file );
		}
	}

	/** True when the group would survive the delete (in-memory local def, or JSON in any load path). */
	private function group_will_persist( string $key, string $id ): bool {
		if ( acf_get_field_group( $id ) ) return true;
		if ( '' !== $key && acf_get_field_group( $key ) ) return true;
		if ( '' !== $key && function_exists( 'acf_get_setting' ) ) {
			foreach ( (array) acf_get_setting( 'load_json' ) as $path ) {
				$file = untrailingslashit( wp_normalize_path( (string) $path ) ) . '/' . $key . '.json';
				if ( is_file( $file ) ) return true;
			}
		}
		return false;
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
		$items = array_map( [ $this, 'detail_field' ], $fields ?: [] );
		return [ 'action' => 'acf_field_list', 'fields' => $items, 'total' => count( $items ) ];
	}

	private function field_get( array $p ): array {
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$f = acf_get_field( $key );
		if ( ! $f ) return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'wp-command-center' ) );
		return [ 'action' => 'acf_field_get', 'field' => $this->detail_field( $f ) ];
	}

	private function field_create( array $p, array $cx ): array {
		// Parent may be a field group, a repeater/group field key, or — together
		// with parent_layout — a flexible-content field key.
		$parent = sanitize_text_field( (string) ( $p['parent'] ?? $p['group_id'] ?? '' ) );
		if ( '' === $parent ) {
			return $this->error( 'wpcc_acf_missing_parent', __( 'group_id or parent is required.', 'wp-command-center' ) );
		}
		if ( ! $this->parent_exists( $parent ) ) {
			return $this->error( 'wpcc_acf_parent_not_found', __( 'Parent field group or field not found.', 'wp-command-center' ) );
		}

		$type = sanitize_key( (string) ( $p['type'] ?? 'text' ) );
		if ( ! in_array( $type, ACFRegistry::FIELD_TYPES, true ) ) {
			return $this->error( 'wpcc_acf_unsupported_field_type', sprintf( __( 'Unsupported field type: %s', 'wp-command-center' ), esc_html( $type ) ) );
		}

		// acf_update_field only links a field when the parent is the numeric post
		// ID of the parent group/field — a KEY string leaves it orphaned
		// (post_parent = 0), which corrupts ACF. Resolve the key to its post ID.
		$parent_ref = $parent;
		$grp        = acf_get_field_group( $parent );
		if ( $grp && isset( $grp['ID'] ) && $grp['ID'] ) {
			$parent_ref = (int) $grp['ID'];
		} else {
			$pf = acf_get_field( $parent );
			if ( $pf && isset( $pf['ID'] ) && $pf['ID'] ) {
				$parent_ref = (int) $pf['ID'];
			}
		}

		$key   = 'field_' . uniqid();
		$label = sanitize_text_field( (string) ( $p['label'] ?? 'New Field' ) );
		$field = [
			'parent' => $parent_ref,
			'key'    => $key,
			'label'  => $label,
			'name'   => sanitize_title( (string) ( $p['name'] ?? $label ) ),
			'type'   => $type,
		];

		// Flexible-content layout sub-field association.
		if ( isset( $p['parent_layout'] ) && '' !== (string) $p['parent_layout'] ) {
			$field['parent_layout'] = sanitize_text_field( (string) $p['parent_layout'] );
		}

		// Common settings.
		if ( isset( $p['instructions'] ) ) {
			$field['instructions'] = sanitize_textarea_field( (string) $p['instructions'] );
		}
		if ( isset( $p['required'] ) ) {
			$field['required'] = $this->boolish( $p['required'] ) ? 1 : 0;
		}
		if ( isset( $p['default_value'] ) && is_scalar( $p['default_value'] ) ) {
			$field['default_value'] = sanitize_text_field( (string) $p['default_value'] );
		}

		// Type-specific configuration (choices, return_format, post_type, …).
		if ( isset( $p['config'] ) && is_array( $p['config'] ) ) {
			$field = array_merge( $field, $this->sanitize_config( $p['config'] ) );
		}

		if ( ! acf_update_field( $field ) ) {
			return $this->error( 'wpcc_field_create_failed', __( 'Failed to create field.', 'wp-command-center' ) );
		}

		// Nested sub-fields (repeater / group): create each child under this field.
		$sub_created = [];
		if ( in_array( $type, [ 'repeater', 'group' ], true ) && ! empty( $p['sub_fields'] ) && is_array( $p['sub_fields'] ) ) {
			foreach ( $p['sub_fields'] as $sf ) {
				if ( ! is_array( $sf ) ) {
					continue;
				}
				$sf['parent'] = $key;
				unset( $sf['group_id'], $sf['parent_layout'] );
				$r = $this->field_create( $sf, $cx );
				if ( isset( $r['field_key'] ) ) {
					$sub_created[] = $r['field_key'];
				}
			}
		}

		$rollback_id = $this->store_rollback( $key, 'field_create', [], $cx );
		$this->audit->record( 'acf.field.created', [ 'field_key' => $key, 'parent' => $parent, 'type' => $type ] );

		return [
			'action'      => 'acf_field_create',
			'field_key'   => $key,
			'label'       => $label,
			'name'        => $field['name'],
			'type'        => $type,
			'parent'      => $parent,
			'sub_fields'  => $sub_created,
			'rollback_id' => $rollback_id,
		];
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

	// ── STEP 92 — flexible-content layouts ───────────────────────

	private function layout_create( array $p, array $cx ): array {
		$field_key = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$f = acf_get_field( $field_key );
		if ( ! $f ) {
			return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'wp-command-center' ) );
		}
		if ( 'flexible_content' !== ( $f['type'] ?? '' ) ) {
			return $this->error( 'wpcc_acf_not_flexible', __( 'Layouts can only be added to a flexible_content field.', 'wp-command-center' ) );
		}

		$name  = sanitize_title( (string) ( $p['name'] ?? $p['label'] ?? 'layout' ) );
		$label = sanitize_text_field( (string) ( $p['label'] ?? $name ) );
		if ( '' === $name ) {
			return $this->error( 'wpcc_acf_missing_layout_name', __( 'A layout name or label is required.', 'wp-command-center' ) );
		}

		$layout_key   = 'layout_' . uniqid();
		$before       = [ 'layouts' => $f['layouts'] ?? [] ];
		$f['layouts'] = is_array( $f['layouts'] ?? null ) ? $f['layouts'] : [];

		$f['layouts'][ $layout_key ] = [
			'key'        => $layout_key,
			'name'       => $name,
			'label'      => $label,
			'display'    => in_array( (string) ( $p['display'] ?? 'block' ), [ 'block', 'table', 'row' ], true ) ? (string) ( $p['display'] ?? 'block' ) : 'block',
			'sub_fields' => [],
			'min'        => '',
			'max'        => '',
		];

		if ( ! acf_update_field( $f ) ) {
			return $this->error( 'wpcc_acf_layout_create_failed', __( 'Failed to create layout.', 'wp-command-center' ) );
		}

		// Optional inline sub-fields for the new layout.
		$sub_created = [];
		if ( ! empty( $p['sub_fields'] ) && is_array( $p['sub_fields'] ) ) {
			foreach ( $p['sub_fields'] as $sf ) {
				if ( ! is_array( $sf ) ) {
					continue;
				}
				$sf['parent']        = $field_key;
				$sf['parent_layout'] = $layout_key;
				unset( $sf['group_id'] );
				$r = $this->field_create( $sf, $cx );
				if ( isset( $r['field_key'] ) ) {
					$sub_created[] = $r['field_key'];
				}
			}
		}

		$rollback_id = $this->store_rollback( $field_key, 'layout_create', $before, $cx );
		$this->audit->record( 'acf.layout.created', [ 'field_key' => $field_key, 'layout_key' => $layout_key, 'name' => $name ] );

		return [ 'action' => 'acf_layout_create', 'field_key' => $field_key, 'layout_key' => $layout_key, 'name' => $name, 'label' => $label, 'sub_fields' => $sub_created, 'rollback_id' => $rollback_id ];
	}

	private function layout_update( array $p, array $cx ): array {
		$field_key  = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$layout_key = sanitize_text_field( (string) ( $p['layout_key'] ?? '' ) );
		$f = acf_get_field( $field_key );
		if ( ! $f || 'flexible_content' !== ( $f['type'] ?? '' ) ) {
			return $this->error( 'wpcc_acf_not_flexible', __( 'Flexible_content field not found.', 'wp-command-center' ) );
		}

		$layouts = is_array( $f['layouts'] ?? null ) ? $f['layouts'] : [];
		$target  = null;
		foreach ( $layouts as $lk => $lay ) {
			if ( $lk === $layout_key || ( $lay['key'] ?? '' ) === $layout_key ) {
				$target = $lk;
				break;
			}
		}
		if ( null === $target ) {
			return $this->error( 'wpcc_acf_layout_not_found', __( 'Layout not found on this field.', 'wp-command-center' ) );
		}

		$before = [ 'layouts' => $layouts ];
		if ( isset( $p['label'] ) ) {
			$layouts[ $target ]['label'] = sanitize_text_field( (string) $p['label'] );
		}
		if ( isset( $p['name'] ) ) {
			$layouts[ $target ]['name'] = sanitize_title( (string) $p['name'] );
		}
		if ( isset( $p['display'] ) && in_array( (string) $p['display'], [ 'block', 'table', 'row' ], true ) ) {
			$layouts[ $target ]['display'] = (string) $p['display'];
		}
		$f['layouts'] = $layouts;

		if ( ! acf_update_field( $f ) ) {
			return $this->error( 'wpcc_acf_layout_update_failed', __( 'Failed to update layout.', 'wp-command-center' ) );
		}

		$rollback_id = $this->store_rollback( $field_key, 'layout_update', $before, $cx );
		$this->audit->record( 'acf.layout.updated', [ 'field_key' => $field_key, 'layout_key' => $layout_key ] );

		return [ 'action' => 'acf_layout_update', 'field_key' => $field_key, 'layout_key' => $layout_key, 'rollback_id' => $rollback_id ];
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

		// Accept a single rule { param, operator, value } or an array of rules
		// (an AND group). ACF location = list of OR-groups; each OR-group is a
		// list of AND-rules; each rule is an assoc array. Build a valid OR-group
		// (a malformed rule here corrupts ACF's compatibility migration).
		$raw = $p['rules'] ?? [];
		$rule_list = isset( $raw['param'] ) ? [ $raw ] : ( is_array( $raw ) ? $raw : [] );
		$and_group = [];
		foreach ( $rule_list as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['param'] ) ) continue;
			$and_group[] = [
				'param'    => sanitize_text_field( (string) $r['param'] ),
				'operator' => sanitize_text_field( (string) ( $r['operator'] ?? '==' ) ),
				'value'    => sanitize_text_field( (string) ( $r['value'] ?? '' ) ),
			];
		}
		if ( empty( $and_group ) ) {
			return $this->error( 'wpcc_acf_invalid_location', __( 'A location rule { param, operator, value } is required.', 'wp-command-center' ) );
		}

		$before   = $g['location'] ?? [];
		$location = is_array( $before ) ? $before : [];
		$location[] = $and_group;
		acf_update_field_group( array_merge( $g, [ 'location' => $location ] ) );
		$this->store_rollback( $id, 'location_assign', [ 'location' => $before ], $cx );
		return [ 'action' => 'acf_location_assign', 'group_id' => $id, 'location' => $location ];
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

		// PROGRAM-4.9 — field-scoped, drift-aware, existence-faithful whole-field delta. The ACF
		// value (scalar or a WHOLE nested array) is captured atomically (never decomposed) and
		// stored as a v2 record in PostMetaRollbackStore on the post. Replaces the unconditional
		// whole-value restore in the shared option (which clobbered later edits and never
		// re-cleared an absent field). Legacy option value records still restore via rollback().
		$acc   = new AcfValueAccessor( $key );
		$prior = RollbackDelta::capture( $acc, $post_id, [ 'value' ] );
		$value = $p['value'] ?? null;
		update_field( $key, $value, $post_id );
		$after = [ 'value' => $acc->read_field( $post_id, 'value' ) ];
		$rid   = wp_generate_uuid4();
		$rec   = RollbackDelta::build_record( [ 'value' ], $prior, $after, $cx, [
			'id' => $rid, 'post_id' => $post_id, 'field_key' => $key, 'action' => 'value_update',
		] );
		( new PostMetaRollbackStore( self::VALUE_RB_PREFIX ) )->persist( $post_id, $rid, $rec );

		$this->audit->record( 'acf.value.updated', [ 'post_id' => $post_id, 'field_key' => $key ] );
		return [ 'action' => 'acf_value_update', 'post_id' => $post_id, 'field_key' => $key, 'rollback_id' => $rid ];
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

		// PROGRAM-4.9 — value_update v2 delta records live in postmeta (per post), resolved by id.
		$store    = new PostMetaRollbackStore( self::VALUE_RB_PREFIX );
		$resolved = $store->resolve( $rid );
		if ( null !== $resolved ) {
			return $this->rollback_value_delta( $store, $rid, $resolved );
		}

		// Legacy option records (pre-P4.9 values + all definition records).
		$rollbacks = get_option( 'wpcc_acf_rollbacks', [] );
		$rec = null; $idx = null;
		foreach ( $rollbacks as $i => $r ) { if ( ( $r['id'] ?? null ) === $rid ) { $rec = $r; $idx = $i; break; } }
		if ( ! $rec ) return $this->error( 'wpcc_rollback_not_found', __( 'Rollback not found.', 'wp-command-center' ) );
		if ( ! empty( $rec['rollback_applied'] ) ) return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'wp-command-center' ) );
		$eid   = $rec['entity_id'];
		$act   = $rec['action'];
		$before = (array) $rec['before_state'];

		// PROGRAM-4.9 — json_import is not faithfully reversible (lossy summary, no restore path);
		// report honestly instead of a phantom clean success.
		if ( 'json_import' === $act ) {
			return $this->rollback_unsupported( __( 'ACF JSON import cannot be automatically rolled back.', 'wp-command-center' ) );
		}

		// PROGRAM-4.9 — fingerprint drift guard for definition update-in-place actions (new
		// records only; legacy records without __after_fp keep the prior unconditional restore).
		// Refuse on drift — never clobber a newer external definition edit.
		if ( in_array( $act, self::FP_GUARDED, true ) && isset( $before['__after_fp'] ) ) {
			if ( $this->definition_fingerprint( (string) $eid, $act ) !== (string) $before['__after_fp'] ) {
				return $this->rollback_conflict( $rid, __( 'ACF definition changed since this update was applied; rollback skipped to avoid clobbering the newer change.', 'wp-command-center' ) );
			}
		}
		unset( $before['__after_fp'] ); // never feed the guard marker into acf_update_*

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
		} elseif ( in_array( $act, [ 'layout_create', 'layout_update' ], true ) ) {
			$f = acf_get_field( $eid );
			if ( $f ) { $f['layouts'] = $before['layouts'] ?? []; acf_update_field( $f ); }
		}
		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_acf_rollbacks', $rollbacks );
		return [ 'action' => 'acf_rollback', 'rollback_id' => $rid ];
	}

	/**
	 * Internal rollback-action names stored in records (and used by rollback()).
	 * Distinct from the public acf_* operation names that ACFRegistry maps, so
	 * rollback is actually recorded (a prior name mismatch silently disabled it).
	 */
	private const ROLLBACKABLE = [
		'group_create', 'group_update', 'group_delete', 'field_create', 'field_update',
		'field_delete', 'location_assign', 'location_remove', 'value_update', 'json_import',
		'layout_create', 'layout_update',
	];

	private function store_rollback( string $id, string $action, array $before, array $cx ): string {
		if ( ! in_array( $action, self::ROLLBACKABLE, true ) ) return '';
		// PROGRAM-4.9 — capture an apply-time fingerprint of the (post-write) live definition for
		// update-in-place actions, so rollback can refuse on drift instead of clobbering. Runs
		// here because store_rollback is invoked immediately after the acf_update_* mutation.
		if ( in_array( $action, self::FP_GUARDED, true ) ) {
			$before['__after_fp'] = $this->definition_fingerprint( $id, $action );
		}
		$rollbacks = get_option( 'wpcc_acf_rollbacks', [] );
		$rid = wp_generate_uuid4();
		$rollbacks[] = [ 'id' => $rid, 'entity_id' => $id, 'action' => $action, 'before_state' => $before, 'rollback_applied' => false, 'created_at' => time(),
			'session_id' => $cx['session_id'] ?? null, 'task_id' => $cx['task_id'] ?? null ];
		if ( count( $rollbacks ) > 200 ) $rollbacks = array_slice( $rollbacks, -200 );
		update_option( 'wpcc_acf_rollbacks', $rollbacks );
		return $rid;
	}

	// ── PROGRAM-4.9 — value-delta rollback + definition drift guard ──────────────

	/**
	 * Restore a value_update v2 delta record (whole-field, drift-aware, existence-faithful).
	 * Marks applied only on a complete restore; a drift conflict is an error envelope that
	 * stays retryable (never clobbers a newer change).
	 *
	 * @param array{entity_id:mixed,record:array<string,mixed>} $resolved
	 */
	private function rollback_value_delta( PostMetaRollbackStore $store, string $rid, array $resolved ): array {
		$rec     = $resolved['record'];
		$post_id = (int) ( $resolved['entity_id'] ?? ( $rec['post_id'] ?? 0 ) );
		if ( ! empty( $rec['rollback_applied'] ) ) {
			return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'wp-command-center' ) );
		}
		$key    = (string) ( $rec['field_key'] ?? '' );
		$fields = (array) ( $rec['fields'] ?? [] );
		$o      = RollbackDelta::restore( new AcfValueAccessor( $key ), $post_id, $fields );
		$this->audit->record( 'acf.value.restored', [ 'post_id' => $post_id, 'field_key' => $key, 'status' => $o['status'] ] );

		if ( 'complete' === $o['status'] ) {
			$rec['rollback_applied'] = true;
			$rec['applied_at']       = time();
			$store->mark_applied( $post_id, $rid, $rec );
			return [ 'action' => 'acf_rollback', 'rollback_id' => $rid, 'post_id' => $post_id, 'field_key' => $key, 'status' => 'complete', 'restored' => true, 'reversible' => true ];
		}
		// drift → conflict: not applied, retryable, honest (never a clean success).
		return [ 'action' => 'acf_rollback', 'rollback_id' => $rid, 'post_id' => $post_id, 'field_key' => $key,
			'error' => true, 'code' => 'wpcc_rollback_conflict', 'status' => $o['status'], 'restored' => false,
			'reversible' => false, 'skipped_fields' => $o['skipped'] ];
	}

	/**
	 * Canonical fingerprint of a live ACF definition (group for group/location actions, field
	 * for field/layout actions). Drops volatile keys so an unchanged definition is stable.
	 * Returns '' when the definition cannot be read (treated as drift → safe refusal).
	 */
	private function definition_fingerprint( string $id, string $action ): string {
		if ( ! function_exists( 'acf_get_field_group' ) ) return '';
		if ( str_starts_with( $action, 'group' ) || str_starts_with( $action, 'location' ) ) {
			$def = acf_get_field_group( $id );
		} else { // field_update, layout_update
			$def = function_exists( 'acf_get_field' ) ? acf_get_field( $id ) : null;
		}
		if ( ! is_array( $def ) ) return '';
		return sha1( (string) wp_json_encode( $this->canonicalize_def( $def ) ) );
	}

	/**
	 * Recursive ksort + drop volatile keys, for a deterministic definition fingerprint.
	 *
	 * @param mixed $v
	 * @return mixed
	 */
	private function canonicalize_def( $v ) {
		if ( is_array( $v ) ) {
			ksort( $v );
			foreach ( $v as $k => $vv ) {
				if ( in_array( $k, [ 'ID', 'id', 'menu_order', 'modified', '_valid' ], true ) ) {
					unset( $v[ $k ] );
					continue;
				}
				$v[ $k ] = $this->canonicalize_def( $vv );
			}
		}
		return $v;
	}

	/** Honest drift-conflict envelope (error → executor success boolean is truthful). */
	private function rollback_conflict( string $rid, string $message ): array {
		return [ 'action' => 'acf_rollback', 'rollback_id' => $rid, 'error' => true, 'code' => 'wpcc_rollback_conflict',
			'status' => 'conflict', 'restored' => false, 'reversible' => false, 'message' => $message ];
	}

	/** Honest irreversible/unsupported envelope. */
	private function rollback_unsupported( string $message ): array {
		return [ 'action' => 'acf_rollback', 'error' => true, 'code' => 'wpcc_rollback_unsupported',
			'reversible' => false, 'message' => $message ];
	}

	// ── STEP 92 helpers ──────────────────────────────────────────

	private function parent_exists( string $parent ): bool {
		return (bool) ( acf_get_field_group( $parent ) || acf_get_field( $parent ) );
	}

	private function boolish( $v ): bool {
		if ( is_bool( $v ) ) return $v;
		if ( is_string( $v ) ) return in_array( strtolower( trim( $v ) ), [ '1', 'true', 'yes', 'on' ], true );
		return ! empty( $v );
	}

	/** Sanitize a field-config blob, blocking keys the runtime controls itself. */
	private function sanitize_config( array $config ): array {
		$blocked = [ 'key', 'parent', 'parent_layout', 'type', 'name', 'label', 'sub_fields', 'layouts' ];
		$out = [];
		foreach ( $config as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( '' === $k || in_array( $k, $blocked, true ) ) continue;
			$out[ $k ] = $this->sanitize_deep( $v );
		}
		return $out;
	}

	private function sanitize_deep( $v ) {
		if ( is_array( $v ) ) {
			$r = [];
			foreach ( $v as $k => $vv ) {
				$r[ is_int( $k ) ? $k : sanitize_text_field( (string) $k ) ] = $this->sanitize_deep( $vv );
			}
			return $r;
		}
		if ( is_bool( $v ) ) return $v ? 1 : 0;
		if ( is_scalar( $v ) ) return sanitize_text_field( (string) $v );
		return '';
	}

	private function summarize_group( array $g ): array {
		return [ 'key' => $g['key'] ?? '', 'title' => $g['title'] ?? '', 'active' => $g['active'] ?? true, 'location' => count( $g['location'] ?? [] ), 'field_count' => 0 ];
	}

	private function summarize_field( array $f ): array {
		return [ 'key' => $f['key'] ?? '', 'label' => $f['label'] ?? '', 'name' => $f['name'] ?? '', 'type' => $f['type'] ?? 'text', 'required' => $f['required'] ?? false, 'parent' => $f['parent'] ?? '' ];
	}

	/**
	 * F3.2 — Recursively serialize a field including its nested structure so an
	 * agent can read back what it created. Repeater/group fields expose their
	 * `sub_fields`; flexible_content exposes `layouts`, each with its own
	 * `sub_fields`. Sub-fields are stored as separate field posts parented to the
	 * container, so acf_get_fields() is passed the field ARRAY (which carries the
	 * post ID) to resolve them — a field KEY string resolves only field groups.
	 *
	 * Read-only: used by acf_group_get / acf_field_get / acf_field_list. The flat
	 * summarize_field() is deliberately left for rollback before-state, which is
	 * fed back to acf_update_field() and must stay a plain field array.
	 *
	 * @param array<string,mixed> $f     ACF field array.
	 * @param int                 $depth Recursion guard against pathological nesting.
	 * @return array<string,mixed>
	 */
	private function detail_field( array $f, int $depth = 0 ): array {
		$out  = $this->summarize_field( $f );
		$type = (string) ( $f['type'] ?? '' );

		if ( $depth >= self::MAX_FIELD_DEPTH || ! function_exists( 'acf_get_fields' ) ) {
			return $out;
		}

		// Repeater / group — children parented directly to this field.
		if ( in_array( $type, [ 'repeater', 'group' ], true ) ) {
			$children          = acf_get_fields( $f ) ?: [];
			$out['sub_fields'] = array_values( array_map(
				fn( $sf ) => $this->detail_field( $sf, $depth + 1 ),
				$children
			) );
			return $out;
		}

		// Flexible content — children carry parent_layout; group them under each
		// layout declared on the field, preserving layout metadata.
		if ( 'flexible_content' === $type ) {
			$children  = acf_get_fields( $f ) ?: [];
			$by_layout = [];
			foreach ( $children as $sf ) {
				$lk                 = (string) ( $sf['parent_layout'] ?? '' );
				$by_layout[ $lk ][] = $this->detail_field( $sf, $depth + 1 );
			}
			$out['layouts'] = [];
			foreach ( (array) ( $f['layouts'] ?? [] ) as $lkey => $layout ) {
				$key              = (string) ( $layout['key'] ?? $lkey );
				$out['layouts'][] = [
					'key'        => $key,
					'name'       => $layout['name'] ?? '',
					'label'      => $layout['label'] ?? '',
					'display'    => $layout['display'] ?? 'block',
					'sub_fields' => $by_layout[ $key ] ?? [],
				];
			}
		}

		return $out;
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
