<?php
/**
 * PROGRAM-6R — connection store (the platform's primary identity).
 *
 * A Connection (opaque id) is the unit of configuration; provider + dialect are
 * properties. Unlimited connections, environments, endpoints. Stored in WP options
 * (autoload=no), connection-id-keyed — conceptually table-ready, no schema change.
 *
 * Backward compatibility (runtime untouched): a virtual "legacy Anthropic"
 * connection is surfaced when a pre-6R Anthropic key/constant exists, so existing
 * installs keep working. sync_runtime() mirrors the default Anthropic connection's
 * key+model into the exact options AnthropicClient already reads — a constant
 * always wins. No generator/transport/AnthropicClient code changes.
 */

namespace WPCommandCenter\Ai\Platform;

use WPCommandCenter\Ai\AnthropicClient;

defined( 'ABSPATH' ) || exit;

final class ConnectionStore {

	public const OPT_CONNECTIONS = 'wpcc_ai_connections';   // id => record (no secret)
	public const OPT_DEFAULT     = 'wpcc_ai_default_conn';  // id
	public const OPT_ROUTES      = 'wpcc_ai_routes';        // feature => id
	public const LEGACY_ID       = 'conn_legacy_anthropic'; // stable id for the bootstrap connection
	public const ANTHROPIC_KEY_OPTION   = 'wpcc_anthropic_api_key';
	public const ANTHROPIC_MODEL_OPTION = 'wpcc_anthropic_model';

	public const FEATURES = [
		'seo_meta'   => 'SEO meta',
		'alt_text'   => 'Alt text',
		'ai_content' => 'AI content',
	];

	private CredentialStore $creds;

	public function __construct() {
		$this->creds = new CredentialStore();
	}

	public function credentials(): CredentialStore {
		return $this->creds;
	}

	/* ---------------- read ---------------- */

