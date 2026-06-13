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
	const DEFAULT_MODE    = self::MODE_DEVELOPER;

	const RISK_DIAGNOSTIC = 'diagnostic';
	const RISK_LOW        = 'low';
	const RISK_MEDIUM     = 'medium';
	const RISK_HIGH       = 'high';
	const RISK_CRITICAL   = 'critical';

	/**
	 * Return the current security mode.
	 *
	 * Reads wpcc_security_mode; defaults to MODE_DEVELOPER when absent. Sites that
	 * had wpcc_enforce_approval = 1 set manually before STEP 80 are NOT
	 * auto-migrated — they default to developer mode and should be explicitly
	 * switched via the WP Admin Security Mode UI (STEP 80B).
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
	 */
	public static function label(): string {
		return match ( self::current() ) {
			self::MODE_CLIENT     => __( 'Client Mode', 'wp-command-center' ),
			self::MODE_ENTERPRISE => __( 'Enterprise Mode', 'wp-command-center' ),
			default               => __( 'Developer Mode', 'wp-command-center' ),
		};
	}
}
