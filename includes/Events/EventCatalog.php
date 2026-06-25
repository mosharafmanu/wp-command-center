<?php
/**
 * PROGRAM-9 — event taxonomy (the published contract).
 *
 * The stable vocabulary subscribers depend on. Event NAMES are `category.verb`
 * (e.g. `operation.completed`) — derived from the raw runtime audit action but
 * normalized so subscribers never parse free-form action strings. Adding a new
 * runtime action maps to an existing category/verb here; subscribers keep working.
 *
 * Pure constants/metadata. No behavior, no I/O.
 */

namespace WPCommandCenter\Events;

defined( 'ABSPATH' ) || exit;

final class EventCatalog {

	/** Stable categories (the left side of an event name). */
	public const CATEGORIES = [
		'operation',   // a governed operation lifecycle
		'ai',          // AI generation lifecycle
		'connection',  // AI connection config / test
		'change',      // a recorded change
		'rollback',    // a rollback / restore
		'approval',    // approval lifecycle
		'security',    // security / capability / denial
		'agent',       // MCP / external agent activity
		'patch',       // file patch lifecycle
		'system',      // anything else
	];

	/** Stable verbs (the right side of an event name). */
	public const VERBS = [
		'started', 'completed', 'failed', 'cancelled', 'denied',
		'exception', 'recorded', 'test', 'dispatched', 'applied',
		'created', 'updated', 'deleted', 'occurred',
	];

	public const SEVERITY_INFO    = 'info';
	public const SEVERITY_WARNING = 'warning';
	public const SEVERITY_ERROR   = 'error';

	/** Verbs that represent a terminal outcome (a finished unit of work). */
	public const TERMINAL_VERBS = [ 'completed', 'failed', 'cancelled', 'recorded', 'test', 'dispatched', 'applied', 'denied' ];

	/** Verbs that imply an error severity. */
	public const ERROR_VERBS = [ 'failed', 'exception', 'denied' ];

	public static function is_category( string $c ): bool {
		return in_array( $c, self::CATEGORIES, true );
	}

	public static function is_verb( string $v ): bool {
		return in_array( $v, self::VERBS, true );
	}

	/** Build a canonical event name from category + verb. */
	public static function name( string $category, string $verb ): string {
		return $category . '.' . $verb;
	}
}
