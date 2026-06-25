<?php
/**
 * PROGRAM-9 — EventFactory: normalize a raw runtime audit record into a typed
 * RuntimeEvent (the published contract). This is the ONLY place raw action
 * strings are parsed; subscribers never see them. Pure mapping, no I/O.
 */

namespace WPCommandCenter\Events;

defined( 'ABSPATH' ) || exit;

final class EventFactory {

	/**
	 * @param string              $action    raw audit action (e.g. operation.seo_manage.completed)
	 * @param array<string,mixed> $context   already-redacted audit context
	 * @param int                 $timestamp unix time
	 */
	public static function from_audit( string $action, array $context, int $timestamp ): RuntimeEvent {
		$category = self::category( $action );
		$verb     = self::verb( $action, $context );
		$subject  = self::subject( $action, $context );
		$severity = self::severity( $verb, $action );
		$terminal = in_array( $verb, EventCatalog::TERMINAL_VERBS, true );

		return new RuntimeEvent(
			EventCatalog::name( $category, $verb ),
			$category,
			$verb,
			$action,
			$subject,
			$context,
			$timestamp > 0 ? $timestamp : time(),
			self::actor( $context ),
			$severity,
			$terminal,
			self::correlation( $context )
		);
	}

	private static function category( string $action ): string {
		$a = strtolower( $action );
		// Explicit prefixes win first — an operation on the SEO runtime is an
		// `operation` event, not an `ai` event. The `ai` heuristic (below) is only
		// for genuine AI generation actions (proposals / generate), never operations.
		if ( str_contains( $a, 'rollback' ) || str_contains( $a, 'restore' ) ) { return 'rollback'; }
		if ( str_starts_with( $a, 'ai.connection' ) || str_starts_with( $a, 'ai.provider' ) ) { return 'connection'; }
		if ( str_starts_with( $a, 'mcp.' ) ) { return 'agent'; }
		if ( str_starts_with( $a, 'change' ) ) { return 'change'; }
		if ( str_starts_with( $a, 'approval' ) || str_contains( $a, 'request' ) || str_contains( $a, 'queue' ) ) { return 'approval'; }
		if ( str_starts_with( $a, 'security' ) || str_starts_with( $a, 'capability' ) || str_contains( $a, 'denied' ) ) { return 'security'; }
		if ( str_starts_with( $a, 'patch' ) ) { return 'patch'; }
		if ( str_starts_with( $a, 'operation' ) ) { return 'operation'; }
		// Genuine AI generation events (not operations): proposals / generation.
		if ( str_contains( $a, 'proposal' ) || str_contains( $a, 'generate' ) || str_starts_with( $a, 'ai.' ) || str_starts_with( $a, 'seo_meta' ) || str_starts_with( $a, 'alt_text' ) ) { return 'ai'; }
		return 'system';
	}

	private static function verb( string $action, array $context ): string {
		$a = strtolower( $action );
		if ( str_contains( $a, 'exception' ) ) { return 'exception'; }
		foreach ( [ 'completed', 'failed', 'cancelled', 'denied', 'started', 'recorded', 'dispatched', 'applied', 'created', 'updated', 'deleted' ] as $v ) {
			if ( str_ends_with( $a, '.' . $v ) || str_contains( $a, '.' . $v . '.' ) || str_ends_with( $a, $v ) ) {
				return $v;
			}
		}
		if ( str_ends_with( $a, '.test' ) || '.test' === substr( $a, -5 ) || str_contains( $a, '.test' ) ) {
			// A test reports a result; treat a non-ok result as failed.
			$res = strtolower( (string) ( $context['result'] ?? 'ok' ) );
			return ( '' !== $res && 'ok' !== $res ) ? 'failed' : 'test';
		}
		return 'occurred';
	}

	private static function subject( string $action, array $context ): string {
		foreach ( [ 'operation', 'connection', 'feature', 'provider' ] as $k ) {
			if ( isset( $context[ $k ] ) && '' !== (string) $context[ $k ] ) {
				return (string) $context[ $k ];
			}
		}
		$parts = explode( '.', $action );
		return count( $parts ) >= 3 ? $parts[1] : ( $parts[0] ?? '' );
	}

	private static function severity( string $verb, string $action ): string {
		if ( in_array( $verb, EventCatalog::ERROR_VERBS, true ) ) {
			return EventCatalog::SEVERITY_ERROR;
		}
		$a = strtolower( $action );
		if ( str_contains( $a, 'warn' ) || str_contains( $a, 'rate' ) || str_contains( $a, 'slow' ) ) {
			return EventCatalog::SEVERITY_WARNING;
		}
		return EventCatalog::SEVERITY_INFO;
	}

	private static function actor( array $context ): string {
		if ( ! isset( $context['actor'] ) ) { return ''; }
		$ac = $context['actor'];
		if ( is_array( $ac ) ) { return (string) ( $ac['label'] ?? $ac['type'] ?? '' ); }
		return (string) $ac;
	}

	private static function correlation( array $context ): string {
		foreach ( [ 'job_id', 'request_id', 'change_id', 'session_id', 'correlation_id' ] as $k ) {
			if ( isset( $context[ $k ] ) && '' !== (string) $context[ $k ] ) {
				return (string) $context[ $k ];
			}
		}
		return '';
	}
}
