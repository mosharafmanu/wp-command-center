<?php
/**
 * §10 Security Model — API tokens with expiration for AI agent access.
 * Tokens are stored as salted hashes under wp-content/uploads/wpcc-tokens/,
 * protected from direct web access. The raw token is only ever returned
 * once, at creation time.
 */

namespace WPCommandCenter\Security;

defined( 'ABSPATH' ) || exit;

final class AuthTokens {

	public const SCOPE_READ_ONLY = 'read_only';
	public const SCOPE_FULL      = 'full';

	public const STATUS_ACTIVE  = 'active';
	public const STATUS_REVOKED = 'revoked';

	private const VALID_SCOPES = [ self::SCOPE_READ_ONLY, self::SCOPE_FULL ];

	private const DIR_NAME       = 'wpcc-tokens';
	private const MANIFEST_FILE  = 'manifest.json';
	private const TOKEN_PREFIX   = 'wpcc_';
	private const PREVIEW_LENGTH = 12;

	public static function scope_label( string $scope ): string {
		$labels = [
			self::SCOPE_READ_ONLY => __( 'Read-only', 'wp-command-center' ),
			self::SCOPE_FULL      => __( 'Full access', 'wp-command-center' ),
		];

		return $labels[ $scope ] ?? $scope;
	}

	/**
	 * Render a token's effective status (active / expired / revoked) as an
	 * HTML badge (escaped).
	 */
	public static function status_badge( array $token ): string {
		if ( self::STATUS_REVOKED === $token['status'] ) {
			return sprintf( '<span class="wpcc-badge wpcc-badge--neutral">%s</span>', esc_html__( 'Revoked', 'wp-command-center' ) );
		}

		if ( null !== $token['expires_at'] && $token['expires_at'] < time() ) {
			return sprintf( '<span class="wpcc-badge wpcc-badge--critical">%s</span>', esc_html__( 'Expired', 'wp-command-center' ) );
		}

		return sprintf( '<span class="wpcc-badge wpcc-badge--good">%s</span>', esc_html__( 'Active', 'wp-command-center' ) );
	}

	/**
	 * Create a new API token.
	 *
	 * @return array{token: string, record: array}|\WP_Error The raw token
	 *         (shown once) and its stored record.
	 */
	public function create( string $label, string $scope, ?int $expires_at, int $user_id ): array|\WP_Error {
		$label = sanitize_text_field( $label );

		if ( '' === $label ) {
			return new \WP_Error( 'wpcc_invalid_label', __( 'Please enter a label for this token.', 'wp-command-center' ) );
		}

		if ( ! in_array( $scope, self::VALID_SCOPES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_scope', __( 'Invalid token scope.', 'wp-command-center' ) );
		}

		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$raw = self::TOKEN_PREFIX . wp_generate_password( 64, false );

		$record = [
			'id'            => wp_generate_uuid4(),
			'label'         => $label,
			'token_hash'    => $this->hash_token( $raw ),
			'token_preview' => substr( $raw, 0, self::PREVIEW_LENGTH ),
			'scope'         => $scope,
			'status'        => self::STATUS_ACTIVE,
			'user_id'       => $user_id,
			'created_at'    => time(),
			'expires_at'    => $expires_at,
			'last_used_at'  => null,
		];

		$manifest   = $this->read_manifest( $dir );
		$manifest[] = $record;

		$this->write_manifest( $dir, $manifest );

		return [
			'token'  => $raw,
			'record' => $record,
		];
	}

	/**
	 * @return array<int, array> Token records (without the raw token),
	 *         newest first.
	 */
	public function list(): array {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return [];
		}

		$manifest = $this->read_manifest( $dir );

		usort( $manifest, static fn( array $a, array $b ): int => $b['created_at'] <=> $a['created_at'] );

		return $manifest;
	}

	public function revoke( string $id ): bool|\WP_Error {
		return $this->update( $id, [ 'status' => self::STATUS_REVOKED ] );
	}

	public function delete( string $id ): bool|\WP_Error {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$manifest = $this->read_manifest( $dir );
		$filtered = array_values( array_filter( $manifest, static fn( array $r ): bool => $r['id'] !== $id ) );

		if ( count( $filtered ) === count( $manifest ) ) {
			return new \WP_Error( 'wpcc_token_not_found', __( 'Token not found.', 'wp-command-center' ) );
		}

		$this->write_manifest( $dir, $filtered );

		return true;
	}

	/**
	 * Validate a raw bearer token. On success, records last_used_at and
	 * returns the token's record.
	 *
	 * @return array|\WP_Error
	 */
	public function validate( string $raw_token ): array|\WP_Error {
		$raw_token = trim( $raw_token );

		if ( '' === $raw_token ) {
			return new \WP_Error( 'wpcc_missing_token', __( 'Missing API token.', 'wp-command-center' ), [ 'status' => 401 ] );
		}

		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$hash     = $this->hash_token( $raw_token );
		$manifest = $this->read_manifest( $dir );

		foreach ( $manifest as &$record ) {
			if ( ! hash_equals( $record['token_hash'], $hash ) ) {
				continue;
			}

			if ( self::STATUS_ACTIVE !== $record['status'] ) {
				return new \WP_Error( 'wpcc_token_revoked', __( 'This API token has been revoked.', 'wp-command-center' ), [ 'status' => 401 ] );
			}

			if ( null !== $record['expires_at'] && $record['expires_at'] < time() ) {
				return new \WP_Error( 'wpcc_token_expired', __( 'This API token has expired.', 'wp-command-center' ), [ 'status' => 401 ] );
			}

			$record['last_used_at'] = time();
			$this->write_manifest( $dir, $manifest );

			return $record;
		}
		unset( $record );

		return new \WP_Error( 'wpcc_invalid_token', __( 'Invalid API token.', 'wp-command-center' ), [ 'status' => 401 ] );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function update( string $id, array $extra ): bool|\WP_Error {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$manifest = $this->read_manifest( $dir );
		$found    = false;

		foreach ( $manifest as &$record ) {
			if ( $record['id'] === $id ) {
				$record = array_merge( $record, $extra );
				$found  = true;
				break;
			}
		}
		unset( $record );

		if ( ! $found ) {
			return new \WP_Error( 'wpcc_token_not_found', __( 'Token not found.', 'wp-command-center' ) );
		}

		$this->write_manifest( $dir, $manifest );

		return true;
	}

	private function hash_token( string $raw_token ): string {
		return hash_hmac( 'sha256', $raw_token, wp_salt( 'auth' ) );
	}

	/**
	 * Absolute path of the token storage directory, creating it (and its
	 * protective files) on first use.
	 */
	private function get_storage_dir(): string|\WP_Error {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) ) {
			return new \WP_Error( 'wpcc_upload_dir_error', $upload_dir['error'] );
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . self::DIR_NAME;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'wpcc_mkdir_failed', __( 'Failed to create the token storage directory.', 'wp-command-center' ) );
		}

		$this->protect_directory( $dir );

		return $dir;
	}

	private function protect_directory( string $dir ): void {
		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$index = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * @return array<int, array>
	 */
	private function read_manifest( string $dir ): array {
		$file = trailingslashit( $dir ) . self::MANIFEST_FILE;

		if ( ! is_readable( $file ) ) {
			return [];
		}

		$data = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * @param array<int, array> $manifest
	 */
	private function write_manifest( string $dir, array $manifest ): void {
		file_put_contents( trailingslashit( $dir ) . self::MANIFEST_FILE, (string) wp_json_encode( array_values( $manifest ), JSON_PRETTY_PRINT ), LOCK_EX );
	}
}
