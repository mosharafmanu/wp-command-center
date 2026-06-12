<?php
namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class FormsRuntimeManager {

	private AuditLog $audit;
	private array $providers = [];

	public function __construct() {
		$this->audit = new AuditLog();
		if ( defined( 'WPCF7_VERSION' ) ) $this->providers['cf7'] = new CF7Provider();
	}

	public function run( array $payload, array $context = [] ): array {
		$provider = sanitize_key( (string) ( $payload['provider'] ?? 'cf7' ) );
		if ( ! isset( $this->providers[ $provider ] ) ) {
			return $this->error( 'wpcc_provider_not_available', sprintf( __( 'Form provider "%s" is not available.', 'wp-command-center' ), $provider ) );
		}
		$p = $this->providers[ $provider ];
		$a = (string) ( $payload['action'] ?? '' );
		if ( ! in_array( $a, FormsRegistry::ACTIONS, true ) ) {
			return $this->error( 'wpcc_invalid_forms_action', __( 'Invalid forms action.', 'wp-command-center' ) );
		}

		$result = match ( $a ) {
			FormsRegistry::A_FORM_LIST       => $p->list_forms( $payload ),
			FormsRegistry::A_FORM_GET        => $this->wrap( $a, function() use($p,$payload) { return $p->get_form( (string) ( $payload['form_id'] ?? '' ) ); }, 'wpcc_form_not_found', 'Form not found.' ),
			FormsRegistry::A_FORM_SEARCH     => $p->search_forms( sanitize_text_field( (string) ( $payload['search'] ?? '' ) ) ),
			FormsRegistry::A_FORM_CREATE     => $this->mutation( $a, $p, $payload, $context, 'create_form', 'form_create' ),
			FormsRegistry::A_FORM_UPDATE     => $this->mutation( $a, $p, $payload, $context, 'update_form', 'form_update' ),
			FormsRegistry::A_FORM_DUPLICATE  => $this->mutation( $a, $p, $payload, $context, 'duplicate_form', 'form_duplicate' ),
			FormsRegistry::A_FORM_DELETE     => $this->mutation( $a, $p, $payload, $context, 'delete_form', 'form_delete' ),
			FormsRegistry::A_FORM_ACTIVATE   => $this->wrap( $a, fn() => $p->activate_form( (string) ( $payload['form_id'] ?? '' ) ), 'wpcc_activate_failed', 'Activation failed.' ),
			FormsRegistry::A_FORM_DEACTIVATE => $this->wrap( $a, fn() => $p->deactivate_form( (string) ( $payload['form_id'] ?? '' ) ), 'wpcc_deactivate_failed', 'Deactivation failed.' ),
			FormsRegistry::A_ENTRY_LIST      => $p->list_entries( (string) ( $payload['form_id'] ?? '' ), $payload ),
			FormsRegistry::A_ENTRY_GET       => $this->wrap( $a, fn() => $p->get_entry( (string) ( $payload['entry_id'] ?? '' ), (string) ( $payload['form_id'] ?? '' ) ), 'wpcc_entry_not_found', 'Entry not found.' ),
			FormsRegistry::A_ENTRY_SEARCH    => $p->search_entries( sanitize_text_field( (string) ( $payload['search'] ?? '' ) ) ),
			FormsRegistry::A_ENTRY_EXPORT    => $p->export_entries( (string) ( $payload['form_id'] ?? '' ) ),
			FormsRegistry::A_NOTIF_GET       => $this->wrap( $a, fn() => $p->get_notification( (string) ( $payload['form_id'] ?? '' ) ), 'wpcc_form_not_found', 'Form not found.' ),
			FormsRegistry::A_NOTIF_UPDATE    => $this->mutation( $a, $p, $payload, $context, 'update_notification', 'notification_update' ),
			FormsRegistry::A_NOTIF_TEST      => $this->wrap( $a, fn() => $p->test_notification( (string) ( $payload['form_id'] ?? '' ) ), 'wpcc_form_not_found', 'Form not found.' ),
			FormsRegistry::A_SUBMISSION_STATS => $p->submission_stats(),
			FormsRegistry::A_FORM_ANALYZE     => $p->analyze_form( (string) ( $payload['form_id'] ?? '' ) ),
			default => $this->error( 'wpcc_unknown_action', 'Unknown action.' ),
		};

		$this->audit_auto( $a, $payload, $result );
		if ( in_array( $a, [ FormsRegistry::A_FORM_CREATE, FormsRegistry::A_FORM_UPDATE, FormsRegistry::A_FORM_DELETE, FormsRegistry::A_FORM_DUPLICATE, FormsRegistry::A_NOTIF_UPDATE ] ) ) {
			$rid = $result['id'] ?? $result['form_id'] ?? ( $payload['form_id'] ?? '' );
			if ( $rid ) $this->store_rollback( (string) $rid, $a, [], $context );
		}
		return array_merge( [ 'action' => $a, 'provider' => $provider ], is_array( $result ) ? $result : [] );
	}

	public function rollback( array $payload, array $context = [] ): array {
		$rid = (string) ( $payload['rollback_id'] ?? '' );
		if ( '' === $rid ) return $this->error( 'wpcc_missing_rollback_id', __( 'Rollback ID required.', 'wp-command-center' ) );
		$rollbacks = get_option( 'wpcc_forms_rollbacks', [] );
		$rec = null; $idx = null;
		foreach ( $rollbacks as $i => $r ) { if ( $r['id'] === $rid ) { $rec = $r; $idx = $i; break; } }
		if ( ! $rec ) return $this->error( 'wpcc_rollback_not_found', __( 'Rollback not found.', 'wp-command-center' ) );
		if ( $rec['rollback_applied'] ) return $this->error( 'wpcc_rollback_already_applied', __( 'Already applied.', 'wp-command-center' ) );
		// Undo create = delete
		$act = $rec['action'];
		$eid = $rec['entity_id'];
		$prov = $this->providers[ $rec['provider'] ?? 'cf7' ] ?? null;
		if ( $prov ) {
			if ( in_array( $act, [ 'form_create', 'form_duplicate' ] ) ) $prov->delete_form( $eid );
			elseif ( 'form_delete' === $act ) wp_publish_post( (int) $eid );
		}
		$rollbacks[ $idx ]['rollback_applied'] = true;
		update_option( 'wpcc_forms_rollbacks', $rollbacks );
		return [ 'action' => 'forms_rollback', 'rollback_id' => $rid ];
	}

	private function mutation( string $action, FormsProvider $p, array $payload, array $context, string $method, string $rollback_action ): array {
		$id = (string) ( $payload['form_id'] ?? '' );
		$result = 'create_form' === $method ? $p->$method( $payload ) : $p->$method( $id, $payload );
		if ( $result && FormsRegistry::supports_rollback( $action ) && isset( $result['id'] ) ) {
			$this->store_rollback( (string) $result['id'], $rollback_action, [], $context );
		}
		return $result ?: $this->error( 'wpcc_mutation_failed', __( 'Operation failed.', 'wp-command-center' ) );
	}

	private function wrap( string $action, callable $fn, string $err_code, string $err_msg ): array {
		$r = $fn();
		return $r ?: $this->error( $err_code, $err_msg );
	}

	private function store_rollback( string $id, string $action, array $before, array $cx ): void {
		$rollbacks = get_option( 'wpcc_forms_rollbacks', [] );
		$rollbacks[] = [ 'id' => wp_generate_uuid4(), 'entity_id' => $id, 'action' => $action, 'before_state' => $before, 'rollback_applied' => false, 'created_at' => time(),
			'provider' => 'cf7', 'session_id' => $cx['session_id'] ?? null, 'task_id' => $cx['task_id'] ?? null ];
		if ( count( $rollbacks ) > 200 ) $rollbacks = array_slice( $rollbacks, -200 );
		update_option( 'wpcc_forms_rollbacks', $rollbacks );
	}

	private function audit_auto( string $action, array $payload, array $result ): void {
		$map = [
			FormsRegistry::A_FORM_CREATE => [ 'form.created', 'form.created' ],
			FormsRegistry::A_FORM_UPDATE => [ 'form.updated', 'form.updated' ],
			FormsRegistry::A_FORM_DELETE => [ 'form.deleted', 'form.deleted' ],
			FormsRegistry::A_FORM_DUPLICATE => [ 'form.duplicated', 'form.duplicated' ],
			FormsRegistry::A_FORM_ACTIVATE => [ 'form.activated', 'form.activated' ],
			FormsRegistry::A_FORM_DEACTIVATE => [ 'form.deactivated', 'form.deactivated' ],
			FormsRegistry::A_NOTIF_UPDATE => [ 'notification.updated', 'notification.updated' ],
			FormsRegistry::A_NOTIF_TEST => [ 'notification.tested', 'notification.tested' ],
			FormsRegistry::A_ENTRY_EXPORT => [ 'entry.exported', 'entry.exported' ],
			FormsRegistry::A_FORM_ANALYZE => [ 'form.analyzed', 'form.analyzed' ],
		];
		if ( isset( $map[ $action ] ) ) {
			$this->audit->record( $map[ $action ][0], [ 'form_id' => $payload['form_id'] ?? null ] );
		}
	}

	private function error( string $code, string $message ): array {
		return [ 'error' => true, 'code' => $code, 'message' => $message ];
	}
}
