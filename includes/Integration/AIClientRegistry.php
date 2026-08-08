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
	/**
	 * Render a client's configuration as the text the user actually pastes.
	 *
	 * Three things made this necessary. Not every client takes JSON — Codex reads TOML and
	 * Claude Code takes a shell command, so those generators return a `__raw` block that
	 * must be printed verbatim rather than json_encode()d into a quoted string. Not every
	 * client uses the same root key — VS Code uses `servers` where the rest use
	 * `mcpServers`. And the token used to be injected by reaching into a fixed path
	 * (`['mcpServers']['wp-command-center']['env']['WPCC_TOKEN']`), which silently did
	 * nothing for any client that did not happen to have that exact shape.
	 *
	 * Every generator emits the same `${WPCC_TOKEN}` placeholder, so substitution happens
	 * once on the rendered text and works for all shapes — JSON, TOML or shell — without
	 * this function needing to know any client's structure.
	 *
	 * @param array<string,mixed> $config Generator output.
	 * @param string              $token  Real token, or '' to leave the placeholder.
	 */
	public static function render_config( array $config, string $token = '' ): string {
		if ( isset( $config['__raw'] ) ) {
			$text = (string) $config['__raw'];
		} else {
			$text = (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		if ( '' !== $token ) {
			$text = str_replace( self::TOKEN_PLACEHOLDER, $token, $text );
		}

		return $text;
	}

	/**
	 * The one string that stands in for the access token in a rendered configuration.
	 *
	 * Every generator emits this, `render_config()` substitutes this, the setup screen
	 * tells the user to look for this, and the browser-side token-fill box searches for
	 * this. It is a constant because those four had already disagreed: the generators
	 * moved to `${WPCC_TOKEN}` while the screen still named `wpcc_YOUR_TOKEN_HERE`, so
	 * the instruction pointed at a string that was not in the box AND the token-fill
	 * field silently did nothing — it replaced a placeholder the configuration no
	 * longer contained, leaving "Copy configuration" to copy an unusable config.
	 */
	public const TOKEN_PLACEHOLDER = '${WPCC_TOKEN}';

	/**
	 * Which transport a client uses: 'http' (direct, no local process) or 'stdio' (relay).
	 *
	 * Derived from the integration class behind the client's config generator, so the
	 * answer always comes from the same class that produced the configuration rather than
	 * from a second list that could drift away from it.
	 */
	public static function transport_for( string $client_id ): string {
		$client = self::get_client( $client_id );
		$class  = $client['config_generator'][0] ?? '';

		if ( is_string( $class ) && is_callable( [ $class, 'transport' ] ) ) {
			return (string) call_user_func( [ $class, 'transport' ] );
		}

		return 'stdio';
	}

	/**
	 * Presentation badges for one client. Pure formatting — reads what the registry
	 * already knows and returns labels; it decides nothing.
	 *
	 * The certification badge is driven entirely by `status`, so awarding certification
	 * after a real end-to-end run is a one-field change in the registry rather than an
	 * edit to any view. Only CERT_GOLD renders "Certified", and only one client holds it:
	 * Claude Code, whose twelve-step run was executed against a live site. Nothing here
	 * may claim more than has actually been executed.
	 *
	 * @return array<int,array{label:string,tone:string,title:string}>
	 */
	public static function ui_badges( string $client_id ): array {
		$client = self::get_client( $client_id );
		if ( ! $client ) {
			return [];
		}

		$badges = [];

		/*
		 * Order and rank ARE the message. Returned strongest-first so the eye travels
		 * name -> recommendation -> transport -> certification, which is the order the
		 * questions actually occur to someone choosing a client: "which should I pick?",
		 * "what will this need from me?", "how far has it been proven?".
		 *
		 * `rank` carries that weighting to the stylesheet so the three never render at
		 * the same visual strength again. Previously all three were identical pills, so
		 * the page stated three unrelated facts in one uniform voice and none of them led.
		 */

		// PRIMARY — the recommendation. The one badge that answers "which do I pick?".
		if ( 'recommended' === ( $client['tier'] ?? '' ) ) {
			$badges[] = [
				'label' => __( 'Recommended', 'ai-command-center' ),
				'tone'  => 'rec',
				'rank'  => 'primary',
				'title' => __( 'Widely used, and its configuration here is confirmed against the vendor\'s current documentation.', 'ai-command-center' ),
			];
		}

		// SECONDARY — transport. Decides whether anything gets installed on your machine.
		if ( 'http' === self::transport_for( $client_id ) ) {
			$badges[] = [
				'label' => __( 'Direct HTTP', 'ai-command-center' ),
				'tone'  => 'info',
				'rank'  => 'secondary',
				'title' => __( 'Connects straight to this site. No connector script and no Node.js on your computer.', 'ai-command-center' ),
			];
		} else {
			$badges[] = [
				'label' => __( 'Relay', 'ai-command-center' ),
				'tone'  => 'neutral',
				'rank'  => 'secondary',
				'title' => __( 'Runs a small connector script on your computer, which needs Node.js installed.', 'ai-command-center' ),
			];
		}

		/*
		 * Certification state — a claim is made only where one has been earned.
		 *
		 * This block used to stay silent for the default status and reason that "absence
		 * means certified, which is true of everything shipped". It was not true of
		 * anything shipped: every client sat at CERT_COMPATIBLE and no assistant had been
		 * driven end to end at all, so silence quietly asserted eleven certifications that
		 * did not exist. The convention also cannot survive its first real result — the
		 * moment one client is certified and another is not, "no badge" has to mean two
		 * opposite things at once.
		 *
		 * So the claim is positive now. `Certified` appears only on a client whose full
		 * twelve-step run has actually been executed and recorded in
		 * docs/ASSISTANT-CERTIFICATION.md §7. A client with no badge is making no claim,
		 * which is the honest description of a client nobody has run the checklist
		 * against — and the Recommended and transport badges still carry the information
		 * that actually helps someone choose.
		 */
		$status = $client['status'] ?? self::CERT_COMPATIBLE;
		if ( self::CERT_GOLD === $status ) {
			$badges[] = [
				'label' => __( 'Certified', 'ai-command-center' ),
				'tone'  => 'ok',
				'rank'  => 'secondary',
				'title' => __( 'Connecting, reading, proposing, approving, undoing and reconnecting have all been run end to end in this assistant against a live site.', 'ai-command-center' ),
			];
		} elseif ( in_array( $status, [ self::CERT_BRONZE, self::CERT_SILVER, self::CERT_ACTIVE ], true ) ) {
			$badges[] = [
				'label' => __( 'Experimental', 'ai-command-center' ),
				'tone'  => 'warn',
				'rank'  => 'secondary',
				'title' => __( 'Connects and reads, but approving and undoing changes has not been fully checked in this assistant.', 'ai-command-center' ),
			];
		} elseif ( self::CERT_PLANNED === $status ) {
			$badges[] = [
				'label' => __( 'Not supported', 'ai-command-center' ),
				'tone'  => 'bad',
				'rank'  => 'secondary',
				'title' => __( 'This assistant is not supported. Do not rely on it.', 'ai-command-center' ),
			];
		}

		return $badges;
	}

	/** The syntax of a rendered config, for the copy box's language hint. */
	public static function config_format( array $config ): string {
		return isset( $config['__format'] ) ? (string) $config['__format'] : 'json';
	}

	public static function get_clients(): array {
		return [
			'claude' => [
				'tier'               => 'recommended',
				'name'               => 'Claude Desktop',
				'type'               => 'desktop',
				'vendor'             => 'Anthropic',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
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
				'tier'               => 'experimental',
				'name'               => 'ChatGPT',
				'type'               => 'desktop',
				'vendor'             => 'OpenAI',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ ChatGPTIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ ChatGPTIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.codex/config.toml (shared with Codex CLI)',
					'windows' => '%USERPROFILE%\\.codex\\config.toml (shared with Codex CLI)',
					'linux'   => '~/.codex/config.toml (shared with Codex CLI)',
				],
				'description'        => 'ChatGPT by OpenAI. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://chatgpt.com',
			],
			'codex' => [
				'tier'               => 'recommended',
				'name'               => 'Codex',
				'type'               => 'desktop',
				'vendor'             => 'OpenAI',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ CodexIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ CodexIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.codex/config.toml',
					'windows' => '%USERPROFILE%\\.codex\\config.toml',
					'linux'   => '~/.codex/config.toml',
				],
				'description'        => 'OpenAI Codex. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://openai.com',
			],
			'gemini' => [
				'tier'               => 'recommended',
				'name'               => 'Gemini',
				'type'               => 'desktop',
				'vendor'             => 'Google',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ GeminiIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ GeminiIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.gemini/settings.json',
					'windows' => '%USERPROFILE%\\.gemini\\settings.json',
					'linux'   => '~/.gemini/settings.json',
				],
				'description'        => 'Google Gemini. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://gemini.google.com',
			],
			'cursor' => [
				'tier'               => 'recommended',
				'name'               => 'Cursor',
				'type'               => 'ide',
				'vendor'             => 'Anysphere',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
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
				'tier'               => 'experimental',
				'name'               => 'Continue',
				'type'               => 'ide_plugin',
				'vendor'             => 'Continue Dev',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
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
				'tier'               => 'experimental',
				'name'               => 'OpenCode',
				'type'               => 'cli',
				'vendor'             => 'Anomaly',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
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
			'windsurf' => [
				'tier'               => 'experimental',
				'name'               => 'Windsurf',
				'type'               => 'ide',
				'vendor'             => 'Codeium',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ WindsurfIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ WindsurfIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.codeium/windsurf/mcp_config.json',
					'windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
					'linux'   => '~/.codeium/windsurf/mcp_config.json',
				],
				'description'        => 'Windsurf IDE by Codeium. MCP-compatible. Connects via the shared MCP Server Runtime.',
				'website'            => 'https://codeium.com/windsurf',
			],
			'command_code' => [
				'tier'               => 'experimental',
				'name'               => 'Command Code',
				'type'               => 'cli',
				'vendor'             => 'Command Code',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results.',
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
			'vscode' => [
				'tier'               => 'recommended',
				'name'               => 'GitHub Copilot / VS Code',
				'type'               => 'ide',
				'vendor'             => 'GitHub / Microsoft',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'Server-side transport verified 2026-08-04 (handshake, 42 tools, 7 resources, governance chain). NOT individually certified: no end-to-end run has been executed in this client. Certification is awarded only from docs/ASSISTANT-CERTIFICATION.md §7 results. Root key is `servers`, not `mcpServers` — a config using the wrong key is silently ignored rather than rejected.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ VSCodeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ VSCodeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '.vscode/mcp.json (workspace) or the user mcp.json',
					'windows' => '.vscode\\mcp.json (workspace) or the user mcp.json',
					'linux'   => '.vscode/mcp.json (workspace) or the user mcp.json',
				],
				'description'        => 'GitHub Copilot in VS Code — the most widely adopted AI coding assistant. Connects over direct HTTP; no relay or Node.js required.',
				'website'            => 'https://code.visualstudio.com/docs/agent-customization/mcp-servers',
			],
			'claude_code' => [
				'tier'               => 'recommended',
				'name'               => 'Claude Code',
				'type'               => 'cli',
				'vendor'             => 'Anthropic',
				'status'             => self::CERT_GOLD,
				'certification_level' => self::CERT_GOLD,
				'last_validated_at'  => '2026-08-04',
				'validation_notes'   => 'CERTIFIED 2026-08-04 — the full twelve-step checklist (docs/ASSISTANT-CERTIFICATION.md §7) was executed in this client against a live HTTPS site (WordPress 6.9.5, PHP 8.3.30): connect, 42 tools, 7 resources, read, proposal, nothing applied, self-approval refused (wpcc_approval_requires_human), human approval, audit attribution, undo (itself gated, then applied and restored exactly), double-undo refused (wpcc_already_rolled_back), reconnect. Registered with `claude mcp add`, not a hand-edited config file.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ ClaudeCodeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ ClaudeCodeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => 'registered via `claude mcp add` (no file to edit)',
					'windows' => 'registered via `claude mcp add` (no file to edit)',
					'linux'   => 'registered via `claude mcp add` (no file to edit)',
				],
				'description'        => 'Claude Code CLI by Anthropic. Connects over direct HTTP; no relay or Node.js required.',
				'website'            => 'https://claude.com/claude-code',
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
