<?php
/**
 * PROGRAM-9 — RuntimeEvent (immutable event contract).
 *
 * A normalized, read-only description of one runtime occurrence. Subscribers
 * receive this — never a raw audit string. Fields are stable; the raw `action`
 * is preserved for traceability but subscribers should key off `name`/`category`.
 *
 * Carries no secrets: `context` is the already-redacted audit context.
 */

namespace WPCommandCenter\Events;

defined( 'ABSPATH' ) || exit;

final class RuntimeEvent {

	/**
	 * @param string               $name          canonical `category.verb`
	 * @param string               $category      one of EventCatalog::CATEGORIES
	 * @param string               $verb          one of EventCatalog::VERBS
	 * @param string               $action        raw runtime audit action (traceability)
	 * @param string               $subject       e.g. the operation/connection id
	 * @param array<string,mixed>  $context       already-redacted audit context
	 * @param int                  $timestamp     unix time
	 * @param string               $actor         actor type/label (non-secret)
	 * @param string               $severity      EventCatalog severity
	 * @param bool                 $terminal      a finished unit of work
	 * @param string               $correlation_id correlation/job id, or ''
	 */
	public function __construct(
		private string $name,
		private string $category,
		private string $verb,
		private string $action,
		private string $subject,
		private array $context,
		private int $timestamp,
		private string $actor,
		private string $severity,
		private bool $terminal,
		private string $correlation_id
	) {}

	public function name(): string { return $this->name; }
	public function category(): string { return $this->category; }
	public function verb(): string { return $this->verb; }
	public function action(): string { return $this->action; }
	public function subject(): string { return $this->subject; }
	public function context(): array { return $this->context; }
	public function get( string $key, $default = null ) { return $this->context[ $key ] ?? $default; }
	public function timestamp(): int { return $this->timestamp; }
	public function actor(): string { return $this->actor; }
	public function severity(): string { return $this->severity; }
	public function is_terminal(): bool { return $this->terminal; }
	public function correlation_id(): string { return $this->correlation_id; }

	/** True when this event's name matches a subscription pattern (exact / wildcard). */
	public function matches( string $pattern ): bool {
		if ( '*' === $pattern || $pattern === $this->name ) {
			return true;
		}
		// `category.*` matches any verb in that category.
		if ( str_ends_with( $pattern, '.*' ) ) {
			$prefix = substr( $pattern, 0, -1 ); // keep the trailing dot
			return str_starts_with( $this->name . '.', $prefix ) || str_starts_with( $this->category . '.', $prefix );
		}
		return false;
	}

	/** A non-secret array form (for logs/diagnostics/future webhooks). */
	public function to_array(): array {
		return [
			'name'           => $this->name,
			'category'       => $this->category,
			'verb'           => $this->verb,
			'action'         => $this->action,
			'subject'        => $this->subject,
			'timestamp'      => $this->timestamp,
			'actor'          => $this->actor,
			'severity'       => $this->severity,
			'terminal'       => $this->terminal,
			'correlation_id' => $this->correlation_id,
		];
	}
}
