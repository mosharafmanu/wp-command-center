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
	 *   last_used_at:?int,
	 *   label:string,
	 *   detail:string
	 * }
	 */
	public static function get(): array {
		$tokens = ( new AuthTokens() )->list();
		$active = array_values( array_filter(
			$tokens,
			static fn ( $t ) => ( $t['status'] ?? '' ) === 'active'
		) );

		$last_used = null;
		foreach ( $active as $token ) {
			$used = isset( $token['last_used_at'] ) ? (int) $token['last_used_at'] : 0;
			if ( $used > 0 && ( null === $last_used || $used > $last_used ) ) {
				$last_used = $used;
			}
		}

		if ( [] === $active ) {
			return self::shape( self::STATE_NO_TOKEN, $active, null );
		}
		if ( null === $last_used ) {
			return self::shape( self::STATE_UNUSED, $active, null );
		}
		$state = ( time() - $last_used ) <= self::ACTIVE_WINDOW
			? self::STATE_CONNECTED
			: self::STATE_IDLE;

		return self::shape( $state, $active, $last_used );
	}

	/**
	 * @param array<int,array<string,mixed>> $active
	 * @return array{state:string,active_tokens:int,last_used_at:?int,label:string,detail:string}
	 */
	private static function shape( string $state, array $active, ?int $last_used ): array {
		$ago = null !== $last_used
			/* translators: %s: human-readable time difference, e.g. "2 hours" */
			? sprintf( __( '%s ago', 'ai-command-center' ), human_time_diff( $last_used, time() ) )
			: '';

		switch ( $state ) {
			case self::STATE_CONNECTED:
				$label  = __( 'Assistant connected', 'ai-command-center' );
				/* translators: %s: how long ago, e.g. "2 hours ago" */
				$detail = sprintf( __( 'Last request %s.', 'ai-command-center' ), $ago );
				break;
			case self::STATE_IDLE:
				$label  = __( 'No recent activity', 'ai-command-center' );
				/* translators: %s: how long ago, e.g. "3 months ago" */
				$detail = sprintf( __( 'An assistant last connected %s.', 'ai-command-center' ), $ago );
				break;
			case self::STATE_UNUSED:
				$label  = __( 'Waiting for your assistant', 'ai-command-center' );
				$detail = __( 'A token is ready, but nothing has connected with it yet. Finish setup in your assistant, then ask it something about this site.', 'ai-command-center' );
				break;
			default:
				$label  = __( 'Not connected', 'ai-command-center' );
				$detail = __( 'No access token yet — an assistant needs one to reach this site.', 'ai-command-center' );
				break;
		}

		return [
			'state'         => $state,
			'active_tokens' => count( $active ),
			'last_used_at'  => $last_used,
			'label'         => $label,
			'detail'        => $detail,
		];
	}

	/** True once an assistant has genuinely reached this site at least once. */
	public static function ever_connected(): bool {
		$s = self::get();
		return in_array( $s['state'], [ self::STATE_CONNECTED, self::STATE_IDLE ], true );
	}
}
