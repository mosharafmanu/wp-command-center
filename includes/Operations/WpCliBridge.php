<?php
/**
 * Step 37 — Structured WP-CLI Runtime.
 *
 * Exposes safe, structured WP-CLI commands through the Operations framework.
 * Supports both command_id + args (new) and bare command (legacy 6-command
 * allowlist) for backward compatibility.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class WpCliBridge {

	private WpCliCommandRegistry $registry;

	public function __construct() {
		$this->registry = new WpCliCommandRegistry();
	}

	public function is_available(): bool {
		$disabled = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );

		if ( ! function_exists( 'shell_exec' ) && ! function_exists( 'proc_open' ) ) {
			return false;
		}

		if ( function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $disabled, true ) ) {
			$output = @shell_exec( 'wp --version 2>/dev/null' );
			if ( ! empty( $output ) && stripos( (string) $output, 'wp-cli' ) !== false ) {
				return true;
			}
		}

		return false;
	}

	public function get_availability_reason(): string {
		if ( ! $this->is_available() ) {
			$disabled = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
			if ( ! function_exists( 'proc_open' ) || in_array( 'proc_open', $disabled, true ) ) {
				return __( 'proc_open is disabled or unavailable.', 'wp-command-center' );
			}
			if ( ! function_exists( 'shell_exec' ) || in_array( 'shell_exec', $disabled, true ) ) {
				return __( 'shell_exec is disabled or unavailable.', 'wp-command-center' );
			}
			return __( 'WP-CLI binary not found or not executable.', 'wp-command-center' );
		}
		return '';
	}

	/**
	 * Run a structured WP-CLI command (new format) or a legacy bare command.
	 *
	 * New format: { command_id: "plugin_list", args: { format: "json" } }
	 * Legacy format: { command: "plugin_list" }
	 *
	 * @param array $params
	 * @param array $context
	 *
	 * @return array|\WP_Error
	 */
	public function run( array $params, array $context = [] ): array|\WP_Error {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'wpcc_wp_cli_unavailable', __( 'WP-CLI bridge is not available on this server.', 'wp-command-center' ) );
		}

		$command_id = sanitize_text_field( $params['command_id'] ?? '' );
		$legacy_cmd = sanitize_key( $params['command'] ?? '' );
		$args       = $params['args'] ?? [];

		// Legacy mode: bare "command" string with 6-command allowlist.
		if ( '' !== $legacy_cmd && '' === $command_id ) {
			return $this->run_legacy( $legacy_cmd );
		}

		// Structured mode: command_id + args.
		if ( '' === $command_id ) {
			return new \WP_Error( 'wpcc_missing_wpcli_command', __( 'command_id is required.', 'wp-command-center' ) );
		}

		if ( ! is_array( $args ) ) {
			return new \WP_Error( 'wpcc_invalid_wpcli_args', __( 'args must be an object.', 'wp-command-center' ) );
		}

		return $this->run_structured( $command_id, $args, $context );
	}

	/**
	 * Legacy mode: bare command string mapped to fixed allowlist.
	 */
	private function run_legacy( string $command_id ): array|\WP_Error {
		$map = [
			'plugin_list'        => 'wp plugin list --format=json',
			'theme_list'         => 'wp theme list --format=json',
			'cache_flush'        => 'wp cache flush',
			'cron_event_list'    => 'wp cron event list --format=json',
			'option_get_siteurl' => 'wp option get siteurl',
			'db_size_check'      => 'wp db size --format=json',
		];

		if ( ! isset( $map[ $command_id ] ) ) {
			return new \WP_Error( 'wpcc_invalid_wpcli_command', __( 'Invalid or unsupported WP-CLI command.', 'wp-command-center' ) );
		}

		$shell_cmd = $map[ $command_id ];

		return $this->execute( $shell_cmd, self::TIMEOUT_DEFAULT, self::OUTPUT_MAX, $command_id );
	}

	/**
	 * Structured mode: validated command_id + args.
	 */
	private function run_structured( string $command_id, array $args, array $context ): array|\WP_Error {
		// Check denylist.
		if ( $this->registry->is_blocked( $command_id ) ) {
			return new \WP_Error(
				'wpcc_wpcli_blocked',
				sprintf( __( 'WP-CLI command blocked for security: %s', 'wp-command-center' ), esc_html( $command_id ) )
			);
		}

		// Get command definition.
		$cmd = $this->registry->get_command( $command_id );
		if ( null === $cmd ) {
			return new \WP_Error(
				'wpcc_invalid_wpcli_command',
				sprintf( __( 'Unknown or unsupported WP-CLI command: %s', 'wp-command-center' ), esc_html( $command_id ) )
			);
		}

		if ( ! $cmd['available'] ) {
			return new \WP_Error( 'wpcc_wpcli_unavailable_cmd', __( 'This WP-CLI command is not available in the current environment.', 'wp-command-center' ) );
		}

		// Validate args.
		$validation = $this->registry->validate_args( $cmd['allowed_args_schema'], $args );
		if ( $validation instanceof \WP_Error ) {
			return $validation;
		}

		// Check for blocked subcommands (double-check against command_parts).
		if ( $this->matches_blocked( $cmd['command_parts'] ) ) {
			return new \WP_Error(
				'wpcc_wpcli_blocked',
				sprintf( __( 'WP-CLI command blocked for security: %s', 'wp-command-center' ), esc_html( $command_id ) )
			);
		}

		// Build the shell command.
		$parts     = $this->registry->build_command_parts( $cmd, $args );
		$shell_cmd = implode( ' ', array_map( 'escapeshellarg', $parts ) );

		$timeout   = $cmd['timeout'] ?? self::TIMEOUT_DEFAULT;
		$output_max = $cmd['output_max'] ?? self::OUTPUT_MAX;

		$result = $this->execute( $shell_cmd, $timeout, $output_max, $command_id );

		// Add health check flag for high/critical commands.
		if ( ! is_wp_error( $result ) && $cmd['requires_health_check'] ) {
			$result['health_check_required'] = true;
		}

		if ( ! is_wp_error( $result ) ) {
			$result['command_id'] = $command_id;
			$result['risk_level'] = $cmd['risk_level'];
		}

		return $result;
	}

	/**
	 * Execute a shell command with timeout and output limits.
	 */
	private function execute( string $shell_cmd, int $timeout, int $output_max, string $command_id ): array|\WP_Error {
		$shell_cmd .= ' --path=' . escapeshellarg( ABSPATH ) . ' --allow-root';

		$process = @proc_open(
			$shell_cmd,
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			return new \WP_Error( 'wpcc_proc_open_failed', __( 'Failed to spawn WP-CLI process.', 'wp-command-center' ) );
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$start   = time();
		$status  = proc_get_status( $process );
		$stdout  = '';
		$stderr  = '';

		while ( $status['running'] ) {
			if ( time() - $start > $timeout ) {
				@proc_terminate( $process );
				@fclose( $pipes[1] );
				@fclose( $pipes[2] );
				@proc_close( $process );
				return new \WP_Error( 'wpcc_wpcli_timeout', __( 'WP-CLI command timed out.', 'wp-command-center' ) );
			}
			$chunk = @stream_get_contents( $pipes[1] );
			if ( is_string( $chunk ) ) {
				$stdout .= $chunk;
			}
			$chunk_err = @stream_get_contents( $pipes[2] );
			if ( is_string( $chunk_err ) ) {
				$stderr .= $chunk_err;
			}
			usleep( 100000 );
			$status = proc_get_status( $process );
		}

		$chunk = @stream_get_contents( $pipes[1] );
		if ( is_string( $chunk ) ) {
			$stdout .= $chunk;
		}
		$chunk_err = @stream_get_contents( $pipes[2] );
		if ( is_string( $chunk_err ) ) {
			$stderr .= $chunk_err;
		}

		@fclose( $pipes[1] );
		@fclose( $pipes[2] );
		@proc_close( $process );

		// Enforce output size limit.
		$stdout = substr( $stdout, 0, $output_max );
		$stderr = substr( $stderr, 0, $output_max );

		$truncated_stdout = strlen( $stdout ) >= $output_max;
		$truncated_stderr = strlen( $stderr ) >= $output_max;

		if ( 0 !== $status['exitcode'] ) {
			return new \WP_Error(
				'wpcc_wpcli_error',
				sprintf(
					__( 'WP-CLI exited with code %d. Stderr: %s', 'wp-command-center' ),
					$status['exitcode'],
					trim( $stderr )
				)
			);
		}

		$stdout_trim = trim( $stdout );
		$json        = json_decode( $stdout_trim, true );

		$response = [
			'command'  => $command_id,
			'output'   => null !== $json ? $json : $stdout_trim,
			'stderr'   => trim( $stderr ),
			'exitcode' => $status['exitcode'],
		];

		if ( $truncated_stdout ) {
			$response['output_truncated'] = true;
		}
		if ( $truncated_stderr ) {
			$response['stderr_truncated'] = true;
		}

		return $response;
	}

	/**
	 * Check if command parts match a blocked subcommand pattern.
	 */
	private function matches_blocked( array $command_parts ): bool {
		if ( count( $command_parts ) < 2 ) {
			return false;
		}

		$cmd_str = implode( ' ', array_slice( $command_parts, 1 ) );

		foreach ( $this->registry::BLOCKED_SUBCOMMANDS as $blocked ) {
			if ( str_starts_with( $cmd_str, $blocked ) ) {
				return true;
			}
		}

		return false;
	}

	const TIMEOUT_DEFAULT = 30;
	const TIMEOUT_MAX     = 120;
	const OUTPUT_MAX      = 262144; // 256KB

	/**
	 * Get command metadata for a single command_id (used by manifest).
	 */
	public function get_command_metadata( string $command_id ): ?array {
		return $this->registry->get_command( $command_id );
	}

	/**
	 * Get all supported command summaries.
	 */
	public function get_supported_commands(): array {
		return $this->registry->get_commands_summary();
	}

	/**
	 * Get blocked policy summary.
	 */
	public function get_blocked_policy_summary(): string {
		return $this->registry->get_blocked_policy_summary();
	}

	/**
	 * Count commands by risk level.
	 */
	public function count_by_risk(): array {
		return $this->registry->count_by_risk();
	}

	/**
	 * Get underlying command registry.
	 */
	public function get_registry(): WpCliCommandRegistry {
		return $this->registry;
	}
}