	/** @return array<string,array<string,mixed>> id => normalized connection. */
	public function all(): array {
		$stored = get_option( self::OPT_CONNECTIONS, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$out    = [];
		foreach ( $stored as $id => $rec ) {
			if ( is_array( $rec ) && ProviderCatalog::is_valid( (string) ( $rec['provider'] ?? '' ) ) ) {
				$out[ (string) $id ] = $this->normalize( (string) $id, $rec );
			}
		}
		// Virtual bootstrap: surface a legacy Anthropic connection when a pre-6R key
		// exists and nothing is stored yet. Not persisted until the user acts on it.
		if ( empty( $out ) && ( new AnthropicClient() )->is_configured() ) {
			$out[ self::LEGACY_ID ] = $this->normalize( self::LEGACY_ID, [
				'name'          => __( 'Anthropic (existing)', 'wp-command-center' ),
				'provider'      => 'anthropic',
				'bridge_legacy' => true,
				'model'         => (string) get_option( self::ANTHROPIC_MODEL_OPTION, '' ),
			] );
		}
		return $out;
	}

	public function get( string $id ): ?array {
		$all = $this->all();
		return $all[ $id ] ?? null;
	}

	public function exists( string $id ): bool {
		return null !== $this->get( $id );
	}

	private function normalize( string $id, array $rec ): array {
		$provider = (string) ( $rec['provider'] ?? 'anthropic' );
		$def      = ProviderCatalog::get( $provider ) ?? [];
		$dialect  = ProviderCatalog::dialect_of( $provider );
		return [
			'id'           => $id,
			'name'         => isset( $rec['name'] ) && '' !== (string) $rec['name'] ? (string) $rec['name'] : (string) ( $def['label'] ?? $provider ),
			'provider'     => $provider,
			'dialect'      => $dialect,
			'endpoint'     => isset( $rec['endpoint'] ) && '' !== (string) $rec['endpoint'] ? (string) $rec['endpoint'] : ProviderCatalog::default_endpoint( $provider ),
			'model'        => (string) ( $rec['model'] ?? ( $def['default_model'] ?? '' ) ),
			'organization' => (string) ( $rec['organization'] ?? '' ),
			'project'      => (string) ( $rec['project'] ?? '' ),
			'deployment'   => (string) ( $rec['deployment'] ?? '' ),
			'enabled'      => isset( $rec['enabled'] ) ? (bool) $rec['enabled'] : true,
			'tags'         => isset( $rec['tags'] ) && is_array( $rec['tags'] ) ? array_values( array_filter( array_map( 'strval', $rec['tags'] ) ) ) : [],
			'scope'        => (string) ( $rec['scope'] ?? 'global' ),
			'metadata'     => isset( $rec['metadata'] ) && is_array( $rec['metadata'] ) ? $rec['metadata'] : [],
			'bridge_legacy'=> (bool) ( $rec['bridge_legacy'] ?? false ),
			'last_test'    => ( isset( $rec['last_test'] ) && is_array( $rec['last_test'] ) ) ? $rec['last_test'] : null,
			'created_at'   => (int) ( $rec['created_at'] ?? 0 ),
		];
	}

	/** Status helpers (read-only, never the key). */
	public function is_configured( array $conn ): bool {
		return $this->creds->has_secret( $conn );
	}
	public function runtime_usable( array $conn ): bool {
		return ProviderCatalog::runtime_usable( (string) $conn['provider'] );
	}
	public function testable( array $conn ): bool {
		return ProviderCatalog::test_supported( (string) $conn['provider'] );
	}

	/* ---------------- write ---------------- */

	/** Persist (materializing the virtual bootstrap on first edit). Returns the id. */
	private function persist( string $id, array $rec ): void {
		$stored        = get_option( self::OPT_CONNECTIONS, [] );
		$stored        = is_array( $stored ) ? $stored : [];
		$existing      = isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ? $stored[ $id ] : [];
		$stored[ $id ] = array_merge( $existing, $rec );
		update_option( self::OPT_CONNECTIONS, $stored, false );
	}

	/** Create a connection. Returns its opaque id. */
	public function create( string $provider, array $fields = [] ): string {
		if ( ! ProviderCatalog::is_valid( $provider ) ) {
			return '';
		}
		$id  = 'conn_' . str_replace( '-', '', wp_generate_uuid4() );
		$def = ProviderCatalog::get( $provider ) ?? [];
		$rec = [
			'name'       => (string) ( $fields['name'] ?? ( $def['label'] ?? $provider ) ),
			'provider'   => $provider,
			'endpoint'   => (string) ( $fields['endpoint'] ?? '' ),
			'model'      => (string) ( $fields['model'] ?? ( $def['default_model'] ?? '' ) ),
			'deployment' => (string) ( $fields['deployment'] ?? '' ),
			'organization' => (string) ( $fields['organization'] ?? '' ),
			'tags'       => isset( $fields['tags'] ) && is_array( $fields['tags'] ) ? $fields['tags'] : [],
			'enabled'    => true,
			'scope'      => 'global',
			'created_at' => time(),
		];
		$this->persist( $id, $rec );
		return $id;
	}

	/** Update editable fields of a connection (materializes the bootstrap if needed). */
	public function update( string $id, array $fields ): void {
		$conn = $this->get( $id );
		if ( ! $conn ) {
			return;
		}
		$allowed = [ 'name', 'endpoint', 'model', 'deployment', 'organization', 'project', 'tags', 'enabled', 'scope' ];
		$rec     = [];
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$rec[ $k ] = $fields[ $k ];
			}
		}
		// Materialize the virtual bootstrap with its identity.
		if ( self::LEGACY_ID === $id ) {
			$rec['provider']      = 'anthropic';
			$rec['bridge_legacy'] = true;
			if ( ! isset( $rec['name'] ) ) {
				$rec['name'] = $conn['name'];
			}
		}
		$this->persist( $id, $rec );
		$this->sync_runtime();
	}

	public function duplicate( string $id ): string {
		$conn = $this->get( $id );
		if ( ! $conn ) {
			return '';
		}
		$new = $this->create( $conn['provider'], [
			'name'       => $conn['name'] . ' ' . __( '(copy)', 'wp-command-center' ),
			'endpoint'   => $conn['endpoint'],
			'model'      => $conn['model'],
			'deployment' => $conn['deployment'],
			'organization' => $conn['organization'],
			'tags'       => $conn['tags'],
		] );
		// Secrets are NOT copied (security): the duplicate starts without a key.
		return $new;
	}

	public function set_enabled( string $id, bool $enabled ): void {
		$this->update( $id, [ 'enabled' => $enabled ] );
		if ( ! $enabled && $this->default_id() === $id ) {
			delete_option( self::OPT_DEFAULT );
			$this->sync_runtime();
		}
	}

	public function delete( string $id ): void {
		$stored = get_option( self::OPT_CONNECTIONS, [] );
		$stored = is_array( $stored ) ? $stored : [];
		unset( $stored[ $id ] );
		update_option( self::OPT_CONNECTIONS, $stored, false );
		$this->creds->clear_secret( $id );
		if ( (string) get_option( self::OPT_DEFAULT, '' ) === $id ) {
			delete_option( self::OPT_DEFAULT );
		}
		$routes = $this->routes_raw();
		foreach ( $routes as $f => $cid ) {
			if ( $cid === $id ) {
				unset( $routes[ $f ] );
			}
		}
		update_option( self::OPT_ROUTES, $routes, false );
		$this->sync_runtime();
	}

	public function record_test( string $id, bool $ok, string $code, array $extra = [] ): void {
		$this->update( $id, [] ); // ensure persisted
		$stored = get_option( self::OPT_CONNECTIONS, [] );
		$stored = is_array( $stored ) ? $stored : [];
		if ( isset( $stored[ $id ] ) ) {
			$stored[ $id ]['last_test'] = array_merge(
				[ 'ok' => $ok, 'code' => $code, 'time' => time() ],
				array_intersect_key( $extra, [ 'latency_ms' => 1, 'models' => 1 ] ) // whitelist — no secrets.
			);
			update_option( self::OPT_CONNECTIONS, $stored, false );
		}
	}

	/* ---------------- default + routing ---------------- */

	/** Resolved default connection id (configured + enabled + runtime-usable). */
	public function default_id(): string {
		$all    = $this->all();
		$stored = (string) get_option( self::OPT_DEFAULT, '' );
		if ( '' !== $stored && isset( $all[ $stored ] ) && $all[ $stored ]['enabled'] && $this->runtime_usable( $all[ $stored ] ) ) {
			return $stored;
		}
		foreach ( $all as $id => $conn ) {
			if ( $conn['enabled'] && $this->runtime_usable( $conn ) && $this->is_configured( $conn ) ) {
				return $id;
			}
		}
		return '';
	}

	/** Set the default; only a runtime-usable connection is allowed. */
	public function set_default( string $id ): bool {
		$conn = $this->get( $id );
		if ( ! $conn || ! $this->runtime_usable( $conn ) ) {
			return false; // honesty: cannot default to a connection the runtime can't use.
		}
		if ( self::LEGACY_ID === $id ) {
			$this->update( $id, [] ); // materialize
		}
		update_option( self::OPT_DEFAULT, $id, false );
		$this->sync_runtime();
		return true;
	}

	private function routes_raw(): array {
		$v = get_option( self::OPT_ROUTES, [] );
		return is_array( $v ) ? $v : [];
	}

	/** Resolved feature → connection id (only runtime-usable; else default). */
	public function routes(): array {
		$raw  = $this->routes_raw();
		$def  = $this->default_id();
		$all  = $this->all();
		$out  = [];
		foreach ( array_keys( self::FEATURES ) as $f ) {
			$cid = isset( $raw[ $f ] ) ? (string) $raw[ $f ] : '';
			$out[ $f ] = ( '' !== $cid && isset( $all[ $cid ] ) && $this->runtime_usable( $all[ $cid ] ) && $this->is_configured( $all[ $cid ] ) ) ? $cid : $def;
		}
		return $out;
	}

	/** Map a feature to a connection; only a runtime-usable connection is accepted. */
	public function set_route( string $feature, string $id ): bool {
		$conn = $this->get( $id );
		if ( ! isset( self::FEATURES[ $feature ] ) || ! $conn || ! $this->runtime_usable( $conn ) ) {
			return false;
		}
		$raw            = $this->routes_raw();
		$raw[ $feature ] = $id;
		update_option( self::OPT_ROUTES, $raw, false );
		return true;
	}

	/* ---------------- runtime bridge (keeps AnthropicClient untouched) ---------------- */

	/**
	 * Mirror the default Anthropic connection's key+model into the legacy options the
	 * runtime already reads. A constant always wins (we never overwrite when one is
	 * set). The bootstrap/legacy connection already IS those options → no-op for it.
	 */
	public function sync_runtime(): void {
		$src = ( new AnthropicClient() )->key_source();
		if ( in_array( $src, [ 'anthropic_constant', 'vision_constant' ], true ) ) {
			return; // constant wins; never overwrite the option.
		}
		$id   = $this->default_id();
		$conn = '' !== $id ? $this->get( $id ) : null;
		if ( ! $conn || Dialect::ANTHROPIC !== $conn['dialect'] ) {
			return;
		}
		if ( ! empty( $conn['bridge_legacy'] ) ) {
			// Its key already lives in wpcc_anthropic_api_key; only sync the model.
			if ( '' !== (string) $conn['model'] ) {
				update_option( self::ANTHROPIC_MODEL_OPTION, (string) $conn['model'], false );
			}
			return;
		}
		$key = $this->creds->secret( $conn );
		if ( '' !== $key ) {
			update_option( self::ANTHROPIC_KEY_OPTION, $key, false );
		}
		if ( '' !== (string) $conn['model'] ) {
			update_option( self::ANTHROPIC_MODEL_OPTION, (string) $conn['model'], false );
		}
	}
}
