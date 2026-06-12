<?php
/**
 * Step 48 — AI Client Integration Layer.
 *
 * Claude Desktop is one registered AI client in the AIClientRegistry.
 * This class provides Claude-specific config generation, discovery,
 * and metadata. No execution logic — consumes the existing MCP Server
 * Runtime exclusively.
 */

namespace WPCommandCenter\Integration;

use WPCommandCenter\Mcp\McpServerRuntime;
use WPCommandCenter\Operations\OperationRegistry;
use WPCommandCenter\Operations\CapabilityRegistry;
use WPCommandCenter\Operations\WpCliBridge;
use WPCommandCenter\Operations\OptionRegistry;
use WPCommandCenter\Operations\PluginRegistry;
use WPCommandCenter\Operations\ThemeRegistry;
use WPCommandCenter\Operations\SnapshotRegistry;
use WPCommandCenter\Operations\ContentRegistry;
use WPCommandCenter\Operations\DatabaseRegistry;
use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ClaudeIntegration {

	const TOOL_GROUPS = [
		'content'  => [
			'label'       => 'Content',
			'description' => 'Inspect, create, update, publish, schedule, and delete WordPress posts and pages. Assign taxonomies and featured images.',
			'tools'       => [ 'content_manage' ],
		],
		'plugins' => [
			'label'       => 'Plugins',
			'description' => 'List, install, activate, deactivate, update, and delete WordPress plugins with health verification and rollback.',
			'tools'       => [ 'plugin_manage' ],
		],
		'themes'  => [
			'label'       => 'Themes',
			'description' => 'List, install, activate, update, and delete WordPress themes with health verification and rollback.',
			'tools'       => [ 'theme_manage' ],
		],
		'database' => [
			'label'       => 'Database',
			'description' => 'Read-only database health, size, table statistics, autoload analysis, index analysis, and orphan detection.',
			'tools'       => [ 'database_inspect' ],
		],
		'snapshots' => [
			'label'       => 'Snapshots',
			'description' => 'Create, list, inspect, verify, and restore file snapshots for rollback-capable changes.',
			'tools'       => [ 'snapshot_manage' ],
		],
		'wp_cli'   => [
			'label'       => 'WP-CLI',
			'description' => 'Run structured, safe WP-CLI commands through the operations framework with risk scoring and approval gating.',
			'tools'       => [ 'wp_cli_bridge' ],
		],
		'options'  => [
			'label'       => 'Options',
			'description' => 'Inspect and safely update registered WordPress options with validation and rollback.',
			'tools'       => [ 'option_manage' ],
		],
		'seeding'  => [
			'label'       => 'Seeding',
			'description' => 'Seed content, ACF fields, Contact Form 7 forms, and WooCommerce products.',
			'tools'       => [ 'content_seed', 'acf_seed', 'cf7_seed', 'woo_product_seed' ],
		],
		'media'    => [
			'label'       => 'Media',
			'description' => 'Import remote images into the WordPress Media Library with strict validation.',
			'tools'       => [ 'media_import' ],
		],
		'updates'  => [
			'label'       => 'Updates',
			'description' => 'Safe plugin and theme updates with post-update health verification.',
			'tools'       => [ 'safe_updates' ],
		],
		'replace'  => [
			'label'       => 'Search & Replace',
			'description' => 'Safe database search and replace with dry-run preview and approval gating.',
			'tools'       => [ 'safe_search_replace' ],
		],
		'capabilities' => [
			'label'       => 'Capabilities',
			'description' => 'List, assign, remove, and validate platform capabilities for tokens and operations.',
			'tools'       => [ 'capability_manage' ],
		],
	];

	const PROMPT_TEMPLATES = [
		[
			'name'        => 'inspect_site',
			'title'       => 'Inspect Site',
			'description' => 'Inspect site health, diagnostics, and configuration.',
			'prompt'      => 'Inspect this WordPress site:\n\n1. Run health verification\n2. Check diagnostics (performance, security, WooCommerce)\n3. Review open recommendations\n4. List active plugins and theme\n5. Report database size and health\n\nDo not make any changes. Report findings only.',
		],
		[
			'name'        => 'review_recommendations',
			'title'       => 'Review Recommendations',
			'description' => 'Review and act on deterministic recommendations.',
			'prompt'      => 'Review the open recommendations for this site:\n\n1. Run a recommendation scan\n2. List all open recommendations\n3. For each critical recommendation, explain the finding and proposed fix\n4. Convert the highest-priority recommendations to actions\n\nDo not create patches or execute operations without approval.',
		],
		[
			'name'        => 'create_content',
			'title'       => 'Create Content',
			'description' => 'Create a new post or page.',
			'prompt'      => 'Create a new WordPress post:\n\n1. Ask me for the title and content\n2. Create a draft post\n3. Show me the preview link\n4. Publish only after my confirmation\n\nUse content_manage with action content_create.',
		],
		[
			'name'        => 'plugin_maintenance',
			'title'       => 'Plugin Maintenance',
			'description' => 'Review and maintain WordPress plugins.',
			'prompt'      => 'Review the plugins on this site:\n\n1. List all plugins with their status and versions\n2. Identify any plugins with available updates\n3. Report inactive plugins\n4. Recommend whether to update or remove any plugins\n\nDo not install, update, or delete plugins without approval.',
		],
		[
			'name'        => 'theme_maintenance',
			'title'       => 'Theme Maintenance',
			'description' => 'Review and maintain WordPress themes.',
			'prompt'      => 'Review the themes on this site:\n\n1. List all installed themes\n2. Identify the active theme\n3. Check for available theme updates\n4. Report inactive themes\n\nDo not switch themes or install/delete themes without approval.',
		],
		[
			'name'        => 'database_health_review',
			'title'       => 'Database Health Review',
			'description' => 'Inspect database health, sizes, and structure.',
			'prompt'      => 'Review the database health:\n\n1. Run db_health_summary to get overall size and table count\n2. Check db_table_stats for individual table sizes\n3. Run db_autoload_analysis for autoloaded options review\n4. Check db_index_analysis for index recommendations\n5. Run db_orphan_detection for orphaned data\n\nReport findings only. Do not run OPTIMIZE or REPAIR without approval.',
		],
		[
			'name'        => 'manage_options',
			'title'       => 'Manage Options',
			'description' => 'Inspect and update WordPress options safely.',
			'prompt'      => 'Manage WordPress options:\n\n1. Review current site settings (site title, tagline, timezone, date/time formats)\n2. Check reading settings (posts per page, front page)\n3. Check discussion settings\n4. Report current values\n\nOnly update options after my explicit confirmation. Use option_manage with option_get first.',
		],
	];

	/**
	 * Generate a Claude Desktop MCP configuration block dynamically.
	 *
	 * Downloads the latest WPCC MCP relay script on every startup and runs it.
	 * The relay bridges Claude Desktop's stdio transport to the WPCC HTTP MCP endpoint.
	 * Never hardcodes environment-specific values.
	 */
	public static function generate_mcp_config(): array {
		$mcp_url    = rest_url( McpServerRuntime::NAMESPACE . '/mcp' );
		$site_url   = get_site_url();
		$relay_url  = WPCC_PLUGIN_URL . 'sdk/javascript/wpcc-mcp-relay.mjs';
		$relay_path = '/tmp/wpcc-mcp-relay.mjs';

		$bootstrap = sprintf(
			'RELAY=%s; curl -fsSL -o "$RELAY" %s; node "$RELAY"',
			escapeshellarg( $relay_path ),
			escapeshellarg( $relay_url . '?v=' . WPCC_VERSION )
		);

		return [
			'mcpServers' => [
				'wp-command-center' => [
					'command' => 'bash',
					'args'    => [ '-c', $bootstrap ],
					'env'     => [
						'WPCC_MCP_URL'      => $mcp_url,
						'WPCC_SITE_URL'     => $site_url,
						'WPCC_TOKEN'        => '${WPCC_TOKEN}',
						'WPCC_CONTEXT_MODE' => 'compact',
					],
				],
			],
		];
	}

	/**
	 * Return Claude-specific MCP discovery metadata.
	 */
	public static function get_discovery_metadata(): array {
		$ops        = ( new OperationRegistry() )->get_operations();
		$mcp_url    = rest_url( McpServerRuntime::NAMESPACE . '/mcp' );
		$bridge     = new WpCliBridge();
		$cap_reg    = new CapabilityRegistry();

		$resources = [
			[ 'uri' => 'wpcc://manifest', 'name' => 'Agent Manifest', 'mimeType' => 'application/json', 'description' => 'Full agent manifest with capabilities, endpoints, and error catalog.' ],
			[ 'uri' => 'wpcc://context', 'name' => 'Agent Context', 'mimeType' => 'application/json', 'description' => 'Runtime context snapshot: health, capabilities, site summary, operations, queue, recommendations.' ],
			[ 'uri' => 'wpcc://capabilities', 'name' => 'Capabilities', 'mimeType' => 'application/json', 'description' => 'Platform capability assignments and enforcement status.' ],
			[ 'uri' => 'wpcc://operations', 'name' => 'Operations', 'mimeType' => 'application/json', 'description' => 'All available operations with parameters, risk levels, and approval requirements.' ],
			[ 'uri' => 'wpcc://queue', 'name' => 'Queue Status', 'mimeType' => 'application/json', 'description' => 'Current operation queue state: pending, running, and failed counts.' ],
			[ 'uri' => 'wpcc://results', 'name' => 'Results', 'mimeType' => 'application/json', 'description' => 'Recent operation execution results.' ],
			[ 'uri' => 'wpcc://recommendations', 'name' => 'Recommendations', 'mimeType' => 'application/json', 'description' => 'Open deterministic recommendations and their severities.' ],
		];

		$tools = [];
		foreach ( $ops as $op ) {
			$capability = CapabilityRegistry::OPERATION_MAP[ $op['id'] ] ?? null;
			$tools[] = [
				'name'                => $op['id'],
				'title'               => $op['title'],
				'description'         => $op['description'],
				'risk_level'          => $op['risk_level'],
				'requires_approval'   => $op['requires_approval'],
				'required_capability' => $capability,
				'group'               => self::resolve_tool_group( $op['id'] ),
				'parameters'          => $op['parameters'],
			];
		}

		return [
			'server'        => [
				'name'           => 'WP Command Center MCP',
				'version'        => WPCC_VERSION,
				'protocol'       => 'JSON-RPC 2.0',
				'mcp_version'    => McpServerRuntime::MCP_VERSION,
				'mcp_endpoint'   => $mcp_url,
				'site_url'       => get_site_url(),
				'documentation'  => rest_url( McpServerRuntime::NAMESPACE . '/agent/manifest' ),
			],
			'resources'     => $resources,
			'tools'         => $tools,
			'tool_groups'   => self::get_tool_groups(),
			'capabilities'  => [
				'enforcement'   => (bool) get_option( 'wpcc_enforce_capabilities', true ),
				'capabilities'  => CapabilityRegistry::ALL_CAPABILITIES,
				'operation_map' => CapabilityRegistry::OPERATION_MAP,
			],
			'approval'      => [
				'enforcement'     => (bool) get_option( 'wpcc_enforce_approval', false ),
				'required_for'    => array_values( array_filter( $ops, static fn( $op ) => $op['requires_approval'] ) ),
				'not_required_for' => array_column( array_filter( $ops, static fn( $op ) => ! $op['requires_approval'] ), 'id' ),
			],
			'wp_cli'        => [
				'available'      => $bridge->is_available(),
				'command_count'  => count( $bridge->get_supported_commands() ),
				'risk_counts'    => $bridge->count_by_risk(),
			],
			'pricing'       => [
				'free'      => true,
				'open_source' => true,
			],
			'compatibility' => [
				'claude_desktop' => true,
				'mcp_client'     => true,
				'requires_token' => true,
			],
		];
	}

	/**
	 * Resolve which group a tool belongs to.
	 */
	private static function resolve_tool_group( string $tool_id ): ?string {
		foreach ( self::TOOL_GROUPS as $group_key => $group ) {
			if ( in_array( $tool_id, $group['tools'], true ) ) {
				return $group_key;
			}
		}
		return null;
	}

	/**
	 * Return tool groups with full metadata (label, description, tools).
	 */
	public static function get_tool_groups(): array {
		$ops = ( new OperationRegistry() )->get_operations();
		$op_map = [];
		foreach ( $ops as $op ) {
			$op_map[ $op['id'] ] = $op;
		}

		$groups = [];
		foreach ( self::TOOL_GROUPS as $group_key => $group ) {
			$tools_in_group = [];
			foreach ( $group['tools'] as $tool_id ) {
				if ( isset( $op_map[ $tool_id ] ) ) {
					$op = $op_map[ $tool_id ];
					$cap = CapabilityRegistry::OPERATION_MAP[ $tool_id ] ?? null;
					$tools_in_group[] = [
						'id'                  => $op['id'],
						'title'              => $op['title'],
						'description'        => $op['description'],
						'risk_level'         => $op['risk_level'],
						'requires_approval'  => $op['requires_approval'],
						'required_capability' => $cap,
					];
				}
			}
			$groups[] = [
				'group'       => $group_key,
				'label'       => $group['label'],
				'description' => $group['description'],
				'tools'       => $tools_in_group,
			];
		}

		return $groups;
	}

	/**
	 * Return prompt template information (no execution logic).
	 */
	public static function get_prompt_templates(): array {
		return [
			'prompts' => self::PROMPT_TEMPLATES,
			'meta'    => [
				'total'      => count( self::PROMPT_TEMPLATES ),
				'read_only'  => true,
				'note'       => 'Prompts are informational guidance only. They contain no execution logic and do not invoke tools directly.',
			],
		];
	}

	/**
	 * Record a Claude-specific audit event.
	 */
	public static function audit( string $event, array $data = [], array $context = [] ): void {
		$audit = new AuditLog();
		$actor = isset( $context['actor'] ) ? AuditLog::resolve_actor( $context['actor'] ) : null;
		$audit->record( $event, array_merge( [ 'source' => 'claude', 'actor' => $actor ], $data ) );
	}

	/**
	 * Return AI client summary for agent context/manifest.
	 */
	public static function get_context_block(): array {
		$ops    = ( new OperationRegistry() )->get_operations();
		$mcp_url = rest_url( McpServerRuntime::NAMESPACE . '/mcp' );
		$counts  = AIClientRegistry::get_counts();

		return [
			'ai_clients' => [
				'available'       => true,
				'mcp_endpoint'    => $mcp_url,
				'mcp_active'      => true,
				'tool_count'      => count( $ops ),
				'resource_count'  => 7,
				'group_count'     => count( self::TOOL_GROUPS ),
				'prompt_count'    => count( self::PROMPT_TEMPLATES ),
				'compatibility'   => 'MCP-compatible (JSON-RPC 2.0)',
				'active_clients'  => $counts['active'],
				'configured_clients' => $counts['configured'],
				'connected_clients'  => $counts['connected'],
				'total_clients'      => $counts['total'],
			],
		];
	}
}
