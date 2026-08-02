<?php
/**
 * V1 Phase 1 — ACF local JSON synchronisation.
 *
 * acf-json is the artefact an agency commits and deploys. ACF writes it only from
 * `acf/update_field_group`, i.e. when the GROUP is saved. Every field mutation this
 * plugin performs goes through acf_update_field()/acf_delete_field(), which never
 * touch that file — so a group built through WPCC was correct in the database and
 * empty on disk, and the next deploy would have restored the empty version.
 *
 * The trap, measured on a real site rather than assumed:
 *
 *   acf_get_field_group( $key )      -> ID 0, fields 0   (the stale JSON copy wins)
 *   acf_get_field_group( $post_id )  -> ID 5104, fields 1 (the database)
 *
 * Once a group has a JSON file, ACF's local-JSON loader answers key lookups from it.
 * Rebuilding the file from a key lookup therefore reads the stale artefact and writes
 * it back — a self-reinforcing loop, and exactly why an earlier attempt produced a
 * file that was short by one or more fields. Everything here resolves the group by
 * its stored post ID, which is the only view that reflects what was actually saved.
 *
 * Guarantees this class is responsible for:
 *   - never a partial file: build and validate fully in memory, then replace atomically
 *   - never a false "synced": status compares CONTENT, not the existence of a file
 *   - a failure leaves the previous file exactly as it was
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class AcfLocalJson {

	/** Written by ACF and by us; kept identical so a diff is meaningful. */
	private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Whether ACF local JSON is on AND a save path is usable.
	 *
	 * A site that deliberately disables local JSON must be left alone — writing files
	 * it did not ask for is its own kind of damage.
	 */
	public static function enabled(): bool {
		if ( ! function_exists( 'acf_get_setting' ) || ! function_exists( 'acf_get_field_group' ) ) {
			return false;
		}
		if ( ! acf_get_setting( 'json' ) ) {
			return false;
		}
		return '' !== self::save_path();
	}

	/**
	 * ACF's own configured save directory.
	 *
	 * Read through ACF rather than assuming `get_stylesheet_directory()/acf-json`, so a
	 * site with a custom save point (acf/settings/save_json, or ACF 6's per-group save
	 * paths) keeps working.
	 */
	public static function save_path(): string {
		$path = '';

		if ( function_exists( 'acf_get_instance' ) ) {
			$local = acf_get_instance( 'ACF_Local_JSON' );
			if ( $local && method_exists( $local, 'get_save_paths' ) ) {
				$paths = (array) $local->get_save_paths();
				$path  = (string) ( reset( $paths ) ?: '' );
			}
		}

		if ( '' === $path ) {
			$path = (string) acf_get_setting( 'save_json' );
		}

		return ( '' !== $path && is_dir( $path ) && wp_is_writable( $path ) ) ? untrailingslashit( $path ) : '';
	}

	/** The stored post ID for a field-group key, or 0. */
	public static function group_post_id( string $group_key ): int {
		if ( '' === $group_key ) {
			return 0;
		}
		$found = get_posts( [
			'post_type'        => 'acf-field-group',
			'name'             => $group_key,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		] );
		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * The group as the DATABASE has it, fields attached, ready to serialise.
	 *
	 * Deliberately resolved by post ID. A key lookup would return the JSON copy this
	 * method exists to replace.
	 *
	 * @return array<string,mixed> Empty when the group is not stored.
	 */
	public static function build( string $group_key ): array {
		$post_id = self::group_post_id( $group_key );
		if ( 0 === $post_id ) {
			return [];
		}

		// RAW accessors, deliberately. Once a group has a local JSON file, ACF
		// registers that file's definition and acf_get_fields() answers from it — even
		// when the group array was resolved from the database. Measured: 15 field posts
		// in wp_posts, acf_get_fields() returning 1, unchanged by resetting ACF's
		// stores. acf_get_raw_field_group()/acf_get_raw_fields() are ACF's own
		// database-only readers and are the only view that cannot be poisoned by the
		// artefact we are about to replace.
		$group = function_exists( 'acf_get_raw_field_group' )
			? acf_get_raw_field_group( $post_id )
			: acf_get_field_group( $post_id );

		if ( ! is_array( $group ) || empty( $group['key'] ) ) {
			return [];
		}

		$group['fields'] = self::raw_fields( $post_id );

		/*
		 * Run through ACF's own exporter so the file is byte-comparable with one ACF
		 * would have written. The raw rows carry database columns — notably `ID`, and
		 * per-field `parent` — which ACF's export format omits precisely because they
		 * are environment-specific. Writing them produced a loadable file that was
		 * nonetheless wrong to commit: post IDs differ per environment and the diff
		 * against an ACF-written file was enormous.
		 */
		if ( function_exists( 'acf_prepare_field_group_for_export' ) ) {
			try {
				$group = acf_prepare_field_group_for_export( $group );
			} catch ( \Throwable $e ) {
				// Refuse to write rather than write a shape ACF may not load. The caller's
				// field operation still succeeds; status() will report the file stale.
				return [];
			}
		}

		$group['modified'] = (int) get_post_modified_time( 'U', true, $post_id );

		return $group;
	}

	/**
	 * Every field stored under a parent, nested as ACF's exporter expects.
	 *
	 * Two different shapes, and getting them confused is what broke the first attempt:
	 *
	 *  - A repeater or group holds its sub-fields as field POSTS whose post_parent is
	 *    the parent field's post ID. They nest under `sub_fields`.
	 *  - A flexible-content field's layout sub-fields are ALSO field posts under that
	 *    field, but each carries `parent_layout` naming the layout it belongs to, and
	 *    the exporter expects them under `layouts[<layout_key>]['sub_fields']` — not on
	 *    the field itself. Measured on the customer's real "Page Builder" group: the
	 *    stored layouts have no `sub_fields` key at all, so ACF's
	 *    acf_prepare_field_for_export() ran array_map() over null and threw.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function raw_fields( int $parent_id, int $depth = 0 ): array {
		if ( $depth > 10 || ! function_exists( 'acf_get_raw_fields' ) ) {
			return [];
		}

		$rows = acf_get_raw_fields( $parent_id );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}

			$children = empty( $field['ID'] ) ? [] : self::raw_fields( (int) $field['ID'], $depth + 1 );

			if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				// Every layout must carry a sub_fields ARRAY, even an empty one, or the
				// exporter cannot walk it.
				foreach ( $field['layouts'] as $layout_key => $layout ) {
					if ( ! is_array( $layout ) ) {
						unset( $field['layouts'][ $layout_key ] );
						continue;
					}
					$field['layouts'][ $layout_key ]['sub_fields'] = [];
				}

				foreach ( $children as $child ) {
					$belongs_to = (string) ( $child['parent_layout'] ?? '' );
					if ( '' !== $belongs_to && isset( $field['layouts'][ $belongs_to ] ) ) {
						$field['layouts'][ $belongs_to ]['sub_fields'][] = $child;
					}
				}
			} elseif ( [] !== $children ) {
				$field['sub_fields'] = $children;
			}

			$out[] = $field;
		}

		return $out;
	}

	/**
	 * Whether a built group is safe to write.
	 *
	 * A file that ACF cannot load is worse than a stale one, so anything that would
	 * produce one is refused before the old file is touched.
	 *
	 * @param array<string,mixed> $group
	 */
	public static function validate( array $group ): bool {
		if ( empty( $group['key'] ) || ! is_string( $group['key'] ) || ! isset( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
			return false;
		}
		if ( ! array_key_exists( 'title', $group ) ) {
			return false;
		}

		foreach ( $group['fields'] as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || ! isset( $field['type'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Rebuild one group's JSON file, atomically.
	 *
	 * Build → validate → encode → write a temp file in the SAME directory → rename over
	 * the target. rename() within a directory is atomic on every filesystem WordPress
	 * runs on, so a reader either sees the whole previous file or the whole new one, and
	 * an interrupted write cannot leave a half-file behind. ACF's own save_file() uses a
	 * plain file_put_contents; this is deliberately stronger.
	 *
	 * @return array{written:bool,reason:string,path:string,fields:int}
	 */
	public static function write( string $group_key ): array {
		$result = [ 'written' => false, 'reason' => '', 'path' => '', 'fields' => 0 ];

		if ( ! self::enabled() ) {
			$result['reason'] = 'local_json_disabled';
			return $result;
		}

		$group = self::build( $group_key );
		if ( [] === $group ) {
			$result['reason'] = 'group_not_stored';
			return $result;
		}
		if ( ! self::validate( $group ) ) {
			$result['reason'] = 'validation_failed';
			return $result;
		}

		$json = wp_json_encode( $group, self::JSON_FLAGS );
		if ( ! is_string( $json ) || '' === $json ) {
			$result['reason'] = 'encode_failed';
			return $result;
		}

		$dir              = self::save_path();
		$target           = $dir . '/' . $group_key . '.json';
		$result['path']   = $target;
		$result['fields'] = count( $group['fields'] );

		// Temp file alongside the target so the rename stays on one filesystem.
		$tmp = $target . '.wpcc-tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- atomic replace; WP_Filesystem offers no atomic rename.
		$handle = @fopen( $tmp, 'wb' );
		if ( false === $handle ) {
			$result['reason'] = 'temp_not_writable';
			return $result;
		}

		$ok = ( false !== fwrite( $handle, $json ) );
		if ( $ok ) {
			fflush( $handle );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with the fopen above.
		fclose( $handle );

		if ( ! $ok ) {
			wp_delete_file( $tmp );
			$result['reason'] = 'write_failed';
			return $result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace; see the class docblock.
		if ( ! @rename( $tmp, $target ) ) {
			wp_delete_file( $tmp );
			$result['reason'] = 'replace_failed';
			return $result;
		}

		$result['written'] = true;
		$result['reason']  = 'ok';
		return $result;
	}

	/**
	 * Whether the file on disk matches what the database would produce.
	 *
	 * Compares CONTENT. The previous status check reported "synced" from the mere
	 * existence of a file, which is how a group with twelve fields could be reported as
	 * synced while its file held none.
	 *
	 * @return array{group:string,in_sync:bool,reason:string,db_fields:int,json_fields:int,json_exists:bool}
	 */
	public static function status( string $group_key ): array {
		$group   = self::build( $group_key );
		$db_n    = [] === $group ? 0 : count( $group['fields'] );
		$dir     = self::save_path();
		$target  = '' === $dir ? '' : $dir . '/' . $group_key . '.json';
		$exists  = '' !== $target && is_readable( $target );

		$out = [
			'group'       => $group_key,
			'in_sync'     => false,
			'reason'      => '',
			'db_fields'   => $db_n,
			'json_fields' => 0,
			'json_exists' => $exists,
		];

		if ( ! self::enabled() ) {
			$out['reason']  = 'local_json_disabled';
			$out['in_sync'] = true; // nothing is meant to be on disk
			return $out;
		}
		if ( [] === $group ) {
			$out['reason'] = 'group_not_stored';
			return $out;
		}
		if ( ! $exists ) {
			$out['reason'] = 'json_missing';
			return $out;
		}

		$raw = file_get_contents( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin-owned artefact, read for comparison.
		$on_disk = json_decode( (string) $raw, true );
		if ( ! is_array( $on_disk ) ) {
			$out['reason'] = 'json_unreadable';
			return $out;
		}

		$out['json_fields'] = isset( $on_disk['fields'] ) && is_array( $on_disk['fields'] ) ? count( $on_disk['fields'] ) : 0;

		// `modified` is a timestamp ACF stamps on save; it differing does not mean the
		// definition differs, so it is excluded from the comparison.
		$a = $group;
		$b = $on_disk;
		unset( $a['modified'], $b['modified'], $a['ID'], $b['ID'] );

		$same = wp_json_encode( $a, self::JSON_FLAGS ) === wp_json_encode( $b, self::JSON_FLAGS );

		$out['in_sync'] = $same;
		$out['reason']  = $same ? 'ok' : ( $out['json_fields'] !== $db_n ? 'field_count_differs' : 'definition_differs' );

		return $out;
	}

	/**
	 * Rebuild the JSON for the group that owns a field, after that field changed.
	 *
	 * Best effort by design: a JSON write must never fail the field operation the
	 * caller actually asked for. The outcome is audited either way so a stale file is
	 * discoverable rather than silent.
	 */
	public static function sync_for_group( string $group_key, string $because ): void {
		if ( '' === $group_key || ! self::enabled() ) {
			return;
		}

		$result = self::write( $group_key );

		( new AuditLog() )->record(
			$result['written'] ? 'acf.json.synced' : 'acf.json.sync_failed',
			[
				'group'  => $group_key,
				'reason' => $result['reason'],
				'fields' => $result['fields'],
				'after'  => $because,
			]
		);
	}
}
