<?php
/**
 * Private on-disk store for artifacts that must never be web-retrievable:
 * access-token records, the audit log, undo snapshots, file patches, media
 * snapshots and plugin backups.
 *
 * WHY THIS EXISTS. These artifacts have to live under wp-content/uploads —
 * on shared hosting it is the only reliably writable location — and uploads
 * is served directly by the web server. Protection used to be a `.htaccess`
 * carrying `Require all denied`, plus an `index.php` to blunt directory
 * listing. Both are Apache-specific: nginx, Caddy and LiteSpeed in its
 * non-htaccess mode never read `.htaccess`, and nginx's index directive
 * looks for `index.html`, not `index.php`. On those stacks every file in
 * these directories was fetchable over plain HTTP, and the fixed filenames
 * (`manifest.json`, `audit.log`) made them findable without a directory
 * listing: read the manifest, learn every snapshot and patch UUID, fetch
 * the lot. Verified against a real nginx before this was written.
 *
 * THE FIX. Each store directory carries a per-install random suffix —
 * `wpcc-snapshots-3f9c…` — so no path in the store can be named by anyone
 * who has not already read it out of the database or the filesystem. That
 * holds on every web server, because it does not depend on the web server
 * honouring anything. The Apache and IIS deny rules and the index files stay
 * as defence in depth, not as the mechanism.
 *
 * The suffix is stored in the `wpcc_storage_suffix` option, and — because
 * losing it would orphan every token and every rollback record — it is
 * recovered from the directory names themselves if the option is ever lost.
 * A pre-existing unsuffixed directory is migrated with a single atomic
 * rename the first time its store is touched.
 */

namespace WPCommandCenter\Security;

defined( 'ABSPATH' ) || exit;

final class PrivateStore {

	/** Option holding this install's directory suffix. Never autoloaded. */
	private const OPTION = 'wpcc_storage_suffix';

	/**
	 * Every store this plugin keeps under uploads. Used for suffix recovery
	 * and mirrored by uninstall.php, which cannot load this class.
	 *
	 * @var string[]
	 */
	public const STORES = [
		'wpcc-tokens',
		'wpcc-audit',
		'wpcc-snapshots',
		'wpcc-media-snapshots',
		'wpcc-patches',
		'wpcc-plugin-backups',
	];

	/** Per-request memo; the suffix cannot change within a request. */
	private static string $suffix = '';

	/**
	 * Per-request memo of resolved store paths. Token validation runs on every
	 * authenticated REST and MCP request and resolves the token store each time;
	 * without this it would repeat an option read and four file_exists() probes
	 * per request for a path that cannot change mid-request.
	 *
	 * @var array<string, string>
	 */
	private static array $paths = [];

	/**
	 * This install's directory suffix, generating it once on first use.
	 */
	public static function suffix(): string {
		if ( '' !== self::$suffix ) {
			return self::$suffix;
		}

		$stored = (string) get_option( self::OPTION, '' );
		if ( self::is_suffix( $stored ) ) {
			self::$suffix = $stored;
			return self::$suffix;
		}

		// The option is gone but the directories are not — adopt their suffix
		// rather than stranding the data behind a new one.
		$recovered = self::recover_suffix();
		if ( '' !== $recovered ) {
			update_option( self::OPTION, $recovered, false );
			self::$suffix = $recovered;
			return self::$suffix;
		}

		$fresh = wp_hash( wp_generate_password( 64, true, true ) . microtime( true ) );
		if ( ! self::is_suffix( $fresh ) ) {
			$fresh = md5( wp_generate_password( 64, true, true ) . microtime( true ) );
		}

		// add_option() fails if a concurrent request won the race; re-read so
		// both requests agree on one suffix and neither creates a second store.
		if ( ! add_option( self::OPTION, $fresh, '', false ) ) {
			$stored = (string) get_option( self::OPTION, '' );
			if ( self::is_suffix( $stored ) ) {
				self::$suffix = $stored;
				return self::$suffix;
			}
		}

		self::$suffix = $fresh;
		return self::$suffix;
	}

