<?php
/**
 * PROGRAM-6 — AI provider configuration store (options-backed, no schema).
 *
 * Holds the configured provider RECORDS (one per provider type), the default
 * provider, and the feature→provider map. Secrets are kept out of the record and
 * never echoed. Backward compatibility is the spine:
 *
 *   - The Anthropic provider's secret IS the existing `wpcc_anthropic_api_key`
 *     option (constant `WPCC_ANTHROPIC_API_KEY` still wins), so `AnthropicClient`
 *     and every generator keep working UNCHANGED.
 *   - Other providers' secrets live in a separate, non-autoloaded
 *     `wpcc_ai_provider_secrets` map, used ONLY for connection testing — WPCC's
 *     runtime does not call them yet (honest "Stored — not used by runtime yet").
 *
 * No DB schema, no DB_VERSION, no autoloaded secrets, no key in any record.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Ai\AnthropicClient;

defined( 'ABSPATH' ) || exit;

final class ProviderStore {

	public const OPT_RECORDS    = 'wpcc_ai_providers';          // type => record (no secret)
	public const OPT_SECRETS    = 'wpcc_ai_provider_secrets';   // type => key (non-anthropic only)
	public const OPT_DEFAULT     = 'wpcc_ai_default_provider';   // type
	public const OPT_FEATURE_MAP = 'wpcc_ai_feature_map';        // feature => type
	public const ANTHROPIC_KEY_OPTION = 'wpcc_anthropic_api_key';
	public const ANTHROPIC_MODEL_OPTION = 'wpcc_anthropic_model';

	/** Features whose provider can (eventually) be chosen. */
	public const FEATURES = [
		'seo_meta'   => 'SEO meta',
		'alt_text'   => 'Alt text',
		'ai_content' => 'AI content',
	];

	/* ---------------- records ---------------- */

	/** @return array<string,array<string,mixed>> type => record (only configured types). */
	public function records(): array {
		$raw = get_option( self::OPT_RECORDS, [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$out = [];
		foreach ( $raw as $type => $rec ) {
			if ( ProviderCatalog::is_valid_type( (string) $type ) && is_array( $rec ) ) {
				$out[ $type ] = $this->normalize( (string) $type, $rec );
			}
		}
		// The Anthropic provider is implicitly configured if its legacy key/constant exists,
		// even without an explicit record (backward compatibility with pre-6 installs).
		if ( ! isset( $out['anthropic'] ) && $this->has_secret( 'anthropic' ) ) {
			$out['anthropic'] = $this->normalize( 'anthropic', [] );
		}
		return $out;
	}

	public function get( string $type ): ?array {
		$all = $this->records();
		return $all[ $type ] ?? null;
	}

	public function exists( string $type ): bool {
		return null !== $this->get( $type );
	}

	/** A record with defaults filled in (never contains a secret). */
	private function normalize( string $type, array $rec ): array {
		$def = ProviderCatalog::type( $type ) ?? [];
		return [
			'type'      => $type,
			'name'      => isset( $rec['name'] ) && '' !== (string) $rec['name'] ? (string) $rec['name'] : (string) ( $def['label'] ?? $type ),
			'model'     => isset( $rec['model'] ) ? (string) $rec['model'] : ( 'anthropic' === $type ? (string) get_option( self::ANTHROPIC_MODEL_OPTION, '' ) : (string) ( $def['default_model'] ?? '' ) ),
			'enabled'   => isset( $rec['enabled'] ) ? (bool) $rec['enabled'] : true,
			'last_test' => ( isset( $rec['last_test'] ) && is_array( $rec['last_test'] ) ) ? $rec['last_test'] : null,
		];
	}

	/** Create/update a provider record (name + model only; never a secret). */
	public function save_record( string $type, string $name, string $model ): void {
		if ( ! ProviderCatalog::is_valid_type( $type ) ) {
			return;
		}
		$raw = get_option( self::OPT_RECORDS, [] );
		$raw = is_array( $raw ) ? $raw : [];
		$existing = isset( $raw[ $type ] ) && is_array( $raw[ $type ] ) ? $raw[ $type ] : [];
		$existing['name']  = $name;
		$existing['model'] = $model;
		if ( ! isset( $existing['enabled'] ) ) {
			$existing['enabled'] = true;
		}
		$raw[ $type ] = $existing;
		update_option( self::OPT_RECORDS, $raw, false );

		// Anthropic model mirrors the legacy option so the runtime sees the choice.
		if ( 'anthropic' === $type ) {
			update_option( self::ANTHROPIC_MODEL_OPTION, $model, false );
		}
	}

	public function set_enabled( string $type, bool $enabled ): void {
		$raw = get_option( self::OPT_RECORDS, [] );
		$raw = is_array( $raw ) ? $raw : [];
		if ( ! isset( $raw[ $type ] ) ) {
			$raw[ $type ] = [];
		}
		$raw[ $type ]['enabled'] = $enabled;
		update_option( self::OPT_RECORDS, $raw, false );
		if ( ! $enabled && $this->default_type() === $type ) {
			$this->recompute_default_after_removal( $type );
		}
	}

	public function delete( string $type ): void {
		$raw = get_option( self::OPT_RECORDS, [] );
		$raw = is_array( $raw ) ? $raw : [];
		unset( $raw[ $type ] );
		update_option( self::OPT_RECORDS, $raw, false );
		$this->clear_secret( $type );
		$this->recompute_default_after_removal( $type );
		// Unmap any feature pointing at the removed provider.
		$map = $this->feature_map_raw();
		foreach ( $map as $feature => $t ) {
			if ( $t === $type ) {
				unset( $map[ $feature ] );
			}
		}
		update_option( self::OPT_FEATURE_MAP, $map, false );
	}

	/* ---------------- secrets ---------------- */

	/** True when a usable key exists for the type (constant or stored). Never returns the key. */
	public function has_secret( string $type ): bool {
		if ( 'anthropic' === $type ) {
			return ( new AnthropicClient() )->is_configured();
		}
		$secrets = $this->secrets_raw();
		return isset( $secrets[ $type ] ) && '' !== (string) $secrets[ $type ];
	}

	/** True when the active key is provided by a PHP constant (read-only in UI). */
	public function is_constant_secret( string $type ): bool {
		if ( 'anthropic' === $type ) {
			return in_array( ( new AnthropicClient() )->key_source(), [ 'anthropic_constant', 'vision_constant' ], true );
		}
		return false; // only Anthropic has a constant path.
	}

	/**
	 * The raw secret for a NON-anthropic provider (used only by the connection
	 * tester). For Anthropic this returns '' — the tester calls AnthropicClient
	 * directly, which reads its own key, so the Anthropic key is never extracted.
	 */
	public function secret( string $type ): string {
		if ( 'anthropic' === $type ) {
			return '';
		}
		$secrets = $this->secrets_raw();
		return (string) ( $secrets[ $type ] ?? '' );
	}

	public function set_secret( string $type, string $key ): void {
		if ( 'anthropic' === $type ) {
			update_option( self::ANTHROPIC_KEY_OPTION, $key, false ); // backward-compat target.
			return;
		}
		$secrets = $this->secrets_raw();
		$secrets[ $type ] = $key;
		update_option( self::OPT_SECRETS, $secrets, false );
	}

	public function clear_secret( string $type ): void {
		if ( 'anthropic' === $type ) {
			if ( ! $this->is_constant_secret( 'anthropic' ) ) {
				delete_option( self::ANTHROPIC_KEY_OPTION );
			}
			return;
		}
		$secrets = $this->secrets_raw();
		unset( $secrets[ $type ] );
		update_option( self::OPT_SECRETS, $secrets, false );
	}

	private function secrets_raw(): array {
		$v = get_option( self::OPT_SECRETS, [] );
		return is_array( $v ) ? $v : [];
	}

	/* ---------------- default provider ---------------- */

	/** Resolved default provider type (must be configured + enabled + runtime-usable). */
	public function default_type(): string {
		$stored = (string) get_option( self::OPT_DEFAULT, '' );
		$records = $this->records();
		if ( '' !== $stored && isset( $records[ $stored ] ) && $records[ $stored ]['enabled'] && ProviderCatalog::runtime_usable( $stored ) ) {
			return $stored;
		}
		// Fall back to the runtime-usable, configured, enabled provider (Anthropic today).
		foreach ( $records as $type => $rec ) {
			if ( $rec['enabled'] && ProviderCatalog::runtime_usable( $type ) && $this->has_secret( $type ) ) {
				return $type;
			}
		}
		return '';
	}

	/** Set the default provider; only a runtime-usable type may be the default. */
	public function set_default( string $type ): bool {
		if ( ! ProviderCatalog::runtime_usable( $type ) ) {
			return false; // honesty: cannot default to a provider the runtime can't call.
		}
		update_option( self::OPT_DEFAULT, $type, false );
		return true;
	}

	private function recompute_default_after_removal( string $removed ): void {
		if ( (string) get_option( self::OPT_DEFAULT, '' ) === $removed ) {
			delete_option( self::OPT_DEFAULT ); // default_type() will fall back safely.
		}
	}

	/* ---------------- feature map ---------------- */

	private function feature_map_raw(): array {
		$v = get_option( self::OPT_FEATURE_MAP, [] );
		return is_array( $v ) ? $v : [];
	}

	/** Resolved feature → provider type. Unmapped/invalid features fall back to the default. */
	public function feature_map(): array {
		$raw  = $this->feature_map_raw();
		$def  = $this->default_type();
		$out  = [];
		foreach ( array_keys( self::FEATURES ) as $feature ) {
			$t = isset( $raw[ $feature ] ) ? (string) $raw[ $feature ] : '';
			// Only honor a mapping to a runtime-usable, configured provider.
			$out[ $feature ] = ( '' !== $t && ProviderCatalog::runtime_usable( $t ) && $this->has_secret( $t ) ) ? $t : $def;
		}
		return $out;
	}

	/** Map a feature to a provider; only a runtime-usable type is accepted. */
	public function set_feature( string $feature, string $type ): bool {
		if ( ! isset( self::FEATURES[ $feature ] ) || ! ProviderCatalog::runtime_usable( $type ) ) {
			return false;
		}
		$raw            = $this->feature_map_raw();
		$raw[ $feature ] = $type;
		update_option( self::OPT_FEATURE_MAP, $raw, false );
		return true;
	}

	/* ---------------- test result ---------------- */

	public function record_test( string $type, bool $ok, string $code ): void {
		$raw = get_option( self::OPT_RECORDS, [] );
		$raw = is_array( $raw ) ? $raw : [];
		if ( ! isset( $raw[ $type ] ) ) {
			$raw[ $type ] = [];
		}
		$raw[ $type ]['last_test'] = [ 'ok' => $ok, 'code' => $code, 'time' => time() ];
		update_option( self::OPT_RECORDS, $raw, false );
	}
}
