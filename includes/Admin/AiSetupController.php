<?php
/**
 * PROGRAM-5A — AI Setup controller (provider key + model + connection test).
 *
 * Handles the same-page admin POST for the AI Setup view, mirroring the existing
 * `settings.php` / `ai-integrations.php` pattern (no new or changed REST routes).
 * It writes ONLY the two options the single AnthropicClient transport already
 * reads — `wpcc_anthropic_api_key` and `wpcc_anthropic_model` — plus a non-secret
 * last-test status. It adds no operations, capabilities, MCP tools, or schema.
 *
 * Security contract:
 *   - every action requires a valid `wpcc_ai_setup` nonce AND `manage_options`;
 *   - the API key is sanitized, stored as a WordPress option (the EXISTING storage
 *     the transport reads — see AnthropicClient key resolution), and is NEVER echoed
 *     back, returned, or logged; the UI shows only a boolean "configured" state;
 *   - every key add/update/clear/test emits an AuditLog event WITHOUT the secret;
 *   - the connection test makes one minimal, non-mutating request (max_tokens=1)
 *     via the shared transport, which returns errors as data and short-circuits
 *     with no network call when no key is configured.
 *
 * Known limitation (documented): the key is stored as a plaintext WordPress option
 * — the same at-rest model the transport already used before this UI existed and
 * the standard WordPress option pattern. This UI does NOT weaken it; it adds
 * masking + no-echo on top. Encrypted-at-rest secret storage is a separate,
 * schema-bearing decision and is intentionally out of scope for adoption readiness.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Ai\AnthropicClient;
use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class AiSetupController {

	public const OPTION_KEY        = 'wpcc_anthropic_api_key';
	public const OPTION_MODEL      = 'wpcc_anthropic_model';
	public const OPTION_LAST_TEST  = 'wpcc_anthropic_last_test';
	public const NONCE_ACTION      = 'wpcc_ai_setup';
	public const DEFAULT_MODEL     = 'claude-sonnet-4-6';

	/** Project model presets (canonical names from the providers). 'custom' = free text. */
	public const MODEL_PRESETS = [
		'claude-sonnet-4-6'          => 'Claude Sonnet 4.6 (recommended — balanced)',
		'claude-opus-4-8'            => 'Claude Opus 4.8 (highest capability)',
		'claude-haiku-4-5-20251001'  => 'Claude Haiku 4.5 (fastest / lowest cost)',
	];

	/**
	 * Inspect $_POST and, if this is an AI-Setup submission, authorize and dispatch
	 * it. Returns a notice array { type: success|error|warning, message } or null
	 * when there is nothing to do. Never returns or includes the API key.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public function handle_post(): ?array {
		if ( ! isset( $_POST['wpcc_ai_setup_action'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return [ 'type' => 'error', 'message' => __( 'You do not have permission to change AI settings.', 'wp-command-center' ) ];
		}
		if ( ! check_admin_referer( self::NONCE_ACTION ) ) {
			// check_admin_referer already wp_die()s on hard failure; this is belt-and-braces.
			return [ 'type' => 'error', 'message' => __( 'Security check failed. Please try again.', 'wp-command-center' ) ];
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['wpcc_ai_setup_action'] ) );

		switch ( $action ) {
			case 'save_key':
				return $this->save_key();
			case 'clear_key':
				return $this->clear_key();
			case 'save_model':
				return $this->save_model();
			case 'test_connection':
				return $this->test_connection();
			default:
				return [ 'type' => 'error', 'message' => __( 'Unknown action.', 'wp-command-center' ) ];
		}
	}

	/** Save/update the Anthropic key. Refuses to overwrite a constant-provided key. */
	private function save_key(): array {
		if ( AdoptionStatus::ai_key_is_constant() ) {
			return [ 'type' => 'warning', 'message' => __( 'A key is defined in wp-config.php (constant). Remove that constant to manage the key here.', 'wp-command-center' ) ];
		}

		// Raw value only used transiently to store; never echoed or logged.
		$raw = isset( $_POST['wpcc_api_key'] ) ? trim( (string) wp_unslash( $_POST['wpcc_api_key'] ) ) : '';
		$key = sanitize_text_field( $raw );

		if ( '' === $key ) {
			return [ 'type' => 'error', 'message' => __( 'Please paste an API key, or use Remove key to clear it.', 'wp-command-center' ) ];
		}
		// Light structural sanity only — do not validate against the provider here
		// (that is the Test connection job). Keys are ASCII tokens.
		if ( strlen( $key ) < 12 || ! preg_match( '/^[A-Za-z0-9._\-]+$/', $key ) ) {
			return [ 'type' => 'error', 'message' => __( 'That does not look like a valid API key. Keys contain only letters, numbers, dashes, dots and underscores.', 'wp-command-center' ) ];
		}

		update_option( self::OPTION_KEY, $key, false ); // autoload=no for a secret.
		// A new key invalidates any prior test result.
		delete_option( self::OPTION_LAST_TEST );

		$this->audit( 'ai.provider.key.updated', [ 'provider' => 'anthropic' ] );

		return [ 'type' => 'success', 'message' => __( 'API key saved. It is stored on this site and never shown again. Use Test connection to verify it.', 'wp-command-center' ) ];
	}

	/** Remove the option-stored key (no effect on a constant-defined key). */
	private function clear_key(): array {
		if ( AdoptionStatus::ai_key_is_constant() ) {
			return [ 'type' => 'warning', 'message' => __( 'The active key is defined as a constant in wp-config.php and cannot be removed from here.', 'wp-command-center' ) ];
		}
		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_LAST_TEST );
		$this->audit( 'ai.provider.key.cleared', [ 'provider' => 'anthropic' ] );
		return [ 'type' => 'success', 'message' => __( 'API key removed. AI features are off until a new key is added.', 'wp-command-center' ) ];
	}

	/** Persist the chosen model (preset or validated custom). Never needs a key. */
	private function save_model(): array {
		$choice = isset( $_POST['wpcc_model_choice'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcc_model_choice'] ) ) : '';

		if ( 'custom' === $choice ) {
			$custom = isset( $_POST['wpcc_model_custom'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcc_model_custom'] ) ) : '';
			$custom = trim( $custom );
			if ( '' === $custom || strlen( $custom ) > 100 || ! preg_match( '/^[A-Za-z0-9._\-]+$/', $custom ) ) {
				return [ 'type' => 'error', 'message' => __( 'Enter a valid model id (letters, numbers, dots, dashes, underscores; up to 100 chars).', 'wp-command-center' ) ];
			}
			$model = $custom;
		} elseif ( isset( self::MODEL_PRESETS[ $choice ] ) ) {
			$model = $choice;
		} else {
			return [ 'type' => 'error', 'message' => __( 'Please choose a model.', 'wp-command-center' ) ];
		}

		update_option( self::OPTION_MODEL, $model, false );
		$this->audit( 'ai.provider.model.updated', [ 'provider' => 'anthropic', 'model' => $model ] );

		/* translators: %s: model id */
		return [ 'type' => 'success', 'message' => sprintf( __( 'Model set to %s.', 'wp-command-center' ), $model ) ];
	}

	/**
	 * Make a single minimal request to verify the key works. Non-mutating: one
	 * user message, max_tokens=1, short timeout. No proposal, no operation, no
	 * site write. Stores a non-secret status + time. Handles missing/invalid key,
	 * timeout, offline, and provider error — all returned as data by the transport.
	 */
	private function test_connection(): array {
		$client = new AnthropicClient();

		if ( ! $client->is_configured() ) {
			$this->store_test( false, 'not_configured' );
			$this->audit( 'ai.provider.test', [ 'provider' => 'anthropic', 'result' => 'not_configured' ] );
			return [ 'type' => 'error', 'message' => __( 'No API key configured. Add a key first, then test.', 'wp-command-center' ) ];
		}

		$model  = $client->model( self::DEFAULT_MODEL );
		$result = $client->send(
			[ [ 'role' => 'user', 'content' => 'ping' ] ],
			1,
			$model,
			[ 'timeout' => 10 ]
		);

		$ok   = ! empty( $result['ok'] );
		$code = $ok ? 'ok' : sanitize_text_field( (string) ( $result['code'] ?? 'error' ) );
		$this->store_test( $ok, $code );
		$this->audit( 'ai.provider.test', [ 'provider' => 'anthropic', 'result' => $code, 'model' => $model ] );

		if ( $ok ) {
			return [ 'type' => 'success', 'message' => __( 'Connection succeeded. Your AI provider key is working.', 'wp-command-center' ) ];
		}

		// $result['message'] is already redacted by the transport; safe to surface.
		$detail = sanitize_text_field( (string) ( $result['message'] ?? '' ) );
		/* translators: %s: short, secret-free error detail */
		return [ 'type' => 'error', 'message' => sprintf( __( 'Connection failed: %s', 'wp-command-center' ), $detail !== '' ? $detail : $code ) ];
	}

	/** Persist a non-secret test result for display. */
	private function store_test( bool $ok, string $code ): void {
		update_option( self::OPTION_LAST_TEST, [
			'ok'   => $ok,
			'code' => $code,
			'time' => time(),
		], false );
	}

	/**
	 * The last connection-test result for display (non-secret), or null.
	 *
	 * @return array{ok:bool,code:string,time:int}|null
	 */
	public static function last_test(): ?array {
		$v = get_option( self::OPTION_LAST_TEST, null );
		if ( ! is_array( $v ) || ! isset( $v['ok'], $v['time'] ) ) {
			return null;
		}
		return [ 'ok' => (bool) $v['ok'], 'code' => (string) ( $v['code'] ?? '' ), 'time' => (int) $v['time'] ];
	}

	/** Record an audit event. Context is asserted secret-free by callers. */
	private function audit( string $action, array $context ): void {
		( new AuditLog() )->record( $action, $context );
	}
}
