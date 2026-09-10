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
		self::CERT_ACTIVE     => 'Pass',
		self::CERT_BRONZE     => 'Certified Bronze',
		self::CERT_SILVER     => 'Certified Silver',
		self::CERT_GOLD       => 'Certified Gold',
	];

	const CERT_DESCRIPTIONS = [
		self::CERT_PLANNED   => 'Not yet validated or implemented.',
		self::CERT_COMPATIBLE => 'Connects successfully via MCP.',
		self::CERT_ACTIVE     => 'Actual client connection and a benign Action Steward read were validated; retained limits are recorded per client.',
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
		return (string) ( self::ask_integration( $client_id, 'transport', [], 'stdio' ) );
	}

	/**
	 * Ask the integration class behind a client a question, falling back to a default
	 * when the client is unknown or predates the method.
	 *
	 * Same reasoning as transport_for(): the answer must come from the class that
	 * generated the configuration, never from a parallel list that can drift away from
	 * it. Every per-client capability added since routes through here so that only one
	 * place knows how to find a client's integration.
	 *
	 * @param array<int,mixed> $args
	 * @return mixed
	 */
	private static function ask_integration( string $client_id, string $method, array $args = [], $default = null ) {
		$client = self::get_client( $client_id );
		$class  = $client['config_generator'][0] ?? '';

		if ( is_string( $class ) && '' !== $class && is_callable( [ $class, $method ] ) ) {
			return call_user_func_array( [ $class, $method ], $args );
		}

		return $default;
	}

	/**
	 * How this client receives the token: 'inline' (written into the config) or
	 * 'env_var' (the config names an environment variable and the token is never in it).
	 *
	 * The setup screen branches on this. An env_var client must NOT be told to paste a
	 * token into its configuration, because the client would then look up an environment
	 * variable whose name is the token — the exact failure this returned from live
	 * testing against ChatGPT Desktop.
	 */
	public static function credential_mode_for( string $client_id ): string {
		return (string) self::ask_integration( $client_id, 'credential_mode', [], 'inline' );
	}

	/** The environment variable an env_var client reads. */
	public static function credential_env_var_for( string $client_id ): string {
		return (string) self::ask_integration( $client_id, 'credential_env_var', [], 'WPCC_TOKEN' );
	}

	/**
	 * A native registration command for this client, or '' when it has none and the user
	 * must edit a file. Never contains a secret for an env_var client.
	 */
	public static function setup_command_for( string $client_id, string $token = '' ): string {
		return (string) self::ask_integration( $client_id, 'setup_command', [ $token ], '' );
	}

	/** App-native install URL, when the client supports a reviewed one-click flow. */
	public static function setup_link_for( string $client_id, string $token = '' ): string {
		return (string) self::ask_integration( $client_id, 'setup_link', [ $token ], '' );
	}

	/** Smallest copyable fragment used by a client's recommended file-based flow. */
	public static function primary_config_for( string $client_id, string $token = '', string $credential_id = '' ): string {
		$scoped = self::ask_integration( $client_id, 'primary_config_for_credential', [ $token, $credential_id ], null );
		if ( is_string( $scoped ) ) {
			return $scoped;
		}

		return (string) self::ask_integration( $client_id, 'primary_config', [ $token ], '' );
	}

	/** Token-free, non-destructive first-run config-file helper, when supported. */
	public static function prepare_config_command_for( string $client_id ): string {
		return (string) self::ask_integration( $client_id, 'prepare_config_command', [], '' );
	}

	/**
	 * Classify the credential contract that controls whether setup can be copied now.
	 *
	 * raw_token     — payload is incomplete until the browser has the current token.
	 * env_var_name  — payload names WPCC_TOKEN; the secret is supplied separately.
	 * client_prompt — payload is complete and the client securely asks for the secret.
	 */
	public static function setup_credential_class_for( string $client_id ): string {
		$mode = self::credential_mode_for( $client_id );
		if ( 'env_var' === $mode ) {
			return 'env_var_name';
		}
		if ( 'prompt' === $mode ) {
			return 'client_prompt';
		}

		return 'raw_token';
	}

	/**
	 * Per-OS commands that put the token where an env_var client will find it.
	 *
	 * @return array<string,string>
	 */
	public static function credential_commands_for( string $client_id, string $token = '' ): array {
		$out = self::ask_integration( $client_id, 'credential_commands', [ $token ], [] );
		return is_array( $out ) ? $out : [];
	}

	/**
	 * Does this client need the application restarted before it sees a newly published
	 * environment variable? True for env_var clients, because a process reads its
	 * environment once at launch — and for GUI apps this is the difference between a
	 * working setup and an inexplicable 401.
	 */
	public static function needs_restart_after_credential( string $client_id ): bool {
		return 'env_var' === self::credential_mode_for( $client_id );
	}

	/**
	 * Client-side behaviour that a correctly configured user will otherwise mistake for a
	 * broken Action Steward connection — a workspace-trust rule that shows the server as "Disabled",
	 * a permission prompt on first tool use, and so on.
	 *
	 * These are the notes that turn a support ticket into a sentence someone already read.
	 *
	 * @return array<int,string>
	 */
	public static function post_setup_notes_for( string $client_id ): array {
		$notes = self::ask_integration( $client_id, 'post_setup_notes', [], [] );
		return is_array( $notes ) ? $notes : [];
	}

	/**
	 * Does this client's configuration live in a file that will already contain unrelated
	 * settings?
	 *
	 * Drives the difference between "create this file" and "merge this INTO your file".
	 * Real cost of getting it wrong, from this finding's testing: a Gemini CLI user whose
	 * settings.json already held authentication, IDE and UI preferences hand-merged
	 * Action Steward's block into it and produced a JSON syntax error. Someone who instead read
	 * "paste this into settings.json" as "replace settings.json" would have silently
	 * discarded all of it.
	 *
	 * True for every file-configured client here: none of these files are Action Steward's to own.
	 * A native command makes the recommended path safe, but a separately displayed manual
	 * fallback still needs the warning because that fallback edits the shared file by hand.
	 */
	/**
	 * Just this server's entry, without the wrapper — the fragment someone adds to a file
	 * that already has other MCP servers in it.
	 *
	 * WHY THIS EXISTS (REAL_TEST_FINDINGS.md #4). The screen offered one artifact: a
	 * complete configuration with a `mcpServers` wrapper, next to a button labelled "Copy
	 * configuration" and a line saying where the file lives. For someone whose
	 * ~/.cursor/mcp.json already contained another MCP server, the obvious reading of
	 * "copy this into that file" destroys the server they already had. The tester had to
	 * work out the merge by hand, and produced a JSON syntax error doing it.
	 *
	 * Giving them the entry ALONE removes the ambiguity: there is nothing to merge
	 * because the fragment is already the thing that goes inside `mcpServers`.
	 *
	 * Returns '' when the client's configuration is not a JSON wrapper+entry shape at all
	 * (Codex emits TOML, Claude Code emits a shell command) — there is no meaningful
	 * fragment to extract from those, and inventing one would be worse than omitting it.
	 *
	 * @param string $token Real token, or '' to keep the placeholder.
	 */
	public static function render_entry_config( string $client_id, string $token = '' ): string {
		$config = self::generate_config( $client_id );

		if ( ! is_array( $config ) || isset( $config['__raw'] ) ) {
			return '';
		}

		$class     = self::get_client( $client_id )['config_generator'][0] ?? '';
		$root_key  = is_string( $class ) && is_callable( [ $class, 'root_key' ] ) ? (string) call_user_func( [ $class, 'root_key' ] ) : 'mcpServers';
		$server_key = is_string( $class ) && is_callable( [ $class, 'server_key' ] ) ? (string) call_user_func( [ $class, 'server_key' ] ) : 'wp-command-center';

		$entry = $config[ $root_key ][ $server_key ] ?? null;
		if ( ! is_array( $entry ) ) {
			return '';
		}

		/*
		 * Rendered as a KEYED fragment — `"wp-command-center": { ... }` — not as a bare
		 * object. The key is half the instruction: it says where the braces go and what
		 * the server will be called. A bare `{ ... }` would leave the reader to invent
		 * both, which is the ambiguity this method exists to remove.
		 */
		$json = (string) wp_json_encode( $entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$json = sprintf( '"%s": %s', $server_key, $json );

		if ( '' !== $token ) {
			$json = str_replace( self::TOKEN_PLACEHOLDER, $token, $json );
		}

		return $json;
	}

	public static function config_file_is_shared( string $client_id ): bool {
		$client = self::get_client( $client_id );
		if ( ! $client || empty( $client['config_paths'] ) ) {
			return false;
		}

		// These advanced fallbacks edit shared files even though native setup is primary.
		// Keep the entry-only merge instructions visible in the fallback itself.
		return in_array( $client_id, [ 'antigravity', 'gemini', 'command_code' ], true ) || '' === self::setup_command_for( $client_id );
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
				'label' => __( 'Recommended', 'action-steward' ),
				'tone'  => 'rec',
				'rank'  => 'primary',
				'title' => __( 'Widely used, and its configuration here is confirmed against the vendor\'s current documentation.', 'action-steward' ),
			];
		}

		// SECONDARY — transport. Decides whether anything gets installed on your machine.
		if ( 'http' === self::transport_for( $client_id ) ) {
			$badges[] = [
				'label' => __( 'Direct HTTP', 'action-steward' ),
				'tone'  => 'info',
				'rank'  => 'secondary',
				'title' => __( 'Connects straight to this site. No connector script and no Node.js on your computer.', 'action-steward' ),
			];
		} else {
			$badges[] = [
				'label' => __( 'Relay', 'action-steward' ),
				'tone'  => 'neutral',
				'rank'  => 'secondary',
				'title' => __( 'Runs a small connector script on your computer, which needs Node.js installed.', 'action-steward' ),
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
		 * So the claim is positive now. `Certified` remains reserved for a Gold client
		 * whose full twelve-step run is recorded in docs/ASSISTANT-CERTIFICATION.md §7.
		 * `Pass` means the actual client connected and completed a benign Action Steward read, with
		 * any narrower retained evidence spelled out in that client's validation notes.
		 * A compatible client with no certification badge makes no execution claim.
		 */
		$status = $client['status'] ?? self::CERT_COMPATIBLE;
		if ( self::CERT_GOLD === $status ) {
			$badges[] = [
				'label' => __( 'Certified', 'action-steward' ),
				'tone'  => 'ok',
				'rank'  => 'secondary',
				'title' => __( 'Connecting, reading, proposing, approving, undoing and reconnecting have all been run end to end in this assistant against a live site.', 'action-steward' ),
			];
		} elseif ( self::CERT_ACTIVE === $status ) {
			$badges[] = [
				'label' => __( 'Pass', 'action-steward' ),
				'tone'  => 'ok',
				'rank'  => 'secondary',
				'title' => __( 'This actual client connected to Action Steward and completed a benign read. See the validation notes for the exact retained scope.', 'action-steward' ),
			];
		} elseif ( in_array( $status, [ self::CERT_BRONZE, self::CERT_SILVER ], true ) ) {
			$badges[] = [
				'label' => __( 'Experimental', 'action-steward' ),
				'tone'  => 'warn',
				'rank'  => 'secondary',
				'title' => __( 'This client has partial certification evidence; see the validation notes for the exact retained scope.', 'action-steward' ),
			];
		} elseif ( self::CERT_PLANNED === $status ) {
			$badges[] = [
				'label' => __( 'Not supported', 'action-steward' ),
				'tone'  => 'bad',
				'rank'  => 'secondary',
				'title' => __( 'This assistant is not supported. Do not rely on it.', 'action-steward' ),
			];
		}

		return $badges;
	}

	/** The syntax of a rendered config, for the copy box's language hint. */
	public static function config_format( array $config ): string {
		return isset( $config['__format'] ) ? (string) $config['__format'] : 'json';
	}

	public static function get_clients(): array {
		$clients = [
			'claude' => [
				'tier'               => 'recommended',
				'name'               => 'Claude Desktop',
				'type'               => 'desktop',
				'vendor'             => 'Anthropic',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-08',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). The actual Claude Desktop client loaded Action Steward, discovered 42 tools and completed system_info. This is desktop-client evidence and is not borrowed from Claude Code; the full governed write/undo lifecycle was not repeated.',
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
				'tier'               => 'recommended',
				'name'               => 'Codex in ChatGPT Desktop',
				'type'               => 'desktop',
				'vendor'             => 'OpenAI',
				'surface_note'       => 'Use Codex mode in the ChatGPT desktop app. Regular ChatGPT chats do not use this local MCP connection.',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-06',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). The actual ChatGPT desktop app\'s OpenAI Codex MCP surface authenticated, initialized, discovered 42 tools and 7 resources, completed a benign read, and completed the governed write -> human approval -> apply -> audit/history -> governed undo -> second human approval -> exact restoration lifecycle, with MCP self-approval denied both times. A current-version GUI restart/reconnect was not independently repeated, so this evidence is not promoted to Gold or reused as a separate Codex CLI certification.',
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
				'description'        => 'The Codex workspace inside the ChatGPT desktop app. Normal ChatGPT conversations do not use this local connection.',
				'website'            => 'https://chatgpt.com',
			],
			'codex' => [
				'tier'               => 'recommended',
				'name'               => 'Codex CLI',
				'type'               => 'cli',
				'vendor'             => 'OpenAI',
				'surface_note'       => 'The terminal version of Codex. It shares ~/.codex/config.toml with Codex in ChatGPT Desktop, but its token must be available in the terminal that starts it.',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-08',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). OWNER RETEST PASS on codex-cli 0.153.4: the generated same-terminal credential and native registration flow authenticated, discovered 42 tools and 7 resources, and system_info completed through the approval-aware interactive launch. Action Steward publishes truthful read-only MCP annotations and retains its own scope, capability and human-approval governance. The full governed write/undo lifecycle was not repeated.',
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
				'description'        => 'The Codex command-line tool from OpenAI. Registers with one command — no file to edit, and your token stays out of the config file.',
				'website'            => 'https://developers.openai.com/codex/cli',
			],
			'gemini' => [
				'tier'               => 'recommended',
				'name'               => 'Gemini CLI',
				'type'               => 'cli',
				'vendor'             => 'Google',
				'surface_note'       => 'The command-line tool only. Gemini Spark custom apps are a separate hosted feature and are not compatible with this site’s current local access-token flow. Antigravity CLI (agy) also uses different commands and configuration.',
				'status'             => self::CERT_COMPATIBLE,
				'certification_level' => self::CERT_COMPATIBLE,
				'last_validated_at'  => '2026-09-06',
				'validation_notes'   => 'FINAL VERDICT: BLOCKED — EXTERNAL ACCOUNT/PROVIDER. Gemini CLI 0.46.0 and the generated native setup/configuration contract were structurally verified, including preservation of unrelated settings. The owner’s individual Gemini Code Assist account now reports that this client is no longer supported for individuals and directs the user to Antigravity. This is an external Google account/client eligibility limitation, not an Action Steward runtime failure.',
				'status_label'       => 'Account unavailable',
				'status_tone'        => 'warn',
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
				'description'        => 'Google’s Gemini command-line tool. Registers with one command that edits your settings file safely, leaving your existing Gemini settings alone.',
				'website'            => 'https://google-gemini.github.io/gemini-cli/',
			],
			/*
			 * Antigravity is a SEPARATE Google surface, not a rename of Gemini CLI.
			 *
			 * They read different files with different field names (settings.json /
			 * `url` versus config/mcp_config.json / `serverUrl`), so one card covering
			 * both would hand half its users a configuration their client ignores
			 * without complaint. Both shapes were confirmed against working local
			 * installations before this entry was added.
			 */
			'antigravity' => [
				'tier'               => 'recommended',
				'name'               => 'Antigravity CLI',
				'type'               => 'cli',
				'vendor'             => 'Google',
				'surface_note'       => 'The Antigravity command-line client (agy). Gemini CLI (gemini) is a separate client with different setup commands and configuration.',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-06',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). LIVE READ-ONLY CLIENT TEST on Antigravity CLI (agy) 1.1.27, 2026-09-06: normal Action Steward token flow and generated native agy mcp add command accepted; initialize, 42 tools, seven resources and fresh-process reconnect confirmed. system_info and report_site_health succeeded with a Read-only token; content_update was denied by scope without site/approval/queue/change-history mutation. Inline header stored in the native global MCP config with 0600 permissions, temporary registration/token cleaned after the run. The governed write/undo checklist was intentionally not repeated.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ AntigravityIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ AntigravityIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.gemini/config/mcp_config.json',
					'windows' => '%USERPROFILE%\\.gemini\\config\\mcp_config.json',
					'linux'   => '~/.gemini/config/mcp_config.json',
				],
				'description'        => 'Google Antigravity CLI (agy). Registers with a native command that preserves other servers. Its global MCP file stores the bearer token.',
				'website'            => 'https://antigravity.google/',
			],
			'cursor' => [
				'tier'               => 'recommended',
				'name'               => 'Cursor',
				'type'               => 'ide',
				'vendor'             => 'Anysphere',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-06',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). Retained actual-client evidence authenticated, initialized, discovered 42 tools and 7 resources, completed system_info, and reloaded successfully. During the latest onboarding retest the Action Steward connection loaded, but Cursor reported High Load and account model availability prevented another benign read. That external model/account limitation is not an Action Steward defect and does not upgrade this evidence.',
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
				'description'        => 'Cursor IDE by Anysphere. Real-client MCP connection, discovery, benign read and reload/reconnect have been validated.',
				'website'            => 'https://cursor.com',
			],
			'continue' => [
				'tier'               => 'recommended',
				'name'               => 'Continue for VS Code',
				'type'               => 'ide_plugin',
				'vendor'             => 'Continue Dev',
				'surface_note'       => 'This setup is for the Continue extension inside VS Code, the surface used for Action Steward’s retained client test. Continue also needs its own tool-capable AI model.',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-06',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). LIVE CLIENT TEST via the Continue VS Code extension: relay launched, MCP endpoint reached, and system_info returned credible WordPress/PHP/MySQL/theme/plugin values. The corrected ~/.continue/config.yaml list-based configuration was exercised. Exact client-side tool/resource counts were not retained, and the twelve-step governed write/undo lifecycle was not run.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ ContinueIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ ContinueIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.continue/config.yaml (the extension\'s "Main Config")',
					'windows' => '%USERPROFILE%\\.continue\\config.yaml',
					'linux'   => '~/.continue/config.yaml',
				],
				'description'        => 'The Continue extension inside VS Code. Add one YAML list entry to its Local Config; do not replace the file.',
				'website'            => 'https://continue.dev',
			],
			/*
			 * Meta Muse Code. A standalone CLI host, distinct from the Muse Spark model/API.
			 * The official 1.0.3 binary proves the settings, authenticated discovery and a
			 * model-driven read-only system_info call. Experimental is retained as product
			 * positioning only; it no longer means the client is untested.
			 */
			'muse_code' => [
				'tier'               => 'experimental',
				'name'               => 'Muse Code',
				'type'               => 'cli',
				'vendor'             => 'Meta',
				'surface_note'       => 'Meta’s terminal coding agent. Its actual MCP connection and a read-only system_info call were verified with Muse Code 1.0.3.',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-09',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). The official Muse Code 1.0.3 client first authenticated and requested tools, resources, resource templates and prompts successfully. OWNER MANUAL TEST after Meta authentication and activation of a usable plan: the actual muse client connected to Action Steward and invoked system_info once in read-only mode. It returned the correct local site plus credible WordPress 7.1, PHP 8.2.27, MySQL 8.0.39, theme, plugin and environment details. No mutation was requested and the full governed write/undo lifecycle was not run.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ MuseCodeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ MuseCodeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.config/muse/settings.json',
					'linux'   => '~/.config/muse/settings.json',
				],
				'description'        => 'Meta’s standalone Muse Code CLI (muse), distinct from the Muse Spark model/API. Configured through its own MCP settings file.',
				'website'            => 'https://dev.meta.ai/docs/muse-code',
			],
			'opencode' => [
				'tier'               => 'recommended',
				'name'               => 'OpenCode',
				'type'               => 'cli',
				'vendor'             => 'Anomaly',
				'surface_note'       => 'Registers with one command. Do not use “opencode mcp auth” — that is for OAuth servers, and this site uses an access token instead.',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-08-11',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). The actual OpenCode client used native remote HTTP with bearer authentication and completed system_info with credible WordPress/PHP/environment data. The native setup preserved an existing server. Exact client-side tool/resource counts were not retained, and the twelve-step governed write/undo lifecycle was not run.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ OpenCodeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ OpenCodeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.config/opencode/opencode.jsonc',
					'windows' => '%USERPROFILE%\\.config\\opencode\\opencode.jsonc',
					'linux'   => '~/.config/opencode/opencode.jsonc',
				],
				'description'        => 'The OpenCode CLI. Registers with one command — no file to edit, no connector and no Node.js.',
				'website'            => 'https://opencode.ai',
			],
			/*
			 * Windsurf was REMOVED from v1 (REAL_TEST_FINDINGS.md #9), not renamed or
			 * hidden. It was the one client in the selector with no reliable validation
			 * against its current real product, and advertising a connector nobody had
			 * driven end to end is the kind of claim this registry now refuses to make
			 * elsewhere too. Deferred rather than abandoned: it may return once it can be
			 * tested properly.
			 *
			 * Deliberately NOT left as "coming soon"/"experimental" — the finding asked
			 * for it simply not to be advertised. The shared relay stays exactly where it
			 * is; Claude Desktop and Continue still depend on it.
			 */
			'command_code' => [
				'tier'               => 'recommended',
				'name'               => 'Command Code',
				'type'               => 'cli',
				'vendor'             => 'Command Code',
				'surface_note'       => 'Registers with one command. It may mention OAuth while adding the server — there is no OAuth step here; the access token is all it needs.',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-09',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS (not CERT_GOLD). OWNER MANUAL TEST on Command Code 1.51.0: the actual client completed the Action Steward connection and invoked system_info successfully in read-only mode. No governed write was attempted and the full approval/apply/undo lifecycle was not run.',
				'compatible'         => true,
				'discovery_support'  => true,
				'mcp_support'        => true,
				'config_generator'   => [ CommandCodeIntegration::class, 'generate_mcp_config' ],
				'discovery_generator' => [ CommandCodeIntegration::class, 'get_discovery_metadata' ],
				'config_paths'       => [
					'macos'   => '~/.commandcode/mcp.json',
					'linux'   => '~/.commandcode/mcp.json',
				],
				'description'        => 'The Command Code CLI. Its verified native registration command is the supported setup path; no connector or Node.js is required.',
				'website'            => '',
			],
			'vscode' => [
				'tier'               => 'recommended',
				'name'               => 'GitHub Copilot in VS Code',
				'type'               => 'ide',
				'vendor'             => 'GitHub / Microsoft',
				'status'             => self::CERT_ACTIVE,
				'certification_level' => self::CERT_ACTIVE,
				'last_validated_at'  => '2026-09-09',
				'validation_notes'   => 'FINAL VERDICT: CERT_PASS — fresh actual VS Code 1.136.2 with built-in GitHub Copilot 0.64.1 reproduced the reported failure and proved its cause. A missing/invalid secure-input value produced a Bearer header, Action Steward returned its ordinary JSON 401 with no WWW-Authenticate header, and VS Code automatically entered OAuth discovery/DCR while still displaying cached 42-tool/6-prompt counts. With a fresh credential-scoped input holding the correct read-only token, the same supported headers.Authorization configuration authenticated, exposed 42 tools and 6 prompts, and Copilot Agent completed system_info exactly once with credible WordPress 7.1, PHP 8.2.27, MySQL 8.0.39 and site-URL data without OAuth/DCR. Restart reconnected and rediscovered 42 tools. Posts, operation requests, queue and change history were unchanged; the expected read-only result record was created. The temporary token was revoked/deleted and the original VS Code configuration restored.',
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
				'validation_notes'   => 'FINAL VERDICT: CERT_GOLD. CERTIFIED 2026-08-04 — the full twelve-step checklist (docs/ASSISTANT-CERTIFICATION.md §7) was executed in this client against a live HTTPS site (WordPress 6.9.5, PHP 8.3.30): connect, 42 tools, 7 resources, read, proposal, nothing applied, self-approval refused (wpcc_approval_requires_human), human approval, audit attribution, undo (itself gated, then applied and restored exactly), double-undo refused (wpcc_already_rolled_back), reconnect. Registered with `claude mcp add`, not a hand-edited config file.',
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

		/*
		 * Product-family order is presentation data, but it belongs beside the client
		 * identities so the selector, API and tests cannot drift. The stored array above
		 * remains easy to review by integration history; this map defines the customer order.
		 */
		$presentation = [
			'chatgpt'      => [ 'family' => 'OpenAI', 'surface' => 'Desktop app · Codex mode', 'setup_kind' => 'command' ],
			'codex'        => [ 'family' => 'OpenAI', 'surface' => 'Terminal app', 'setup_kind' => 'command' ],
			'claude'       => [ 'family' => 'Anthropic', 'surface' => 'Desktop app', 'setup_kind' => 'file' ],
			'claude_code'  => [ 'family' => 'Anthropic', 'surface' => 'Terminal app', 'setup_kind' => 'command' ],
			'antigravity'  => [ 'family' => 'Google', 'surface' => 'Terminal app · agy', 'setup_kind' => 'command' ],
			'gemini'       => [ 'family' => 'Google', 'surface' => 'Terminal app · gemini', 'setup_kind' => 'command' ],
			'cursor'       => [ 'family' => 'Editors & coding assistants', 'surface' => 'Code editor', 'setup_kind' => 'install_link' ],
			'continue'     => [ 'family' => 'Editors & coding assistants', 'surface' => 'VS Code extension', 'setup_kind' => 'continue_config' ],
			'vscode'       => [ 'family' => 'Editors & coding assistants', 'surface' => 'Copilot Agent mode', 'setup_kind' => 'vscode_config' ],
			'opencode'     => [ 'family' => 'Editors & coding assistants', 'surface' => 'Terminal app', 'setup_kind' => 'command' ],
			'command_code' => [ 'family' => 'Editors & coding assistants', 'surface' => 'Terminal app', 'setup_kind' => 'command' ],
			'muse_code'    => [ 'family' => 'Other / Experimental', 'surface' => 'Terminal app · verified connection', 'setup_kind' => 'file' ],
		];

		$ordered = [];
		foreach ( $presentation as $id => $meta ) {
			if ( isset( $clients[ $id ] ) ) {
				$ordered[ $id ] = $clients[ $id ] + $meta;
			}
		}

		return $ordered;
	}

	/** Clients grouped in the exact app-first selector order. */
	public static function get_client_groups(): array {
		$groups = [];
		foreach ( self::get_active_clients() as $id => $client ) {
			$groups[ $client['family'] ?? __( 'Other', 'action-steward' ) ][ $id ] = $client;
		}
		return $groups;
	}

	/** A single truthful, beginner-readable status for the app selector. */
	public static function selector_badge_for( string $client_id ): array {
		$client = self::get_client( $client_id );
		if ( ! $client ) {
			return [];
		}

		$status = $client['certification_level'] ?? self::CERT_COMPATIBLE;
		$notes  = (string) ( $client['validation_notes'] ?? '' );
		if ( isset( $client['status_label'], $client['status_tone'] ) ) {
			return [
				'label' => (string) $client['status_label'],
				'tone'  => (string) $client['status_tone'],
			];
		}
		if ( self::CERT_GOLD === $status ) {
			return [ 'label' => __( 'Certified', 'action-steward' ), 'tone' => 'ok' ];
		}
		if ( self::CERT_ACTIVE === $status ) {
			return [ 'label' => __( 'Connection tested', 'action-steward' ), 'tone' => 'ok' ];
		}
		if ( str_contains( $notes, 'BLOCKED — EXTERNAL' ) ) {
			return [ 'label' => __( 'Account-limited test', 'action-steward' ), 'tone' => 'warn' ];
		}
		if ( str_contains( $notes, 'NOT TESTABLE' ) ) {
			return [ 'label' => __( 'Not tested', 'action-steward' ), 'tone' => 'warn' ];
		}
		if ( str_contains( $notes, 'FINAL VERDICT: FAIL' ) ) {
			return [ 'label' => __( 'Needs final retest', 'action-steward' ), 'tone' => 'bad' ];
		}

		return [ 'label' => __( 'Compatible', 'action-steward' ), 'tone' => 'neutral' ];
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
