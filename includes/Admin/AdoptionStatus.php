<?php
/**
 * PROGRAM-5A — Adoption readiness status (read-only).
 *
 * A single, side-effect-free snapshot of "is this site set up for a design
 * partner to use safely?" — security mode, AI key/model state, token count, and
 * the AI-surface build flags. It performs NO writes, NO external calls, and NEVER
 * returns or exposes the API key (only a boolean + non-secret source label and a
 * short masked hint derived without revealing the secret).
 *
 * Consumed by the first-run panel (Overview) and the AI Setup view. It adds no
 * routes, operations, capabilities, MCP tools, or schema.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Ai\AnthropicClient;
use WPCommandCenter\Operations\SecurityModeManager;
use WPCommandCenter\Security\AuthTokens;

defined( 'ABSPATH' ) || exit;

final class AdoptionStatus {

	/**
	 * Whether WPCC's outbound AI is configured (a key is present from any source).
	 * Delegates to the single transport; no network call.
	 */
	public static function ai_configured(): bool {
		return ( new AnthropicClient() )->is_configured();
	}

	/**
	 * Non-secret label of where the key came from (constant vs option, canonical vs
	 * legacy) or 'none'. Never the key itself.
	 */
	public static function ai_key_source(): string {
		return ( new AnthropicClient() )->key_source();
	}

	/** True when the active key comes from a PHP constant (UI must treat it read-only). */
	public static function ai_key_is_constant(): bool {
		return in_array( self::ai_key_source(), [ 'anthropic_constant', 'vision_constant' ], true );
	}

	/**
	 * The resolved model name (canonical option/constant → legacy → caller default).
	 * Not a secret. Empty string when nothing is set and no default is supplied.
	 */
	public static function ai_model(): string {
		return ( new AnthropicClient() )->model();
	}

	/** Current security mode slug (developer|client|enterprise). */
	public static function security_mode(): string {
		return SecurityModeManager::current();
	}

	/** Human label for the current security mode. */
	public static function security_mode_label(): string {
		return SecurityModeManager::label();
	}

	/** True when the current mode self-approves writes (developer): risky for client sites. */
	public static function is_self_approving(): bool {
		return ! SecurityModeManager::requires_human_approver();
	}

	/** Number of API tokens (active + inactive) configured for agent access. */
	public static function token_count(): int {
		return count( ( new AuthTokens() )->list() );
	}

	/** Number of currently-active (non-revoked, non-expired) tokens. */
	public static function active_token_count(): int {
		$active = array_filter(
			( new AuthTokens() )->list(),
			static fn ( $t ) => ( $t['status'] ?? '' ) === 'active'
		);
		return count( $active );
	}

	/**
	 * Whether ANY AI-surface build flag is on. Read-only reflection of the flags;
	 * this method never changes them.
	 */
	public static function any_ai_surface_enabled(): bool {
		foreach ( [ 'WPCC_ALT_TEXT_UI', 'WPCC_SEO_META_UI', 'WPCC_AI_CONTENT_UI', 'WPCC_PROPOSALS_DEV_UI' ] as $const ) {
			if ( defined( $const ) && constant( $const ) ) {
				return true;
			}
		}
		foreach ( [ 'wpcc_alt_text_ui', 'wpcc_seo_meta_ui', 'wpcc_ai_content_ui', 'wpcc_proposals_dev_ui' ] as $filter ) {
			if ( (bool) apply_filters( $filter, false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The ordered first-run checklist for the Overview panel. Each step is a plain
	 * data row (no secrets): key, label, done flag, hint, and a target admin URL.
	 *
	 * @return array<int,array{key:string,label:string,done:bool,hint:string,url:string}>
	 */
	public static function checklist(): array {
		$ai_configured = self::ai_configured();
		$tokens        = self::active_token_count();
		$self_approve  = self::is_self_approving();

		return [
			[
				'key'   => 'security_mode',
				'label' => __( 'Choose a safety mode', 'wp-command-center' ),
				'done'  => ! $self_approve, // "done" for a client site = NOT self-approving.
				'hint'  => $self_approve
					? __( 'Currently Developer mode: AI writes apply with no approval. Switch to Client mode before working on a client site.', 'wp-command-center' )
					: __( 'A human-approval mode is active. Writes wait for your review.', 'wp-command-center' ),
				'url'   => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=security' ),
			],
			[
				'key'   => 'ai_key',
				'label' => __( 'Add an AI provider key (optional)', 'wp-command-center' ),
				'done'  => $ai_configured,
				'hint'  => $ai_configured
					? __( 'An Anthropic key is configured. AI features can be used once their surface is enabled.', 'wp-command-center' )
					: __( 'No key yet. AI stays off until you add one — WPCC works without it.', 'wp-command-center' ),
				'url'   => admin_url( 'admin.php?page=wpcc-built-in-ai&wpcc_tab=providers' ),
			],
			[
				'key'   => 'token',
				'label' => __( 'Create an access token for your AI agent', 'wp-command-center' ),
				'done'  => $tokens > 0,
				'hint'  => $tokens > 0
					/* translators: %d: number of active tokens */
					? sprintf( _n( '%d active token.', '%d active tokens.', $tokens, 'wp-command-center' ), $tokens )
					: __( 'No tokens yet. Create one to let Claude or another agent connect over MCP/REST.', 'wp-command-center' ),
				'url'   => admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=access' ),
			],
			[
				'key'   => 'review',
				'label' => __( 'Know where to review & undo changes', 'wp-command-center' ),
				'done'  => false, // informational; never auto-checks.
				'hint'  => __( 'Approvals live under Activity → Approvals. Every change and its undo live under History → Changes.', 'wp-command-center' ),
				'url'   => admin_url( 'admin.php?page=wpcc-history&wpcc_tab=changes' ),
			],
		];
	}

	/** True when setup is incomplete enough to warrant showing the first-run panel. */
	public static function setup_incomplete(): bool {
		// Incomplete if it's still self-approving (unsafe default for a client site)
		// or no token exists yet. AI key is optional and does NOT block completion.
		return self::is_self_approving() || self::active_token_count() === 0;
	}
}
