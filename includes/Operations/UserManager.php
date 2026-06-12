<?php
/**
 * Step 61 — User Management Runtime Manager.
 * Safe WordPress user operations through the platform pipeline.
 * All operations flow through Registry → Capability → Approval → Queue → Execute.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class UserManager {

	private AuditLog $audit;

	public function __construct() {
		$this->audit = new AuditLog();
	}

	/**
	 * Main entry point — dispatches to sub-action handlers.
	 */
	public function run( array $payload, array $context = [] ): array {
		$action = (string) ( $payload['action'] ?? '' );

		if ( ! in_array( $action, UserRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_user_action', __( 'Invalid user action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			UserRegistry::ACTION_LIST            => $this->list_users( $payload ),
			UserRegistry::ACTION_GET             => $this->get_user( $payload ),
			UserRegistry::ACTION_SEARCH          => $this->search_users( $payload ),
			UserRegistry::ACTION_CREATE          => $this->create_user( $payload, $context ),
			UserRegistry::ACTION_UPDATE          => $this->update_user( $payload, $context ),
			UserRegistry::ACTION_DELETE          => $this->delete_user( $payload, $context ),
			UserRegistry::ACTION_SUSPEND         => $this->suspend_user( $payload, $context ),
			UserRegistry::ACTION_RESET_PASSWORD  => $this->reset_password( $payload, $context ),
			UserRegistry::ACTION_ASSIGN_ROLE     => $this->assign_role( $payload, $context ),
			UserRegistry::ACTION_REMOVE_ROLE     => $this->remove_role( $payload, $context ),
			default                              => $this->error( 'wpcc_unknown_user_action', __( 'Unknown user action.', 'wp-command-center' ) ),
		};
	}

	// ── Read Operations ──

	private function list_users( array $payload ): array {
		$per_page = min( 100, max( 1, (int) ( $payload['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $payload['page'] ?? 1 ) );
		$role     = sanitize_key( (string) ( $payload['role'] ?? '' ) );

		$args = [
			'number' => $per_page,
			'paged'  => $page,
			'fields' => 'all_with_meta',
		];
		if ( '' !== $role ) {
			$args['role'] = $role;
		}

		$query  = new \WP_User_Query( $args );
		$users  = $query->get_results();
		$total  = $query->get_total();
		$result = [];

		foreach ( $users as $u ) {
			$result[] = $this->format_user( $u );
		}

		$this->audit->record( 'user.list', [ 'count' => count( $result ), 'total' => $total ] );

		return [ 'action' => 'user_list', 'users' => $result, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
	}

	private function get_user( array $payload ): array {
		$user_id = (int) ( $payload['user_id'] ?? 0 );
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return $this->error( 'wpcc_user_not_found', __( 'User not found.', 'wp-command-center' ) );
		}

		$this->audit->record( 'user.get', [ 'user_id' => $user_id ] );

		return [ 'action' => 'user_get', 'user' => $this->format_user( $user ) ];
	}

	private function search_users( array $payload ): array {
		$search = sanitize_text_field( (string) ( $payload['search'] ?? '' ) );
		if ( '' === $search ) {
			return $this->error( 'wpcc_missing_search', __( 'Search term is required.', 'wp-command-center' ) );
		}

		$query = new \WP_User_Query( [
			'search'         => '*' . $search . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name', 'user_nicename' ],
			'number'         => 50,
		] );

		$users  = $query->get_results();
		$result = [];
		foreach ( $users as $u ) {
			$result[] = $this->format_user( $u );
		}

		$this->audit->record( 'user.search', [ 'search' => $search, 'results' => count( $result ) ] );

		return [ 'action' => 'user_search', 'users' => $result, 'total' => count( $result ) ];
	}

	// ── Mutation Operations ──

	private function create_user( array $payload, array $context ): array {
		$username = sanitize_user( (string) ( $payload['username'] ?? '' ), true );
		$email    = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$password = (string) ( $payload['password'] ?? '' );
		$role     = sanitize_key( (string) ( $payload['role'] ?? 'subscriber' ) );
		$display  = sanitize_text_field( (string) ( $payload['display_name'] ?? '' ) );
		$first    = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
		$last     = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );

		if ( '' === $username || '' === $email || '' === $password ) {
			return $this->error( 'wpcc_missing_user_fields', __( 'Username, email, and password are required.', 'wp-command-center' ) );
		}

		if ( username_exists( $username ) ) {
			return $this->error( 'wpcc_username_exists', __( 'Username already exists.', 'wp-command-center' ) );
		}
		if ( email_exists( $email ) ) {
			return $this->error( 'wpcc_user_email_exists', __( 'Email already in use.', 'wp-command-center' ) );
		}

		// Critical: flag administrator account creation in the audit log
		if ( 'administrator' === $role ) {
			$this->audit->record( 'user.create.administrator', [
				'username' => $username,
				'email'    => $email,
			] );
		}

		$user_data = [
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
			'role'       => $role,
		];
		if ( '' !== $display ) {
			$user_data['display_name'] = $display;
		}
		if ( '' !== $first ) {
			$user_data['first_name'] = $first;
		}
		if ( '' !== $last ) {
			$user_data['last_name'] = $last;
		}

		$user_id = wp_insert_user( $user_data );
		if ( is_wp_error( $user_id ) ) {
			$this->audit->record( 'user.create.failed', [ 'error' => $user_id->get_error_message() ] );
			return $this->error( 'wpcc_user_create_failed', $user_id->get_error_message() );
		}

		$this->store_rollback( $user_id, 'create', [], $context );

		$this->audit->record( 'user.created', [
			'user_id'  => $user_id,
			'username' => $username,
			'role'     => $role,
		] );

		return [ 'action' => 'user_create', 'user_id' => $user_id, 'username' => $username, 'role' => $role ];
	}

	private function update_user( array $payload, array $context ): array {
		$user_id = (int) ( $payload['user_id'] ?? 0 );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return $this->error( 'wpcc_user_not_found', __( 'User not found.', 'wp-command-center' ) );
		}

		$before = [
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
		];

		$updates = [];
		if ( isset( $payload['email'] ) ) {
			$updates['user_email'] = sanitize_email( (string) $payload['email'] );
		}
		if ( isset( $payload['display_name'] ) ) {
			$updates['display_name'] = sanitize_text_field( (string) $payload['display_name'] );
		}
		if ( isset( $payload['first_name'] ) ) {
			$updates['first_name'] = sanitize_text_field( (string) $payload['first_name'] );
		}
		if ( isset( $payload['last_name'] ) ) {
			$updates['last_name'] = sanitize_text_field( (string) $payload['last_name'] );
		}

		if ( empty( $updates ) ) {
			return $this->error( 'wpcc_no_user_updates', __( 'No fields to update.', 'wp-command-center' ) );
		}

		$updates['ID'] = $user_id;
		$result = wp_update_user( $updates );
		if ( is_wp_error( $result ) ) {
			return $this->error( 'wpcc_user_update_failed', $result->get_error_message() );
		}

		$this->store_rollback( $user_id, 'update', $before, $context );

		$this->audit->record( 'user.updated', [ 'user_id' => $user_id, 'fields' => array_keys( $updates ) ] );

		return [ 'action' => 'user_update', 'user_id' => $user_id, 'updated_fields' => array_keys( $updates ) ];
	}

	private function delete_user( array $payload, array $context ): array {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$user_id = (int) ( $payload['user_id'] ?? 0 );
		$reassign_to = (int) ( $payload['reassign_to'] ?? 0 );
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return $this->error( 'wpcc_user_not_found', __( 'User not found.', 'wp-command-center' ) );
		}

		// Cannot delete yourself
		if ( get_current_user_id() === $user_id ) {
			return $this->error( 'wpcc_cannot_delete_self', __( 'Cannot delete your own account.', 'wp-command-center' ) );
		}

		$before = $this->format_user( $user );
		$this->store_rollback( $user_id, 'delete', $before, $context );

		if ( $reassign_to > 0 ) {
			$result = wp_delete_user( $user_id, $reassign_to );
		} else {
			$result = wp_delete_user( $user_id );
		}

		if ( ! $result ) {
			return $this->error( 'wpcc_user_delete_failed', __( 'Failed to delete user.', 'wp-command-center' ) );
		}

		$this->audit->record( 'user.deleted', [
			'user_id'     => $user_id,
			'username'    => $before['username'],
			'reassign_to' => $reassign_to,
		] );

		return [ 'action' => 'user_delete', 'user_id' => $user_id, 'username' => $before['username'], 'reassign_to' => $reassign_to ];
	}

	private function suspend_user( array $payload, array $context ): array {
		$user_id = (int) ( $payload['user_id'] ?? 0 );
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return $this->error( 'wpcc_user_not_found', __( 'User not found.', 'wp-command-center' ) );
		}

		$before_roles = $user->roles;
		$this->store_rollback( $user_id, 'suspend', [ 'roles' => $before_roles ], $context );

		// Remove all roles — effectively suspending
		foreach ( $before_roles as $r ) {
			$user->remove_role( $r );
		}
		$user->add_role( 'subscriber' );
		// Set a flag
		update_user_meta( $user_id, 'wpcc_suspended', 1 );

		$this->audit->record( 'user.suspended', [ 'user_id' => $user_id, 'previous_roles' => $before_roles ] );

		return [ 'action' => 'user_suspend', 'user_id' => $user_id, 'previous_roles' => $before_roles ];
	}

	private function reset_password( array $payload, array $context ): array {
		$user_id  = (int) ( $payload['user_id'] ?? 0 );
		$password = (string) ( $payload['new_password'] ?? '' );
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return $this->error( 'wpcc_user_not_found', __( 'User not found.', 'wp-command-center' ) );
		}

		if ( '' === $password ) {
			$password = wp_generate_password( 24, true, false );
			$generated = true;
		} else {
			if ( strlen( $password ) < 8 ) {
				return $this->error( 'wpcc_weak_password', __( 'Password must be at least 8 characters.', 'wp-command-center' ) );
			}
			$generated = false;
		}

		wp_set_password( $password, $user_id );

		$this->audit->record( 'user.password.reset', [
			'user_id'   => $user_id,
			'generated' => $generated,
		] );

		$response = [ 'action' => 'user_reset_password', 'user_id' => $user_id ];
		if ( $generated ) {
			$response['new_password'] = $password;
			$response['note'] = __( 'Password was auto-generated. Save it now — it will not be shown again.', 'wp-command-center' );
		}

		return $response;
	}

	private function assign_role( array $payload, array $context ): array {
		$user_id = (int) ( $payload['user_id'] ?? 0 );
		$role    = sanitize_key( (string) ( $payload['role'] ?? '' ) );
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return $this->error( 'wpcc_user_not_found', __( 'User not found.', 'wp-command-center' ) );
		}

		$wp_roles = wp_roles();
		if ( ! $wp_roles->is_role( $role ) ) {
			return $this->error( 'wpcc_invalid_role', __( 'Invalid role.', 'wp-command-center' ) );
		}

		// Critical: flag administrator role assignment in the audit log
		if ( 'administrator' === $role ) {
			$this->audit->record( 'user.role.administrator_assign', [ 'user_id' => $user_id ] );
		}

		$before_roles = $user->roles;
		$this->store_rollback( $user_id, 'assign_role', [ 'roles' => $before_roles ], $context );

		$user->add_role( $role );

		$this->audit->record( 'user.role.assigned', [
			'user_id' => $user_id,
			'role'    => $role,
		] );

		return [ 'action' => 'user_assign_role', 'user_id' => $user_id, 'role' => $role, 'current_roles' => array_values( $user->roles ) ];
	}

	private function remove_role( array $payload, array $context ): array {
		$user_id = (int) ( $payload['user_id'] ?? 0 );
		$role    = sanitize_key( (string) ( $payload['role'] ?? '' ) );
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return $this->error( 'wpcc_user_not_found', __( 'User not found.', 'wp-command-center' ) );
		}

		if ( ! in_array( $role, $user->roles, true ) ) {
			return $this->error( 'wpcc_user_role_not_assigned', __( 'User does not have this role.', 'wp-command-center' ) );
		}

		if ( 1 === count( $user->roles ) ) {
			return $this->error( 'wpcc_cannot_remove_last_role', __( 'Cannot remove the last role.', 'wp-command-center' ) );
		}

		$before_roles = $user->roles;
		$this->store_rollback( $user_id, 'remove_role', [ 'roles' => $before_roles ], $context );

		$user->remove_role( $role );

		$this->audit->record( 'user.role.removed', [
			'user_id' => $user_id,
			'role'    => $role,
		] );

		return [ 'action' => 'user_remove_role', 'user_id' => $user_id, 'role' => $role, 'current_roles' => array_values( $user->roles ) ];
	}

	// ── Rollback Support ──

	private function store_rollback( int $user_id, string $action, array $before, array $context ): void {
		if ( ! UserRegistry::supports_rollback( $action ) ) {
			return;
		}

		$rollbacks = get_option( 'wpcc_user_rollbacks', [] );
		$rollback_id = wp_generate_uuid4();

		$rollbacks[] = [
			'id'              => $rollback_id,
			'user_id'         => $user_id,
			'action'          => $action,
			'before_state'    => $before,
			'rollback_applied' => false,
			'created_at'      => time(),
			'session_id'      => $context['session_id'] ?? null,
			'task_id'         => $context['task_id'] ?? null,
		];

		// Keep only last 100 rollback records
		if ( count( $rollbacks ) > 100 ) {
			$rollbacks = array_slice( $rollbacks, -100 );
		}

		update_option( 'wpcc_user_rollbacks', $rollbacks );
	}

	public function rollback( array $payload, array $context = [] ): array {
		$rollback_id = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rollback_id ) {
			return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID is required.', 'wp-command-center' ) );
		}

		$rollbacks = get_option( 'wpcc_user_rollbacks', [] );
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

		$user_id = $record['user_id'];
		$action  = $record['action'];
		$before  = $record['before_state'];

		switch ( $action ) {
			case 'create':
				require_once ABSPATH . 'wp-admin/includes/user.php';
				wp_delete_user( $user_id );
				break;
			case 'delete':
				if ( ! empty( $before ) ) {
					wp_insert_user( [
						'user_login'   => $before['username'],
						'user_email'   => $before['email'],
						'user_pass'    => wp_generate_password(),
						'role'         => $before['role'],
						'display_name' => $before['display_name'],
					] );
				}
				break;
			case 'update':
				$before['ID'] = $user_id;
				wp_update_user( $before );
				break;
			case 'assign_role':
				$user = get_userdata( $user_id );
				if ( $user ) {
					$user->set_role( '' );
					foreach ( $before['roles'] as $r ) {
						$user->add_role( $r );
					}
				}
				break;
			case 'remove_role':
				$user = get_userdata( $user_id );
				if ( $user ) {
					$user->set_role( '' );
					foreach ( $before['roles'] as $r ) {
						$user->add_role( $r );
					}
				}
				break;
			case 'suspend':
				$user = get_userdata( $user_id );
				if ( $user ) {
					delete_user_meta( $user_id, 'wpcc_suspended' );
					$user->set_role( '' );
					foreach ( $before['roles'] as $r ) {
						$user->add_role( $r );
					}
				}
				break;
		}

		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_user_rollbacks', $rollbacks );

		$this->audit->record( 'user.rollback.applied', [
			'rollback_id' => $rollback_id,
			'user_id'     => $user_id,
			'action'      => $action,
		] );

		return [ 'action' => 'user_rollback', 'rollback_id' => $rollback_id, 'user_id' => $user_id, 'rolled_back_action' => $action ];
	}

	// ── Helpers ──

	private function format_user( \WP_User $user ): array {
		return [
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'roles'        => array_values( $user->roles ),
			'registered'   => $user->user_registered,
		];
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
