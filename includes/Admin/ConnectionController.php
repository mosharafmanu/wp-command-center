<?php
/**
 * PROGRAM-6R — connection configuration controller (same-page admin POST).
 *
 * Drives the connection-centric AI platform UI through ConnectionStore (no REST
 * change). Reuses the `wpcc_ai_setup` nonce. Security: nonce + manage_options on
 * every action; inputs sanitized/validated; the API key is stored via
 * CredentialStore and NEVER echoed/returned/logged; every mutation emits a
 * secret-free AuditLog event; default/route only accept runtime-usable connections.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Ai\Platform\ConnectionStore;
use WPCommandCenter\Ai\Platform\ConnectionTester;
use WPCommandCenter\Ai\Platform\ProviderCatalog;
use WPCommandCenter\Ai\Platform\Dialect;
use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ConnectionController {

	public const NONCE = 'wpcc_ai_setup';

	private ConnectionStore $store;

	public function __construct() {
		$this->store = new ConnectionStore();
	}

	/** @return array{type:string,message:string}|null */
	public function handle_post(): ?array {
		if ( ! isset( $_POST['wpcc_conn_action'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->n( 'error', __( 'You do not have permission to change AI settings.', 'wp-command-center' ) );
		}
		if ( ! check_admin_referer( self::NONCE ) ) {
			return $this->n( 'error', __( 'Security check failed. Please try again.', 'wp-command-center' ) );
		}
		$action = sanitize_key( wp_unslash( (string) $_POST['wpcc_conn_action'] ) );
		$id     = isset( $_POST['wpcc_conn_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wpcc_conn_id'] ) ) : '';

		switch ( $action ) {
			case 'create':      return $this->create();
			case 'update':      return $this->update( $id );
			case 'update_key':  return $this->update_key( $id );
			case 'clear_key':   return $this->clear_key( $id );
			case 'set_default': return $this->set_default( $id );
			case 'set_enabled': return $this->set_enabled( $id );
			case 'duplicate':   return $this->duplicate( $id );
			case 'delete':      return $this->delete( $id );
			case 'test':        return $this->test( $id );
			case 'save_routes': return $this->save_routes();
			default:            return $this->n( 'error', __( 'Unknown action.', 'wp-command-center' ) );
		}
	}

	private function create(): array {
		$provider = isset( $_POST['wpcc_provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['wpcc_provider'] ) ) : '';
		if ( ! ProviderCatalog::is_valid( $provider ) ) {
			return $this->n( 'error', __( 'Choose a provider.', 'wp-command-center' ) );
		}
		$name  = $this->str( 'wpcc_name', 60 );
		$model = $this->model_value( $provider );
		$endpoint = $this->endpoint_value( $provider );
		if ( is_array( $endpoint ) ) { return $endpoint; }
		$id = $this->store->create( $provider, [
			'name'       => $name,
			'model'      => $model,
			'endpoint'   => $endpoint,
			'deployment' => $this->str( 'wpcc_deployment', 120 ),
			'tags'       => $this->tags(),
		] );
		$raw_key = $this->raw_key();
		if ( '' !== $raw_key ) {
			$k = $this->clean_key( $raw_key );
			if ( is_array( $k ) ) { return $k; }
			$this->store->credentials()->set_secret( $id, $k );
			$this->audit( 'ai.connection.key.updated', [ 'connection' => $id, 'provider' => $provider ] );
		}
		// Auto-default if this is the first runtime-usable, configured connection.
		$conn = $this->store->get( $id );
		if ( $conn && $this->store->runtime_usable( $conn ) && '' === $this->store->default_id() && $this->store->is_configured( $conn ) ) {
			$this->store->set_default( $id );
		}
		$this->store->sync_runtime();
		$this->audit( 'ai.connection.created', [ 'connection' => $id, 'provider' => $provider ] );
		return $this->n( 'success', __( 'Connection created.', 'wp-command-center' ) );
	}

	private function update( string $id ): array {
		$conn = $this->store->get( $id );
		if ( ! $conn ) { return $this->n( 'error', __( 'Connection not found.', 'wp-command-center' ) ); }
		$endpoint = $this->endpoint_value( (string) $conn['provider'] );
		if ( is_array( $endpoint ) ) { return $endpoint; }
		$fields = [
			'name'       => $this->str( 'wpcc_name', 60 ) ?: $conn['name'],
			'model'      => $this->model_value( (string) $conn['provider'] ),
			'endpoint'   => $endpoint,
			'deployment' => $this->str( 'wpcc_deployment', 120 ),
			'tags'       => $this->tags(),
		];
		$this->store->update( $id, $fields );
		$this->audit( 'ai.connection.updated', [ 'connection' => $id ] );
		return $this->n( 'success', __( 'Connection saved.', 'wp-command-center' ) );
	}

	private function update_key( string $id ): array {
		$conn = $this->store->get( $id );
		if ( ! $conn ) { return $this->n( 'error', __( 'Connection not found.', 'wp-command-center' ) ); }
		if ( $this->store->credentials()->is_constant_backed( $conn ) ) {
			return $this->n( 'warning', __( 'This key is defined in wp-config.php (a constant) and cannot be changed here.', 'wp-command-center' ) );
		}
		$raw = $this->raw_key();
		if ( '' === $raw ) { return $this->n( 'error', __( 'Please paste an API key, or use Remove key.', 'wp-command-center' ) ); }
		$k = $this->clean_key( $raw );
		if ( is_array( $k ) ) { return $k; }
		$this->store->credentials()->set_secret( $id, $k );
		$this->store->record_test( $id, false, 'untested' );
		$this->store->sync_runtime();
		$this->audit( 'ai.connection.key.updated', [ 'connection' => $id ] );
		return $this->n( 'success', __( 'API key saved. It is stored on this site and never shown again.', 'wp-command-center' ) );
	}

	private function clear_key( string $id ): array {
		$conn = $this->store->get( $id );
		if ( ! $conn ) { return $this->n( 'error', __( 'Connection not found.', 'wp-command-center' ) ); }
		if ( $this->store->credentials()->is_constant_backed( $conn ) ) {
			return $this->n( 'warning', __( 'The active key is a constant in wp-config.php and cannot be removed here.', 'wp-command-center' ) );
		}
		$this->store->credentials()->clear_secret( $id );
		$this->audit( 'ai.connection.key.cleared', [ 'connection' => $id ] );
		return $this->n( 'success', __( 'API key removed.', 'wp-command-center' ) );
	}

	private function set_default( string $id ): array {
		if ( ! $this->store->set_default( $id ) ) {
			return $this->n( 'error', __( 'This connection cannot be the default — WP Command Center cannot use its provider for AI features yet.', 'wp-command-center' ) );
		}
		$this->audit( 'ai.connection.default.set', [ 'connection' => $id ] );
		return $this->n( 'success', __( 'Default connection updated.', 'wp-command-center' ) );
	}

	private function set_enabled( string $id ): array {
		$enabled = ! empty( $_POST['wpcc_enabled'] );
		$this->store->set_enabled( $id, $enabled );
		$this->audit( 'ai.connection.enabled', [ 'connection' => $id, 'enabled' => $enabled ] );
		return $this->n( 'success', $enabled ? __( 'Connection enabled.', 'wp-command-center' ) : __( 'Connection disabled.', 'wp-command-center' ) );
	}

	private function duplicate( string $id ): array {
		$new = $this->store->duplicate( $id );
		if ( '' === $new ) { return $this->n( 'error', __( 'Connection not found.', 'wp-command-center' ) ); }
		$this->audit( 'ai.connection.duplicated', [ 'from' => $id, 'connection' => $new ] );
		return $this->n( 'success', __( 'Connection duplicated (without its key — add a key to the copy).', 'wp-command-center' ) );
	}

	private function delete( string $id ): array {
		$this->store->delete( $id );
		$this->audit( 'ai.connection.deleted', [ 'connection' => $id ] );
		return $this->n( 'success', __( 'Connection deleted.', 'wp-command-center' ) );
	}

	private function test( string $id ): array {
		$conn = $this->store->get( $id );
		if ( ! $conn ) { return $this->n( 'error', __( 'Connection not found.', 'wp-command-center' ) ); }
		if ( ! $this->store->testable( $conn ) ) {
			return $this->n( 'warning', __( 'A connection test is not available for this provider yet.', 'wp-command-center' ) );
		}
		$key    = $this->store->credentials()->secret( $conn );
		$t0     = microtime( true );
		$result = ( new ConnectionTester() )->test( $conn, $key );
		$ms     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$ok     = ! empty( $result['ok'] );
		$code   = sanitize_text_field( (string) ( $result['code'] ?? 'error' ) );
		$this->store->record_test( $id, $ok, $code, [ 'latency_ms' => $ms, 'models' => (int) ( $result['models'] ?? 0 ) ] );
		$this->audit( 'ai.connection.test', [ 'connection' => $id, 'dialect' => $conn['dialect'], 'result' => $code ] );
		if ( $ok ) { return $this->n( 'success', __( 'Connection succeeded.', 'wp-command-center' ) ); }
		$detail = sanitize_text_field( (string) ( $result['message'] ?? '' ) );
		/* translators: %s: secret-free error detail */
		return $this->n( 'error', sprintf( __( 'Connection failed: %s', 'wp-command-center' ), '' !== $detail ? $detail : $code ) );
	}

	private function save_routes(): array {
		$changed = 0;
		foreach ( array_keys( ConnectionStore::FEATURES ) as $f ) {
			$key = 'wpcc_route_' . $f;
			if ( ! isset( $_POST[ $key ] ) ) { continue; }
			$cid = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
			if ( $this->store->set_route( $f, $cid ) ) {
				$changed++;
				$this->audit( 'ai.connection.route.set', [ 'feature' => $f, 'connection' => $cid ] );
			}
		}
		return $this->n( 'success', sprintf(
			/* translators: %d: number of feature routes updated */
			_n( '%d feature route saved.', '%d feature routes saved.', $changed, 'wp-command-center' ),
			$changed
		) );
	}

	/* ---------------- input helpers ---------------- */

	private function raw_key(): string {
		return isset( $_POST['wpcc_key'] ) ? trim( (string) wp_unslash( $_POST['wpcc_key'] ) ) : '';
	}

	/** @return string|array */
	private function clean_key( string $raw ) {
		$key = sanitize_text_field( $raw );
		if ( strlen( $key ) < 8 || ! preg_match( '/^[A-Za-z0-9._\-]+$/', $key ) ) {
			return $this->n( 'error', __( 'That does not look like a valid API key.', 'wp-command-center' ) );
		}
		return $key;
	}

	private function model_value( string $provider ): string {
		$def    = ProviderCatalog::get( $provider ) ?? [];
		$choice = isset( $_POST['wpcc_model'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcc_model'] ) ) : '';
		if ( 'custom' === $choice ) {
			$custom = isset( $_POST['wpcc_model_custom'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wpcc_model_custom'] ) ) ) : '';
			if ( '' !== $custom && strlen( $custom ) <= 100 && preg_match( '/^[A-Za-z0-9._:\-]+$/', $custom ) ) {
				return $custom;
			}
			return '';
		}
		$models = is_array( $def['models'] ?? null ) ? $def['models'] : [];
		if ( '' !== $choice && isset( $models[ $choice ] ) ) {
			return $choice;
		}
		return (string) ( $def['default_model'] ?? '' );
	}

	/** @return string|array endpoint, or error envelope when required+missing/invalid. */
	private function endpoint_value( string $provider ) {
		$def    = ProviderCatalog::get( $provider ) ?? [];
		$dialect = (string) ( $def['dialect'] ?? '' );
		$raw    = isset( $_POST['wpcc_endpoint'] ) ? trim( (string) wp_unslash( $_POST['wpcc_endpoint'] ) ) : '';
		if ( '' === $raw ) {
			// Required for providers that need a custom endpoint (Azure/local/custom).
			if ( ! empty( $def['needs_endpoint'] ) ) {
				return $this->n( 'error', __( 'This provider needs a base URL / endpoint.', 'wp-command-center' ) );
			}
			return ''; // store empty → normalize() fills the default.
		}
		if ( ! Dialect::endpoint_editable( $dialect ) ) {
			return ''; // ignore endpoint edits for fixed-endpoint dialects.
		}
		$url = esc_url_raw( $raw, [ 'http', 'https' ] );
		if ( '' === $url ) {
			return $this->n( 'error', __( 'Enter a valid http(s) base URL.', 'wp-command-center' ) );
		}
		return $url;
	}

	private function str( string $key, int $max ): string {
		$v = isset( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : '';
		return strlen( $v ) > $max ? substr( $v, 0, $max ) : $v;
	}

	/** @return array<int,string> */
	private function tags(): array {
		$raw = isset( $_POST['wpcc_tags'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wpcc_tags'] ) ) : '';
		if ( '' === $raw ) { return []; }
		$tags = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$tags = array_map( static fn ( $t ) => sanitize_key( str_replace( ' ', '-', $t ) ), $tags );
		return array_values( array_unique( array_filter( $tags ) ) );
	}

	private function audit( string $action, array $context ): void {
		( new AuditLog() )->record( $action, $context );
	}

	private function n( string $type, string $message ): array {
		return [ 'type' => $type, 'message' => $message ];
	}
}
