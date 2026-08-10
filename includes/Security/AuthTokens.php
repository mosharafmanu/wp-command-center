<?php
/**
 * §10 Security Model — API tokens with expiration for AI agent access.
 * Tokens are stored as salted hashes in the `wpcc-tokens` private store (see
 * Security\PrivateStore — an uploads directory carrying a per-install random
 * suffix, so it is not web-retrievable on any server). The raw token is only
 * ever returned once, at creation time.
 */

namespace WPCommandCenter\Security;

use WPCommandCenter\Operations\CapabilityRegistry;

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
			self::SCOPE_READ_ONLY => __( 'Read-only', 'ai-command-center' ),
			self::SCOPE_FULL      => __( 'Full access', 'ai-command-center' ),
		];

		return $labels[ $scope ] ?? $scope;
	}

	/**
	 * Render a token's effective status (active / expired / revoked) as an
	 * HTML badge (escaped).
	 */
	/**
	 * Resolve the raw bearer token from the request, across servers that do not
	 * hand PHP the Authorization header the usual way.
	 *
	 * Discovered on a clean WordPress install: on Apache (apache2handler, and
	 * commonly CGI/FastCGI) `$_SERVER['HTTP_AUTHORIZATION']` is never populated,
	 * so `WP_REST_Request::get_header('authorization')` returns nothing and every
	 * assistant request failed with "Missing API token" — even with a valid,
	 * active token. The header IS present; only that one lookup cannot see it.
	 *
	 * This checks the same sources WordPress core and the wider ecosystem use, in
	 * order of reliability. It changes NO authorization policy: whatever is found
	 * still goes through validate() exactly as before. It only stops a correct
	 * token from being thrown away before it is ever checked.
	 *
	 * @return string Raw token, or '' when the request carries no bearer header.
	 */
	public static function bearer_from_request( \WP_REST_Request $request ): string {
		$candidates = [];

		$header = $request->get_header( 'authorization' );
		if ( is_string( $header ) && '' !== $header ) {
			$candidates[] = $header;
		}
		// Apache rewrites the header into REDIRECT_* when it passes through a rule.
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$candidates[] = (string) $_SERVER[ $key ];
			}
		}
		// Last resort: ask the server module directly (case-insensitive key match).
		foreach ( [ 'getallheaders', 'apache_request_headers' ] as $fn ) {
			if ( ! function_exists( $fn ) ) {
				continue;
			}
			$headers = call_user_func( $fn );
			if ( ! is_array( $headers ) ) {
				continue;
			}
			foreach ( $headers as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'authorization' ) && '' !== (string) $value ) {
					$candidates[] = (string) $value;
				}
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( preg_match( '/^Bearer\s+(.+)$/i', trim( $candidate ), $m ) ) {
				return trim( $m[1] );
			}
		}

		return '';
	}

	/**
	 * Canonical answer to "would this token work right now?".
	 *
	 * The stored `status` is not the whole rule: it stays 'active' on a token
	 * whose `expires_at` has passed, because expiry is computed at use time
	 * rather than written back. Anything that counts, lists or offers tokens
	 * has to apply both halves or it disagrees with validate() — which is how
	 * the Set up screen came to announce "4 access tokens ready" on a site with
	 * one usable token and three revoked ones. This is the single definition for
	 * anything that COUNTS or OFFERS tokens: usable_only() wraps it, and
	 * AdoptionStatus, ConnectionStatus and the Integrations screen all go
	 * through that.
	 *
	 * status_badge() deliberately does not call this. A badge has to say WHY a
	 * token is unusable — "Revoked" and "Expired" are different facts to the
	 * person reading the table — so it keeps the three-way form of the same
	 * rule. Both agree on the boundary: expired means `expires_at < time()`.
	 *
	 * @param array<string, mixed> $token A token record from list().
	 */
	public static function is_usable( array $token ): bool {
		if ( self::STATUS_ACTIVE !== ( $token['status'] ?? '' ) ) {
			return false;
		}

		$expires = $token['expires_at'] ?? null;

		return null === $expires || (int) $expires >= time();
	}

	/**
	 * Filter a list of token records down to the ones that would work right now.
	 *
	 * @param array<int, array<string, mixed>> $tokens
	 * @return array<int, array<string, mixed>> Re-indexed.
	 */
	public static function usable_only( array $tokens ): array {
		return array_values( array_filter( $tokens, [ self::class, 'is_usable' ] ) );
	}

	public static function status_badge( array $token ): string {
		if ( self::STATUS_REVOKED === $token['status'] ) {
			return sprintf( '<span class="wpcc-badge wpcc-badge--neutral">%s</span>', esc_html__( 'Revoked', 'ai-command-center' ) );
		}

		if ( null !== $token['expires_at'] && $token['expires_at'] < time() ) {
			return sprintf( '<span class="wpcc-badge wpcc-badge--critical">%s</span>', esc_html__( 'Expired', 'ai-command-center' ) );
		}

		return sprintf( '<span class="wpcc-badge wpcc-badge--good">%s</span>', esc_html__( 'Active', 'ai-command-center' ) );
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
			return new \WP_Error( 'wpcc_invalid_label', __( 'Please enter a label for this token.', 'ai-command-center' ) );
		}

		if ( ! in_array( $scope, self::VALID_SCOPES, true ) ) {
			return new \WP_Error( 'wpcc_invalid_scope', __( 'Invalid token scope.', 'ai-command-center' ) );
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

		// Step 79 — Auto-bootstrap capability assignment from token scope so
		// the token is immediately usable over MCP with no manual setup.
		( new CapabilityRegistry() )->bootstrap_token( $record['id'], $scope, 'token_created' );

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
		$result = $this->update( $id, [ 'status' => self::STATUS_REVOKED ] );

		// Step 79 — Remove the revoked token's capability assignment so it
		// cannot be reused and doesn't linger as a stale mapping.
		if ( true === $result ) {
			( new CapabilityRegistry() )->deprovision_token( $id );
		}

		return $result;
	}

	public function delete( string $id ): bool|\WP_Error {
		$dir = $this->get_storage_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$manifest = $this->read_manifest( $dir );
		$filtered = array_values( array_filter( $manifest, static fn( array $r ): bool => $r['id'] !== $id ) );

		if ( count( $filtered ) === count( $manifest ) ) {
			return new \WP_Error( 'wpcc_token_not_found', __( 'Token not found.', 'ai-command-center' ) );
		}

		$this->write_manifest( $dir, $filtered );

		// Step 79 — Remove the deleted token's capability assignment.
		( new CapabilityRegistry() )->deprovision_token( $id );

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
			return new \WP_Error( 'wpcc_missing_token', __( 'No access token was sent. Add your token to the assistant configuration — you can create one in WP Command Center → Settings → Connections.', 'ai-command-center' ), [ 'status' => 401 ] );
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
				return new \WP_Error( 'wpcc_token_revoked', __( 'This access token was revoked, so it no longer works. Create a new one in WP Command Center → Settings → Connections and update your assistant configuration.', 'ai-command-center' ), [ 'status' => 401 ] );
			}

			if ( null !== $record['expires_at'] && $record['expires_at'] < time() ) {
				return new \WP_Error( 'wpcc_token_expired', __( 'This API token has expired.', 'ai-command-center' ), [ 'status' => 401 ] );
			}

			$record['last_used_at'] = time();
			$this->write_manifest( $dir, $manifest );

			return $record;
		}
		unset( $record );

		return new \WP_Error( 'wpcc_invalid_token', __( 'This access token was not recognised by this site. Check it was copied in full and belongs to this site, or create a new one in WP Command Center → Settings → Connections.', 'ai-command-center' ), [ 'status' => 401 ] );
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
			return new \WP_Error( 'wpcc_token_not_found', __( 'Token not found.', 'ai-command-center' ) );
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
		return PrivateStore::dir(
			self::DIR_NAME,
			__( 'Failed to create the token storage directory.', 'ai-command-center' )
		);
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
