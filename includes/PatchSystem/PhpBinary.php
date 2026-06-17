<?php
/**
 * STEP 105.6 — PHP CLI binary discovery + bounded execution.
 *
 * Resolves a USABLE PHP CLI binary for `php -l` syntax checking, validating
 * each candidate is actually executable and CLI (not php-fpm) before use, and
 * runs lint with a wall-clock timeout so a missing/slow binary never hangs or
 * is misread as a syntax failure.
 *
 * Discovery order (first usable wins):
 *   1. WPCC_PHP_BINARY constant
 *   2. wpcc_php_binary option
 *   3. PHP_BINARY            (if executable + CLI, not *-fpm)
 *   4. PHP_BINDIR/php[, /phpMAJOR.MINOR]
 *   5. command -v php (and php8.4 … php8 via PATH)
 *
 * Read-only/idempotent: no writes, no persistence. Result cached per request.
 */

namespace WPCommandCenter\PatchSystem;

defined( 'ABSPATH' ) || exit;

final class PhpBinary {

	/** Default wall-clock budget for a single lint/probe (seconds). */
	public const DEFAULT_TIMEOUT = 5;

	/** @var array{path:?string, reason:string}|null Per-request resolution cache. */
	private static ?array $resolved = null;

	/**
	 * Resolve a usable PHP CLI binary.
	 *
	 * @return array{path:?string, reason:string} reason ∈ ok|php_cli_not_found|php_cli_not_executable
	 */
	public static function resolve(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		if ( ! self::can_shell_exec() ) {
			return self::$resolved = [ 'path' => null, 'reason' => 'php_cli_not_found' ];
		}

		$saw_candidate = false;

		foreach ( self::candidates() as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			$saw_candidate = true;
			if ( self::is_usable_cli( $candidate ) ) {
				return self::$resolved = [ 'path' => $candidate, 'reason' => 'ok' ];
			}
		}

		// Candidates existed but none ran as a CLI (e.g. /usr/sbin/php8.4 missing,
		// or PHP_BINARY points at php-fpm) → distinguish from "nothing to try".
		return self::$resolved = [
			'path'   => null,
			'reason' => $saw_candidate ? 'php_cli_not_executable' : 'php_cli_not_found',
		];
	}

