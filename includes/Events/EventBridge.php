<?php
/**
 * PROGRAM-9 — EventBridge: the single source that feeds the EventBus.
 *
 * Subscribes ONCE to the runtime's existing behavior-neutral emission point
 * (`wpcc_audit_recorded`, fired AFTER the authoritative audit write) and publishes
 * exactly ONE typed RuntimeEvent per record to the EventBus. This is the only
 * audit→bus path → ZERO duplicate events. It changes no runtime behavior, records
 * nothing itself, and never throws into the runtime.
 *
 * Audit stays authoritative + upstream; the bus is the downstream fan-out for
 * every other (current/future) subscriber.
 */

namespace WPCommandCenter\Events;

defined( 'ABSPATH' ) || exit;

final class EventBridge {

	public function init(): void {
		// One listener, one publish per audit record. Priority is independent of
		// the existing Telemetry subscriber on the same hook (they don't interact).
		add_action( 'wpcc_audit_recorded', [ $this, 'on_audit' ], 10, 3 );
	}

	/**
	 * @param string $action
	 * @param mixed  $context
	 * @param int    $timestamp
	 */
	public function on_audit( string $action, $context, int $timestamp = 0 ): void {
		try {
			EventBus::publish( EventFactory::from_audit( $action, is_array( $context ) ? $context : [], (int) $timestamp ) );
		} catch ( \Throwable $e ) {
			// The bus + factory are guarded; this is belt-and-braces so a malformed
			// record can never affect the audit or the runtime.
		}
	}
}
