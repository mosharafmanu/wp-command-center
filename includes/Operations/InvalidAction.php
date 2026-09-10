<?php
/**
 * Shared "invalid action" message builder.
 *
 * The MCP tools/call envelope only surfaces { isError, code, message } to the
 * agent — any extra WP_Error data (e.g. a `valid_actions` array) is dropped on
 * the way out. So the list of valid actions MUST live in the message text to be
 * useful to the caller. This helper is the single source of truth for that
 * phrasing so every runtime returns an identical, self-describing error when it
 * receives an unknown action.
 *
 * All Operations runtimes share the WPCommandCenter\Operations namespace, so
 * they can call InvalidAction::message( ... ) with no import.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class InvalidAction {

	/**
	 * Build a uniform "invalid action" message that enumerates valid actions.
	 *
	 * @param string        $noun    Human runtime noun, e.g. "content", "SEO", "menu".
	 * @param string        $action  The action the caller supplied (may be empty).
	 * @param array<string> $valid   The list of valid actions for this runtime.
	 * @param string        $describe Optional describe-action name to hint (e.g. "acf_describe").
	 */
	public static function message( string $noun, string $action, array $valid, string $describe = '' ): string {
		$supplied = '' === $action ? '(none)' : $action;
		$hint     = '' !== $describe
			? ' ' . sprintf(
				/* translators: %s: the describe action name */
				__( 'Call action="%s" for details.', 'action-steward' ),
				$describe
			)
			: '';

		return sprintf(
			/* translators: 1: runtime noun, 2: the invalid action supplied, 3: comma-separated list of valid actions, 4: optional describe hint */
			__( 'Invalid %1$s action "%2$s". Valid actions: %3$s.%4$s', 'action-steward' ),
			$noun,
			$supplied,
			implode( ', ', $valid ),
			$hint
		);
	}
}
