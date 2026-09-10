<?php
/**
 * V1 refinement — "Is an assistant actually connected?", answered honestly.
 *
 * Home and Connect both needed to answer this, and neither could: the old
 * "Connection ready" state was derived from setup (a token exists, a config was
 * shown), not from anything an assistant had ever done. A site with a token that
 * no assistant had ever used looked identical to a working one.
 *
 * This reports the one fact the system already knows for certain: AuthTokens
 * stamps `last_used_at` every time a bearer token successfully authenticates.
 * That is real evidence of a real client calling this site. No new option, no new
 * route, no new schema, and nothing self-reported — if no assistant has called,
 * this says so.
 *
 * READ-ONLY. Never writes, never calls out.
 */

namespace WPCommandCenter\Admin;

use WPCommandCenter\Security\AuthTokens;

defined( 'ABSPATH' ) || exit;

final class ConnectionStatus {

	/** An assistant that has called within this window counts as connected. */
	private const ACTIVE_WINDOW = 7 * DAY_IN_SECONDS;

	public const STATE_NO_TOKEN  = 'no_token';
	public const STATE_UNUSED    = 'unused';
	public const STATE_CONNECTED = 'connected';
	public const STATE_IDLE      = 'idle';

	/**
	 * @return array{
	 *   state:string,
	 *   active_tokens:int,
	 *   read_only_tokens:int,
	 *   last_used_at:?int,
	 *   last_label:string,
	 *   label:string,
	 *   detail:string
	 * }
	 */
	public static function get(): array {
		// Usable, not merely flagged active: an expired token keeps status
		// 'active' in the manifest but is refused by AuthTokens::validate(), so
		// counting it here reported a live connection for a key that cannot open
		// the door. AuthTokens::is_usable() is the rule validate() applies.
		$tokens = ( new AuthTokens() )->list();
		$active = AuthTokens::usable_only( $tokens );

		/*
		 * WHICH token last opened the door, and how many of them are read-only.
		 *
		 * Both are read off records this method already has in hand — no new
		 * option, route, or stored state. They exist because the two questions a
		 * customer actually asks of this screen are "which assistant is that?"
		 * and "how much can it do?", and a bare count answered neither.
		 *
		 * `last_label` is the token's LABEL — the name the customer typed when
		 * they created it. The token-creation form pre-fills that with the
		 * assistant they picked, so in practice it reads "Claude Desktop"; but it
		 * is editable, so this is reported as the name they gave, never as a
		 * detected client. The product does not sniff user agents and this does
		 * not start.
		 */
		$last_used  = null;
		$last_label = '';
		$read_only  = 0;
		foreach ( $active as $token ) {
			if ( AuthTokens::SCOPE_READ_ONLY === ( $token['scope'] ?? '' ) ) {
				++$read_only;
			}
			$used = isset( $token['last_used_at'] ) ? (int) $token['last_used_at'] : 0;
			if ( $used > 0 && ( null === $last_used || $used > $last_used ) ) {
				$last_used  = $used;
				$last_label = (string) ( $token['label'] ?? '' );
			}
		}

		if ( [] === $active ) {
			return self::shape( self::STATE_NO_TOKEN, $active, null, '', 0 );
		}
		if ( null === $last_used ) {
			return self::shape( self::STATE_UNUSED, $active, null, '', $read_only );
		}
		$state = ( time() - $last_used ) <= self::ACTIVE_WINDOW
			? self::STATE_CONNECTED
			: self::STATE_IDLE;

		return self::shape( $state, $active, $last_used, $last_label, $read_only );
	}

	/**
	 * @param array<int,array<string,mixed>> $active
	 * @return array{state:string,active_tokens:int,read_only_tokens:int,last_used_at:?int,last_label:string,label:string,detail:string}
	 */
	private static function shape( string $state, array $active, ?int $last_used, string $last_label = '', int $read_only = 0 ): array {
		$ago = null !== $last_used
			/* translators: %s: human-readable time difference, e.g. "2 hours" */
			? sprintf( __( '%s ago', 'action-steward' ), human_time_diff( $last_used, time() ) )
			: '';

		switch ( $state ) {
			case self::STATE_CONNECTED:
				$label  = __( 'Assistant connected', 'action-steward' );
				/* translators: %s: how long ago, e.g. "2 hours ago" */
				$detail = sprintf( __( 'Last request %s.', 'action-steward' ), $ago );
				break;
			case self::STATE_IDLE:
				$label  = __( 'No recent activity', 'action-steward' );
				/* translators: %s: how long ago, e.g. "3 months ago" */
				$detail = sprintf( __( 'An assistant last connected %s.', 'action-steward' ), $ago );
				break;
			case self::STATE_UNUSED:
				$label  = __( 'Waiting for your assistant', 'action-steward' );
				$detail = __( 'A token is ready, but nothing has connected with it yet. Finish setup in your assistant, then ask it something about this site.', 'action-steward' );
				break;
			default:
				$label  = __( 'Not connected', 'action-steward' );
				$detail = __( 'No access token yet — an assistant needs one to reach this site.', 'action-steward' );
				break;
		}

		return [
			'state'            => $state,
			'active_tokens'    => count( $active ),
			'read_only_tokens' => $read_only,
			'last_used_at'     => $last_used,
			'last_label'       => $last_label,
			'label'            => $label,
			'detail'           => $detail,
		];
	}

	/** True once an assistant has genuinely reached this site at least once. */
	public static function ever_connected(): bool {
		$s = self::get();
		return in_array( $s['state'], [ self::STATE_CONNECTED, self::STATE_IDLE ], true );
	}
}
