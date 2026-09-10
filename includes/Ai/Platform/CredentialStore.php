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

	/** True when a usable credential exists for a connection (constant, stored, legacy, or not-needed). */
	public function has_secret( array $conn ): bool {
		if ( $this->is_constant_backed( $conn ) ) {
			return true;
		}
		// The bootstrap connection's key lives in the pre-6R option, not in this store.
		if ( $this->is_legacy_option_backed( $conn ) ) {
			return true;
		}

		/*
		 * An empty or partial record is a valid read state while no default provider
		 * has been selected. It cannot identify a stored credential or establish
		 * that a provider permits keyless access, so it is not configured.
		 */
		if ( ! isset( $conn['id'], $conn['provider'] ) || '' === (string) $conn['id'] || '' === (string) $conn['provider'] ) {
			return false;
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
		return $this->legacy_key_source( $conn, [ 'anthropic_constant', 'vision_constant' ] );
	}

	/**
	 * A pre-6R key OPTION provides this bootstrap connection's key.
	 *
	 * ConnectionStore surfaces the virtual "Anthropic (existing)" connection precisely
	 * BECAUSE a legacy key exists — and then this store, which only ever looked in its
	 * own per-connection option, answered that the connection had no key. The screen
	 * said "Needs a key · Add an API key to finish setup" about a connection whose key
	 * the runtime was reading and using on every call, and nothing could be routed to it
	 * because both default_id() and routes() gate on has_secret(). The two halves of the
	 * bridge now read the same source.
	 */
	public function is_legacy_option_backed( array $conn ): bool {
		return $this->legacy_key_source( $conn, [ 'anthropic_option', 'vision_option' ] );
	}

	/**
	 * Whether the bootstrap Anthropic connection's key comes from one of $sources.
	 * Only the bridge connection can be legacy-backed — a normal saved connection keeps
	 * its key in this store, keyed by its own id.
	 *
	 * @param string[] $sources
	 */
	private function legacy_key_source( array $conn, array $sources ): bool {
		if ( Dialect::ANTHROPIC !== ( $conn['dialect'] ?? '' ) || empty( $conn['bridge_legacy'] ) ) {
			return false;
		}
		return in_array( ( new AnthropicClient() )->key_source(), $sources, true );
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
