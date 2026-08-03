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
			return $this->error( 'wpcc_acf_inactive', __( 'Advanced Custom Fields is not active.', 'ai-command-center' ) );
		}
		$a = (string) ( $payload['action'] ?? '' );
		if ( 'acf_describe' === $a ) {
			return $this->describe();
		}
		if ( ! in_array( $a, ACFRegistry::ACTIONS, true ) ) {
			return $this->error(
				'wpcc_invalid_acf_action',
				sprintf(
					/* translators: 1: the invalid action, 2: comma-separated valid actions */
					__( 'Invalid ACF action "%1$s". Valid actions: %2$s. Call action="acf_describe" for details.', 'ai-command-center' ),
					$a,
					implode( ', ', ACFRegistry::ACTIONS )
				),
				[ 'valid_actions' => ACFRegistry::ACTIONS ]
			);
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
			ACFRegistry::ACTION_VALUE_SET        => $this->value_set( $payload, $context ),
			ACFRegistry::ACTION_BULK_VALUE_UPDATE => $this->bulk_value_update( $payload, $context ),
			ACFRegistry::ACTION_INVENTORY         => $this->inventory( $payload ),
			ACFRegistry::ACTION_LAYOUT_CREATE     => $this->layout_create( $payload, $context ),
			ACFRegistry::ACTION_LAYOUT_UPDATE     => $this->layout_update( $payload, $context ),
			ACFRegistry::ACTION_LAYOUT_USAGE      => $this->layout_usage( $payload ),
			default => $this->error( 'wpcc_unknown_acf_action', __( 'Unknown ACF action.', 'ai-command-center' ) ),
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
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
		$fields = acf_get_fields( $id );
		$this->audit->record( 'acf.group.get', [ 'group_id' => $id ] );
		return [ 'action' => 'acf_group_get', 'group' => $this->summarize_group( $g ), 'fields' => array_map( [ $this, 'detail_field' ], $fields ?: [] ) ];
	}

	private function group_create( array $p, array $cx ): array {
		$title = sanitize_text_field( (string) ( $p['title'] ?? '' ) );
		if ( '' === $title ) return $this->error( 'wpcc_missing_title', __( 'Title is required.', 'ai-command-center' ) );
		$g = [ 'title' => $title, 'fields' => [], 'location' => [], 'menu_order' => 0, 'position' => 'normal', 'style' => 'default', 'label_placement' => 'top', 'instruction_placement' => 'label', 'hide_on_screen' => '', 'active' => true,
			'key' => 'group_' . uniqid(), ];
		if ( isset( $p['location'] ) ) $g['location'] = (array) $p['location'];
		$result = acf_update_field_group( $g );
		if ( ! $result ) return $this->error( 'wpcc_group_create_failed', __( 'Failed to create field group.', 'ai-command-center' ) );
		$id = $g['key'];
		$this->store_rollback( $id, 'group_create', [], $cx );
		$this->audit->record( 'acf.group.created', [ 'group_id' => $id, 'title' => $title ] );
				AcfLocalJson::sync_for_group( (string) $g['key'], 'acf_group_create' );
		return [ 'action' => 'acf_group_create', 'group_id' => $id, 'key' => $g['key'], 'title' => $title ];
	}

	private function group_update( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
		// STEP 102.6 (F-4): store the COMPLETE original group as the rollback before-state.
		// summarize_group() was lossy (location collapsed to an int count, no post ID),
		// so rollback()'s acf_update_field_group( $before ) could not faithfully restore.
		// $g is the unmutated original here (the title/active edits below copy-on-write
		// into $g, not $before), so this preserves the full group for exact restoration.
		$before = $g;
		if ( isset( $p['title'] ) ) $g['title'] = sanitize_text_field( (string) $p['title'] );
		if ( isset( $p['active'] ) ) $g['active'] = (bool) $p['active'];
		$result = acf_update_field_group( array_merge( $g, $p ) );
		if ( ! $result ) return $this->error( 'wpcc_group_update_failed', __( 'Failed to update field group.', 'ai-command-center' ) );
		$this->store_rollback( $id, 'group_update', $before, $cx );
		$this->audit->record( 'acf.group.updated', [ 'group_id' => $id ] );
				AcfLocalJson::sync_for_group( (string) ( $g['key'] ?? $id ), 'acf_group_update' );
		return [ 'action' => 'acf_group_update', 'group_id' => $id ];
	}

	private function group_delete( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
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
				__( 'Field group still exists after delete — it is defined in a read-only local JSON/PHP source (e.g. theme acf-json) and was not removed.', 'ai-command-center' )
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
		if ( is_file( $file ) && wp_is_writable( $file ) ) {
			wp_delete_file( $file );
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
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );

		/*
		 * acf_duplicate_field_group() takes an ID, key or name — NOT the field group
		 * array. Passing the array made ACF try to use it as an array offset, so
		 * every duplicate attempt died with "Illegal offset type in isset or empty"
		 * and the caller got a handler exception instead of a new group. It also
		 * RETURNS the new group array, so the old code stored an array where a
		 * rollback id was expected.
		 */
		$dup = acf_duplicate_field_group( (string) ( $g['key'] ?? $id ) );
		if ( ! is_array( $dup ) || empty( $dup['key'] ) ) {
			return $this->error( 'wpcc_acf_duplicate_failed', __( 'The field group could not be duplicated.', 'ai-command-center' ) );
		}

		$new_id = (string) $dup['key'];
		$this->store_rollback( $new_id, 'group_create', [], $cx );
		AcfLocalJson::sync_for_group( (string) ( $dup['key'] ?? '' ), 'acf_group_duplicate' );
		return [
			'action'      => 'acf_group_duplicate',
			'group_id'    => $new_id,
			'title'       => (string) ( $dup['title'] ?? '' ),
			'original_id' => $id,
		];
	}

	private function group_activate( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
		acf_update_field_group( array_merge( $g, [ 'active' => true ] ) );
		// acf_update_field_group() fires ACF's own JSON writer, which appends
		// acf_get_fields( $g ) — and $g came from a key lookup, so on a site with a
		// stale file that hook can write the group back with NO fields. Rebuild from
		// the database afterwards so a toggle can never empty a good file.
		AcfLocalJson::sync_for_group( (string) ( $g['key'] ?? $id ), 'acf_group_activate' );
		return [ 'action' => 'acf_group_activate', 'group_id' => $id ];
	}

	private function group_deactivate( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
		acf_update_field_group( array_merge( $g, [ 'active' => false ] ) );
		AcfLocalJson::sync_for_group( (string) ( $g['key'] ?? $id ), 'acf_group_deactivate' );
		return [ 'action' => 'acf_group_deactivate', 'group_id' => $id ];
	}

	private function field_list( array $p ): array {
		$group_id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		if ( '' !== $group_id ) {
			$fields = $this->group_fields( $group_id );
		} else {
			$fields = [];
			foreach ( acf_get_field_groups() as $g ) {
				$fields = array_merge( $fields, $this->group_fields( (string) ( $g['key'] ?? '' ) ) );
			}
		}

		$counts = self::count_field_tree( $fields );
		$items  = array_map( [ $this, 'detail_field' ], $fields );

		return array_merge( [
			'action' => 'acf_field_list',
			'fields' => $items,
			// `total` is the number of fields IN the group. Sub-fields of a
			// repeater/group/flexible layout are reported separately rather than
			// inflating it — a repeater with two sub-fields is one field, not three.
			'total'  => $counts['top_level_fields'],
		], $counts );
	}

	private function field_get( array $p ): array {
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$f = acf_get_field( $key );
		if ( ! $f ) return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'ai-command-center' ) );
		return [ 'action' => 'acf_field_get', 'field' => $this->detail_field( $f ) ];
	}

	/**
	 * Numeric post ID for a field-group or field KEY.
	 *
	 * acf_update_field() only links a new field when `parent` is the numeric post
	 * ID of the owning group/field; a key string leaves post_parent = 0 and the
	 * field belongs to nothing.
	 *
	 * Resolving that with acf_get_field_group()/acf_get_field() alone is not
	 * enough. When a site has ACF local JSON enabled — standard practice for
	 * agencies, and the case on the site this was found on — those functions
	 * return the JSON copy, whose `ID` is 0. The old guard tested `$grp['ID']`,
	 * got 0, fell through, and stored the key. Every field created through WPCC on
	 * such a site was therefore orphaned: acf_get_fields() returned the same flat
	 * pile of parentless fields for every group, wp-admin showed the group empty,
	 * and the acf-json file kept "fields": [].
	 *
	 * So fall back to the database, where ACF stores the key as post_name.
	 */
	private function parent_post_id( string $key ): int {
		foreach ( [ 'acf_get_field_group', 'acf_get_field' ] as $fn ) {
			$obj = $fn( $key );
			if ( is_array( $obj ) && ! empty( $obj['ID'] ) ) {
				return (int) $obj['ID'];
			}
		}

		foreach ( [ 'acf-field-group', 'acf-field' ] as $post_type ) {
			$found = get_posts( [
				'post_type'        => $post_type,
				'name'             => $key,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			] );
			if ( ! empty( $found ) ) {
				return (int) $found[0];
			}
		}

		return 0;
	}

	/**
	 * A field group array whose `ID` is the stored post ID.
	 *
	 * acf_get_field_group() may return the LOCAL JSON copy of a group, whose `ID`
	 * is 0. acf_get_fields() cannot collect a group's fields from that, so every
	 * read that starts from a group key — field_list, inventory, json_export —
	 * silently returned an empty set on a site with ACF local JSON enabled. That
	 * is standard practice for agencies, so it was the common case, not the edge.
	 *
	 * @return array<string,mixed> Empty array when the group cannot be resolved.
	 */
	private function group_with_id( string $group_key ): array {
		$group = acf_get_field_group( $group_key );
		if ( ! is_array( $group ) ) {
			return [];
		}
		if ( empty( $group['ID'] ) ) {
			$group['ID'] = $this->parent_post_id( $group_key );
		}
		return empty( $group['ID'] ) ? [] : $group;
	}

	/**
	 * Every field of a group, resolved through the stored post ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function group_fields( string $group_key ): array {
		$group = $this->group_with_id( $group_key );
		if ( [] === $group ) {
			return [];
		}
		$fields = acf_get_fields( $group );
		return is_array( $fields ) ? $fields : [];
	}

	/**
	 * Count a field tree honestly.
	 *
	 * A repeater with two sub-fields is ONE field in the group, not three. The
	 * previous `total` counted whatever acf_get_fields() returned, which invited
	 * "this group has more fields than it does".
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @return array{top_level_fields:int,nested_sub_fields:int,total_nodes:int}
	 */
	private static function count_field_tree( array $fields ): array {
		$nested = 0;

		$walk = static function ( array $nodes ) use ( &$walk, &$nested ): void {
			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$children = [];
				if ( ! empty( $node['sub_fields'] ) && is_array( $node['sub_fields'] ) ) {
					$children = $node['sub_fields'];
				}
				foreach ( (array) ( $node['layouts'] ?? [] ) as $layout ) {
					if ( is_array( $layout ) && ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$children = array_merge( $children, $layout['sub_fields'] );
					}
				}
				if ( [] !== $children ) {
					$nested += count( $children );
					$walk( $children );
				}
			}
		};
		$walk( $fields );

		$top = count( $fields );
		return [
			'top_level_fields'  => $top,
			'nested_sub_fields' => $nested,
			'total_nodes'       => $top + $nested,
		];
	}

	/**
	 * Rebuild the owning group's acf-json after a field-level change.
	 *
	 * The database is the source of truth; AcfLocalJson resolves the group by its
	 * stored post ID so it can never read back the stale file it is replacing.
	 */
	private function sync_json_for_field( string $field_key, string $because ): void {
		AcfLocalJson::sync_for_group( $this->group_key_for_field( $field_key ), $because );
	}

	/**
	 * The owning field-group KEY for a field key, resolved from the database.
	 *
	 * Deliberately not via acf_get_field(). Once a group has a local JSON file, ACF
	 * answers field lookups from it — so a field the STALE file does not yet know
	 * about cannot be found, which is precisely the field whose creation should have
	 * triggered the rebuild. That chicken-and-egg left field_update, field_delete and
	 * layout writes silently un-synced.
	 *
	 * ACF stores a field's key as post_name and its owner as post_parent, so walking
	 * the post tree answers the question without consulting any cache.
	 */
	private function group_key_for_field( string $field_key ): string {
		if ( '' === $field_key ) {
			return '';
		}

		$found = get_posts( [
			'post_type'        => 'acf-field',
			'name'             => $field_key,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'suppress_filters' => false,
		] );
		if ( empty( $found ) ) {
			return '';
		}

		$parent = (int) $found[0]->post_parent;
		$guard  = 0;
		while ( $parent > 0 && $guard++ < 10 ) {
			$post = get_post( $parent );
			if ( ! $post ) {
				return '';
			}
			if ( 'acf-field-group' === $post->post_type ) {
				return (string) $post->post_name;
			}
			if ( 'acf-field' !== $post->post_type ) {
				return '';
			}
			$parent = (int) $post->post_parent;
		}

		return '';
	}

	private function field_create( array $p, array $cx ): array {
		// Parent may be a field group, a repeater/group field key, or — together
		// with parent_layout — a flexible-content field key.
		$parent = sanitize_text_field( (string) ( $p['parent'] ?? $p['group_id'] ?? '' ) );
		if ( '' === $parent ) {
			return $this->error( 'wpcc_acf_missing_parent', __( 'group_id or parent is required.', 'ai-command-center' ) );
		}
		if ( ! $this->parent_exists( $parent ) ) {
			return $this->error( 'wpcc_acf_parent_not_found', __( 'Parent field group or field not found.', 'ai-command-center' ) );
		}

		$type = sanitize_key( (string) ( $p['type'] ?? 'text' ) );
		if ( ! in_array( $type, ACFRegistry::FIELD_TYPES, true ) ) {
			return $this->error( 'wpcc_acf_unsupported_field_type', sprintf( /* translators: %s: value */ __( 'Unsupported field type: %s', 'ai-command-center' ), esc_html( $type ) ) );
		}

		// acf_update_field only links a field when the parent is the numeric post
		// ID of the parent group/field — a KEY string leaves it orphaned
		// (post_parent = 0), which corrupts ACF. Resolve the key to its post ID.
		$parent_ref = $this->parent_post_id( $parent );
		if ( 0 === $parent_ref ) {
			return $this->error(
				'wpcc_acf_parent_unresolved',
				sprintf(
					/* translators: %s: the field group or field key that could not be resolved */
					__( 'Could not resolve "%s" to a stored field group or field, so the new field would not be attached to anything. Nothing was created.', 'ai-command-center' ),
					$parent
				)
			);
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
			return $this->error( 'wpcc_field_create_failed', __( 'Failed to create field.', 'ai-command-center' ) );
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

		$this->sync_json_for_field( $key, 'acf_field_create' );
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
		if ( ! $f ) return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'ai-command-center' ) );
		$before = $this->summarize_field( $f );
		if ( isset( $p['label'] ) ) $f['label'] = sanitize_text_field( (string) $p['label'] );
		if ( isset( $p['type'] ) ) $f['type'] = sanitize_key( (string) $p['type'] );
		if ( isset( $p['instructions'] ) ) $f['instructions'] = sanitize_textarea_field( (string) $p['instructions'] );

		/*
		 * Preserve the STORED parent, and take the stored post ID with it.
		 *
		 * acf_get_field() answers from the local JSON registration once a group has a
		 * file, and that copy carries no post ID and a `parent` that is a key string or
		 * 0. Writing it straight back made acf_update_field() insert a fresh, PARENTLESS
		 * row: measured on staging, renaming a field left its post with parent 0, so the
		 * field vanished from its group while reporting success. This is the same defect
		 * fixed for field_create in 3b6fe49, which the update path still had.
		 */
		$stored = get_posts( [
			'post_type'        => 'acf-field',
			'name'             => $key,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'suppress_filters' => false,
		] );
		if ( ! empty( $stored ) ) {
			$f['ID']     = (int) $stored[0]->ID;
			$f['parent'] = (int) $stored[0]->post_parent;
		}

		acf_update_field( $f );
		$this->sync_json_for_field( $key, 'acf_field_update' );
		$this->store_rollback( $key, 'field_update', $before, $cx );
		$this->audit->record( 'acf.field.updated', [ 'field_key' => $key ] );
		return [ 'action' => 'acf_field_update', 'field_key' => $key ];
	}

	private function field_delete( array $p, array $cx ): array {
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$f = acf_get_field( $key );
		if ( ! $f ) return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'ai-command-center' ) );
		$before = $this->summarize_field( $f );
		$this->store_rollback( $key, 'field_delete', $before, $cx );
		// Resolved before the delete — afterwards the field is gone and its owning
		// group can no longer be walked to.
		$owning_group = $this->group_key_for_field( $key );

		/*
		 * Delete by STORED post ID, not by key. acf_delete_field() resolves a key
		 * through acf_get_field(), which on a site with local JSON returns the file's
		 * copy — ID 0 — so the delete matched nothing and silently removed nothing
		 * while reporting success. Measured on staging: field count unchanged in the
		 * database after a delete that returned a rollback id.
		 */
		$stored = get_posts( [
			'post_type'        => 'acf-field',
			'name'             => $key,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		] );
		if ( ! empty( $stored ) ) {
			acf_delete_field( (int) $stored[0] );
		} else {
			acf_delete_field( $key );
		}
		AcfLocalJson::sync_for_group( $owning_group, 'acf_field_delete' );
		$this->audit->record( 'acf.field.deleted', [ 'field_key' => $key ] );
		return [ 'action' => 'acf_field_delete', 'field_key' => $key ];
	}

	// ── STEP 92 — flexible-content layouts ───────────────────────

	/**
	 * Restore a field array's stored identity before writing it back.
	 *
	 * acf_get_field() answers from the local JSON registration once a group has a
	 * file, and that copy carries no post ID and a `parent` that is a key string or 0.
	 * Writing it back makes acf_update_field() insert a fresh, PARENTLESS row — the
	 * field silently leaves its group while the call reports success. Measured on
	 * staging for the rename path; the layout paths reload the same way.
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>
	 */
	private function with_stored_identity( array $field, string $field_key ): array {
		$stored = get_posts( [
			'post_type'        => 'acf-field',
			'name'             => $field_key,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'suppress_filters' => false,
		] );
		if ( ! empty( $stored ) ) {
			$field['ID']     = (int) $stored[0]->ID;
			$field['parent'] = (int) $stored[0]->post_parent;
		}
		return $field;
	}

	private function layout_create( array $p, array $cx ): array {
		$field_key = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$f = acf_get_field( $field_key );
		if ( ! $f ) {
			return $this->error( 'wpcc_acf_field_not_found', __( 'Field not found.', 'ai-command-center' ) );
		}
		if ( 'flexible_content' !== ( $f['type'] ?? '' ) ) {
			return $this->error( 'wpcc_acf_not_flexible', __( 'Layouts can only be added to a flexible_content field.', 'ai-command-center' ) );
		}
		$f = $this->with_stored_identity( $f, $field_key );

		$name  = sanitize_title( (string) ( $p['name'] ?? $p['label'] ?? 'layout' ) );
		$label = sanitize_text_field( (string) ( $p['label'] ?? $name ) );
		if ( '' === $name ) {
			return $this->error( 'wpcc_acf_missing_layout_name', __( 'A layout name or label is required.', 'ai-command-center' ) );
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
			return $this->error( 'wpcc_acf_layout_create_failed', __( 'Failed to create layout.', 'ai-command-center' ) );
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

		$this->sync_json_for_field( $field_key, 'acf_layout_create' );
		return [ 'action' => 'acf_layout_create', 'field_key' => $field_key, 'layout_key' => $layout_key, 'name' => $name, 'label' => $label, 'sub_fields' => $sub_created, 'rollback_id' => $rollback_id ];
	}

	private function layout_update( array $p, array $cx ): array {
		$field_key  = sanitize_text_field( (string) ( $p['field_key'] ?? '' ) );
		$layout_key = sanitize_text_field( (string) ( $p['layout_key'] ?? '' ) );
		$f = acf_get_field( $field_key );
		if ( ! $f || 'flexible_content' !== ( $f['type'] ?? '' ) ) {
			return $this->error( 'wpcc_acf_not_flexible', __( 'Flexible_content field not found.', 'ai-command-center' ) );
		}
		$f = $this->with_stored_identity( $f, $field_key );

		$layouts = is_array( $f['layouts'] ?? null ) ? $f['layouts'] : [];
		$target  = null;
		foreach ( $layouts as $lk => $lay ) {
			if ( $lk === $layout_key || ( $lay['key'] ?? '' ) === $layout_key ) {
				$target = $lk;
				break;
			}
		}
		if ( null === $target ) {
			return $this->error( 'wpcc_acf_layout_not_found', __( 'Layout not found on this field.', 'ai-command-center' ) );
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
			return $this->error( 'wpcc_acf_layout_update_failed', __( 'Failed to update layout.', 'ai-command-center' ) );
		}

		$rollback_id = $this->store_rollback( $field_key, 'layout_update', $before, $cx );
		$this->audit->record( 'acf.layout.updated', [ 'field_key' => $field_key, 'layout_key' => $layout_key ] );

		$this->sync_json_for_field( $field_key, 'acf_layout_update' );
		return [ 'action' => 'acf_layout_update', 'field_key' => $field_key, 'layout_key' => $layout_key, 'rollback_id' => $rollback_id ];
	}

	private function location_list( array $p ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
		return [ 'action' => 'acf_location_list', 'group_id' => $id, 'location' => $g['location'] ?? [] ];
	}

	private function location_assign( array $p, array $cx ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$g = acf_get_field_group( $id );
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );

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
			return $this->error( 'wpcc_acf_invalid_location', __( 'A location rule { param, operator, value } is required.', 'ai-command-center' ) );
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
		if ( ! $g ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
		$idx = (int) ( $p['rule_index'] ?? -1 );
		$rules = $g['location'] ?? [];
		$before = $rules;
		if ( isset( $rules[ $idx ] ) ) { unset( $rules[ $idx ] ); $rules = array_values( $rules ); }
		acf_update_field_group( array_merge( $g, [ 'location' => $rules ] ) );
		$this->store_rollback( $id, 'location_remove', [ 'location' => $before ], $cx );
		return [ 'action' => 'acf_location_remove', 'group_id' => $id, 'location' => $rules ];
	}

	private function json_status( array $p ): array {
		/*
		 * This used to count groups whose `local` flag was 'json' — that is where a
		 * group was LOADED FROM, not whether the file agrees with the database. A
		 * group with twelve fields whose file held none counted as "synced". Compare
		 * content instead, per group.
		 */
		$groups   = acf_get_field_groups();
		$synced   = 0;
		$unsynced = 0;
		$detail   = [];

		foreach ( $groups as $g ) {
			$key = (string) ( $g['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$st = AcfLocalJson::status( $key );
			if ( $st['in_sync'] ) {
				$synced++;
			} else {
				$unsynced++;
				$detail[] = [
					'group'       => $key,
					'title'       => (string) ( $g['title'] ?? '' ),
					'reason'      => $st['reason'],
					'db_fields'   => $st['db_fields'],
					'json_fields' => $st['json_fields'],
				];
			}
		}

		return [
			'action'         => 'acf_json_status',
			'local_json'     => AcfLocalJson::enabled(),
			'total_groups'   => count( $groups ),
			'synced'         => $synced,
			'unsynced'       => $unsynced,
			'out_of_sync'    => $detail,
			'json_path'      => AcfLocalJson::save_path(),
			'compared'       => 'content',
		];
	}

	private function json_export( array $p ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		if ( '' === $id ) return $this->error( 'wpcc_missing_id', __( 'Group ID is required for export.', 'ai-command-center' ) );
		// Built from the stored post ID. acf_get_fields( $key ) answers from the local
		// JSON copy once a file exists, so exporting by key exported the stale file.
		$group = AcfLocalJson::build( $id );
		if ( [] === $group ) return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
		$json = wp_json_encode( $group, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return [ 'action' => 'acf_json_export', 'group_id' => $id, 'field_count' => count( $group['fields'] ), 'json' => $json ];
	}

	private function json_import( array $p, array $cx ): array {
		$json = (string) ( $p['json'] ?? '' );
		if ( '' === $json ) return $this->error( 'wpcc_missing_json', __( 'JSON content is required.', 'ai-command-center' ) );
		$data = json_decode( $json, true );
		if ( ! $data ) return $this->error( 'wpcc_invalid_json', __( 'Invalid JSON.', 'ai-command-center' ) );
		// Store before import rollback
		$existing = isset( $data['key'] ) ? acf_get_field_group( $data['key'] ) : null;
		if ( $existing ) $this->store_rollback( $data['key'], 'json_import', $this->summarize_group( $existing ), $cx );
		// Import via ACF
		$imported = acf_import_field_group( $data );
		if ( ! $imported ) return $this->error( 'wpcc_import_failed', __( 'Failed to import field group.', 'ai-command-center' ) );
		$this->audit->record( 'acf.json.imported', [ 'group_key' => $data['key'] ?? 'unknown' ] );
		return [ 'action' => 'acf_json_import', 'imported_key' => $data['key'] ?? 'unknown' ];
	}

	private function json_sync( array $p, array $cx ): array {
		/*
		 * Two directions, and the old code did neither usefully.
		 *
		 * It skipped every group with a `local` flag — which, once a JSON file exists,
		 * is all of them — so it always reported synced_count 0. And it only ever went
		 * JSON -> database, which cannot repair the case this plugin creates: a
		 * database that is correct and a file that is stale.
		 *
		 * `direction` defaults to db_to_json, the repair an operator actually needs
		 * after making changes through WPCC. json_to_db remains available and is what
		 * ACF's own "Sync available" means.
		 */
		$direction = sanitize_key( (string) ( $p['direction'] ?? 'db_to_json' ) );
		$only      = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		$all       = filter_var( $p['all_groups'] ?? false, FILTER_VALIDATE_BOOLEAN );

		/*
		 * Refuse to touch every group unless that is explicitly what was asked for.
		 * A site-wide rewrite reaches field groups this plugin never created — the
		 * customer's own, under version control — and even a correct rewrite of those
		 * is an unrequested change to files someone else owns.
		 */
		if ( '' === $only && ! $all ) {
			return $this->error(
				'wpcc_acf_sync_scope_required',
				__( 'Pass group_id to synchronise one field group, or all_groups: true to synchronise every group on the site. Rewriting every acf-json file by default would touch groups this plugin did not create.', 'ai-command-center' )
			);
		}
		$groups    = acf_get_field_groups();
		$written   = [];
		$failed    = [];
		$imported  = 0;

		if ( 'json_to_db' === $direction ) {
			$json_files = acf_get_local_json_files();
			foreach ( $groups as $g ) {
				$key = (string) ( $g['key'] ?? '' );
				if ( '' === $key || ( '' !== $only && $key !== $only ) ) {
					continue;
				}
				$file = $json_files[ $key ] ?? null;
				if ( ! $file ) {
					continue;
				}
				$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ACF-owned artefact.
				if ( is_array( $data ) ) {
					acf_import_field_group( $data );
					$imported++;
				}
			}
			$this->audit->record( 'acf.json.synced', [ 'direction' => 'json_to_db', 'count' => $imported ] );
			return [ 'action' => 'acf_json_sync', 'direction' => 'json_to_db', 'synced_count' => $imported ];
		}

		if ( ! AcfLocalJson::enabled() ) {
			return $this->error( 'wpcc_acf_json_disabled', __( 'ACF local JSON is not enabled, or its save directory is not writable, so there is nothing to write.', 'ai-command-center' ) );
		}

		foreach ( $groups as $g ) {
			$key = (string) ( $g['key'] ?? '' );
			if ( '' === $key || ( '' !== $only && $key !== $only ) ) {
				continue;
			}
			$st = AcfLocalJson::status( $key );
			if ( $st['in_sync'] ) {
				continue;
			}
			$r = AcfLocalJson::write( $key );
			if ( $r['written'] ) {
				$written[] = [ 'group' => $key, 'fields' => $r['fields'] ];
			} else {
				$failed[] = [ 'group' => $key, 'reason' => $r['reason'] ];
			}
		}

		$this->audit->record( 'acf.json.synced', [ 'direction' => 'db_to_json', 'count' => count( $written ), 'failed' => count( $failed ) ] );

		return [
			'action'       => 'acf_json_sync',
			'direction'    => 'db_to_json',
			'synced_count' => count( $written ),
			'written'      => $written,
			'failed'       => $failed,
		];
	}

	private function json_diff( array $p ): array {
		$id = sanitize_text_field( (string) ( $p['group_id'] ?? '' ) );
		if ( '' === $id ) return $this->error( 'wpcc_missing_id', __( 'Group ID required.', 'ai-command-center' ) );
		// Reported only whether each side EXISTED, which cannot reveal a file that is
		// present and wrong — the actual failure. Compare the definitions.
		$st = AcfLocalJson::status( $id );
		if ( 'group_not_stored' === $st['reason'] ) {
			return $this->error( 'wpcc_acf_group_not_found', __( 'Field group not found.', 'ai-command-center' ) );
		}
		return [
			'action'      => 'acf_json_diff',
			'group_id'    => $id,
			'in_sync'     => $st['in_sync'],
			'reason'      => $st['reason'],
			'db_fields'   => $st['db_fields'],
			'json_fields' => $st['json_fields'],
			'json_exists' => $st['json_exists'],
			'json_path'   => AcfLocalJson::save_path() . '/' . $id . '.json',
		];
	}

	private function value_get( array $p ): array {
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? $p['field_name'] ?? '' ) );
		if ( '' === $key ) return $this->error( 'wpcc_missing_field', __( 'Field key or name is required.', 'ai-command-center' ) );

		// ISSUE 10 — route through the same selector resolution as acf_value_set so a
		// term/user/option value is read from the RIGHT object. Never silently fall
		// back to post_id 0 (which returns value:false and hides a real value).
		$object_type = sanitize_key( (string) ( $p['object_type'] ?? '' ) );
		if ( '' === $object_type && isset( $p['post_id'] ) ) {
			$object_type = 'post';
		}
		if ( '' !== $object_type ) {
			$object_id = (int) ( $p['object_id'] ?? $p['post_id'] ?? 0 );
			$selector  = $this->resolve_acf_selector( $object_type, $object_id );
			if ( is_array( $selector ) ) {
				return $selector; // error() — object params present but unresolvable
			}
		} else {
			/*
			 * No object identity supplied. In a REST/MCP request there is no "current
			 * post", so get_field() answers null — indistinguishable from a field that
			 * is genuinely empty. Reporting that as `value: null` told callers the
			 * field was empty when it held a value; the usual cause is simply the
			 * wrong parameter name. Only fall through to the global context when one
			 * actually exists (a template render), otherwise say what is missing.
			 */
			$current = function_exists( 'get_the_ID' ) ? (int) get_the_ID() : 0;
			if ( $current <= 0 ) {
				return $this->error(
					'wpcc_acf_no_object_context',
					__( 'No object to read the field from. Pass object_type ("post", "term", "user" or "option") together with object_id — for a post, post_id also works. Without one of those there is no current object in an API request, and an empty answer would be indistinguishable from a field that has no value.', 'ai-command-center' )
				);
			}
			$selector = false;
		}

		// ISSUE 14 — format=raw returns get_field()'s unformatted value (for
		// flexible content that's just the ordered layout-name array — tiny,
		// no sub-field payload at all).
		$format    = sanitize_key( (string) ( $p['format'] ?? 'formatted' ) );
		$formatted = 'raw' !== $format;

		/*
		 * get_field() returns null both for "no such field" and for "this field has
		 * no value". Ask the registry which one it is, so the caller is not left to
		 * guess whether they mistyped the key or the field is simply blank.
		 */
		$definition = acf_get_field( $key );
		if ( ! $definition ) {
			return $this->error(
				'wpcc_acf_field_not_found',
				sprintf(
					/* translators: %s: the field key or name that was requested */
					__( 'No ACF field named "%s" is registered on this site. Use acf_field_list to see the fields of a group; an unknown field is reported here rather than as an empty value.', 'ai-command-center' ),
					$key
				)
			);
		}

		$value = get_field( $key, $selector, $formatted );

		$base = [
			'action'      => 'acf_value_get',
			'object_type' => '' !== $object_type ? $object_type : null,
			'selector'    => is_bool( $selector ) ? null : (string) $selector,
			'post_id'     => is_int( $selector ) ? $selector : 0,
			'field_key'   => $key,
			'field_type'  => (string) ( $definition['type'] ?? '' ),
			// The field is known to exist by this point; say whether it holds a value
			// so `null` is never ambiguous.
			'field_exists' => true,
			'value_state'  => ( null === $value || '' === $value || [] === $value || false === $value ) ? 'empty' : 'has_value',
		];

		// layouts_only — for a flexible-content value, skip all sub-field payload
		// entirely and return just the ordered layout names.
		$layouts_only = filter_var( $p['layouts_only'] ?? false, FILTER_VALIDATE_BOOLEAN );
		if ( $layouts_only && is_array( $value ) && $this->looks_like_row_list( $value ) ) {
			$layouts = [];
			foreach ( $value as $row ) {
				$layouts[] = is_array( $row ) ? ( $row['acf_fc_layout'] ?? null ) : null;
			}
			return $base + [ 'layouts' => $layouts ];
		}

		$context_mode = sanitize_key( (string) ( $p['context_mode'] ?? 'standard' ) );
		if ( ! in_array( $context_mode, [ 'compact', 'standard', 'verbose' ], true ) ) {
			$context_mode = 'standard';
		}
		$depth_cap = isset( $p['depth'] ) ? max( 0, (int) $p['depth'] ) : null;

		return $base + [
			'context_mode' => $context_mode,
			'value'        => $this->shape_acf_value( $value, $context_mode, $depth_cap ),
		];
	}

	/**
	 * ISSUE 14 — shape an ACF value by context_mode so a caller never has to pull
	 * the full formatted tree just to see layout names or a few scalar fields.
	 * Structural (duck-typed), not field-config-driven, so it works uniformly
	 * across image/gallery/repeater/flexible-content/group without needing to
	 * fetch and align each sub-field's definition:
	 *   - compact:  images/files -> {ID, url, alt}; flexible content -> array of
	 *               {index, layout, fields: <scalar sub-fields only>}; repeaters
	 *               -> {row_count, first_row: <scalar sub-fields only>}.
	 *   - standard: full structure, but every image/file array is reduced to
	 *               {ID, url, alt, width, height}.
	 *   - verbose:  untouched — the current full tree.
	 */
	private function shape_acf_value( $value, string $mode, ?int $depth_cap, int $depth = 0 ) {
		if ( 'verbose' === $mode ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( null !== $depth_cap && $depth > $depth_cap ) {
			return [ '_depth_truncated' => true, 'count' => count( $value ) ];
		}

		if ( $this->is_acf_image_array( $value ) ) {
			return 'compact' === $mode
				? [ 'ID' => $value['ID'] ?? ( $value['id'] ?? null ), 'url' => $value['url'] ?? null, 'alt' => $value['alt'] ?? '' ]
				: [
					'ID'     => $value['ID'] ?? ( $value['id'] ?? null ),
					'url'    => $value['url'] ?? null,
					'alt'    => $value['alt'] ?? '',
					'width'  => $value['width'] ?? null,
					'height' => $value['height'] ?? null,
				];
		}

		if ( $this->looks_like_row_list( $value ) ) {
			$is_flexible = $this->is_flexible_content_value( $value );

			if ( 'compact' === $mode ) {
				$rows = [];
				foreach ( $value as $i => $row ) {
					if ( ! is_array( $row ) ) {
						$rows[] = $row;
						continue;
					}
					$rows[] = $is_flexible
						? [ 'index' => $i, 'layout' => $row['acf_fc_layout'] ?? null, 'fields' => $this->scalar_subfields( $row ) ]
						: $this->scalar_subfields( $row );
				}
				if ( $is_flexible ) {
					return $rows;
				}
				// Repeater in compact mode: row count + first row summary only.
				return [ 'row_count' => count( $value ), 'first_row' => $rows[ array_key_first( $rows ) ] ?? null ];
			}

			// standard — keep every row, recursing so nested images still shrink.
			$rows = [];
			foreach ( $value as $i => $row ) {
				$rows[ $i ] = is_array( $row )
					? array_map( fn( $v ) => $this->shape_acf_value( $v, $mode, $depth_cap, $depth + 1 ), $row )
					: $row;
			}
			return $rows;
		}

		// Plain assoc (group) or list of scalars — recurse per key.
		$out = [];
		foreach ( $value as $k => $v ) {
			$out[ $k ] = $this->shape_acf_value( $v, $mode, $depth_cap, $depth + 1 );
		}
		return $out;
	}

	/** An ACF image/file field's formatted return_format=array shape. */
	private function is_acf_image_array( array $v ): bool {
		return ( isset( $v['ID'] ) || isset( $v['id'] ) ) && isset( $v['url'] ) && ! isset( $v['acf_fc_layout'] );
	}

	/** Repeater or flexible-content value: every key numeric, every value a row array. */
	private function looks_like_row_list( array $v ): bool {
		if ( empty( $v ) ) {
			return false;
		}
		foreach ( array_keys( $v ) as $k ) {
			if ( ! is_int( $k ) && ! ctype_digit( (string) $k ) ) {
				return false;
			}
		}
		foreach ( $v as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}
		}
		return true;
	}

	/** A row-list is flexible content when every row carries acf_fc_layout. */
	private function is_flexible_content_value( array $v ): bool {
		foreach ( $v as $row ) {
			if ( ! is_array( $row ) || ! array_key_exists( 'acf_fc_layout', $row ) ) {
				return false;
			}
		}
		return true;
	}

	/** A row's non-array (scalar) sub-fields only — drops nested images/repeaters/groups. */
	private function scalar_subfields( array $row ): array {
		$out = [];
		foreach ( $row as $k => $v ) {
			if ( 'acf_fc_layout' === $k ) {
				continue;
			}
			if ( ! is_array( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * ISSUE 15 — which posts use a given flexible-content layout, without paying
	 * the acf_value_get payload cost per post. A flexible-content field's OWN
	 * meta_key (the field name, e.g. "cms") holds a serialized {row_index =>
	 * layout_name} map — confirmed empirically (this ACF version does NOT write
	 * a separate "<field>_<n>_acf_fc_layout" meta row per row). One query on
	 * that meta_key, joined to wp_posts and excluding revisions, then an
	 * in-PHP check of the unserialized layout names, finds every match cheaply
	 * — no per-post acf_value_get payload.
	 */
	private function layout_usage( array $p ): array {
		global $wpdb;

		$layout = sanitize_text_field( (string) ( $p['layout'] ?? '' ) );
		if ( '' === $layout ) return $this->error( 'wpcc_missing_layout', __( 'A layout name is required.', 'ai-command-center' ) );

		$field         = sanitize_text_field( (string) ( $p['field'] ?? '' ) );
		$post_types    = array_values( array_filter( array_map( 'sanitize_key', (array) ( $p['post_type'] ?? [] ) ) ) );
		$post_statuses = array_values( array_filter( array_map( 'sanitize_key', (array) ( $p['post_status'] ?? [ 'publish' ] ) ) ) );
		if ( empty( $post_statuses ) ) {
			$post_statuses = [ 'publish' ];
		}

		$field_names = '' !== $field ? [ $field ] : $this->all_flexible_content_field_names();
		if ( empty( $field_names ) ) {
			return [ 'action' => 'acf_layout_usage', 'layout' => $layout, 'field' => '' !== $field ? $field : null, 'total' => 0, 'posts' => [] ];
		}

		$where  = [ 'pm.meta_key IN (' . implode( ',', array_fill( 0, count( $field_names ), '%s' ) ) . ')', "p.post_type != 'revision'" ];
		$values = array_values( $field_names );

		if ( ! empty( $post_types ) ) {
			$where[] = 'p.post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
			array_push( $values, ...$post_types );
		}
		if ( ! empty( $post_statuses ) ) {
			$where[] = 'p.post_status IN (' . implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) ) . ')';
			array_push( $values, ...$post_statuses );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- The interpolated WHERE list contains only placeholders; every value is bound through prepare().
		$sql = "SELECT p.ID as post_id, p.post_title as title, p.post_type as post_type, p.post_status as status, pm.meta_value as meta_value
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE " . implode( ' AND ', $where ) . '
			ORDER BY p.ID ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		$by_post = [];
		foreach ( (array) $rows as $row ) {
			$layout_map = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $layout_map ) ) {
				continue;
			}
			$occurrences = count( array_filter( $layout_map, static fn( $l ) => $l === $layout ) );
			if ( $occurrences <= 0 ) {
				continue;
			}

			$pid = (int) $row['post_id'];
			if ( ! isset( $by_post[ $pid ] ) ) {
				$by_post[ $pid ] = [
					'post_id'     => $pid,
					'title'       => $row['title'],
					'post_type'   => $row['post_type'],
					'status'      => $row['status'],
					'permalink'   => get_permalink( $pid ) ?: null,
					'occurrences' => 0,
				];
			}
			$by_post[ $pid ]['occurrences'] += $occurrences;
		}

		$this->audit->record( 'acf.layout.usage', [ 'layout' => $layout, 'field' => $field, 'matches' => count( $by_post ) ] );

		return [
			'action' => 'acf_layout_usage',
			'layout' => $layout,
			'field'  => '' !== $field ? $field : null,
			'total'  => count( $by_post ),
			'posts'  => array_values( $by_post ),
		];
	}

	/** Every flexible_content field's name across every registered field group. */
	private function all_flexible_content_field_names(): array {
		$names = [];
		foreach ( acf_get_field_groups() as $g ) {
			foreach ( acf_get_fields( $g['key'] ) ?: [] as $f ) {
				if ( 'flexible_content' === ( $f['type'] ?? '' ) && ! empty( $f['name'] ) ) {
					$names[ $f['name'] ] = true;
				}
			}
		}
		return array_keys( $names );
	}

	/**
	 * ISSUE 9 — self-describe: list the runtime's actions with risk/approval so a
	 * caller never has to read plugin source to learn valid action names. Generated
	 * from ACFRegistry (the same source of truth the risk/approval maps use).
	 */
	private function describe(): array {
		$actions = [];
		foreach ( ACFRegistry::ACTIONS as $act ) {
			$actions[] = [
				'action'            => $act,
				'risk'              => ACFRegistry::get_risk( $act ),
				'requires_approval' => ACFRegistry::requires_approval( $act ),
			];
		}
		return [
			'action'  => 'acf_describe',
			'runtime' => 'acf_manage',
			'actions' => $actions,
			'notes'   => __( 'acf_value_set/get accept {object_type: post|term|user|option, object_id}. acf_value_set takes fields:{field_key:value}. acf_value_get accepts context_mode (compact|standard|verbose, default standard), format (formatted|raw), layouts_only (flexible content layout names only), depth (cap nesting). acf_layout_usage {layout, field?, post_type?, post_status?} finds every post using a flexible-content layout without reading each post\'s full field value. Definition ops (group_*/field_*) are high-risk and approval-gated.', 'ai-command-center' ),
		];
	}

	private function value_update( array $p, array $cx ): array {
		$post_id = (int) ( $p['post_id'] ?? 0 );
		if ( $post_id <= 0 ) return $this->error( 'wpcc_missing_post_id', __( 'Post ID is required.', 'ai-command-center' ) );
		$key = sanitize_text_field( (string) ( $p['field_key'] ?? $p['field_name'] ?? '' ) );
		if ( '' === $key ) return $this->error( 'wpcc_missing_field', __( 'Field key or name is required.', 'ai-command-center' ) );

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
		if ( $post_id <= 0 ) return $this->error( 'wpcc_missing_post_id', __( 'Post ID is required.', 'ai-command-center' ) );
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

	/**
	 * Location-rule params that legitimately target each ACF object type. Used to
	 * reject writing a field to an object its field group does not apply to.
	 */
	private const LOCATION_PARAMS = [
		'post'   => [ 'post_type', 'post_template', 'post_status', 'post_format', 'post_category', 'post_taxonomy', 'page_template', 'page_type', 'page_parent', 'page' ],
		'term'   => [ 'taxonomy' ],
		'user'   => [ 'user_form', 'user_role' ],
		'option' => [ 'options_page' ],
	];

	/**
	 * ISSUE 3 — set an ACF field value on any object type (post/term/user/option),
	 * not just posts. Routes to the correct native ACF selector so a term/user id
	 * can never be mistaken for a post id and silently corrupt unrelated postmeta.
	 * Backward compatible: a bare post_id still works (mapped to object_type=post).
	 * Snapshots prior values and returns a rollback_id like other mutating ops.
	 */
	private function value_set( array $p, array $cx ): array {
		$object_type = sanitize_key( (string) ( $p['object_type'] ?? '' ) );
		if ( '' === $object_type && isset( $p['post_id'] ) ) {
			$object_type = 'post';
		}
		$object_id = (int) ( $p['object_id'] ?? $p['post_id'] ?? 0 );

		$fields = (array) ( $p['fields'] ?? [] );
		if ( empty( $fields ) && ( isset( $p['field_key'] ) || isset( $p['field_name'] ) ) ) {
			$single = (string) ( $p['field_key'] ?? $p['field_name'] ?? '' );
			if ( '' !== $single ) {
				$fields = [ $single => $p['value'] ?? null ];
			}
		}
		if ( empty( $fields ) ) {
			return $this->error( 'wpcc_no_fields', __( 'No fields supplied. Provide fields:{field_key:value} (or field_key + value).', 'ai-command-center' ) );
		}

		// Resolve to a native ACF selector and validate the target object EXISTS.
		// This is the guard against wrong-table corruption: term/user/option values
		// go to their own selector, never to a post's meta.
		$selector = $this->resolve_acf_selector( $object_type, $object_id );
		if ( is_array( $selector ) ) {
			return $selector; // error() shape
		}

		// Validate each field exists AND its group location applies to this object
		// type; capture the prior raw value for rollback.
		$before = [ 'selector' => $selector, 'object_type' => $object_type, 'object_id' => $object_id, 'fields' => [] ];
		foreach ( array_keys( $fields ) as $rawk ) {
			$k = sanitize_text_field( (string) $rawk );
			$field_object = acf_get_field( $k );
			if ( ! $field_object ) {
				return $this->error( 'wpcc_unknown_acf_field', sprintf( /* translators: %s: value */ __( 'ACF field "%s" not found.', 'ai-command-center' ), $k ) );
			}
			if ( 'no' === $this->field_targets_object_type( $field_object, $object_type ) ) {
				return $this->error( 'wpcc_acf_location_mismatch', sprintf(
					/* translators: 1: field key, 2: object type */
					__( 'ACF field "%1$s" does not apply to a %2$s — its field group location targets a different object type. Refusing to write to avoid corrupting unrelated data.', 'ai-command-center' ),
					$k,
					$object_type
				) );
			}
			$before['fields'][ $k ] = get_field( $k, $selector, false );
		}

		// Apply.
		foreach ( $fields as $rawk => $value ) {
			update_field( sanitize_text_field( (string) $rawk ), $value, $selector );
		}

		$rid = $this->store_rollback( (string) $selector, 'value_set', $before, $cx );
		$this->audit->record( 'acf.value.set', [ 'object_type' => $object_type, 'object_id' => $object_id, 'fields' => array_keys( $before['fields'] ) ] );

		return [
			'action'      => 'acf_value_set',
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'selector'    => (string) $selector,
			'field_count' => count( $fields ),
			'rollback_id' => '' !== $rid ? $rid : null,
		];
	}

	/**
	 * Resolve an object_type + id into the native ACF selector, validating the
	 * target exists. Returns an int|string selector, or an error() array.
	 *
	 * @return int|string|array<string,mixed>
	 */
	private function resolve_acf_selector( string $type, int $id ) {
		switch ( $type ) {
			case 'post':
				if ( $id <= 0 || ! get_post( $id ) ) {
					return $this->error( 'wpcc_invalid_object', __( 'Invalid or non-existent post ID.', 'ai-command-center' ) );
				}
				return $id;
			case 'term':
				$term = $id > 0 ? get_term( $id ) : null;
				if ( ! $term || is_wp_error( $term ) ) {
					return $this->error( 'wpcc_invalid_object', __( 'Invalid or non-existent term ID.', 'ai-command-center' ) );
				}
				return 'term_' . $id;
			case 'user':
				if ( $id <= 0 || ! get_userdata( $id ) ) {
					return $this->error( 'wpcc_invalid_object', __( 'Invalid or non-existent user ID.', 'ai-command-center' ) );
				}
				return 'user_' . $id;
			case 'option':
				return 'option';
			default:
				return $this->error( 'wpcc_invalid_object_type', __( 'object_type must be one of: post, term, user, option.', 'ai-command-center' ) );
		}
	}

	/**
	 * Best-effort check that a field's group location rules target $object_type.
	 * Returns 'no' only on a CLEAR mismatch (the group has location rules and none
	 * apply to the object type); 'unknown' when indeterminate (permissive).
	 */
	private function field_targets_object_type( array $field_object, string $type ): string {
		$group = $this->resolve_field_group( $field_object );
		$rules = $group['location'] ?? [];
		$expected = self::LOCATION_PARAMS[ $type ] ?? [];
		if ( empty( $rules ) || empty( $expected ) ) {
			return 'unknown';
		}
		foreach ( $rules as $group_rules ) {
			foreach ( (array) $group_rules as $rule ) {
				if ( in_array( $rule['param'] ?? '', $expected, true ) ) {
					return 'yes';
				}
			}
		}
		return 'no';
	}

	/** Resolve the top-level field group for a field, walking up sub-field parents. */
	private function resolve_field_group( array $field_object ): array {
		$parent = $field_object['parent'] ?? '';
		$guard  = 0;
		while ( is_string( $parent ) && str_starts_with( $parent, 'field_' ) && $guard++ < 10 ) {
			$pf = acf_get_field( $parent );
			if ( ! $pf ) {
				break;
			}
			$parent = $pf['parent'] ?? '';
		}
		if ( is_string( $parent ) && '' !== $parent ) {
			$g = acf_get_field_group( $parent );
			if ( $g ) {
				return $g;
			}
		}
		return [];
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
		if ( '' === $rid ) return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID required.', 'ai-command-center' ) );

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
		if ( ! $rec ) return $this->error( 'wpcc_rollback_not_found', __( 'Rollback not found.', 'ai-command-center' ) );
		if ( ! empty( $rec['rollback_applied'] ) ) return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'ai-command-center' ) );
		$eid   = $rec['entity_id'];
		$act   = $rec['action'];
		$before = (array) $rec['before_state'];

		// PROGRAM-4.9 — json_import is not faithfully reversible (lossy summary, no restore path);
		// report honestly instead of a phantom clean success.
		if ( 'json_import' === $act ) {
			return $this->rollback_unsupported( __( 'ACF JSON import cannot be automatically rolled back.', 'ai-command-center' ) );
		}

		// PROGRAM-4.9 — fingerprint drift guard for definition update-in-place actions (new
		// records only; legacy records without __after_fp keep the prior unconditional restore).
		// Refuse on drift — never clobber a newer external definition edit.
		if ( in_array( $act, self::FP_GUARDED, true ) && isset( $before['__after_fp'] ) ) {
			if ( $this->definition_fingerprint( (string) $eid, $act ) !== (string) $before['__after_fp'] ) {
				return $this->rollback_conflict( $rid, __( 'ACF definition changed since this update was applied; rollback skipped to avoid clobbering the newer change.', 'ai-command-center' ) );
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
		} elseif ( 'value_set' === $act ) {
			$selector = $before['selector'] ?? $eid;
			foreach ( (array) ( $before['fields'] ?? [] ) as $key => $val ) {
				update_field( (string) $key, $val, $selector );
			}
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
		'field_delete', 'location_assign', 'location_remove', 'value_update', 'value_set', 'json_import',
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
			return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'ai-command-center' ) );
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
		// field_count was hardcoded to 0; resolve the group's actual fields so the
		// summary reflects reality (acf_get_fields accepts the group array or key).
		$fields = function_exists( 'acf_get_fields' ) ? acf_get_fields( $g ) : [];
		return [
			'key'         => $g['key'] ?? '',
			'title'       => $g['title'] ?? '',
			'active'      => $g['active'] ?? true,
			'location'    => count( $g['location'] ?? [] ),
			'field_count' => is_array( $fields ) ? count( $fields ) : 0,
		];
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

	/**
	 * Structured error envelope. $extra can carry discoverability fields such as
	 * valid_actions or expected_params so a caller can self-correct in one retry.
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message, array $extra = [] ): array {
		return array_merge( [ 'error' => true, 'code' => $code, 'message' => $message ], $extra );
	}
}
