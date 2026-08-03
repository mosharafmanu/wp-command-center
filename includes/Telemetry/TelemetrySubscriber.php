<?php
/**
 * PROGRAM-8 — telemetry subscriber (audit observer; observe, don't change).
 *
 * Listens to the single behavior-neutral `wpcc_audit_recorded` hook and projects
 * MEANINGFUL terminal lifecycle events into structured telemetry. It captures only
 * what the runtime already records (status, timestamps, provider/model, duration
 * where present); tokens/cost stay NULL ("unknown") until the runtime is
 * instrumented to call TelemetryRecorder directly. It writes one row per terminal
 * event (no excessive/duplicate writes) and never throws into the runtime.
 *
 * This is the OBSERVATION path. The PUSH path (TelemetryRecorder) is the sanctioned
 * insertion point for future runtime instrumentation — that wiring would edit
 * execution code and is intentionally out of THIS program's "observe, not change"
 * boundary.
 */

namespace WPCommandCenter\Telemetry;

defined( 'ABSPATH' ) || exit;

final class TelemetrySubscriber {

	private TelemetryRecorder $recorder;

	public function __construct( ?TelemetryRecorder $recorder = null ) {
		$this->recorder = $recorder ?? new TelemetryRecorder();
	}

	public function init(): void {
		add_action( 'wpcc_audit_recorded', [ $this, 'on_audit' ], 10, 3 );
	}

	/** Map a terminal audit event → a telemetry row. Bounded + guarded. */
	public function on_audit( string $action, $context, int $timestamp ): void {
		try {
			if ( ! $this->is_terminal( $action ) ) {
				return; // ignore non-terminal/noise — avoids excessive writes.
			}
			$ctx = is_array( $context ) ? $context : [];
			$this->recorder->record( [
				'kind'         => $this->kind( $action ),
				'operation'    => $this->operation( $action, $ctx ),
				'capability'   => isset( $ctx['capability'] ) ? (string) $ctx['capability'] : '',
				'provider'     => isset( $ctx['provider'] ) ? (string) $ctx['provider'] : '',
				'model'        => isset( $ctx['model'] ) ? (string) $ctx['model'] : '',
				'status'       => $this->status( $action, $ctx ),
				'completed_at' => $timestamp > 0 ? $timestamp : time(),
				// Real measurement when the runtime already records it; else unknown (NULL).
				'duration_ms'  => isset( $ctx['duration_ms'] ) ? (int) $ctx['duration_ms'] : null,
				'error_code'   => $this->error_code( $action, $ctx ),
				'actor_type'   => $this->actor( $ctx ),
				// tokens / cost intentionally absent → stored NULL (not yet measured).
			] );
		} catch ( \Throwable $e ) { /* observation must never break the runtime */ }
	}

	/** Terminal/meaningful events only — one row per real job outcome. */
	private function is_terminal( string $a ): bool {
		$a = strtolower( $a );
		if ( str_ends_with( $a, '.completed' ) || str_ends_with( $a, '.failed' ) ) { return true; }
		if ( str_contains( $a, 'exception' ) ) { return true; }
		if ( str_contains( $a, 'rollback' ) && ( str_contains( $a, 'dispatched' ) || str_contains( $a, 'completed' ) || str_contains( $a, 'applied' ) ) ) { return true; }
		if ( 'ai.connection.test' === $a ) { return true; }
		if ( 'change.recorded' === $a ) { return true; }
		return false;
	}

	private function kind( string $a ): string {
		$a = strtolower( $a );
		if ( str_contains( $a, 'rollback' ) ) { return 'rollback'; }
		if ( str_starts_with( $a, 'ai.connection' ) ) { return 'connection_test'; }
		if ( 'change.recorded' === $a ) { return 'change'; }
		if ( str_contains( $a, 'seo' ) || str_contains( $a, 'alt_text' ) || str_contains( $a, 'content' ) || str_contains( $a, 'proposal' ) ) { return 'ai_generation'; }
		return 'operation';
	}

	/** Best-effort operation id: explicit context, else the middle of the action. */
	private function operation( string $a, array $ctx ): string {
		if ( isset( $ctx['operation'] ) && '' !== (string) $ctx['operation'] ) {
			return (string) $ctx['operation'];
		}
		$parts = explode( '.', $a );
		return count( $parts ) >= 2 ? $parts[1] : ( $parts[0] ?? '' );
	}

	/**
	 * The `result` context value as a scalar string, or '' when it is not scalar.
	 *
	 * Operation-completion events carry the STRUCTURED operation result (an array) under
	 * `result`, while AI/connection events carry a short scalar verdict ('ok', 'api_error…').
	 * Casting an array with (string) raises "Array to string conversion" and yields the
	 * literal "Array", which error_code() then stored as the error code of every successful
	 * operation. Non-scalar means "no scalar verdict was supplied" — not a failure.
	 * Mirrors the is_array() guard already used by actor().
	 */
	private function result_scalar( array $ctx ): string {
		if ( ! isset( $ctx['result'] ) || ! is_scalar( $ctx['result'] ) ) { return ''; }
		return (string) $ctx['result'];
	}

	private function status( string $a, array $ctx ): string {
		$a = strtolower( $a );
		if ( str_ends_with( $a, '.failed' ) || str_contains( $a, 'exception' ) ) { return 'failed'; }
		$result = strtolower( $this->result_scalar( $ctx ) );
		if ( '' === $result ) { return 'completed'; }
		if ( in_array( $result, [ 'fail', 'failed', 'error' ], true ) ) { return 'failed'; }
		if ( str_starts_with( $result, 'api_error' ) ) { return 'failed'; }
		return 'completed';
	}

	private function error_code( string $a, array $ctx ): string {
		$result = $this->result_scalar( $ctx );
		if ( '' !== $result && 'ok' !== strtolower( $result ) ) {
			return $result;
		}
		if ( isset( $ctx['code'] ) && is_scalar( $ctx['code'] ) ) { return (string) $ctx['code']; }
		if ( str_ends_with( strtolower( $a ), '.failed' ) || str_contains( strtolower( $a ), 'exception' ) ) { return 'error'; }
		return '';
	}

	private function actor( array $ctx ): string {
		if ( ! isset( $ctx['actor'] ) ) { return ''; }
		$ac = $ctx['actor'];
		if ( is_array( $ac ) ) { return (string) ( $ac['type'] ?? '' ); }
		return (string) $ac;
	}
}
