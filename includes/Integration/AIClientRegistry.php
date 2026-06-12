<?php
/**
 * Step 53 — AI Client Registry (Certification Framework).
 *
 * Single source of truth for all supported AI clients. Each client
 * carries a certification level from the unified certification framework.
 * All clients connect through the same MCP Server Runtime — no per-client
 * runtimes.
 */

namespace WPCommandCenter\Integration;

defined( 'ABSPATH' ) || exit;

final class AIClientRegistry {

	const CERT_PLANNED   = 'planned';
	const CERT_COMPATIBLE = 'compatible';
	const CERT_ACTIVE     = 'active';
	const CERT_BRONZE     = 'bronze';
	const CERT_SILVER     = 'silver';
	const CERT_GOLD       = 'gold';

	const CERT_LEVELS = [
		self::CERT_PLANNED,
		self::CERT_COMPATIBLE,
		self::CERT_ACTIVE,
		self::CERT_BRONZE,
		self::CERT_SILVER,
		self::CERT_GOLD,
	];

	const CERT_LABELS = [
		self::CERT_PLANNED   => 'Planned',
		self::CERT_COMPATIBLE => 'Compatible',
		self::CERT_ACTIVE     => 'Active',
		self::CERT_BRONZE     => 'Certified Bronze',
		self::CERT_SILVER     => 'Certified Silver',
		self::CERT_GOLD       => 'Certified Gold',
	];

	const CERT_DESCRIPTIONS = [
		self::CERT_PLANNED   => 'Not yet validated or implemented.',
		self::CERT_COMPATIBLE => 'Connects successfully via MCP.',
		self::CERT_ACTIVE     => 'Connects and discovers tools and resources.',
		self::CERT_BRONZE     => 'Discovery validated (resources + tools).',
		self::CERT_SILVER     => 'Bronze + capabilities, approvals, and queue validated.',
		self::CERT_GOLD       => 'Silver + rollback, audit, timeline, security, and stress testing.',
	];

