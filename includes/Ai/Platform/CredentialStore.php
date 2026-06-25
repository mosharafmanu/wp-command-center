<?php
/**
 * PROGRAM-6R — credential store (one store, keyed by connection id).
 *
 * Secrets live in a single non-autoloaded option, keyed by opaque connection id —
 * NOT special-cased by provider. The ONE bridge to the legacy world: a connection
 * may be "constant-backed" when a `WPCC_ANTHROPIC_API_KEY`/`WPCC_VISION_API_KEY`
 * constant is set, in which case its key is read-only and resolved by
 * AnthropicClient (the key is never extracted). The runtime mirror (writing the
 * default Anthropic connection's key into `wpcc_anthropic_api_key` so the
 * unchanged runtime can read it) is owned by ConnectionStore::sync_runtime().
 *
 * Secrets: never in the connection record, never echoed, never logged, autoload=no.
 */

namespace WPCommandCenter\Ai\Platform;

use WPCommandCenter\Ai\AnthropicClient;

defined( 'ABSPATH' ) || exit;

final class CredentialStore {

	public const OPT = 'wpcc_ai_credentials'; // connection_id => key

	/** True when a usable credential exists for a connection (constant, stored, or not-needed). */
	public function has_secret( array $conn ): bool {
		if ( $this->is_constant_backed( $conn ) ) {
			return true;
		}
		$store = $this->raw();
		if ( isset( $store[ $conn['id'] ] ) && '' !== (string) $store[ $conn['id'] ] ) {
			return true;
		}
		// Local/self-hosted providers may not need a key.
		$def = ProviderCatalog::get( (string) $conn['provider'] );
		return (bool) ( $def['key_optional'] ?? false );
	}

	/** A constant (Anthropic/Vision) provides this connection's key → read-only in UI. */
	public function is_constant_backed( array $conn ): bool {
		if ( Dialect::ANTHROPIC !== ( $conn['dialect'] ?? '' ) ) {
			return false;
		}
		$src = ( new AnthropicClient() )->key_source();
		return in_array( $src, [ 'anthropic_constant', 'vision_constant' ], true )
			&& ! empty( $conn['bridge_legacy'] );
	}

	/**
	 * The raw secret for a connection — used ONLY by the tester. For a
	 * constant-backed connection this returns '' (the tester calls AnthropicClient,
	 * which reads its own key, so the key is never extracted here).
	 */
	public function secret( array $conn ): string {
		if ( $this->is_constant_backed( $conn ) ) {
			return '';
		}
		$store = $this->raw();
		return (string) ( $store[ $conn['id'] ] ?? '' );
	}

	public function set_secret( string $connection_id, string $key ): void {
		$store                   = $this->raw();
		$store[ $connection_id ] = $key;
		update_option( self::OPT, $store, false );
	}

	public function clear_secret( string $connection_id ): void {
		$store = $this->raw();
		unset( $store[ $connection_id ] );
		update_option( self::OPT, $store, false );
	}

	private function raw(): array {
		$v = get_option( self::OPT, [] );
		return is_array( $v ) ? $v : [];
	}
}
