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

	private AuditLog $audit;
	private Redactor $redactor;

	public function __construct() {
		$this->audit    = new AuditLog();
		$this->redactor = new Redactor();
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
					'tools'     => [],
					'resources' => [],
					'prompts'   => [],
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
					'type' => $p['type'] === 'integer' ? 'number' : 'string',
				];
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
					'required'   => array_keys( array_filter( $op['parameters'], static fn( $p ) => ! empty( $p['required'] ) ) ),
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

		$this->audit( 'mcp.tool.invoke', [ 'tool' => $tool_name, 'args' => $args ], $context );

		// Scope check — a read_only token may only call read-only-scope operations,
		// regardless of whether the operation is mapped in CapabilityRegistry::OPERATION_MAP.
		// Mirrors RestApi::require_write() so MCP cannot grant more access than REST.
		$cap_reg = new CapabilityRegistry();
		$token_scope = $context['token_scope'] ?? '';
		if ( AuthTokens::SCOPE_READ_ONLY === $token_scope && $cap_reg->requires_full_scope( $tool_name ) ) {
			$this->audit( 'mcp.denied', [ 'tool' => $tool_name, 'reason' => 'insufficient_scope' ], $context );
			return $this->error( -32001, __( 'This API token is read-only and cannot perform this action.', 'wp-command-center' ), null );
		}

		// Capability check
		$token_id = $context['token_id'] ?? '';
		if ( '' !== $token_id && get_option( 'wpcc_enforce_capabilities', true ) ) {
			$validation = $cap_reg->validate( $tool_name, 'token', $token_id );
			if ( ! $validation['allowed'] ) {
				$this->audit( 'mcp.denied', [ 'tool' => $tool_name, 'reason' => 'missing_capability', 'required' => $validation['required_capability'] ], $context );
				return $this->error( -32001, 'Operation denied: missing capability ' . $validation['required_capability'], null );
			}
		}

		// Execute via OperationExecutor
		$executor = new OperationExecutor();
		$result   = $executor->run( $tool_name, $args, $context );

		if ( ! $result['success'] ) {
			$err = $result['errors'][0] ?? [ 'message' => 'Unknown error' ];
			return $this->error( -32000, $err['message'], null );
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

	private function error( int $code, string $message, mixed $id ): array {
		return [
			'error' => [ 'code' => $code, 'message' => $message ],
			'id'    => $id,
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
