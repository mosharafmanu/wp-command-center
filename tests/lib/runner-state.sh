#!/usr/bin/env bash
# Shared WordPress state isolation for tests/run.sh.
#
# Snapshot data may contain credential hashes and encrypted provider credentials. It is
# intentionally kept in shell variables and passed to WP-CLI through the environment;
# callers must never echo it or place it on a command line.

RUNNER_STATE_SNAPSHOT() {
	wp --path="$WP_ROOT" eval '
		use WPCommandCenter\Security\PrivateStore;

		$option_names = [
			"wpcc_security_mode",
			"wpcc_enforce_capabilities",
			"wpcc_builtin_ai_tools",
			"wpcc_capability_assignments",
			"wpcc_ai_connections",
			"wpcc_ai_default_conn",
			"wpcc_ai_routes",
			"wpcc_ai_credentials",
			"wpcc_anthropic_api_key",
			"wpcc_anthropic_model",
		];
		$options = [];
		foreach ( $option_names as $name ) {
			$sentinel = new stdClass();
			$value = get_option( $name, $sentinel );
			$options[ $name ] = [
				"exists" => $value !== $sentinel,
				"value"  => $value !== $sentinel ? $value : null,
			];
		}

		$token_dir  = PrivateStore::path( "wpcc-tokens" );
		$token_file = "" !== $token_dir ? trailingslashit( $token_dir ) . "manifest.json" : "";
		$token_raw  = "" !== $token_file && is_file( $token_file ) ? file_get_contents( $token_file ) : false;
		if ( false === $token_raw && "" !== $token_file && is_file( $token_file ) ) {
			WP_CLI::error( "Unable to read the access-token manifest for runner isolation." );
		}

		echo wp_json_encode( [
			"version"        => 1,
			"options"        => $options,
			"token_manifest" => [
				"exists"  => false !== $token_raw,
				"contents" => false !== $token_raw ? base64_encode( $token_raw ) : "",
				"sha256"   => false !== $token_raw ? hash( "sha256", $token_raw ) : "",
			],
		] );
	' 2>/dev/null
}

RUNNER_STATE_RESTORE() {
	local snapshot="$1" output
	[ -n "$snapshot" ] || return 1

	output="$(env WPCC_RUNNER_STATE_SNAPSHOT="$snapshot" wp --path="$WP_ROOT" eval '
		use WPCommandCenter\Security\PrivateStore;

		$raw = getenv( "WPCC_RUNNER_STATE_SNAPSHOT" );
		$state = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $state ) || 1 !== ( $state["version"] ?? null ) || ! is_array( $state["options"] ?? null ) ) {
			WP_CLI::error( "Invalid runner state snapshot." );
		}

		foreach ( $state["options"] as $name => $entry ) {
			if ( ! is_string( $name ) || ! is_array( $entry ) || ! array_key_exists( "exists", $entry ) ) {
				WP_CLI::error( "Invalid option entry in runner state snapshot." );
			}
			if ( $entry["exists"] ) {
				update_option( $name, $entry["value"] ?? null, false );
			} else {
				delete_option( $name );
			}
		}

		$token = $state["token_manifest"] ?? null;
		if ( ! is_array( $token ) || ! array_key_exists( "exists", $token ) ) {
			WP_CLI::error( "Invalid token entry in runner state snapshot." );
		}
		$token_dir  = PrivateStore::path( "wpcc-tokens" );
		$token_file = "" !== $token_dir ? trailingslashit( $token_dir ) . "manifest.json" : "";
		if ( "" === $token_file ) {
			WP_CLI::error( "Unable to resolve the access-token manifest for runner isolation." );
		}
		if ( $token["exists"] ) {
			$contents = base64_decode( (string) ( $token["contents"] ?? "" ), true );
			if ( false === $contents || ! hash_equals( (string) ( $token["sha256"] ?? "" ), hash( "sha256", $contents ) ) ) {
				WP_CLI::error( "Invalid access-token manifest payload in runner state snapshot." );
			}
			if ( strlen( $contents ) !== file_put_contents( $token_file, $contents, LOCK_EX ) ) {
				WP_CLI::error( "Unable to restore the access-token manifest." );
			}
		} elseif ( is_file( $token_file ) && ! unlink( $token_file ) ) {
			WP_CLI::error( "Unable to remove a suite-created access-token manifest." );
		}

		// Read everything back. A successful update call is not proof that the persisted
		// value has the same type/value, and a missing option must remain missing.
		foreach ( $state["options"] as $name => $entry ) {
			$sentinel = new stdClass();
			$actual = get_option( $name, $sentinel );
			if ( $entry["exists"] ) {
				if ( $actual === $sentinel || maybe_serialize( $actual ) !== maybe_serialize( $entry["value"] ?? null ) ) {
					WP_CLI::error( "Runner option restoration verification failed." );
				}
			} elseif ( $actual !== $sentinel ) {
				WP_CLI::error( "Runner option deletion verification failed." );
			}
		}

		$actual_exists = is_file( $token_file );
		if ( (bool) $token["exists"] !== $actual_exists ) {
			WP_CLI::error( "Runner token restoration existence check failed." );
		}
		if ( $actual_exists ) {
			$actual_raw = file_get_contents( $token_file );
			if ( false === $actual_raw || ! hash_equals( (string) $token["sha256"], hash( "sha256", $actual_raw ) ) ) {
				WP_CLI::error( "Runner token restoration verification failed." );
			}
		}

		echo "WPCC_RUNNER_RESTORE_OK";
	' 2>/dev/null)" || return 1

	[ "$output" = "WPCC_RUNNER_RESTORE_OK" ]
}

export -f RUNNER_STATE_SNAPSHOT RUNNER_STATE_RESTORE
