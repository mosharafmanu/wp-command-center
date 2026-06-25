<?php
/**
 * PROGRAM-9 — EventBus (the single publish/subscribe layer).
 *
 * The central fan-out for runtime events. Subscribers register by name pattern
 * (exact `operation.completed`, wildcard `operation.*`, or `*`) and receive typed
 * RuntimeEvents — never raw audit strings. Future subscribers (notifications,
 * webhooks, live dashboard, fleet, analytics) attach here with ZERO runtime
 * modification.
 *
 * Guarantees:
 *   - Every handler is `\Throwable`-guarded → one bad subscriber can never break
 *     another, the bus, or the runtime.
 *   - Dispatch is priority-ordered, deterministic.
 *   - The bus NEVER records anything itself (it does not duplicate Audit/Telemetry);
 *     it only delivers events to its subscribers.
 *   - Backward compatible: it is additive; nothing breaks if no one subscribes.
 */

namespace WPCommandCenter\Events;

defined( 'ABSPATH' ) || exit;

final class EventBus {

	/** @var array<int,array{pattern:string,priority:int,seq:int,handler:callable}> */
	private static array $subscribers = [];
	private static int $seq = 0;

	/**
	 * Subscribe a handler to events matching $pattern.
	 *
	 * @param string   $pattern  exact name | `category.*` | `*`
	 * @param callable $handler  fn( RuntimeEvent $event ): void
	 * @param int      $priority lower runs first (default 10)
	 */
	public static function subscribe( string $pattern, callable $handler, int $priority = 10 ): void {
		self::$subscribers[] = [
			'pattern'  => $pattern,
			'priority' => $priority,
			'seq'      => self::$seq++,
			'handler'  => $handler,
		];
	}

	/** Publish an event to all matching subscribers, priority-ordered, each guarded. */
	public static function publish( RuntimeEvent $event ): void {
		if ( empty( self::$subscribers ) ) {
			return; // additive: no subscribers → no-op.
		}
		$matching = array_filter( self::$subscribers, static fn ( $s ) => $event->matches( $s['pattern'] ) );
		usort( $matching, static function ( $a, $b ) {
			return $a['priority'] <=> $b['priority'] ?: $a['seq'] <=> $b['seq'];
		} );
		foreach ( $matching as $s ) {
			try {
				( $s['handler'] )( $event );
			} catch ( \Throwable $e ) {
				// A subscriber failure must never affect the runtime or other subscribers.
			}
		}
	}

	/** Number of registered subscribers (introspection/diagnostics/tests). */
	public static function count(): int {
		return count( self::$subscribers );
	}

	/** Registered subscription patterns (introspection; not the handlers). */
	public static function patterns(): array {
		return array_map( static fn ( $s ) => $s['pattern'], self::$subscribers );
	}

	/** Clear all subscribers (test isolation only). */
	public static function reset(): void {
		self::$subscribers = [];
		self::$seq = 0;
	}
}
