<?php
/**
 * Step 80 — Security Mode Manager.
 *
 * Three first-class product modes replace the binary wpcc_enforce_approval
 * flag with a named, commercially understandable concept.
 *
 * | Mode       | Approval gate                              | Target                          |
 * |------------|--------------------------------------------|---------------------------------|
 * | developer  | OFF — all ops execute immediately          | Developers, agencies, staging   |
 * | client     | ON — medium/high/critical ops gated        | Freelance clients, small biz    |
 * | enterprise | ON — all non-diagnostic ops gated          | Teams, compliance environments  |
 *
 * Risk tiers (from lowest to highest):
 *   diagnostic → low → medium → high → critical
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SecurityModeManager {

	const MODE_DEVELOPER  = 'developer';
	const MODE_CLIENT     = 'client';
	const MODE_ENTERPRISE = 'enterprise';
	const MODES           = [ self::MODE_DEVELOPER, self::MODE_CLIENT, self::MODE_ENTERPRISE ];

	/**
	 * The fail-safe mode. This is what a site runs as when `wpcc_security_mode` is
	 * missing, empty, or corrupt — so it MUST be a protected mode, never developer.
	 * A missing option must never silently downgrade a public site into
	 * execute-without-approval; the approval promise has to survive a lost row.
	 * Activator seeds this same value on fresh installs.
	 */
	const DEFAULT_MODE    = self::MODE_CLIENT;

	const RISK_DIAGNOSTIC = 'diagnostic';
	const RISK_LOW        = 'low';
	const RISK_MEDIUM     = 'medium';
	const RISK_HIGH       = 'high';
	const RISK_CRITICAL   = 'critical';

	/**
	 * Return the current security mode.
	 *
	 * Reads wpcc_security_mode and falls back to DEFAULT_MODE (client — Standard
	 * protection) whenever the stored value is absent, empty, or not a known mode.
	 * Failing closed is deliberate: a site that loses this option must keep asking
	 * for approval rather than start executing changes unattended.
	 */
	public static function current(): string {
		$mode = (string) get_option( 'wpcc_security_mode', '' );
		return in_array( $mode, self::MODES, true ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Resolve the effective risk level for a specific action on an operation.
	 *
	 * Checks operation['action_risks'][$action] first; falls back to
	 * operation['risk_level']; defaults to 'high' when neither is set.
	 */
	public static function effective_risk( array $operation, string $action = '' ): string {
		if ( '' !== $action && isset( $operation['action_risks'][ $action ] ) ) {
			return $operation['action_risks'][ $action ];
		}
		return $operation['risk_level'] ?? self::RISK_HIGH;
	}

	/**
	 * Whether the given risk level requires an approval request in the current mode.
	 *
	 * | Risk       | Developer | Client | Enterprise |
	 * |------------|-----------|--------|------------|
	 * | diagnostic | NO        | NO     | NO         |
	 * | low        | NO        | NO     | YES        |
	 * | medium     | NO        | YES    | YES        |
	 * | high       | NO        | YES    | YES        |
	 * | critical   | NO        | YES    | YES        |
	 */
	public static function requires_approval( string $risk_level ): bool {
		$mode = self::current();

		if ( self::MODE_DEVELOPER === $mode ) {
			return false;
		}

		if ( self::MODE_ENTERPRISE === $mode ) {
			return self::RISK_DIAGNOSTIC !== $risk_level;
		}

		// Client mode: medium, high, critical are gated; diagnostic and low are free.
		return in_array( $risk_level, [ self::RISK_MEDIUM, self::RISK_HIGH, self::RISK_CRITICAL ], true );
	}

	/**
	 * Whether approvals must be granted by a human WordPress administrator.
	 *
	 * In Client and Enterprise modes, API-token actors cannot self-approve,
	 * self-reject, or trigger queue execution. Only WP_User actors (WordPress
	 * admin UI / cookie-authenticated REST calls) may perform those actions.
	 */
	public static function requires_human_approver(): bool {
		return self::current() !== self::MODE_DEVELOPER;
	}

	/**
	 * Human-readable label for the current mode.
	 *
	 * These are the plain-language product names shown everywhere in the admin. The
	 * mode KEYS (developer/client/enterprise) are the stable API and never change —
	 * only the words a site owner reads. "Client"/"Enterprise" described who we
	 * imagined buying it; these describe what the site actually does.
	 */
	public static function label(): string {
		return self::label_for( self::current() );
	}

	/** Plain-language label for any mode key. */
	public static function label_for( string $mode ): string {
		return match ( $mode ) {
			self::MODE_CLIENT     => __( 'Standard protection', 'ai-command-center' ),
			self::MODE_ENTERPRISE => __( 'Strict approval', 'ai-command-center' ),
			default               => __( 'Development — no approval', 'ai-command-center' ),
		};
	}

	/** One-sentence description of what a mode does, in the user's terms. */
	public static function describe( string $mode ): string {
		return match ( $mode ) {
			self::MODE_CLIENT     => __( 'Safe read-only requests run straight away. Anything that changes the site waits for you to approve it.', 'ai-command-center' ),
			self::MODE_ENTERPRISE => __( 'Only read-only requests run straight away. Every change of any size waits for you to approve it.', 'ai-command-center' ),
			default               => __( 'Changes run immediately with no approval step. For your own development or staging site only.', 'ai-command-center' ),
		};
	}

	/**
	 * Whether the current mode holds changes for human approval. False only in
	 * developer mode. Used by the UI to state the site's protection honestly.
	 */
	public static function is_protected(): bool {
		return self::current() !== self::MODE_DEVELOPER;
	}
}