	/**
	 * Registered AI clients with certification tracking.
	 */
	public static function get_clients(): array {
		return [
			'claude' => [
				'name'               => 'Claude Desktop',
				'type'               => 'desktop',
				'vendor'             => 'Anthropic',
				'status'             => self::CERT_GOLD,
				'certification_level' => self::CERT_GOLD,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Full gold certification: discovery, resources, tools, capabilities, approvals, queue, rollback, audit, timeline, security, performance, stress testing all validated.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ ClaudeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ ClaudeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/Library/Application Support/Claude/claude_desktop_config.json',
					'windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
					'linux'   => '~/.config/Claude/claude_desktop_config.json',
				],
				'description'        => 'Claude Desktop by Anthropic. MCP-native client with full tool, resource, and prompt support.',
				'website'            => 'https://claude.ai/download',
			],
			'chatgpt' => [
				'name'               => 'ChatGPT',
				'type'               => 'desktop',
				'vendor'             => 'OpenAI',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ ChatGPTIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ ChatGPTIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/Library/Application Support/ChatGPT/mcp.json',
					'windows' => '%APPDATA%\\ChatGPT\\mcp.json',
					'linux'   => '~/.config/ChatGPT/mcp.json',
				],
				'description'        => 'ChatGPT by OpenAI. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://chatgpt.com',
			],
			'codex' => [
				'name'               => 'Codex',
				'type'               => 'desktop',
				'vendor'             => 'OpenAI',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ CodexIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ CodexIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/Library/Application Support/Codex/codex_config.json',
					'windows' => '%APPDATA%\\Codex\\codex_config.json',
					'linux'   => '~/.config/Codex/codex_config.json',
				],
				'description'        => 'OpenAI Codex. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://openai.com',
			],
			'gemini' => [
				'name'               => 'Gemini',
				'type'               => 'desktop',
				'vendor'             => 'Google',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ GeminiIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ GeminiIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/Library/Application Support/Gemini/mcp.json',
					'windows' => '%APPDATA%\\Gemini\\mcp.json',
					'linux'   => '~/.config/Gemini/mcp.json',
				],
				'description'        => 'Google Gemini. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://gemini.google.com',
			],
			'cursor' => [
				'name'               => 'Cursor',
				'type'               => 'ide',
				'vendor'             => 'Anysphere',
				'status'             => self::CERT_GOLD,
				'certification_level' => self::CERT_GOLD,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Full gold certification: MCP discovery, 7 resources, 15 tools, capabilities, approvals, queue, rollback, audit, timeline, security, stress (20 rapid/0 failures). Uses shared MCP endpoint — no per-client runtime.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ CursorIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ CursorIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.cursor/mcp.json',
					'windows' => '%APPDATA%\\Cursor\\mcp.json',
					'linux'   => '~/.config/Cursor/mcp.json',
				],
				'description'        => 'Cursor IDE by Anysphere. MCP-compatible. Certified Gold — all platform features validated.',
				'website'            => 'https://cursor.com',
			],
			'continue' => [
				'name'               => 'Continue',
				'type'               => 'ide_plugin',
				'vendor'             => 'Continue Dev',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ ContinueIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ ContinueIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.continue/mcp.json',
					'windows' => '%USERPROFILE%\\.continue\\mcp.json',
					'linux'   => '~/.continue/mcp.json',
				],
				'description'        => 'Continue open-source AI code assistant. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://continue.dev',
			],
			'opencode' => [
				'name'               => 'OpenCode',
				'type'               => 'cli',
				'vendor'             => 'Anomaly',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ OpenCodeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ OpenCodeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.config/opencode/mcp.json',
					'windows' => '%APPDATA%\\opencode\\mcp.json',
					'linux'   => '~/.config/opencode/mcp.json',
				],
				'description'        => 'OpenCode CLI. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://opencode.ai',
			],
			'aider' => [
				'name'               => 'Aider',
				'type'               => 'cli',
				'vendor'             => 'Aider AI',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ AiderIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ AiderIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.aider/mcp.json',
					'linux'   => '~/.aider/mcp.json',
				],
				'description'        => 'Aider AI pair programming. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://aider.chat',
			],
			'roo_code' => [
				'name'               => 'Roo Code',
				'type'               => 'ide_plugin',
				'vendor'             => 'Roo',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ RooCodeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ RooCodeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/Library/Application Support/Roo Code/mcp.json',
					'windows' => '%APPDATA%\\Roo Code\\mcp.json',
					'linux'   => '~/.config/Roo Code/mcp.json',
				],
				'description'        => 'Roo Code IDE extension. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://roocode.com',
			],
			'windsurf' => [
				'name'               => 'Windsurf',
				'type'               => 'ide',
				'vendor'             => 'Codeium',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ WindsurfIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ WindsurfIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/Library/Application Support/Windsurf/mcp.json',
					'windows' => '%APPDATA%\\Windsurf\\mcp.json',
					'linux'   => '~/.config/Windsurf/mcp.json',
				],
				'description'        => 'Windsurf IDE by Codeium. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://codeium.com/windsurf',
			],
			'command_code' => [
				'name'               => 'Command Code',
				'type'               => 'cli',
				'vendor'             => 'Command Code',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-06-12',
				'validation_notes'   => 'Connects via the shared MCP Server Runtime — the same protocol-compliant endpoint independently certified Gold for Claude Desktop and Cursor. Not yet individually certified end-to-end for this client.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ CommandCodeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ CommandCodeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.config/command-code/mcp.json',
					'linux'   => '~/.config/command-code/mcp.json',
				],
				'description'        => 'Command Code CLI. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => '',
			],
		];
	}

	/**
	 * Get only clients at or above a certification level.
	 */
	public static function get_certified_clients( string $min_level = self::CERT_ACTIVE ): array {
		$levels = array_flip( self::CERT_LEVELS );
		$min    = $levels[ $min_level ] ?? 0;
		return array_filter( self::get_clients(), static fn( $c ) => ( $levels[ $c['certification_level'] ] ?? -1 ) >= $min );
	}

	/**
	 * Get active (configured) clients.
	 */
	public static function get_active_clients(): array {
		return array_filter( self::get_clients(), static fn( $c ) => self::CERT_PLANNED !== $c['certification_level'] && null !== $c['config_generator'] );
	}

	/**
	 * Get a specific client by ID or null if not found.
	 */
	public static function get_client( string $client_id ): ?array {
		return self::get_clients()[ $client_id ] ?? null;
	}

	/**
	 * Generate configuration for a specific client.
	 */
	public static function generate_config( string $client_id ): ?array {
		$client = self::get_client( $client_id );
		if ( ! $client || ! $client['config_generator'] ) {
			return null;
		}
		return call_user_func( $client['config_generator'] );
	}

	/**
	 * Get discovery metadata for a specific client.
	 */
	public static function get_discovery( string $client_id ): ?array {
		$client = self::get_client( $client_id );
		if ( ! $client || ! $client['discovery_generator'] ) {
			return null;
		}
		return call_user_func( $client['discovery_generator'] );
	}

	/**
	 * Client counts for dashboards.
	 */
	public static function get_counts(): array {
		$all          = self::get_clients();
		$active       = count( self::get_certified_clients( self::CERT_ACTIVE ) );
		$configured   = count( self::get_active_clients() );
		$certified    = count( self::get_certified_clients( self::CERT_BRONZE ) );
		$gold         = count( self::get_certified_clients( self::CERT_GOLD ) );

		return [
			'total'       => count( $all ),
			'active'      => $active,
			'configured'  => $configured,
			'connected'   => $configured,
			'certified'   => $certified,
			'gold'        => $gold,
			'planned'     => count( array_filter( $all, static fn( $c ) => self::CERT_PLANNED === $c['certification_level'] ) ),
		];
	}

	/**
	 * Build a certification compatibility matrix for admin display.
	 */
	public static function get_compatibility_matrix(): array {
		$matrix = [];
		foreach ( self::get_clients() as $id => $client ) {
			$matrix[] = [
				'id'                   => $id,
				'name'                 => $client['name'],
				'vendor'               => $client['vendor'],
				'type'                 => $client['type'],
				'compatible'           => $client['compatible'],
				'configured'           => self::CERT_PLANNED !== $client['certification_level'] && null !== $client['config_generator'],
				'connected'            => self::CERT_PLANNED !== $client['certification_level'],
				'mcp_support'          => $client['mcp_support'],
				'status'               => $client['status'],
				'certification_level'  => $client['certification_level'],
				'certification_label'  => self::CERT_LABELS[ $client['certification_level'] ] ?? 'Unknown',
				'last_validated_at'    => $client['last_validated_at'],
				'validation_notes'     => $client['validation_notes'],
			];
		}
		return $matrix;
	}
}
