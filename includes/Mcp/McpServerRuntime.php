<?php
/**
 * Step 45 — MCP Server Runtime.
 * Standards-based MCP adapter. Thin wrapper — no duplicate logic.
 * Resources → existing REST endpoints. Tools → existing operations.
 * All requests pass through Capability → Approval → Queue → Execute.
 */

namespace WPCommandCenter\Mcp;

use WPCommandCenter\AiAgent\ContextSummaryBuilder;
use WPCommandCenter\Operations\CapabilityRegistry;
use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\OperationExecutor;
use WPCommandCenter\Operations\OperationQueue;
use WPCommandCenter\Operations\OperationResults;
use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\AuthTokens;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class McpServerRuntime {

	const NAMESPACE = 'wp-command-center/v1';
	const MCP_VERSION = '2024-11-05';

	/**
	 * F2.2 — Synchronous execution-time budget.
	 *
	 * MCP clients (Claude Desktop, Cursor) abandon a request at ~240s, while PHP
	 * max_execution_time is often far higher (480s on the production host).
	 * Without a server-side budget, an op running in that 240–480s window keeps
	 * executing — and writing — after the client has already reported failure: a
	 * silent partial write. We cap synchronous MCP execution just under the
	 * client timeout so the failure is mutual and clean; genuinely long work
	 * belongs on the OperationQueue/worker, not a synchronous tool call.
	 */
	const MCP_CLIENT_TIMEOUT = 240; // Observed MCP client abandonment (seconds).
	const MCP_TIME_BUDGET    = 200; // Default server budget (< client timeout).

	private AuditLog $audit;
	private Redactor $redactor;

	public function __construct() {
		$this->audit    = new AuditLog();
		$this->redactor = new Redactor();
	}

	// ── F2.2 — Synchronous execution-time budget ──

	/**
	 * Effective synchronous execution budget, in seconds.
	 *
	 * Filterable via `wpcc_mcp_time_budget`; return 0 to disable capping entirely
	 * (escape hatch for hosts that prefer PHP's own limit). The result is clamped
	 * to stay strictly below the client timeout so a structured timeout response
	 * still has a chance to reach the client before it gives up.
	 */
	public static function time_budget(): int {
		$budget = (int) apply_filters( 'wpcc_mcp_time_budget', self::MCP_TIME_BUDGET );
		if ( $budget <= 0 ) {
			return 0; // Capping disabled.
		}
		return min( $budget, self::MCP_CLIENT_TIMEOUT - 1 );
	}

	/**
	 * Cap the current request's execution time at the synchronous budget so the
	 * server cannot keep writing after the MCP client has abandoned the request.
	 *
	 * Only ever LOWERS the limit: a host that already caps execution below the
	 * budget (e.g. 30s shared hosting) is left untouched — its silent-write
	 * window is already closed. Also registers a shutdown handler that turns a
	 * timeout fatal into a clean, AI-readable error plus a terminal audit event,
	 * so a timed-out op no longer reads as "stuck started" in reporting (F7.3).
	 *
	 * @param mixed $id JSON-RPC request id, echoed back on a timeout response.
	 */
	public static function apply_time_budget( $id = null ): void {
		$budget = self::time_budget();
		if ( $budget <= 0 || ! function_exists( 'set_time_limit' ) ) {
			return;
		}

		$current = (int) ini_get( 'max_execution_time' );
		// 0 = unlimited (CLI / some hosts) → always cap. Otherwise only lower.
		if ( 0 === $current || $current > $budget ) {
			@set_time_limit( $budget ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- disabled-function safe.
		}

		register_shutdown_function( [ self::class, 'handle_timeout_shutdown' ], $id );
	}

	/**
	 * Shutdown handler: if the request died on the execution-time limit, record a
	 * terminal audit event and (best-effort) emit a structured timeout response.
	 *
	 * The audit event is the guaranteed behavior; emitting clean JSON is
	 * best-effort because WP's own fatal handler may have already sent output.
	 *
	 * @param mixed $id JSON-RPC request id.
	 */
	public static function handle_timeout_shutdown( $id = null ): void {
		$err = error_get_last();
		if ( null === $err || E_ERROR !== (int) ( $err['type'] ?? 0 ) ) {
			return;
		}
		if ( false === stripos( (string) ( $err['message'] ?? '' ), 'Maximum execution time' ) ) {
			return;
		}

		( new AuditLog() )->record( 'mcp.timeout', [
			'source' => 'mcp',
			'budget' => self::time_budget(),
		] );

		if ( headers_sent() ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( self::timeout_response( $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Structured JSON-RPC body for a synchronous-budget timeout. Mirrors the
	 * isError tool-result shape so an agent reads code + message as tool output.
	 *
	 * @param mixed $id JSON-RPC request id.
	 * @return array<string,mixed>
	 */
	public static function timeout_response( $id = null ): array {
		$message = sprintf(
			/* translators: %d: seconds */
			__( 'Operation exceeded the %ds synchronous execution budget. Queue long-running work instead of calling it synchronously.', 'wp-command-center' ),
			self::time_budget()
		);
		return [
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => [
				'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( [
					'isError' => true,
					'code'    => 'wpcc_operation_timeout',
					'message' => $message,
				] ) ] ],
				'isError' => true,
			],
		];
	}

	/**
	 * Handle an MCP JSON-RPC request.
	 *
	 * JSON-RPC 2.0 §4.1: Notifications (no "id") MUST NOT be responded to.
	 */
	public function handle( array $request, array $context = [] ): array {
		$method = $request['method'] ?? '';
		$params = $request['params'] ?? [];
		$id     = $request['id'] ?? null;

		$this->audit( 'mcp.request', [ 'method' => $method, 'client' => $context['client'] ?? 'unknown' ], $context );

		// Step 79 — Self-healing capability validation. Runs on every
		// authenticated MCP request (any method) so a missing assignment is
		// repaired before the first tools/call. Cheap when already populated
		// (one get_option read, no write); no-ops if token_id is empty.
		if ( ! empty( $context['token_id'] ) ) {
			( new CapabilityRegistry() )->ensure_token_capabilities( $context['token_id'], $context['token_scope'] ?? '' );
		}

		// Notifications — no response expected (JSON-RPC 2.0 §4.1)
		if ( null === $id ) {
			if ( 'notifications/initialized' === $method ) {
				$this->audit( 'mcp.initialized', [], $context );
			}
			// Return sentinel so the REST layer knows not to send a body.
			return [ '_skip' => true, '_notification' => $method ];
		}

		$result = match ( $method ) {
			'initialize'          => $this->initialize( $params ),
			'resources/list'      => $this->resources_list( $params ),
			'resources/read'      => $this->resources_read( $params ),
			'tools/list'          => $this->tools_list( $params ),
			'tools/call'          => $this->tools_call( $params, $context ),
			'prompts/list'        => $this->prompts_list(),
			default               => $this->error( -32601, 'Method not found', $id ),
		};

		if ( isset( $result['error'] ) ) {
			$this->audit( 'mcp.denied', [ 'method' => $method, 'error' => $result['error']['message'] ?? 'unknown' ], $context );
		}

		return [ 'jsonrpc' => '2.0', 'id' => $id ] + $result;
	}

	// ── Initialize ──

	private function initialize( array $params ): array {
		return [
			'result' => [
				'protocolVersion' => self::MCP_VERSION,
				'capabilities'    => [
					'tools'     => new \stdClass(),
					'resources' => new \stdClass(),
					'prompts'   => new \stdClass(),
				],
				'serverInfo'      => [
					'name'    => 'WP Command Center',
					'version' => WPCC_VERSION,
				],
				'instructions'    => 'WP Command Center provides WordPress site management tools for AI agents. Use tools/list to discover available operations (plugin management, content creation, database inspection, etc.) and resources/list to browse site intelligence data. All operations respect WordPress capability enforcement and approval gates.',
			],
		];
	}

	// ── Resources ──

	private function resources_list( array $params = [] ): array {
		$mode = ContextModeOptimizer::normalize( $params['context_mode'] ?? null );
		$this->audit( 'mcp.resource.read', [ 'resource' => 'list' ], [] );
		$resources = [
			[ 'uri' => 'wpcc://manifest', 'name' => 'Agent Manifest', 'mimeType' => 'application/json', 'summary' => 'Security and operation counts', 'description' => 'Full agent manifest' ],
			[ 'uri' => 'wpcc://context', 'name' => 'Agent Context', 'mimeType' => 'application/json', 'summary' => 'Site health and content counts', 'description' => 'Runtime context snapshot' ],
			[ 'uri' => 'wpcc://capabilities', 'name' => 'Capabilities', 'mimeType' => 'application/json', 'summary' => 'Capability counts and enforcement', 'description' => 'Platform capabilities' ],
			[ 'uri' => 'wpcc://operations', 'name' => 'Operations', 'mimeType' => 'application/json', 'summary' => 'Operation availability and risk counts', 'description' => 'Available operations' ],
			[ 'uri' => 'wpcc://queue', 'name' => 'Queue Status', 'mimeType' => 'application/json', 'summary' => 'Pending, running, and failed counts', 'description' => 'Operation queue state' ],
			[ 'uri' => 'wpcc://results', 'name' => 'Results', 'mimeType' => 'application/json', 'summary' => 'Recent operation outcomes', 'description' => 'Recent operation results' ],
			[ 'uri' => 'wpcc://recommendations', 'name' => 'Recommendations', 'mimeType' => 'application/json', 'summary' => 'Open issue counts and top findings', 'description' => 'Open recommendations' ],
		];
		foreach ( $resources as &$resource ) {
			if ( ContextModeOptimizer::COMPACT === $mode ) {
				unset( $resource['description'] );
			} else {
				unset( $resource['summary'] );
			}
		}
		unset( $resource );

		return [
			'result' => [
				'resources' => $resources,
				'_meta'     => [
					'defaultContextMode' => $mode,
					'contextModes'       => ContextModeOptimizer::MODES,
				],
			],
		];
	}

	private function resources_read( array $params ): array {
		$uri  = $params['uri'] ?? '';
		$mode = ContextModeOptimizer::normalize( $params['context_mode'] ?? null );
		$this->audit( 'mcp.resource.read', [ 'uri' => $uri ], [] );

		$data = match ( $uri ) {
			'wpcc://manifest'        => ContextModeOptimizer::COMPACT === $mode ? ( new ContextSummaryBuilder() )->manifest_summary() : $this->fetch_rest( '/agent/manifest' ),
			'wpcc://context'         => ContextModeOptimizer::COMPACT === $mode ? ( new ContextSummaryBuilder() )->build() : $this->fetch_rest( '/agent/context' ),
			'wpcc://capabilities'    => ( new CapabilityRegistry() )->get_summary(),
			'wpcc://operations'      => ( new OperationRegistry() )->get_operations(),
			'wpcc://queue'           => $this->get_queue_status(),
			'wpcc://results'         => $this->get_results(),
			'wpcc://recommendations' => $this->get_recommendations(),
			default                  => null,
		};

		if ( null === $data ) {
			return $this->error( -32002, 'Resource not found: ' . $uri, null );
		}

		$optimized = ( new ContextModeOptimizer() )->optimize( $data, $mode );
		$redacted  = $this->redactor->redact_recursive( $optimized );

		return [
			'result' => [
				'contents' => [
					[ 'uri' => $uri, 'mimeType' => 'application/json', 'text' => wp_json_encode( $redacted['data'] ) ],
				],
			],
		];
	}

	// ── Tools ──

	private function tools_list( array $params = [] ): array {
		$mode    = ContextModeOptimizer::normalize( $params['context_mode'] ?? null );
		$ops     = ( new OperationRegistry() )->get_operations();
		$tools   = [];

		foreach ( $ops as $op ) {
			$props = [];
			foreach ( $op['parameters'] as $p ) {
				$props[ $p['name'] ] = [
					'type' => match ( $p['type'] ) {
						'integer' => 'number',
						'boolean' => 'boolean',
						'object'  => 'object',
						'array'   => 'array',
						default   => 'string',
					},
				];
				if ( isset( $p['enum'] ) ) {
					$props[ $p['name'] ]['enum'] = $p['enum'];
				}
				if ( isset( $p['default'] ) ) {
					$props[ $p['name'] ]['default'] = $p['default'];
				}
				// STEP 105.6 — pass through richer JSON Schema when a parameter
				// declares it (e.g. patch_manage `files`: per-item object schema with
				// a mode enum, per-mode required via oneOf, and worked examples), so
				// the contract is machine-readable, not just descriptive text.
				if ( isset( $p['items'] ) && is_array( $p['items'] ) ) {
					$props[ $p['name'] ]['items'] = $p['items'];
				}
				if ( isset( $p['examples'] ) && ContextModeOptimizer::COMPACT !== $mode ) {
					$props[ $p['name'] ]['examples'] = $p['examples'];
				}
				if ( ContextModeOptimizer::COMPACT !== $mode ) {
					$props[ $p['name'] ]['description'] = $p['description'] ?? $p['name'];
				}
			}
			$props['context_mode'] = [
				'type'    => 'string',
				'enum'    => ContextModeOptimizer::MODES,
				'default' => ContextModeOptimizer::COMPACT,
			];
			if ( ContextModeOptimizer::COMPACT !== $mode ) {
				$props['context_mode']['description'] = 'Response detail: compact, standard, or verbose.';
			}
			if ( 'search_manage' === $op['id'] ) {
				$props['max_results'] = [ 'type' => 'number', 'default' => 20 ];
				$props['cursor']      = [ 'type' => 'string' ];
				if ( ContextModeOptimizer::COMPACT !== $mode ) {
					$props['max_results']['description'] = 'Maximum results, up to 50.';
					$props['cursor']['description']      = 'Opaque cursor from a previous response.';
				}
			}
			$tools[] = [
				'name'        => $op['id'],
				'description' => ContextModeOptimizer::COMPACT === $mode ? $op['title'] : $op['title'] . ': ' . $op['description'],
				'inputSchema' => [
					'type'       => 'object',
					'properties' => $props,
					'required'   => array_values( array_map( static fn( $p ) => $p['name'], array_filter( $op['parameters'], static fn( $p ) => ! empty( $p['required'] ) ) ) ),
				],
			];
		}

		$this->audit( 'mcp.tool.list', [], [] );

		return [ 'result' => [ 'tools' => $tools ] ];
	}

	private function tools_call( array $params, array $context ): array {
		$tool_name = $params['name'] ?? '';
		$args      = $params['arguments'] ?? [];
		$mode      = ContextModeOptimizer::normalize( $args['context_mode'] ?? $params['context_mode'] ?? null );
		unset( $args['context_mode'] );

		// B5: Lift AI continuity fields from args into context so OperationExecutor
		// can store them on auto-created approval requests.
		foreach ( [ 'plan_id', 'session_id', 'task_id', 'step' ] as $field ) {
			if ( isset( $args[ $field ] ) && ! isset( $context[ $field ] ) ) {
				$context[ $field ] = $args[ $field ];
				unset( $args[ $field ] );
			}
		}

		$this->audit( 'mcp.tool.invoke', [ 'tool' => $tool_name, 'args' => $args ], $context );

		// Scope check — a read_only token may only call read-only-scope operations,
		// regardless of whether the operation is mapped in CapabilityRegistry::OPERATION_MAP.
		// Mirrors RestApi::require_write() so MCP cannot grant more access than REST.
		$cap_reg = new CapabilityRegistry();
		$token_scope = $context['token_scope'] ?? '';
		if ( AuthTokens::SCOPE_READ_ONLY === $token_scope && $cap_reg->requires_full_scope( $tool_name ) ) {
			$this->audit( 'mcp.denied', [ 'tool' => $tool_name, 'reason' => 'insufficient_scope' ], $context );
			return $this->tool_error( 'wpcc_token_read_only', __( 'This API token is read-only and cannot perform this action.', 'wp-command-center' ) );
		}

		// Capability check
		$token_id = $context['token_id'] ?? '';
		if ( '' !== $token_id && get_option( 'wpcc_enforce_capabilities', true ) ) {
			$validation = $cap_reg->validate( $tool_name, 'token', $token_id );
			if ( ! $validation['allowed'] ) {
				$this->audit( 'mcp.denied', [ 'tool' => $tool_name, 'reason' => 'missing_capability', 'required' => $validation['required_capability'] ], $context );
				return $this->tool_error( 'wpcc_capability_denied', sprintf(
					/* translators: %s: capability name */
					__( 'Operation denied: missing capability %s', 'wp-command-center' ),
					(string) $validation['required_capability']
				) );
			}
		}

		// Execute via OperationExecutor
		$executor = new OperationExecutor();
		$result   = $executor->run( $tool_name, $args, $context );

		if ( ! $result['success'] ) {
			$err = $result['errors'][0] ?? [ 'code' => 'unknown_error', 'message' => 'Unknown error' ];
			return $this->tool_error( (string) ( $err['code'] ?? 'unknown_error' ), (string) ( $err['message'] ?? 'Unknown error' ) );
		}

		// STEP 89 — some runtime managers report failures in-band as a result of
		// the shape { error:true, code, message } rather than a WP_Error (e.g.
		// media_*). Surface those as isError too, so every failure is uniform and
		// AI-readable regardless of the handler's internal convention.
		$payload = $result['result'] ?? [];
		if ( is_array( $payload ) && ! empty( $payload['error'] ) && isset( $payload['code'] ) && is_string( $payload['code'] ) ) {
			return $this->tool_error( $payload['code'], (string) ( $payload['message'] ?? 'Operation failed.' ) );
		}

		$optimized = ( new ContextModeOptimizer() )->optimize( $result['result'], $mode );
		$redacted  = $this->redactor->redact_recursive( $optimized );

		return [
			'result' => [
				'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $redacted['data'] ) ] ],
			],
		];
	}

	// ── Prompts (informational) ──

	private function prompts_list(): array {
		return [
			'result' => [
				'prompts' => [
					[ 'name' => 'inspect_site', 'description' => 'Inspect site health and configuration' ],
					[ 'name' => 'manage_content', 'description' => 'Manage WordPress content safely' ],
					[ 'name' => 'manage_plugins', 'description' => 'Manage WordPress plugins safely' ],
					[ 'name' => 'manage_themes', 'description' => 'Manage WordPress themes safely' ],
					[ 'name' => 'manage_options', 'description' => 'Manage WordPress options safely' ],
					[ 'name' => 'inspect_database', 'description' => 'Inspect database health' ],
				],
			],
		];
	}

	// ── Helpers ──

	private function error( int $code, string $message, mixed $id, array $data = [] ): array {
		$error = [ 'code' => $code, 'message' => $message ];
		if ( ! empty( $data ) ) {
			$error['data'] = $data;
		}
		return [
			'error' => $error,
			'id'    => $id,
		];
	}

	/**
	 * STEP 89 — MCP error surface hardening.
	 *
	 * Return a tool-execution failure as an MCP `isError` result (per the MCP
	 * spec) instead of a JSON-RPC protocol error, so AI agents receive the
	 * structured code + message as tool output and can explain/recover from it.
	 * JSON-RPC errors (via error(), -326xx) remain reserved for transport/
	 * protocol failures — parse errors, unknown method, unknown resource.
	 *
	 * The content payload mirrors the documented shape:
	 *   { "isError": true, "code": "wpcc_*", "message": "…" }
	 */
	private function tool_error( string $code, string $message ): array {
		return [
			'result' => [
				'content'  => [ [ 'type' => 'text', 'text' => wp_json_encode( [
					'isError' => true,
					'code'    => $code,
					'message' => $message,
				] ) ] ],
				'isError'  => true,
			],
		];
	}

	private function get_context(): array {
		$api      = new RestApi();
		$request  = new \WP_REST_Request( 'GET', '/' );
		$response = $api->get_agent_context( $request );
		return $response->get_data();
	}

	private function get_queue_status(): array {
		$queue = new OperationQueue();
		return [
			'pending' => count( $queue->list_items( [ 'status' => OperationQueue::STATUS_QUEUED, 'limit' => 100 ] ) ),
			'running' => count( $queue->list_items( [ 'status' => OperationQueue::STATUS_RUNNING, 'limit' => 100 ] ) ),
			'failed'  => count( $queue->list_items( [ 'status' => OperationQueue::STATUS_FAILED, 'limit' => 100 ] ) ),
		];
	}

	private function get_results(): array {
		$results = new OperationResults();
		return $results->list_results( [ 'limit' => 10 ] );
	}

	private function get_recommendations(): array {
		$recs = new \WPCommandCenter\Recommendations\RecommendationEngine();
		return $recs->list( [ 'status' => 'open', 'limit' => 20 ] );
	}

	private function fetch_rest( string $path ): array {
		$url = rest_url( self::NAMESPACE . $path );
		$args = [ 'headers' => [] ];
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$args['headers']['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
		}
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}
		return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
	}

	private function audit( string $event, array $data, array $context = [] ): void {
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$this->audit->record( $event, array_merge( [ 'source' => 'mcp', 'actor' => $actor ], $data ) );
	}
}
