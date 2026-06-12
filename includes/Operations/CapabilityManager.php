<?php
/**
 * Step 44 — Capability Manager.
 * CRUD for capability assignments, validation, enforcement.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class CapabilityManager {

	private CapabilityRegistry $registry;

	public function __construct() {
		$this->registry = new CapabilityRegistry();
	}

	public function run( array $params, array $context = [] ): array|\WP_Error {
		$action = sanitize_key( $params['action'] ?? '' );
		if ( ! in_array( $action, CapabilityRegistry::ACTIONS, true ) ) {
			return new \WP_Error( 'wpcc_invalid_capability_action', __( 'Invalid capability action.', 'wp-command-center' ) );
		}

		return match ( $action ) {
			CapabilityRegistry::ACTION_LIST     => $this->list_caps(),
			CapabilityRegistry::ACTION_GET      => $this->get_caps( $params ),
			CapabilityRegistry::ACTION_ASSIGN   => $this->assign( $params, $context ),
			CapabilityRegistry::ACTION_REMOVE   => $this->remove_cap( $params, $context ),
			CapabilityRegistry::ACTION_VALIDATE => $this->validate_op( $params, $context ),
			default => new \WP_Error( 'wpcc_invalid_capability_action', __( 'Unknown action.', 'wp-command-center' ) ),
		};
	}

	private function list_caps(): array {
		return [ 'action' => 'capability_list', 'summary' => $this->registry->get_summary() ];
	}

	private function get_caps( array $params ): array|\WP_Error {
		$subject    = sanitize_key( $params['subject'] ?? 'token' );
		$subject_id = sanitize_text_field( $params['subject_id'] ?? '' );
		if ( '' === $subject_id ) {
			return new \WP_Error( 'wpcc_missing_subject_id', __( 'subject_id is required.', 'wp-command-center' ) );
		}
		return [
			'action'      => 'capability_get',
			'subject'     => $subject,
			'subject_id'  => $subject_id,
			'capabilities'=> $this->registry->get_for_subject( $subject, $subject_id ),
		];
	}

	private function assign( array $params, array $context ): array|\WP_Error {
		$subject    = sanitize_key( $params['subject'] ?? 'token' );
		$subject_id = sanitize_text_field( $params['subject_id'] ?? '' );
		$capability = sanitize_text_field( $params['capability'] ?? '' );

		if ( '' === $subject_id ) {
			return new \WP_Error( 'wpcc_missing_subject_id', __( 'subject_id is required.', 'wp-command-center' ) );
		}
		if ( '' === $capability ) {
			return new \WP_Error( 'wpcc_missing_capability', __( 'capability is required.', 'wp-command-center' ) );
		}

		$result = $this->registry->assign( $subject, $subject_id, $capability );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$this->audit( 'capability.assigned', [ 'subject' => $subject, 'subject_id' => $subject_id, 'capability' => $capability ], $context );

		return [
			'action'     => 'capability_assign',
			'subject'    => $subject,
			'subject_id' => $subject_id,
			'capability' => $capability,
			'assigned'   => true,
		];
	}

	private function remove_cap( array $params, array $context ): array|\WP_Error {
		$subject    = sanitize_key( $params['subject'] ?? 'token' );
		$subject_id = sanitize_text_field( $params['subject_id'] ?? '' );
		$capability = sanitize_text_field( $params['capability'] ?? '' );

		if ( '' === $subject_id ) {
			return new \WP_Error( 'wpcc_missing_subject_id', __( 'subject_id is required.', 'wp-command-center' ) );
		}
		if ( '' === $capability ) {
			return new \WP_Error( 'wpcc_missing_capability', __( 'capability is required.', 'wp-command-center' ) );
		}

		$result = $this->registry->remove( $subject, $subject_id, $capability );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$this->audit( 'capability.removed', [ 'subject' => $subject, 'subject_id' => $subject_id, 'capability' => $capability ], $context );

		return [
			'action'     => 'capability_remove',
			'subject'    => $subject,
			'subject_id' => $subject_id,
			'capability' => $capability,
			'removed'    => true,
		];
	}

	private function validate_op( array $params, array $context ): array|\WP_Error {
		$operation  = sanitize_key( $params['operation'] ?? '' );
		$subject    = sanitize_key( $params['subject'] ?? 'token' );
		$subject_id = sanitize_text_field( $params['subject_id'] ?? '' );

		if ( '' === $operation ) {
			return new \WP_Error( 'wpcc_missing_operation', __( 'operation is required.', 'wp-command-center' ) );
		}

		$result = $this->registry->validate( $operation, $subject, $subject_id );

		$this->audit( 'capability.validated', [
			'operation'  => $operation,
			'subject'    => $subject,
			'subject_id' => $subject_id,
			'allowed'    => $result['allowed'],
			'required'   => $result['required_capability'],
		], $context );

		if ( ! $result['allowed'] ) {
			$this->audit( 'capability.denied', [
				'operation'  => $operation,
				'subject'    => $subject,
				'subject_id' => $subject_id,
				'required'   => $result['required_capability'],
			], $context );
		}

		return [ 'action' => 'capability_validate' ] + $result;
	}

	private function audit( string $event, array $data, array $context = [] ): void {
		$audit = new AuditLog();
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$audit->record( $event, array_merge( [ 'risk_level' => CapabilityRegistry::RISK_LOW, 'actor' => $actor ], $data ) );
	}

	public function get_registry(): CapabilityRegistry {
		return $this->registry;
	}
}
