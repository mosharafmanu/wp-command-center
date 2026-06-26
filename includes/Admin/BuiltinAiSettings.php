<?php
/**
 * Phase 4 — in-admin enablement of the built-in AI tools (SEO · Alt Text · Content).
 *
 * Removes the design-partner blocker where the built-in AI tools could only be turned
 * on by editing wp-config (a PHP constant) or via a programmatic filter. An admin can
 * now enable/disable each tool from the UI, governed and audited.
 *
 * Resolution order (explicit, never silent):
 *   1. A DEFINED constant is site configuration and WINS (on or off) — the UI cannot
 *      override a hard-coded policy. (`enabled_by_config` / `disabled_by_config`)
 *   2. A truthy `wpcc_*_ui` filter is a programmatic opt-in. (`enabled_by_config`)
 *   3. Otherwise the per-tool option governs (default OFF). (`enabled` / `disabled`)
 *
 * It changes NO provider execution, REST, MCP, capability, or runtime behavior — only
 * whether a tool's surface is available. A tool that is enabled but has no AI provider
 * is honestly reported as `requires_provider` (its own screen guides connecting one).
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class BuiltinAiSettings {

	/** Option storing per-tool enablement, e.g. [ 'seo' => true, 'alt_text' => false ]. */
	public const OPTION = 'wpcc_builtin_ai_tools';

	/** Nonce action for the toggle form. */
	public const NONCE = 'wpcc_builtin_ai_toggle';

	/**
	 * The built-in AI tools. `const`/`filter` mirror the existing build flags; the UI
	 * option only applies when neither constant is defined nor filter is truthy.
	 *
	 * @var array<string,array{const:string,filter:string,label:string,tab:string,provider_required:bool}>
	 */
	private const TOOLS = [
		'seo'      => [ 'const' => 'WPCC_SEO_META_UI',   'filter' => 'wpcc_seo_meta_ui',   'label' => 'SEO',      'tab' => 'seo',      'provider_required' => true ],
		'alt_text' => [ 'const' => 'WPCC_ALT_TEXT_UI',   'filter' => 'wpcc_alt_text_ui',   'label' => 'Alt Text', 'tab' => 'alt_text', 'provider_required' => true ],
		'content'  => [ 'const' => 'WPCC_AI_CONTENT_UI', 'filter' => 'wpcc_ai_content_ui', 'label' => 'Content',  'tab' => 'content',  'provider_required' => true ],
	];

	/** @return array<string,array{const:string,filter:string,label:string,tab:string,provider_required:bool}> */
	public static function tools(): array {
		return self::TOOLS;
	}

	public static function label( string $key ): string {
		return self::TOOLS[ $key ]['label'] ?? $key;
	}

	private static function key_for_const( string $const ): ?string {
		foreach ( self::TOOLS as $k => $t ) {
			if ( $t['const'] === $const ) {
				return $k;
			}
		}
		return null;
	}

	/**
	 * True when a defined constant OR a truthy filter controls this tool — i.e. site
	 * configuration is in charge and the UI toggle is locked.
	 */
	public static function is_config_controlled( string $key ): bool {
		if ( ! isset( self::TOOLS[ $key ] ) ) {
			return false;
		}
		$t = self::TOOLS[ $key ];
		return defined( $t['const'] ) || (bool) apply_filters( $t['filter'], false );
	}

	/** The raw per-tool option intent (ignores provider / FeatureGate). */
	public static function option_on( string $key ): bool {
		if ( ! isset( self::TOOLS[ $key ] ) ) {
			return false;
		}
		$opt = get_option( self::OPTION, [] );
		return is_array( $opt ) && ! empty( $opt[ $key ] );
	}

	/**
	 * Consulted by AppShell::flag(): is this constant's tool enabled by the in-admin
	 * option? (flag() handles the constant/filter cases itself.)
	 */
	public static function enabled_by_option( string $const ): bool {
		$key = self::key_for_const( $const );
		return $key ? self::option_on( $key ) : false;
	}

	/**
	 * Whether the tool's surface is "on" at all (constant OR filter OR option) — the
	 * same precedence AppShell::flag() applies.
	 */
	public static function is_on( string $key ): bool {
		if ( ! isset( self::TOOLS[ $key ] ) ) {
			return false;
		}
		$t = self::TOOLS[ $key ];
		if ( defined( $t['const'] ) ) {
			return (bool) constant( $t['const'] );
		}
		if ( (bool) apply_filters( $t['filter'], false ) ) {
			return true;
		}
		return self::option_on( $key );
	}

	/**
	 * Rich, honest status for the UI.
	 *
	 * @return string enabled_by_config|disabled_by_config|enabled|requires_provider|disabled
	 */
	public static function status( string $key ): string {
		if ( ! isset( self::TOOLS[ $key ] ) ) {
			return 'disabled';
		}
		$t = self::TOOLS[ $key ];
		if ( defined( $t['const'] ) ) {
			return constant( $t['const'] ) ? 'enabled_by_config' : 'disabled_by_config';
		}
		if ( (bool) apply_filters( $t['filter'], false ) ) {
			return 'enabled_by_config';
		}
		if ( ! self::option_on( $key ) ) {
			return 'disabled';
		}
		if ( $t['provider_required'] && ! AdoptionStatus::ai_configured() ) {
			return 'requires_provider';
		}
		return 'enabled';
	}

	/**
	 * Persist a tool's enablement option. No-op (returns false) when config-controlled
	 * or unchanged. Caller audits. Does NOT enable provider execution or fake support.
	 */
	public static function set( string $key, bool $on ): bool {
		if ( ! isset( self::TOOLS[ $key ] ) || self::is_config_controlled( $key ) ) {
			return false;
		}
		$opt = get_option( self::OPTION, [] );
		if ( ! is_array( $opt ) ) {
			$opt = [];
		}
		if ( ! empty( $opt[ $key ] ) === $on ) {
			return false;
		}
		$opt[ $key ] = $on;
		update_option( self::OPTION, $opt );
		return true;
	}

	/**
	 * Process a governed toggle POST (nonce + capability + audit). Returns a notice
	 * array `{type,message}` for display, or null when this is not a toggle request.
	 * Never renders or logs a secret.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function handle_post(): ?array {
		if ( ! isset( $_POST['wpcc_builtin_ai_tool'], $_POST['wpcc_builtin_ai_state'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			return null;
		}
		$key = sanitize_key( wp_unslash( $_POST['wpcc_builtin_ai_tool'] ) );
		$on  = '1' === (string) wp_unslash( $_POST['wpcc_builtin_ai_state'] );
		if ( ! isset( self::TOOLS[ $key ] ) ) {
			return null;
		}
		$label = self::label( $key );
		if ( self::is_config_controlled( $key ) ) {
			return [
				'type'    => 'warning',
				/* translators: %s: tool name */
				'message' => sprintf( __( '%s is controlled by your site configuration and can’t be changed here.', 'wp-command-center' ), $label ),
			];
		}
		if ( self::set( $key, $on ) ) {
			( new AuditLog() )->record(
				'builtin_ai.tool_' . ( $on ? 'enabled' : 'disabled' ),
				[ 'tool' => $key, 'actor' => wp_get_current_user()->user_login ]
			);
		}
		if ( ! $on ) {
			/* translators: %s: tool name */
			return [ 'type' => 'success', 'message' => sprintf( __( '%s is now turned off.', 'wp-command-center' ), $label ) ];
		}
		if ( ! AdoptionStatus::ai_configured() ) {
			/* translators: %s: tool name */
			return [ 'type' => 'success', 'message' => sprintf( __( '%s is on. Connect an AI provider to start generating.', 'wp-command-center' ), $label ) ];
		}
		/* translators: %s: tool name */
		return [ 'type' => 'success', 'message' => sprintf( __( '%s is on and ready.', 'wp-command-center' ), $label ) ];
	}
}
