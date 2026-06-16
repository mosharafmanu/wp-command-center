<?php
/**
 * Step 38 — Option Management Runtime.
 *
 * Safely inspects and updates approved WordPress options through the
 * Operations framework. Registry-driven, risk-scored, approval-aware,
 * auditable, and rollback-capable.
 *
 * Supports two actions:
 *   - option_get:  Read a registered option.
 *   - option_update: Update a registered option (with rollback capture).
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class OptionManager {

	private OptionRegistry $registry;

	public function __construct() {
		$this->registry = new OptionRegistry();
	}

	/**
	 * Emit an audit event with option context.
	 */
	private function audit( string $event, array $option, array $extra = [], array $context = [] ): void {
		$audit = new AuditLog();
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;

		$data = array_merge( [
			'option_id'   => $option['option_id'],
			'option_name' => $option['option_name'],
			'risk_level'  => $option['risk_level'],
			'actor'       => $actor,
		], $extra );

		$audit->record( $event, $data );
	}

	/**
	 * Run an option management operation.
	 *
	 * @param array{
	 *     action: string,
	 *     option_id?: string,
	 *     value?: mixed,
	 *     rollback_id?: string
	 * } $params
	 * @param array $context
	 *
	 * @return array|\WP_Error
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action    = sanitize_key( $params['action'] ?? '' );
		$option_id = sanitize_text_field( $params['option_id'] ?? '' );

		// STEP 104.3 — option_rollback is drivable by rollback_id alone (e.g. from
		// change_history rollback_target / the unified OperationExecutor::rollback
		// dispatcher). Resolve the option_id from the stored rollback record when
		// the caller did not supply it; the record carries it.
		if ( '' === $option_id && 'option_rollback' === $action ) {
			$record = $this->get_rollback( sanitize_text_field( (string) ( $params['rollback_id'] ?? '' ) ) );
			if ( is_array( $record ) && ! empty( $record['option_id'] ) ) {
				$option_id = (string) $record['option_id'];
			}
		}

		if ( '' === $option_id ) {
			return new \WP_Error( 'wpcc_missing_option_id', __( 'option_id is required.', 'wp-command-center' ) );
		}

		$option = $this->registry->get_option( $option_id );
		if ( null === $option ) {
			return new \WP_Error( 'wpcc_invalid_option_id', __( 'Unknown option ID.', 'wp-command-center' ) );
		}

		switch ( $action ) {
			case 'option_get':
				return $this->option_get( $option, $context );
			case 'option_update':
				return $this->option_update( $option, $params['value'] ?? null, $context );
			case 'option_rollback':
				return $this->option_rollback( $option, $params['rollback_id'] ?? '', $context );
			default:
				return new \WP_Error( 'wpcc_invalid_option_action', sprintf( __( 'Invalid action: %s. Use option_get or option_update.', 'wp-command-center' ), esc_html( $action ) ) );
		}
	}

	/**
	 * Read a registered option.
	 */
	private function option_get( array $option, array $context = [] ): array {
		$value = get_option( $option['option_name'], null );

		$this->audit( 'option.read', $option, [
			'current_value' => $value,
		], $context );

		return [
			'action'        => 'option_get',
			'option_id'     => $option['option_id'],
			'option_name'   => $option['option_name'],
			'type'          => $option['type'],
			'current_value' => $value,
			'risk_level'    => $option['risk_level'],
			'title'         => $option['title'],
		];
	}

	/**
	 * Update a registered option with rollback capture.
	 */
	private function option_update( array $option, mixed $new_value, array $context ): array|\WP_Error {
		// Validate the value.
		$validation = $this->registry->validate_value( $option['option_id'], $new_value );
		if ( $validation instanceof \WP_Error ) {
			return $validation;
		}

		// Normalize value based on type.
		$new_value = $this->normalize_value( $new_value, $option['type'] );

		// Capture old value for rollback.
		$old_value = get_option( $option['option_name'], null );

		// No change — skip update but still return success.
		// Use loose comparison because WP stores options as strings.
		if ( $old_value == $new_value ) {
			$this->audit( 'option.update.started', $option, [
				'old_value' => $old_value,
				'new_value' => $new_value,
			], $context );
			$this->audit( 'option.update.completed', $option, [
				'old_value' => $old_value,
				'new_value' => $new_value,
				'unchanged' => true,
			], $context );

			return [
				'action'      => 'option_update',
				'option_id'   => $option['option_id'],
				'option_name' => $option['option_name'],
				'unchanged'   => true,
				'old_value'   => $old_value,
				'new_value'   => $new_value,
				'risk_level'  => $option['risk_level'],
			];
		}

		$this->audit( 'option.update.started', $option, [
			'old_value' => $old_value,
			'new_value' => $new_value,
		], $context );

		// Store rollback metadata before the update.
		$rollback_id = wp_generate_uuid4();
		$rollback    = $this->store_rollback( $rollback_id, $option, $old_value, $new_value, $context );

		// Apply the update.
		$updated = update_option( $option['option_name'], $new_value );

		if ( ! $updated ) {
			// Clean up rollback record on failure.
			$this->delete_rollback( $rollback_id );
			$this->audit( 'option.update.failed', $option, [
				'old_value' => $old_value,
				'new_value' => $new_value,
			], $context );
			return new \WP_Error( 'wpcc_option_update_failed', __( 'Failed to update the option.', 'wp-command-center' ) );
		}

		$this->audit( 'option.update.completed', $option, [
			'old_value'   => $old_value,
			'new_value'   => $new_value,
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'       => 'option_update',
			'option_id'    => $option['option_id'],
			'option_name'  => $option['option_name'],
			'old_value'    => $old_value,
			'new_value'    => $new_value,
			'risk_level'   => $option['risk_level'],
			'rollback_id'  => $rollback_id,
			'rollbackable' => true,
		];
	}

	/**
	 * Roll back an option update using a stored rollback record.
	 */
	private function option_rollback( array $option, string $rollback_id, array $context ): array|\WP_Error {
		if ( '' === $rollback_id ) {
			return new \WP_Error( 'wpcc_missing_rollback_id', __( 'rollback_id is required for rollback.', 'wp-command-center' ) );
		}

		$record = $this->get_rollback( $rollback_id );
		if ( null === $record ) {
			return new \WP_Error( 'wpcc_rollback_not_found', __( 'Rollback record not found.', 'wp-command-center' ) );
		}

		if ( $record['rollback_applied'] ) {
			return new \WP_Error( 'wpcc_rollback_already_applied', __( 'Rollback has already been applied.', 'wp-command-center' ) );
		}

		$updated = update_option( $record['option_name'], $record['old_value'] );

		if ( ! $updated ) {
			return new \WP_Error( 'wpcc_rollback_failed', __( 'Failed to restore the previous option value.', 'wp-command-center' ) );
		}

		// Mark rollback as applied.
		$this->mark_rollback_applied( $rollback_id );

		$this->audit( 'option.update.rolled_back', $option, [
			'old_value'   => $record['old_value'],
			'new_value'   => $record['new_value'],
			'rollback_id' => $rollback_id,
		], $context );

		return [
			'action'       => 'option_rollback',
			'option_id'    => $option['option_id'],
			'option_name'  => $option['option_name'],
			'restored_to'  => $record['old_value'],
			'was_value'    => $record['new_value'],
			'rollback_id'  => $rollback_id,
		];
	}

	/**
	 * Store rollback metadata before an update.
	 */
	private function store_rollback( string $rollback_id, array $option, mixed $old_value, mixed $new_value, array $context ): ?array {
		$record = [
			'id'               => $rollback_id,
			'option_id'        => $option['option_id'],
			'option_name'      => $option['option_name'],
			'old_value'        => $old_value,
			'new_value'        => $new_value,
			'old_value_type'   => gettype( $old_value ),
			'rollback_applied' => false,
			'created_at'       => time(),
			'operation_id'     => $context['operation_id'] ?? 'option_manage',
			'queue_item_id'    => $context['queue_id'] ?? '',
			'session_id'       => $context['session_id'] ?? '',
			'task_id'          => $context['task_id'] ?? '',
		];

		$records = get_option( 'wpcc_option_rollbacks', [] );
		$records[ $rollback_id ] = $record;
		update_option( 'wpcc_option_rollbacks', $records );

		return $record;
	}

	private function get_rollback( string $rollback_id ): ?array {
		$records = get_option( 'wpcc_option_rollbacks', [] );
		return $records[ $rollback_id ] ?? null;
	}

	private function mark_rollback_applied( string $rollback_id ): void {
		$records = get_option( 'wpcc_option_rollbacks', [] );
		if ( isset( $records[ $rollback_id ] ) ) {
			$records[ $rollback_id ]['rollback_applied'] = true;
			$records[ $rollback_id ]['applied_at']       = time();
			update_option( 'wpcc_option_rollbacks', $records );
		}
	}

	private function delete_rollback( string $rollback_id ): void {
		$records = get_option( 'wpcc_option_rollbacks', [] );
		unset( $records[ $rollback_id ] );
		update_option( 'wpcc_option_rollbacks', $records );
	}

	/**
	 * Normalize a value based on its expected type.
	 */
	private function normalize_value( mixed $value, string $type ): mixed {
		switch ( $type ) {
			case OptionRegistry::TYPE_INTEGER:
				return (int) $value;
			case OptionRegistry::TYPE_BOOL:
				return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			case OptionRegistry::TYPE_STRING:
			case OptionRegistry::TYPE_EMAIL:
			case OptionRegistry::TYPE_URL:
				return (string) $value;
			default:
				return $value;
		}
	}

	public function get_registry(): OptionRegistry {
		return $this->registry;
	}
}
