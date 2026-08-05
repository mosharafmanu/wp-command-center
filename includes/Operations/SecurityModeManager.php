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

	/*
	 * ── The product's promise, in ONE place ──────────────────────────────────
	 *
	 * "Anything it changes waits for your approval" was written out by hand on a
	 * dozen screens — Home, Built-in AI, the assistants setup, the token dialogs,
	 * the trust strip on every generation screen. All of them stated it
	 * unconditionally, so on a site in Development mode the product told the
	 * customer their changes were held for approval while the engine applied them
	 * immediately. A safety promise that is false is worse than no promise.
	 *
	 * The promise is a function of the mode, so it lives with the mode. Callers
	 * ask for the sentence rather than writing one, which is what stops the
	 * twelve copies drifting apart again.
	 */

	/**
	 * The one-line guarantee shown beside an AI feature.
	 *
	 * Every mode keeps the audit trail and the undo; only the approval step
	 * differs, so only that clause changes.
	 */
	public static function promise(): string {
		/*
		 * Standard and Strict are NOT the same promise, and saying "anything it
		 * changes waits" was wrong for Standard: low-risk writes
		 * (media_regenerate_metadata, cache_purge_all/url, media_snapshot_create,
		 * patch_create) run immediately there — see the table on
		 * requires_approval(). The Protection screen's own mode cards and
		 * readme.txt have always drawn this distinction correctly; this helper
		 * now matches them instead of contradicting them.
		 */
		return match ( self::current() ) {
			self::MODE_ENTERPRISE => __( 'Every change waits for your approval, including low-risk ones, and everything is recorded and can be undone.', 'ai-command-center' ),
			self::MODE_CLIENT     => __( 'Anything that could affect your visitors waits for your approval, low-risk edits go straight through, and everything is recorded and can be undone.', 'ai-command-center' ),
			default               => __( 'This site is in Development mode, so changes run immediately without approval. Everything is still recorded and can be undone.', 'ai-command-center' ),
		};
	}

	/**
	 * The approval guarantee as a short chip label — "Requires approval" on a
	 * protected site, and the truth on an unprotected one.
	 */
	public static function approval_chip(): string {
		return self::is_protected()
			? __( 'Requires approval', 'ai-command-center' )
			: __( 'Runs immediately', 'ai-command-center' );
	}

	/**
	 * The review guarantee as a short chip label.
	 *
	 * "Reviewed by you" was hardcoded beside the mode-aware approval chip, so a
	 * Development site rendered "Reviewed by you" and "Runs immediately" side by
	 * side — the same fact, asserted twice, in opposite directions. Review IS the
	 * approval step; when there is no approval step there is no review, and the
	 * chip has to say so rather than reassure.
	 */
	public static function review_chip(): string {
		return self::is_protected()
			? __( 'Reviewed by you', 'ai-command-center' )
			: __( 'Not reviewed', 'ai-command-center' );
	}

	/**
	 * The "you approve" step in Home's four-step explainer.
	 *
	 * Home stated this unconditionally while the banner directly above it said
	 * "Approvals are turned off" — the same screen promising and denying the same
	 * guarantee.
	 */
	public static function approval_step(): string {
		// Three modes, three different truths — see promise() for why Standard
		// cannot claim that everything waits.
		return match ( self::current() ) {
			self::MODE_ENTERPRISE => __( 'Every change waits for your yes, including low-risk ones.', 'ai-command-center' ),
			self::MODE_CLIENT     => __( 'Anything that could affect your visitors waits for your yes.', 'ai-command-center' ),
			default               => __( 'Development mode is on, so changes run immediately without waiting for you.', 'ai-command-center' ),
		};
	}

	/**
	 * The Approvals screen's own description.
	 *
	 * On a Development site nothing NEW is held — but anything queued before the
	 * mode was changed is still waiting, and turning approvals off does not
	 * release it. Saying only "nothing runs until you approve it" is false; saying
	 * only "nothing waits" would strand the queue. Both halves are needed.
	 */
	public static function approvals_desc(): string {
		return self::is_protected()
			? __( 'Changes waiting for your decision. Nothing runs until you approve it.', 'ai-command-center' )
			: __( 'Development mode is on, so new changes run immediately. Anything queued before you switched still waits for your decision here.', 'ai-command-center' );
	}

	/**
	 * The Approvals empty state, which has the same problem as approvals_desc().
	 */
	public static function approvals_empty_detail(): string {
		return self::is_protected()
			? __( 'When your assistant asks to change something, it appears here for your decision. Nothing runs until you approve it.', 'ai-command-center' )
			: __( 'Development mode is on, so your assistant’s changes apply immediately instead of waiting here. Switch to Standard protection if you want to approve them first.', 'ai-command-center' );
	}

	/**
	 * A short warning for Development mode, or '' when the site is protected.
	 * Callers render it only when non-empty, so protected sites gain no clutter.
	 */
	public static function dev_warning(): string {
		return self::is_protected()
			? ''
			: __( 'Development mode is on: AI changes apply immediately, with no approval step. Switch to Standard protection before using this on a live site.', 'ai-command-center' );
	}

	/** Where a customer changes the mode — used by the warnings above. */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=wpcc-settings&wpcc_tab=security' );
	}
}