	/**
	 * Ordered, de-duplicated candidate paths/names to try.
	 *
	 * @return list<string>
	 */
	private static function candidates(): array {
		$list = [];

		if ( defined( 'WPCC_PHP_BINARY' ) && is_string( WPCC_PHP_BINARY ) && '' !== WPCC_PHP_BINARY ) {
			$list[] = WPCC_PHP_BINARY;
		}

		$opt = (string) get_option( 'wpcc_php_binary', '' );
		if ( '' !== $opt ) {
			$list[] = $opt;
		}

		if ( defined( 'PHP_BINARY' ) && '' !== PHP_BINARY && ! str_contains( basename( PHP_BINARY ), 'fpm' ) ) {
			$list[] = PHP_BINARY;
		}

		if ( defined( 'PHP_BINDIR' ) && '' !== PHP_BINDIR ) {
			$list[] = trailingslashit( PHP_BINDIR ) . 'php';
			if ( defined( 'PHP_MAJOR_VERSION' ) && defined( 'PHP_MINOR_VERSION' ) ) {
				$list[] = trailingslashit( PHP_BINDIR ) . 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
			}
		}

		// PATH-resolved names (covers hosts where the CLI is only on PATH).
		foreach ( [ 'php', 'php8.4', 'php8.3', 'php8.2', 'php8.1', 'php8', 'php-cli' ] as $name ) {
			$which = self::which( $name );
			if ( null !== $which ) {
				$list[] = $which;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/** Resolve a bare command name via `command -v`, returning its path or null. */
	private static function which( string $name ): ?string {
		$out = self::run( 'command -v ' . escapeshellarg( $name ), 3 );
		if ( 'timeout' === $out['status'] ) {
			return null;
		}
		$path = trim( (string) $out['stdout'] );
		return ( '' !== $path && self::looks_absolute( $path ) ) ? $path : null;
	}

	/**
	 * A candidate is usable iff it is executable and `-v` reports a PHP CLI
	 * (and is not php-fpm, which doesn't support `-l`).
	 */
	public static function is_usable_cli( string $path ): bool {
		// Absolute path must be a real executable file; bare names rely on PATH.
		if ( self::looks_absolute( $path ) && ! @is_executable( $path ) ) {
			return false;
		}
		if ( str_contains( basename( $path ), 'fpm' ) ) {
			return false;
		}

		$probe = self::run( escapeshellarg( $path ) . ' -v', 3 );
		if ( 'ok' !== $probe['status'] ) {
			return false;
		}
		$out = (string) $probe['stdout'] . (string) $probe['stderr'];

		// A real CLI prints "PHP x.y.z (cli) ..."; reject fpm/usage output.
		return str_contains( $out, 'PHP ' ) && ! str_contains( $out, 'fpm' ) && ! str_starts_with( ltrim( $out ), 'Usage:' );
	}

	/**
	 * Run `php -l` on a file with the resolved binary, bounded by a timeout.
	 *
	 * @return array{ran:bool, passed:bool, output:string, reason:string, binary:?string}
	 *         reason ∈ ok|syntax_error|verification_timeout|php_cli_not_found|php_cli_not_executable
	 */
	public static function lint( string $real_path, ?int $timeout = null ): array {
		$timeout = $timeout ?? self::lint_timeout();
		$r       = self::resolve();

		if ( null === $r['path'] ) {
			return [ 'ran' => false, 'passed' => false, 'output' => '', 'reason' => $r['reason'], 'binary' => null ];
		}

		$res = self::run( escapeshellarg( $r['path'] ) . ' -l ' . escapeshellarg( $real_path ), $timeout );

		if ( 'timeout' === $res['status'] ) {
			return [ 'ran' => false, 'passed' => false, 'output' => '', 'reason' => 'verification_timeout', 'binary' => $r['path'] ];
		}

		$out = trim( (string) $res['stdout'] . (string) $res['stderr'] );

		if ( str_contains( $out, 'No syntax errors detected' ) ) {
			return [ 'ran' => true, 'passed' => true, 'output' => $out, 'reason' => 'ok', 'binary' => $r['path'] ];
		}

		// The binary vanished between resolve() and now, or isn't a real CLI:
		// treat as a tooling failure (NOT a syntax error) so we fall back.
		if ( '' === $out || str_starts_with( $out, 'Usage:' ) || self::looks_like_shell_not_found( $out ) ) {
			return [ 'ran' => false, 'passed' => false, 'output' => $out, 'reason' => 'php_cli_not_executable', 'binary' => $r['path'] ];
		}

		// A real php -l syntax error (e.g. "PHP Parse error: ... in file on line N").
		return [ 'ran' => true, 'passed' => false, 'output' => $out, 'reason' => 'syntax_error', 'binary' => $r['path'] ];
	}

	/** Configurable lint wall-clock budget (seconds). */
	public static function lint_timeout(): int {
		if ( defined( 'WPCC_PHP_LINT_TIMEOUT' ) && (int) WPCC_PHP_LINT_TIMEOUT > 0 ) {
			return (int) WPCC_PHP_LINT_TIMEOUT;
		}
		$opt = (int) get_option( 'wpcc_php_lint_timeout', 0 );
		return $opt > 0 ? $opt : self::DEFAULT_TIMEOUT;
	}

	/**
	 * Bounded command execution via proc_open + stream_select. Never blocks past
	 * $timeout; kills a runaway child.
	 *
	 * @return array{status:string, stdout:string, stderr:string} status ∈ ok|timeout|error
	 */
	private static function run( string $cmd, int $timeout ): array {
		if ( ! function_exists( 'proc_open' ) ) {
			// Fall back to shell_exec (no hard timeout, but discovery uses small probes).
			if ( function_exists( 'shell_exec' ) ) {
				$o = shell_exec( $cmd . ' 2>&1' );
				return [ 'status' => is_string( $o ) ? 'ok' : 'error', 'stdout' => is_string( $o ) ? $o : '', 'stderr' => '' ];
			}
			return [ 'status' => 'error', 'stdout' => '', 'stderr' => '' ];
		}

		$descriptors = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
		$proc        = @proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			return [ 'status' => 'error', 'stdout' => '', 'stderr' => '' ];
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout   = '';
		$stderr   = '';
		$deadline = microtime( true ) + $timeout;

		do {
			$status = proc_get_status( $proc );
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );

			if ( ! $status['running'] ) {
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				proc_terminate( $proc, 9 );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				proc_close( $proc );
				return [ 'status' => 'timeout', 'stdout' => $stdout, 'stderr' => $stderr ];
			}
			usleep( 20000 ); // 20ms
		} while ( true );

		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $proc );

		return [ 'status' => 'ok', 'stdout' => $stdout, 'stderr' => $stderr ];
	}

	private static function looks_absolute( string $p ): bool {
		return str_starts_with( $p, '/' ) || (bool) preg_match( '#^[A-Za-z]:[\\\\/]#', $p );
	}

	private static function looks_like_shell_not_found( string $out ): bool {
		$o = strtolower( $out );
		return str_contains( $o, 'not found' ) || str_contains( $o, 'no such file' ) || str_contains( $o, 'command not found' );
	}

	public static function can_shell_exec(): bool {
		if ( ! function_exists( 'shell_exec' ) && ! function_exists( 'proc_open' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		// Need at least one runner available and not disabled.
		$shell_ok = function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $disabled, true );
		$proc_ok  = function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled, true );
		return $shell_ok || $proc_ok;
	}

	/** Test/seam: reset the per-request cache. */
	public static function reset_cache(): void {
		self::$resolved = null;
	}
}