	/**
	 * Absolute path of a store directory — created, migrated and hardened on
	 * first use. Returns '' when uploads is unusable; callers that need a
	 * reason use dir() instead.
	 */
	public static function path( string $name ): string {
		if ( isset( self::$paths[ $name ] ) ) {
			return self::$paths[ $name ];
		}

		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$base   = trailingslashit( $upload_dir['basedir'] );
		$dir    = $base . $name . '-' . self::suffix();
		$legacy = $base . $name;

		// One-time migration off the guessable path. rename() is atomic, so a
		// reader sees the whole directory at the old name or at the new one.
		if ( ! is_dir( $dir ) && is_dir( $legacy ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic directory move. WP_Filesystem::move() offers no atomicity guarantee, and token and rollback integrity depend on this being all-or-nothing.
			@rename( $legacy, $dir );
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// Both present: a restored backup, or a rename that failed once and left
		// a fresh store beside the old one. Leaving the legacy directory there
		// would strand its data AND keep it at the guessable path, which is the
		// whole problem this class exists to remove. Drain it.
		if ( is_dir( $legacy ) ) {
			self::drain_legacy( $legacy, $dir );
		}

		// FAIL SAFE, NOT FAIL SECURE. If the host would not let us move the data —
		// a read-only uploads mount, a restrictive open_basedir, an SELinux label —
		// then the legacy directory still holds the tokens and the rollback
		// snapshots while the new one is empty. Handing back the empty store would
		// invalidate every access token on the site and orphan every undo, turning
		// a hardening change into an outage. Keep using the old location, which is
		// no less protected than it was before this class existed, and try again on
		// the next request.
		if ( self::has_payload( $legacy ) ) {
			self::harden( $legacy );
			self::$paths[ $name ] = $legacy;

			return $legacy;
		}

		self::harden( $dir );

		self::$paths[ $name ] = $dir;

		return $dir;
	}

	/** True when a directory still holds something other than our own markers. */
	private static function has_payload( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$entries = scandir( $dir );

		if ( false === $entries ) {
			return false;
		}

		$markers = [ '.', '..', '.htaccess', 'index.php', 'index.html', 'web.config' ];

		foreach ( $entries as $entry ) {
			if ( ! in_array( $entry, $markers, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve every store once, which is what performs the migration off the
	 * guessable paths.
	 *
	 * Resolution is lazy — a store moves the first time its own feature touches
	 * it. That is fine for tokens, which are read on the next authenticated
	 * request, but not for the rest: a site that upgrades and never creates
	 * another patch would leave its existing patches sitting at
	 * `uploads/wpcc-patches/` indefinitely, which is exactly the exposure this
	 * class removes. So the upgrade path walks all of them.
	 *
	 * @return int Number of stores now resolved to a suffixed directory.
	 */
	public static function relocate_all(): int {
		$moved = 0;

		foreach ( self::STORES as $store ) {
			$path = self::path( $store );

			if ( '' !== $path && str_ends_with( $path, '-' . self::suffix() ) ) {
				++$moved;
			}
		}

		return $moved;
	}

	/**
	 * path() with an explanatory error, for callers that surface failures.
	 *
	 * @return string|\WP_Error
	 */
	public static function dir( string $name, string $error_message ) {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new \WP_Error( 'wpcc_upload_dir_error', $upload_dir['error'] );
		}

		$dir = self::path( $name );

		if ( '' === $dir ) {
			return new \WP_Error( 'wpcc_mkdir_failed', $error_message );
		}

		return $dir;
	}

	/**
	 * Defence in depth for servers that do read these: Apache (.htaccess),
	 * IIS (web.config), plus index files so a directory request cannot be
	 * answered with a listing. None of this is the protection — the
	 * unguessable directory name is.
	 */
	public static function harden( string $dir ): void {
		$dir = trailingslashit( $dir );

		$markers = [
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			// nginx resolves a directory request against index.html, not index.php.
			'index.html' => '',
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		];

		foreach ( $markers as $file => $contents ) {
			if ( ! file_exists( $dir . $file ) ) {
				file_put_contents( $dir . $file, $contents );
			}
		}
	}

	/**
	 * Move anything still sitting in a legacy store into the private one and
	 * remove the legacy directory. An entry that already exists at the target
	 * is left alone — the suffixed store is the live one, and a stale copy must
	 * never overwrite it — and then discarded with the directory.
	 */
	private static function drain_legacy( string $legacy, string $dir ): void {
		$entries = scandir( $legacy );

		if ( false === $entries ) {
			return;
		}

		$stuck = false;

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$from = trailingslashit( $legacy ) . $entry;
			$to   = trailingslashit( $dir ) . $entry;

			// The live store already has this name, so the legacy copy is stale.
			// Our own marker files are in this category too.
			if ( file_exists( $to ) ) {
				self::rmdir_recursive( $from );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic move within one filesystem; see the note on the directory rename above.
			if ( ! @rename( $from, $to ) ) {
				$stuck = true;
			}
		}

		// Only remove the directory once it is genuinely empty. Removing it while
		// a move was refused would delete the very data this migration exists to
		// preserve — the caller falls back to the legacy path instead.
		if ( ! $stuck ) {
			self::rmdir_recursive( $legacy );
		}
	}

	/** Remove a directory this plugin created, without following symlinks. */
	private static function rmdir_recursive( string $path ): void {
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		if ( is_link( $path ) || ! is_dir( $path ) ) {
			wp_delete_file( $path );
			return;
		}

		$entries = scandir( $path );

		if ( false !== $entries ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				self::rmdir_recursive( trailingslashit( $path ) . $entry );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- removes a now-empty directory this plugin created. WP_Filesystem requires credentialed initialisation that is not available here.
		@rmdir( $path );
	}

	/** A suffix is exactly 32 lowercase hex characters. */
	private static function is_suffix( string $candidate ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $candidate );
	}

	/**
	 * Read this install's suffix back off the directory names, for the case
	 * where the option was deleted but the stores survived.
	 */
	private static function recover_suffix(): string {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$base = trailingslashit( $upload_dir['basedir'] );

		foreach ( self::STORES as $store ) {
			foreach ( (array) glob( $base . $store . '-*', GLOB_ONLYDIR ) as $found ) {
				$candidate = substr( basename( (string) $found ), strlen( $store ) + 1 );
				if ( self::is_suffix( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return '';
	}
}
