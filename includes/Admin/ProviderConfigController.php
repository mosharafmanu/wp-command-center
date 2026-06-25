<?php
/**
 * PROGRAM-6 — provider configuration controller (same-page admin POST).
 *
 * Drives the multi-provider AI Setup UI through ProviderStore. Mirrors the 5A
 * same-page POST pattern (no new/changed REST routes). Reuses the existing
 * `wpcc_ai_setup` nonce string for continuity with Program-5A's view.
 *
 * Security contract (every action):
 *   - valid nonce AND `manage_options`;
 *   - inputs sanitized + validated; provider type validated against the catalogue;
 *   - the API key is stored via ProviderStore and NEVER echoed, returned, or logged;
 *   - every add/update/clear/delete/default/enable/test/map emits a secret-free
 *     AuditLog event;
 *   - a default/feature provider can only be a runtime-usable type (honest routing).
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class ProviderConfigController {

	public const NONCE = 'wpcc_ai_setup'; // same string as AiSetupController::NONCE_ACTION.

	private ProviderStore $store;

	public function __construct() {
		$this->store = new ProviderStore();
	}

	/** @return array{type:string,message:string}|null */
	public function handle_post(): ?array {
		if ( ! isset( $_POST['wpcc_provider_action'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->err( __( 'You do not have permission to change AI settings.', 'wp-command-center' ) );
		}
		if ( ! check_admin_referer( self::NONCE ) ) {
			return $this->err( __( 'Security check failed. Please try again.', 'wp-command-center' ) );
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['wpcc_provider_action'] ) );
		$type   = isset( $_POST['wpcc_provider_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['wpcc_provider_type'] ) ) : '';

		// Every action except feature-map needs a valid provider type.
		if ( 'save_feature_map' !== $action && ! ProviderCatalog::is_valid_type( $type ) ) {
			return $this->err( __( 'Unknown provider.', 'wp-command-center' ) );
		}

		switch ( $action ) {
			case 'save_provider':   return $this->save_provider( $type );
			case 'update_key':      return $this->update_key( $type );
			case 'clear_key':       return $this->clear_key( $type );
			case 'save_model':      return $this->save_model( $type );
			case 'delete_provider': return $this->delete_provider( $type );
			case 'set_default':     return $this->set_default( $type );
			case 'set_enabled':     return $this->set_enabled( $type );
			case 'test_connection': return $this->test_connection( $type );
			case 'save_feature_map':return $this->save_feature_map();
			default:                return $this->err( __( 'Unknown action.', 'wp-command-center' ) );
		}
	}

	/** Add or update a provider: name + model (+ optional key on first add). */
	private function save_provider( string $type ): array {
		$name  = $this->clean_name( $type );
		$model = $this->clean_model( $type );
		if ( is_array( $model ) ) { // error envelope
			return $model;
		}

		$this->store->save_record( $type, $name, $model );

		// Optional key on the add form (skip for constant-provided Anthropic key).
		$raw_key = isset( $_POST['wpcc_provider_key'] ) ? trim( (string) wp_unslash( $_POST['wpcc_provider_key'] ) ) : '';
		if ( '' !== $raw_key && ! $this->store->is_constant_secret( $type ) ) {
			$key = $this->clean_key( $raw_key );
			if ( is_array( $key ) ) {
				return $key;
			}
			$this->store->set_secret( $type, $key );
			$this->audit( 'ai.provider.key.updated', [ 'provider' => $type ] );
		}

		// Auto-set default to the first runtime-usable configured provider.
		if ( ProviderCatalog::runtime_usable( $type ) && '' === $this->store->default_type() && $this->store->has_secret( $type ) ) {
			$this->store->set_default( $type );
		}

		$this->audit( 'ai.provider.added', [ 'provider' => $type ] );
		return $this->ok( sprintf(
			/* translators: %s: provider label */
			__( '%s saved.', 'wp-command-center' ),
			$this->label( $type )
		) );
	}

	private function update_key( string $type ): array {
		if ( $this->store->is_constant_secret( $type ) ) {
			return $this->warn( __( 'This key is defined in wp-config.php (a constant) and cannot be changed here.', 'wp-command-center' ) );
		}
		$raw = isset( $_POST['wpcc_provider_key'] ) ? trim( (string) wp_unslash( $_POST['wpcc_provider_key'] ) ) : '';
		if ( '' === $raw ) {
			return $this->err( __( 'Please paste an API key, or use Remove key to clear it.', 'wp-command-center' ) );
		}
		$key = $this->clean_key( $raw );
		if ( is_array( $key ) ) {
			return $key;
		}
		$this->store->set_secret( $type, $key );
		$this->store->record_test( $type, false, 'untested' );
		$this->audit( 'ai.provider.key.updated', [ 'provider' => $type ] );
		return $this->ok( __( 'API key saved. It is stored on this site and never shown again. Use Test connection to verify it.', 'wp-command-center' ) );
	}

	private function clear_key( string $type ): array {
		if ( $this->store->is_constant_secret( $type ) ) {
			return $this->warn( __( 'The active key is defined as a constant in wp-config.php and cannot be removed here.', 'wp-command-center' ) );
		}
		$this->store->clear_secret( $type );
		$this->audit( 'ai.provider.key.cleared', [ 'provider' => $type ] );
		return $this->ok( __( 'API key removed.', 'wp-command-center' ) );
	}

	private function save_model( string $type ): array {
		$model = $this->clean_model( $type );
		if ( is_array( $model ) ) {
			return $model;
		}
		$name = $this->store->get( $type )['name'] ?? $this->label( $type );
		$this->store->save_record( $type, (string) $name, $model );
		$this->audit( 'ai.provider.model.updated', [ 'provider' => $type, 'model' => $model ] );
		/* translators: %s: model id */
		return $this->ok( sprintf( __( 'Model set to %s.', 'wp-command-center' ), $model ) );
	}

	private function delete_provider( string $type ): array {
		$this->store->delete( $type );
		$this->audit( 'ai.provider.deleted', [ 'provider' => $type ] );
		return $this->ok( sprintf(
			/* translators: %s: provider label */
			__( '%s removed.', 'wp-command-center' ),
			$this->label( $type )
		) );
	}

	private function set_default( string $type ): array {
		if ( ! ProviderCatalog::runtime_usable( $type ) ) {
			return $this->err( __( 'This provider cannot be the default yet — WP Command Center cannot use it for AI features.', 'wp-command-center' ) );
		}
		if ( ! $this->store->has_secret( $type ) ) {
			return $this->err( __( 'Add a key for this provider before making it the default.', 'wp-command-center' ) );
		}
		$this->store->set_default( $type );
		$this->audit( 'ai.provider.default.set', [ 'provider' => $type ] );
		return $this->ok( sprintf(
			/* translators: %s: provider label */
			__( '%s is now the default provider.', 'wp-command-center' ),
			$this->label( $type )
		) );
	}

	private function set_enabled( string $type ): array {
		$enabled = ! empty( $_POST['wpcc_provider_enabled'] );
		$this->store->set_enabled( $type, $enabled );
		$this->audit( 'ai.provider.enabled', [ 'provider' => $type, 'enabled' => $enabled ] );
		return $this->ok( $enabled ? __( 'Provider enabled.', 'wp-command-center' ) : __( 'Provider disabled.', 'wp-command-center' ) );
	}

	private function test_connection( string $type ): array {
		if ( ! ProviderCatalog::test_supported( $type ) ) {
			return $this->warn( __( 'A connection test is not available for this provider yet. Your settings are saved.', 'wp-command-center' ) );
		}
		if ( ! $this->store->has_secret( $type ) ) {
			$this->store->record_test( $type, false, 'not_configured' );
			$this->audit( 'ai.provider.test', [ 'provider' => $type, 'result' => 'not_configured' ] );
			return $this->err( __( 'No API key configured. Add a key first, then test.', 'wp-command-center' ) );
		}
		$model  = (string) ( $this->store->get( $type )['model'] ?? '' );
		$result = ( new ProviderConnectionTester() )->test( $type, $this->store->secret( $type ), $model );
		$ok     = ! empty( $result['ok'] );
		$code   = sanitize_text_field( (string) ( $result['code'] ?? 'error' ) );
		$this->store->record_test( $type, $ok, $code );
		$this->audit( 'ai.provider.test', [ 'provider' => $type, 'result' => $code, 'model' => $model ] );
		if ( $ok ) {
			return $this->ok( __( 'Connection succeeded. This provider key is working.', 'wp-command-center' ) );
		}
		$detail = sanitize_text_field( (string) ( $result['message'] ?? '' ) );
		/* translators: %s: short, secret-free error detail */
		return $this->err( sprintf( __( 'Connection failed: %s', 'wp-command-center' ), '' !== $detail ? $detail : $code ) );
	}

	private function save_feature_map(): array {
		$changed = 0;
		foreach ( array_keys( ProviderStore::FEATURES ) as $feature ) {
			$key = 'wpcc_feature_' . $feature;
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$type = sanitize_key( wp_unslash( (string) $_POST[ $key ] ) );
			if ( ProviderCatalog::runtime_usable( $type ) && $this->store->set_feature( $feature, $type ) ) {
				$changed++;
				$this->audit( 'ai.provider.feature.mapped', [ 'feature' => $feature, 'provider' => $type ] );
			}
		}
		return $this->ok( sprintf(
			/* translators: %d: number of features updated */
			_n( '%d feature mapping saved.', '%d feature mappings saved.', $changed, 'wp-command-center' ),
			$changed
		) );
	}

	/* ---------------- helpers ---------------- */

	/** @return string|array sanitized model id, or an error envelope. */
	private function clean_model( string $type ) {
		$def    = ProviderCatalog::type( $type ) ?? [];
		$choice = isset( $_POST['wpcc_provider_model'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcc_provider_model'] ) ) : '';
		if ( 'custom' === $choice ) {
			if ( empty( $def['allow_custom_model'] ) ) {
				return $this->err( __( 'This provider does not allow a custom model id.', 'wp-command-center' ) );
			}
			$custom = isset( $_POST['wpcc_provider_model_custom'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wpcc_provider_model_custom'] ) ) ) : '';
			if ( '' === $custom || strlen( $custom ) > 100 || ! preg_match( '/^[A-Za-z0-9._\-]+$/', $custom ) ) {
				return $this->err( __( 'Enter a valid model id (letters, numbers, dots, dashes, underscores; up to 100 chars).', 'wp-command-center' ) );
			}
			return $custom;
		}
		$models = is_array( $def['models'] ?? null ) ? $def['models'] : [];
		if ( '' !== $choice && isset( $models[ $choice ] ) ) {
			return $choice;
		}
		// No explicit choice: keep the existing or the catalogue default.
		$existing = (string) ( $this->store->get( $type )['model'] ?? '' );
		return '' !== $existing ? $existing : (string) ( $def['default_model'] ?? '' );
	}

	/** @return string|array sanitized key, or an error envelope. */
	private function clean_key( string $raw ) {
		$key = sanitize_text_field( $raw );
		if ( strlen( $key ) < 12 || ! preg_match( '/^[A-Za-z0-9._\-]+$/', $key ) ) {
			return $this->err( __( 'That does not look like a valid API key. Keys contain only letters, numbers, dashes, dots and underscores.', 'wp-command-center' ) );
		}
		return $key;
	}

	private function clean_name( string $type ): string {
		$raw = isset( $_POST['wpcc_provider_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcc_provider_name'] ) ) : '';
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 60 ) {
			return $this->label( $type );
		}
		return $raw;
	}

	private function label( string $type ): string {
		$def = ProviderCatalog::type( $type );
		return $def ? (string) $def['label'] : $type;
	}

	private function audit( string $action, array $context ): void {
		( new AuditLog() )->record( $action, $context );
	}

	private function ok( string $m ): array {
		return [ 'type' => 'success', 'message' => $m ];
	}
	private function err( string $m ): array {
		return [ 'type' => 'error', 'message' => $m ];
	}
	private function warn( string $m ): array {
		return [ 'type' => 'warning', 'message' => $m ];
	}
}
