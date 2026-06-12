<?php
/**
 * Step 37 — Structured WP-CLI Command Registry.
 *
 * Defines every supported WP-CLI command with risk levels, allowed args,
 * approval requirements, output limits, and denylist protection. No raw
 * terminal or arbitrary command execution.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class WpCliCommandRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_HIGH     = 'high';
	const RISK_CRITICAL = 'critical';

	const RISK_LEVELS = [ 'low', 'medium', 'high', 'critical' ];

	const OUTPUT_FORMAT_JSON  = 'json';
	const OUTPUT_FORMAT_TEXT  = 'text';
	const OUTPUT_FORMAT_TABLE = 'table';

	const TIMEOUT_DEFAULT = 30;
	const TIMEOUT_MAX     = 120;
	const OUTPUT_MAX      = 262144; // 256KB

	/**
	 * Always-blocked WP-CLI subcommands. These are never supported
	 * regardless of risk level or argument context.
	 */
	const BLOCKED_SUBCOMMANDS = [
		'db reset',
		'db drop',
		'db import',
		'user delete',
		'post delete',
		'plugin delete',
		'theme delete',
		'core update',
		'core download',
		'eval',
		'eval-file',
		'shell',
		'package install',
		'scaffold',
		'config set',
		'config create',
		'rewrite structure',
	];

	/**
	 * Structured command definitions.
	 *
	 * @return array<string, array>
	 */
	public function get_commands(): array {
		return [
			// ── Low Risk ──────────────────────────────────────────────
			'plugin_list' => [
				'command_id'            => 'plugin_list',
				'title'                 => __( 'List Plugins', 'wp-command-center' ),
				'description'           => __( 'List all installed plugins with status and version.', 'wp-command-center' ),
				'risk_level'            => self::RISK_LOW,
				'command_parts'         => [ 'wp', 'plugin', 'list' ],
				'allowed_args_schema'   => [
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'table', 'csv', 'yaml' ], 'default' => 'json' ],
					'status'  => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'active-network', 'must-use', 'dropin' ] ],
					'fields'  => [ 'type' => 'string' ],
				],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_JSON,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'theme_list' => [
				'command_id'            => 'theme_list',
				'title'                 => __( 'List Themes', 'wp-command-center' ),
				'description'           => __( 'List all installed themes with status and version.', 'wp-command-center' ),
				'risk_level'            => self::RISK_LOW,
				'command_parts'         => [ 'wp', 'theme', 'list' ],
				'allowed_args_schema'   => [
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'table', 'csv', 'yaml' ], 'default' => 'json' ],
					'status'  => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'parent' ] ],
					'fields'  => [ 'type' => 'string' ],
				],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_JSON,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'option_get_siteurl' => [
				'command_id'            => 'option_get_siteurl',
				'title'                 => __( 'Get Site URL', 'wp-command-center' ),
				'description'           => __( 'Retrieve the WordPress siteurl option.', 'wp-command-center' ),
				'risk_level'            => self::RISK_LOW,
				'command_parts'         => [ 'wp', 'option', 'get', 'siteurl' ],
				'allowed_args_schema'   => [
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'plaintext' ], 'default' => 'plaintext' ],
				],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'option_get_home' => [
				'command_id'            => 'option_get_home',
				'title'                 => __( 'Get Home URL', 'wp-command-center' ),
				'description'           => __( 'Retrieve the WordPress home option.', 'wp-command-center' ),
				'risk_level'            => self::RISK_LOW,
				'command_parts'         => [ 'wp', 'option', 'get', 'home' ],
				'allowed_args_schema'   => [
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'plaintext' ], 'default' => 'plaintext' ],
				],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'cron_event_list' => [
				'command_id'            => 'cron_event_list',
				'title'                 => __( 'List Cron Events', 'wp-command-center' ),
				'description'           => __( 'List all scheduled WP-Cron events.', 'wp-command-center' ),
				'risk_level'            => self::RISK_LOW,
				'command_parts'         => [ 'wp', 'cron', 'event', 'list' ],
				'allowed_args_schema'   => [
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'table', 'csv', 'yaml' ], 'default' => 'json' ],
					'fields'  => [ 'type' => 'string' ],
				],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_JSON,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'transient_delete_expired' => [
				'command_id'            => 'transient_delete_expired',
				'title'                 => __( 'Delete Expired Transients', 'wp-command-center' ),
				'description'           => __( 'Delete all expired transients from the database.', 'wp-command-center' ),
				'risk_level'            => self::RISK_LOW,
				'command_parts'         => [ 'wp', 'transient', 'delete-expired' ],
				'allowed_args_schema'   => [
					'network'  => [ 'type' => 'boolean' ],
				],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'rewrite_list' => [
				'command_id'            => 'rewrite_list',
				'title'                 => __( 'List Rewrite Rules', 'wp-command-center' ),
				'description'           => __( 'List current WordPress rewrite rules.', 'wp-command-center' ),
				'risk_level'            => self::RISK_LOW,
				'command_parts'         => [ 'wp', 'rewrite', 'list' ],
				'allowed_args_schema'   => [
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'table', 'csv', 'yaml' ], 'default' => 'json' ],
				],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_JSON,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],

			// ── Medium Risk ───────────────────────────────────────────
			'cache_flush' => [
				'command_id'            => 'cache_flush',
				'title'                 => __( 'Flush Cache', 'wp-command-center' ),
				'description'           => __( 'Flush the WordPress object cache.', 'wp-command-center' ),
				'risk_level'            => self::RISK_MEDIUM,
				'command_parts'         => [ 'wp', 'cache', 'flush' ],
				'allowed_args_schema'   => [],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'rewrite_flush' => [
				'command_id'            => 'rewrite_flush',
				'title'                 => __( 'Flush Rewrite Rules', 'wp-command-center' ),
				'description'           => __( 'Flush WordPress rewrite rules (regenerates .htaccess).', 'wp-command-center' ),
				'risk_level'            => self::RISK_MEDIUM,
				'command_parts'         => [ 'wp', 'rewrite', 'flush' ],
				'allowed_args_schema'   => [
					'hard'  => [ 'type' => 'boolean' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'cron_event_run_due_now' => [
				'command_id'            => 'cron_event_run_due_now',
				'title'                 => __( 'Run Due Cron Events', 'wp-command-center' ),
				'description'           => __( 'Run all due WP-Cron events now.', 'wp-command-center' ),
				'risk_level'            => self::RISK_MEDIUM,
				'command_parts'         => [ 'wp', 'cron', 'event', 'run', '--due-now' ],
				'allowed_args_schema'   => [],
				'requires_approval'     => true,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_MAX,
				'output_max'            => self::OUTPUT_MAX,
			],
			'option_update_blogdescription' => [
				'command_id'            => 'option_update_blogdescription',
				'title'                 => __( 'Update Blog Description', 'wp-command-center' ),
				'description'           => __( 'Update the WordPress blogdescription (tagline) option.', 'wp-command-center' ),
				'risk_level'            => self::RISK_MEDIUM,
				'command_parts'         => [ 'wp', 'option', 'update', 'blogdescription' ],
				'allowed_args_schema'   => [
					'value'   => [ 'type' => 'string', 'required' => true, 'max_length' => 500 ],
					'format'  => [ 'type' => 'string', 'enum' => [ 'plaintext' ], 'default' => 'plaintext' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
			'option_update_blogname' => [
				'command_id'            => 'option_update_blogname',
				'title'                 => __( 'Update Blog Name', 'wp-command-center' ),
				'description'           => __( 'Update the WordPress blogname (site title) option.', 'wp-command-center' ),
				'risk_level'            => self::RISK_MEDIUM,
				'command_parts'         => [ 'wp', 'option', 'update', 'blogname' ],
				'allowed_args_schema'   => [
					'value'   => [ 'type' => 'string', 'required' => true, 'max_length' => 500 ],
					'format'  => [ 'type' => 'string', 'enum' => [ 'plaintext' ], 'default' => 'plaintext' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],

			// ── High Risk ─────────────────────────────────────────────
			'plugin_update_single' => [
				'command_id'            => 'plugin_update_single',
				'title'                 => __( 'Update Single Plugin', 'wp-command-center' ),
				'description'           => __( 'Update a single plugin to its latest version.', 'wp-command-center' ),
				'risk_level'            => self::RISK_HIGH,
				'command_parts'         => [ 'wp', 'plugin', 'update' ],
				'allowed_args_schema'   => [
					'plugin'  => [ 'type' => 'string', 'required' => true, 'max_length' => 200, 'pattern' => '/^[a-zA-Z0-9._\-\/]+$/' ],
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'table' ], 'default' => 'json' ],
					'minor'   => [ 'type' => 'boolean' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => true,
				'output_format'         => self::OUTPUT_FORMAT_JSON,
				'available'             => true,
				'timeout'               => self::TIMEOUT_MAX,
				'output_max'            => self::OUTPUT_MAX,
			],
			'theme_update_single' => [
				'command_id'            => 'theme_update_single',
				'title'                 => __( 'Update Single Theme', 'wp-command-center' ),
				'description'           => __( 'Update a single theme to its latest version.', 'wp-command-center' ),
				'risk_level'            => self::RISK_HIGH,
				'command_parts'         => [ 'wp', 'theme', 'update' ],
				'allowed_args_schema'   => [
					'theme'   => [ 'type' => 'string', 'required' => true, 'max_length' => 200, 'pattern' => '/^[a-zA-Z0-9._\-\/]+$/' ],
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'table' ], 'default' => 'json' ],
					'minor'   => [ 'type' => 'boolean' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => true,
				'output_format'         => self::OUTPUT_FORMAT_JSON,
				'available'             => true,
				'timeout'               => self::TIMEOUT_MAX,
				'output_max'            => self::OUTPUT_MAX,
			],
			'search_replace_dry_run' => [
				'command_id'            => 'search_replace_dry_run',
				'title'                 => __( 'Search & Replace (Dry Run)', 'wp-command-center' ),
				'description'           => __( 'Preview a database search and replace without making changes.', 'wp-command-center' ),
				'risk_level'            => self::RISK_HIGH,
				'command_parts'         => [ 'wp', 'search-replace' ],
				'allowed_args_schema'   => [
					'search'         => [ 'type' => 'string', 'required' => true, 'max_length' => 1000 ],
					'replace'        => [ 'type' => 'string', 'required' => true, 'max_length' => 1000 ],
					'all-tables-with-prefix' => [ 'type' => 'boolean', 'required' => true ],
					'dry-run'        => [ 'type' => 'boolean', 'default' => true ],
					'format'         => [ 'type' => 'string', 'enum' => [ 'table', 'count', 'json' ], 'default' => 'table' ],
					'precise'        => [ 'type' => 'boolean' ],
					'regex'          => [ 'type' => 'boolean' ],
					'skip-columns'   => [ 'type' => 'string' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_TABLE,
				'available'             => true,
				'timeout'               => self::TIMEOUT_MAX,
				'output_max'            => self::OUTPUT_MAX,
			],
			'search_replace_execute' => [
				'command_id'            => 'search_replace_execute',
				'title'                 => __( 'Search & Replace (Execute)', 'wp-command-center' ),
				'description'           => __( 'Execute a live database search and replace.', 'wp-command-center' ),
				'risk_level'            => self::RISK_HIGH,
				'command_parts'         => [ 'wp', 'search-replace' ],
				'allowed_args_schema'   => [
					'search'         => [ 'type' => 'string', 'required' => true, 'max_length' => 1000 ],
					'replace'        => [ 'type' => 'string', 'required' => true, 'max_length' => 1000 ],
					'all-tables-with-prefix' => [ 'type' => 'boolean', 'required' => true ],
					'dry-run'        => [ 'type' => 'boolean' ],
					'format'         => [ 'type' => 'string', 'enum' => [ 'table', 'count', 'json' ], 'default' => 'table' ],
					'precise'        => [ 'type' => 'boolean' ],
					'regex'          => [ 'type' => 'boolean' ],
					'skip-columns'   => [ 'type' => 'string' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => true,
				'output_format'         => self::OUTPUT_FORMAT_TABLE,
				'available'             => true,
				'timeout'               => self::TIMEOUT_MAX,
				'output_max'            => self::OUTPUT_MAX,
			],

			// ── Critical Risk ─────────────────────────────────────────
			'db_export' => [
				'command_id'            => 'db_export',
				'title'                 => __( 'Database Export', 'wp-command-center' ),
				'description'           => __( 'Export the WordPress database via WP-CLI.', 'wp-command-center' ),
				'risk_level'            => self::RISK_CRITICAL,
				'command_parts'         => [ 'wp', 'db', 'export' ],
				'allowed_args_schema'   => [
					'add-drop-table' => [ 'type' => 'boolean' ],
					'tables'         => [ 'type' => 'string' ],
					'porcelain'      => [ 'type' => 'boolean' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => true,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_MAX,
				'output_max'            => 1048576, // 1MB for db export
			],
			'db_optimize' => [
				'command_id'            => 'db_optimize',
				'title'                 => __( 'Optimize Database', 'wp-command-center' ),
				'description'           => __( 'Optimize WordPress database tables.', 'wp-command-center' ),
				'risk_level'            => self::RISK_CRITICAL,
				'command_parts'         => [ 'wp', 'db', 'optimize' ],
				'allowed_args_schema'   => [
					'tables'  => [ 'type' => 'string' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => true,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_MAX,
				'output_max'            => self::OUTPUT_MAX,
			],
			'db_repair' => [
				'command_id'            => 'db_repair',
				'title'                 => __( 'Repair Database', 'wp-command-center' ),
				'description'           => __( 'Repair WordPress database tables.', 'wp-command-center' ),
				'risk_level'            => self::RISK_CRITICAL,
				'command_parts'         => [ 'wp', 'db', 'repair' ],
				'allowed_args_schema'   => [
					'tables'  => [ 'type' => 'string' ],
				],
				'requires_approval'     => true,
				'requires_health_check' => true,
				'output_format'         => self::OUTPUT_FORMAT_TEXT,
				'available'             => true,
				'timeout'               => self::TIMEOUT_MAX,
				'output_max'            => self::OUTPUT_MAX,
			],

			// ── Legacy compat commands (old 6-command allowlist) ──────
			'db_size_check' => [
				'command_id'            => 'db_size_check',
				'title'                 => __( 'Database Size', 'wp-command-center' ),
				'description'           => __( 'Check the WordPress database size.', 'wp-command-center' ),
				'risk_level'            => self::RISK_LOW,
				'command_parts'         => [ 'wp', 'db', 'size' ],
				'allowed_args_schema'   => [
					'format'  => [ 'type' => 'string', 'enum' => [ 'json', 'table' ], 'default' => 'json' ],
				],
				'requires_approval'     => false,
				'requires_health_check' => false,
				'output_format'         => self::OUTPUT_FORMAT_JSON,
				'available'             => true,
				'timeout'               => self::TIMEOUT_DEFAULT,
				'output_max'            => self::OUTPUT_MAX,
			],
		];
	}

	/**
	 * Get a single command definition by ID.
	 */
	public function get_command( string $command_id ): ?array {
		$commands = $this->get_commands();
		return $commands[ $command_id ] ?? null;
	}

	/**
	 * Check if a command is in the always-blocked list.
	 */
	public function is_blocked( string $command_id ): bool {
		return in_array( $command_id, self::BLOCKED_SUBCOMMANDS, true );
	}

	/**
	 * Validate args against a command's allowed_args_schema.
	 *
	 * Returns null on success, or a WP_Error on validation failure.
	 *
	 * @param array  $schema The allowed_args_schema for the command.
	 * @param array  $args   The args to validate.
	 * @return null|\WP_Error
	 */
	public function validate_args( array $schema, array $args ): ?\WP_Error {
		$allowed_keys = array_keys( $schema );

		foreach ( $args as $key => $value ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				return new \WP_Error(
					'wpcc_invalid_wpcli_arg',
					sprintf( __( 'Unknown argument: %s', 'wp-command-center' ), esc_html( $key ) )
				);
			}

			$def = $schema[ $key ];

			if ( ! empty( $def['required'] ) && ( null === $value || '' === $value ) ) {
				return new \WP_Error(
					'wpcc_missing_wpcli_arg',
					sprintf( __( 'Missing required argument: %s', 'wp-command-center' ), esc_html( $key ) )
				);
			}

			// Check shell metacharacters BEFORE enum/pattern validation,
			// so injection attempts are caught regardless of enum membership.
			if ( is_string( $value ) && $this->contains_shell_metacharacters( $value ) ) {
				return new \WP_Error(
					'wpcc_unsafe_wpcli_arg',
					sprintf( __( 'Arg %s contains unsafe shell characters.', 'wp-command-center' ), esc_html( $key ) )
				);
			}

			if ( isset( $def['enum'] ) && ! in_array( $value, $def['enum'], true ) ) {
				return new \WP_Error(
					'wpcc_invalid_wpcli_arg_value',
					sprintf( __( 'Invalid value for %s. Allowed: %s', 'wp-command-center' ), esc_html( $key ), implode( ', ', $def['enum'] ) )
				);
			}

			if ( isset( $def['pattern'] ) && ! preg_match( $def['pattern'], (string) $value ) ) {
				return new \WP_Error(
					'wpcc_invalid_wpcli_arg_pattern',
					sprintf( __( 'Invalid format for %s', 'wp-command-center' ), esc_html( $key ) )
				);
			}

			if ( isset( $def['max_length'] ) && is_string( $value ) && mb_strlen( $value ) > $def['max_length'] ) {
				return new \WP_Error(
					'wpcc_wpcli_arg_too_long',
					sprintf( __( 'Value for %s exceeds maximum length of %d', 'wp-command-center' ), esc_html( $key ), $def['max_length'] )
				);
			}
		}

		return null;
	}

	/**
	 * Build shell command parts from command_id + validated args.
	 *
	 * @param array  $command_def The command definition from get_commands().
	 * @param array  $args        Validated args.
	 * @return string[] Command parts (wp, subcommand, --flags, positional args)
	 */
	public function build_command_parts( array $command_def, array $args ): array {
		$parts = $command_def['command_parts'];

		foreach ( $args as $key => $value ) {
			if ( is_bool( $value ) ) {
				if ( $value ) {
					$parts[] = '--' . $key;
				}
			} elseif ( '' !== (string) $value && null !== $value ) {
				$parts[] = '--' . $key . '=' . $value;
			}
		}

		return $parts;
	}

	/**
	 * Check if a string value contains dangerous shell metacharacters.
	 */
	public function contains_shell_metacharacters( string $value ): bool {
		return (bool) preg_match( '/[;&|`$()!<>\\n\\r\'"\\\\]/', $value );
	}

	/**
	 * Summarize the blocked command policy for manifest/context.
	 */
	public function get_blocked_policy_summary(): string {
		return 'Always blocked WP-CLI subcommands: db reset, db drop, db import, user delete, post delete, plugin delete, theme delete, core update, core download, eval, eval-file, shell, package install, scaffold, config set, config create, rewrite structure. No raw shell, no pipes, no redirects, no semicolons, no backticks.';
	}

	/**
	 * Get a summary of supported commands for manifest display.
	 *
	 * @return array<int, array>
	 */
	public function get_commands_summary(): array {
		$summary = [];
		foreach ( $this->get_commands() as $cmd ) {
			$summary[] = [
				'command_id'            => $cmd['command_id'],
				'title'                 => $cmd['title'],
				'risk_level'            => $cmd['risk_level'],
				'requires_approval'     => $cmd['requires_approval'],
				'requires_health_check' => $cmd['requires_health_check'],
				'available'             => $cmd['available'],
			];
		}
		return $summary;
	}

	/**
	 * Count available commands grouped by risk level.
	 *
	 * @return array<string, int>
	 */
	public function count_by_risk(): array {
		$counts = [
			'low'      => 0,
			'medium'   => 0,
			'high'     => 0,
			'critical' => 0,
		];
		foreach ( $this->get_commands() as $cmd ) {
			if ( $cmd['available'] && isset( $counts[ $cmd['risk_level'] ] ) ) {
				$counts[ $cmd['risk_level'] ]++;
			}
		}
		return $counts;
	}
}
