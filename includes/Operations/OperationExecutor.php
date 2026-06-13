<?php
/**
 * Step 21 — Central Operation Executor.
 *
 * A unified engine for dispatching and standardizing the execution of
 * all WordPress operations (seeders, updates, search-replace, etc.).
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Operations\MediaRuntimeManager;
use WPCommandCenter\Operations\WooCommerceRuntimeManager;
use WPCommandCenter\Operations\UserManager;
use WPCommandCenter\Operations\ACFRuntimeManager;
use WPCommandCenter\Operations\FormsRuntimeManager;
use WPCommandCenter\Operations\MenuRuntimeManager;
use WPCommandCenter\Operations\SettingsRuntimeManager;
use WPCommandCenter\Operations\SearchRuntimeManager;
use WPCommandCenter\Operations\BulkRuntimeManager;
use WPCommandCenter\Operations\WorkflowRuntimeManager;
use WPCommandCenter\Operations\CommentsRuntimeManager;
use WPCommandCenter\Operations\WidgetsRuntimeManager;
use WPCommandCenter\Operations\CPTRuntimeManager;
use WPCommandCenter\Operations\ApprovalRuntimeManager;
use WPCommandCenter\Operations\SecurityModeManager;

defined( 'ABSPATH' ) || exit;

final class OperationExecutor {

	/**
	 * Execute a WordPress operation by ID.
	 *
	 * @param string $operation_id
	 * @param array  $payload
	 * @param array  $context Optional metadata (session_id, task_id, etc.)
	 *
	 * @return array{
	 *     operation_id: string,
	 *     success: bool,
	 *     result: array,
	 *     errors: array,
	 *     created: array,
	 *     updated: array,
	 *     skipped: array
	 * }
	 */
	public function run( string $operation_id, array $payload, array $context = [] ): array {
		$audit      = new AuditLog();
		$actor      = $context['actor'] ?? null;
		$started_at = microtime( true );
		$links      = array_intersect_key( $context, array_flip( [ 'session_id', 'task_id', 'action_id', 'plan_id', 'request_id', 'queue_id' ] ) );

		$audit->record( "operation.{$operation_id}.started", array_merge( $links, [
			'params' => $payload,
			'actor'  => $actor ? AuditLog::resolve_actor( $actor ) : null,
		] ) );

		$audit->record( 'operation.execution.started', array_merge( $links, [
			'operation_id' => $operation_id,
			'payload'      => $payload,
			'context'      => array_diff_key( $context, [ 'actor' => 1 ] ),
			'actor'        => $actor ? AuditLog::resolve_actor( $actor ) : null,
		] ) );

		$registry  = new OperationRegistry();
		$operation = $registry->get_operation( $operation_id );

		// 1. Validation: Operation exists.
		if ( ! $operation ) {
			return $this->fail( $operation_id, 'operation_not_found', __( 'Operation not found in registry.', 'wp-command-center' ) );
		}

		// 1b. Capability enforcement (enabled by default).
		if ( get_option( 'wpcc_enforce_capabilities', true ) ) {
			$cap_registry = new CapabilityRegistry();
			$token_id     = $context['token_id'] ?? ( $actor['id'] ?? '' );
			if ( '' !== $token_id ) {
				$validation = $cap_registry->validate( $operation_id, 'token', $token_id );
				if ( ! $validation['allowed'] ) {
					$audit->record( 'capability.denied', [
						'operation_id' => $operation_id,
						'token_id'     => $token_id,
						'required'     => $validation['required_capability'],
						'actor'        => $actor ? AuditLog::resolve_actor( $actor ) : null,
					] );
					return $this->fail( $operation_id, 'wpcc_capability_denied', sprintf( __( 'Missing capability: %s', 'wp-command-center' ), $validation['required_capability'] ) );
				}
			}
		}

		// 1c. Step 80 — Security Mode approval gate.
		// When a mode other than Developer requires approval for the operation's
		// effective risk level, auto-create an approval request and return a
		// structured pending_approval response instead of a -32000 error. The AI
		// receives enough information to poll for completion without manual steps.
		$is_queued    = ! empty( $context['queue_id'] );
		$is_requested = ! empty( $context['request_id'] );
		if ( ! $is_queued && ! $is_requested ) {
			$action         = (string) ( $payload['action'] ?? '' );
			$effective_risk = SecurityModeManager::effective_risk( $operation, $action );

			if ( SecurityModeManager::requires_approval( $effective_risk ) ) {
				$request_meta = [
					'session_id' => $context['session_id'] ?? null,
					'task_id'    => $context['task_id'] ?? null,
					'action_id'  => $context['action_id'] ?? null,
					'plan_id'    => $context['plan_id'] ?? null,
					'actor'      => $actor,
				];

				$request = ( new OperationManager() )->create_request( $operation_id, $payload, $request_meta );

				if ( is_wp_error( $request ) ) {
					return $this->fail( $operation_id, $request->get_error_code(), $request->get_error_message() );
				}

				$audit->record( 'operation.approval.auto_requested', array_merge( $links, [
					'operation_id'  => $operation_id,
					'request_id'    => $request['request_id'],
					'risk_level'    => $effective_risk,
					'security_mode' => SecurityModeManager::current(),
					'actor'         => $actor ? AuditLog::resolve_actor( $actor ) : null,
				] ) );

				return $this->pending_approval( $operation_id, $request, $effective_risk, $action );
			}
		}

		// 2. Validation: Operation is available.
		if ( empty( $operation['available'] ) ) {
			return $this->fail( $operation_id, 'operation_not_available', __( 'Operation is not available in the current environment.', 'wp-command-center' ) );
		}

		// 3. Dispatch to handler.
		$handler = $this->resolve_handler( $operation_id );
		if ( ! $handler ) {
			return $this->fail( $operation_id, 'execution_failed', __( 'Execution logic not yet implemented for this operation.', 'wp-command-center' ) );
		}

		$result = $handler->run( $payload, $context );
		$ended_at = microtime( true );
		$duration = (int) ( ( $ended_at - $started_at ) * 1000 );

		$res_manager = new OperationResults();
		$res_base = [
			'operation_id'      => $operation_id,
			'queue_id'          => $context['queue_id'] ?? null,
			'request_id'        => $context['request_id'] ?? null,
			'execution_time_ms' => $duration,
			'started_at'        => (int) $started_at,
			'completed_at'      => (int) $ended_at,
		] + $links;

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();

			// Emit blocked/denied audit events for wp_cli_bridge operations.
			if ( 'wp_cli_bridge' === $operation_id && 'wpcc_wpcli_blocked' === $error_code ) {
				$audit->record( 'operation.wp_cli_bridge.blocked', array_merge( $links, [
					'command_id'    => $payload['command_id'] ?? null,
					'error_message' => $result->get_error_message(),
					'actor'         => $actor ? AuditLog::resolve_actor( $actor ) : null,
				] ) );
			} elseif ( 'wp_cli_bridge' === $operation_id && 'wpcc_invalid_wpcli_command' === $error_code ) {
				$audit->record( 'operation.wp_cli_bridge.denied', array_merge( $links, [
					'command_id'    => $payload['command_id'] ?? null,
					'error_message' => $result->get_error_message(),
					'actor'         => $actor ? AuditLog::resolve_actor( $actor ) : null,
				] ) );
			}

			$audit->record( "operation.{$operation_id}.failed", array_merge( $links, [
				'error_code'    => $error_code,
				'error_message' => $result->get_error_message(),
				'actor'         => $actor ? AuditLog::resolve_actor( $actor ) : null,
			] ) );

			$res_id = $res_manager->create( array_merge( $res_base, [
				'status'      => 'failed',
				'error_count' => 1,
				'error_json'  => wp_json_encode( [
					'code'    => $error_code,
					'message' => $result->get_error_message(),
				] ),
			] ) );

			$audit->record( 'operation.result.failed', array_merge( $links, [
				'result_id'    => $res_id,
				'operation_id' => $operation_id,
				'actor'        => $actor ? AuditLog::resolve_actor( $actor ) : null,
			] ) );

			$audit->record( 'operation.execution.failed', array_merge( $links, [
				'operation_id'  => $operation_id,
				'error_code'    => $error_code,
				'error_message' => $result->get_error_message(),
				'actor'         => $actor ? AuditLog::resolve_actor( $actor ) : null,
			] ) );

			return $this->fail( $operation_id, $error_code, $result->get_error_message() );
		}

		// 4. Normalize response.
		$normalized = $this->normalize_success( $operation_id, $result );

		$res_id = $res_manager->create( array_merge( $res_base, [
			'status'        => 'completed',
			'created_count' => count( $normalized['created'] ),
			'updated_count' => count( $normalized['updated'] ),
			'skipped_count' => count( $normalized['skipped'] ),
			'result_json'   => wp_json_encode( $normalized ),
		] ) );

		$audit->record( 'operation.result.completed', array_merge( $links, [
			'result_id'    => $res_id,
			'operation_id' => $operation_id,
			'actor'        => $actor ? AuditLog::resolve_actor( $actor ) : null,
		] ) );

		$audit->record( "operation.{$operation_id}.completed", array_merge( $links, [
			'result' => $result,
			'actor'  => $actor ? AuditLog::resolve_actor( $actor ) : null,
		] ) );

		$audit->record( 'operation.execution.completed', array_merge( $links, [
			'operation_id' => $operation_id,
			'result'       => $normalized,
			'actor'        => $actor ? AuditLog::resolve_actor( $actor ) : null,
		] ) );

		return $normalized;
	}

	/**
	 * Map operation ID to its handler class.
	 */
	private function resolve_handler( string $operation_id ): ?object {
		switch ( $operation_id ) {
			case 'content_seed':
				return new ContentSeed();
			case 'acf_seed':
				return new AcfSeed();
			case 'cf7_seed':
				return new Cf7Seed();
			case 'woo_product_seed':
				return new WooProductSeed();
			case 'safe_search_replace':
				return new SearchReplace();
			case 'media_import':
				return new MediaImport();
			case 'safe_updates':
				return new SafeUpdates();
			case 'wp_cli_bridge':
				return new WpCliBridge();
			case 'theme_manage':
				return new ThemeManager();
			case 'snapshot_manage':
				return new SnapshotManager();
			case 'content_manage':
				return new ContentManager();
			case 'system_info':
				return new SystemInfoRuntime();
			case 'database_inspect':
				return new DatabaseInspector();
			case 'capability_manage':
				return new CapabilityManager();
			case 'plugin_manage':
				return new PluginManager();
			case 'option_manage':
				return new OptionManager();
			case 'user_manage':
				return new UserManager();
			case 'media_manage':
				return new MediaRuntimeManager();
			case 'woocommerce_manage':
				return new WooCommerceRuntimeManager();
			case 'acf_manage':
				return new ACFRuntimeManager();
			case 'forms_manage':
				return new FormsRuntimeManager();
			case 'menu_manage':
				return new MenuRuntimeManager();
			case 'settings_manage':
				return new SettingsRuntimeManager();
			case 'search_manage':
				return new SearchRuntimeManager();
			case 'bulk_manage':
				return new BulkRuntimeManager();
			case 'workflow_manage':
				return new WorkflowRuntimeManager();
			case 'comments_manage':
				return new CommentsRuntimeManager();
			case 'widgets_manage':
				return new WidgetsRuntimeManager();
			case 'cpt_manage':
				return new CPTRuntimeManager();
			case 'approval_manage':
				return new ApprovalRuntimeManager();
		}

		return null;
	}

	/**
	 * Step 80 — Structured result returned when an operation is queued for approval.
	 * success = true so McpServerRuntime surfaces it as a tool result (not an error),
	 * giving the AI the request_id and polling instructions in one response.
	 */
	private function pending_approval( string $operation_id, array $request, string $risk_level, string $action ): array {
		$mode = SecurityModeManager::current();
		return [
			'operation_id' => $operation_id,
			'success'      => true,
			'result'       => [
				'status'             => 'pending_approval',
				'request_id'         => $request['request_id'],
				'operation'          => $operation_id,
				'action'             => $action,
				'risk_level'         => $risk_level,
				'security_mode'      => $mode,
				'rollback_available' => true,
				'approval_url'       => admin_url( 'admin.php?page=wpcc-approvals' ),
				'message'            => sprintf(
					/* translators: 1: security mode label, 2: request ID, 3: approval URL */
					__( 'Approval required (%1$s). A site administrator must approve this request at: %3$s — or poll status with: approval_manage {action: "request_get", request_id: "%2$s"}', 'wp-command-center' ),
					$mode,
					$request['request_id'],
					admin_url( 'admin.php?page=wpcc-approvals' )
				),
			],
			'errors'  => [],
			'created' => [],
			'updated' => [],
			'skipped' => [],
		];
	}

	/**
	 * Standard result shape for failures.
	 */
	private function fail( string $operation_id, string $code, string $message ): array {
		return [
			'operation_id' => $operation_id,
			'success'      => false,
			'result'       => [],
			'errors'       => [
				[ 'code' => $code, 'message' => $message ],
			],
			'created'      => [],
			'updated'      => [],
			'skipped'      => [],
		];
	}

	/**
	 * Standard result shape for success, extracting common seeder outputs.
	 */
	private function normalize_success( string $operation_id, array $result ): array {
		$base = [
			'operation_id' => $operation_id,
			'success'      => true,
			'result'       => $result,
			'errors'       => [],
			'created'      => [],
			'updated'      => [],
			'skipped'      => [],
		];

		// Extract created IDs from seeder-style results.
		if ( isset( $result['created_ids'] ) ) {
			$base['created'] = $result['created_ids'];
		} elseif ( isset( $result['id'] ) && is_numeric( $result['id'] ) ) {
			$base['created'] = [ (int) $result['id'] ];
		} elseif ( isset( $result['product_id'] ) ) {
			$base['created'] = [ (int) $result['product_id'] ];
		}

		// Extract content_id for content operations.
		if ( isset( $result['content_id'] ) && is_numeric( $result['content_id'] ) && $result['content_id'] > 0 ) {
			if ( ! in_array( (int) $result['content_id'], $base['created'], true ) ) {
				$base['created'][] = (int) $result['content_id'];
			}
		}

		if ( isset( $result['execution_result'] ) && is_array( $result['execution_result'] ) ) {
			$base['updated'] = array_keys( array_filter( $result['execution_result'], fn( $s ) => 'updated' === $s ) );
		}

		return $base;
	}
}
