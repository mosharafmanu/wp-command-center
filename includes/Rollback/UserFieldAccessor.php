<?php
/**
 * PROGRAM-4 / P4.5 — User {@see FieldAccessor}.
 *
 * Drives the runtime-agnostic {@see RollbackDelta} core over a user's editable
 * profile fields, a mix of the users table and usermeta — all read via get_userdata
 * (WP_User magic properties) and written via wp_update_user:
 *   email→user_email (column), display_name→display_name (column),
 *   first_name→first_name (usermeta), last_name→last_name (usermeta).
 *
 * The unified field maps to its wp_update_user field name (which doubles as the
 * WP_User property name), so capture/restore use one consistent identifier — this is
 * also what fixes the pre-P4.5 update-rollback bug where `email` (vs `user_email`) was
 * silently dropped. User fields are treated as always-present; restore writes the
 * prior value (including ''). Roles are a separate set-valued concern, not handled here.
 */

namespace WPCommandCenter\Rollback;

defined( 'ABSPATH' ) || exit;

final class UserFieldAccessor implements FieldAccessor {

	/** Unified field → wp_update_user field name (== WP_User property name). */
	private const KEYS = [
		'email'        => 'user_email',
		'display_name' => 'display_name',
		'first_name'   => 'first_name',
		'last_name'    => 'last_name',
	];

	public function backing_keys( string $field ): array {
		return isset( self::KEYS[ $field ] ) ? [ self::KEYS[ $field ] ] : [];
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function read_field( $entity_id, string $field ) {
		$key = self::KEYS[ $field ] ?? '';
		return '' === $key ? '' : $this->key_get( $entity_id, $key );
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_exists( $entity_id, string $key ): bool {
		return (bool) get_userdata( (int) $entity_id );
	}

	/**
	 * @param int|string $entity_id
	 * @return mixed
	 */
	public function key_get( $entity_id, string $key ) {
		$user = get_userdata( (int) $entity_id );
		return $user ? (string) $user->{$key} : '';
	}

	/**
	 * @param int|string $entity_id
	 * @param mixed      $value
	 */
	public function key_set( $entity_id, string $key, $value ): void {
		wp_update_user( [ 'ID' => (int) $entity_id, $key => (string) $value ] );
	}

	/**
	 * @param int|string $entity_id
	 */
	public function key_delete( $entity_id, string $key ): void {
		// User profile fields cannot be absent in a way wp_update_user can remove;
		// "restore absence" is an empty value (unreachable — key_exists is always true).
		wp_update_user( [ 'ID' => (int) $entity_id, $key => '' ] );
	}

	/**
	 * @param mixed $current
	 * @param mixed $after
	 */
	public function equals( string $field, $current, $after ): bool {
		return (string) $current === (string) $after;
	}
}
