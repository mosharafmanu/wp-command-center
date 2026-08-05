<?php
/**
 * PROGRAM-7 — AI activity / mission-control read model (read-only, honest).
 *
 * EXPERIENCE ONLY. Aggregates signals the platform ALREADY produces — the
 * append-only AuditLog and the pending-approval queue — into a compact
 * "mission control" feed + counters. It performs NO writes, NO AI calls, NO
 * schema/registry/runtime change; it only reads existing data, exactly as the
 * Overview home and the admin-bar badge already do.
 *
 * Honesty: it reports REAL recorded events. It never invents jobs, and it does
 * NOT fabricate token/cost numbers (per-token cost is not instrumented in the
 * runtime — surfaced as an explicit "not tracked yet", never a fake figure).
 */

namespace WPCommandCenter\Ai\Platform;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Operations\OperationManager;

defined( 'ABSPATH' ) || exit;

final class AiActivity {

	/** Classify a raw audit action into a human category. */
	public static function categorize( string $action ): string {
		$a = strtolower( $action );
		if ( str_contains( $a, 'rollback' ) || str_contains( $a, 'restore' ) ) { return 'rollback'; }
		if ( str_starts_with( $a, 'ai.connection' ) || str_starts_with( $a, 'ai.provider' ) ) { return 'connection'; }
		if ( str_contains( $a, 'seo' ) || str_contains( $a, 'alt_text' ) || str_contains( $a, 'proposal' ) || str_contains( $a, 'generate' ) ) { return 'generation'; }
		if ( str_starts_with( $a, 'mcp.' ) ) { return 'agent'; }
		if ( str_starts_with( $a, 'change' ) ) { return 'change'; }
		if ( str_starts_with( $a, 'operation' ) ) { return 'operation'; }
		if ( str_starts_with( $a, 'security' ) ) { return 'security'; }
		if ( str_starts_with( $a, 'patch' ) ) { return 'patch'; }
		return 'activity';
	}

	/** Human label + dot color for a category. */
	public static function category_meta( string $cat ): array {
		$map = [
			'rollback'   => [ __( 'Rollback', 'ai-command-center' ), '#7b3fbf' ],
			'connection' => [ __( 'Connection', 'ai-command-center' ), '#2271b1' ],
			'generation' => [ __( 'AI generation', 'ai-command-center' ), '#0a7a33' ],
			'agent'      => [ __( 'AI agent', 'ai-command-center' ), '#1d62b0' ],
			'change'     => [ __( 'Change', 'ai-command-center' ), '#8c5e00' ],
			'operation'  => [ __( 'Operation', 'ai-command-center' ), '#50575e' ],
			'security'   => [ __( 'Security', 'ai-command-center' ), '#d63638' ],
			'patch'      => [ __( 'Patch', 'ai-command-center' ), '#2c3a4f' ],
			'activity'   => [ __( 'Activity', 'ai-command-center' ), '#646970' ],
		];
		return $map[ $cat ] ?? $map['activity'];
	}

	/** Humanize a raw action string ("ai.connection.test" → "Ai connection test"). */
	public static function humanize( string $action ): string {
		/*
		 * The activity feed is customer-facing, and it was rendering raw audit
		 * ids with the dots swapped for spaces: "Operation worker completed",
		 * "Operation result created", "Operation execution started". On a real
		 * install those three account for the overwhelming majority of the feed
		 * (31 of 40 entries when this was written), so what a customer actually
		 * saw on the Built-in AI screen was a wall of the engine talking to
		 * itself — twice over, since the category chip beside it already said
		 * "Operation".
		 *
		 * Three layers, in order:
		 *   1. an explicit map for the events customers actually meet;
		 *   2. `operation.{operation_id}.{state}` resolved through ActionLabels,
		 *      the same dictionary Approvals and Changes use, so one operation
		 *      reads the same way everywhere;
		 *   3. the original mechanical humanizer, so a newly added event still
		 *      degrades to readable words rather than to a raw id.
		 */
		$explicit = self::event_labels();
		if ( isset( $explicit[ $action ] ) ) {
			return $explicit[ $action ];
		}

		// operation.<operation_id>.<started|completed|failed>
		if ( preg_match( '/^operation\.([a-z0-9_]+)\.(started|completed|failed)$/', $action, $m ) ) {
			$title = \WPCommandCenter\Admin\ActionLabels::describe( $m[1], '', [], '' );
			if ( '' !== $title ) {
				return match ( $m[2] ) {
					'started'   => sprintf( /* translators: %s: what the change does. */ __( '%s — started', 'ai-command-center' ), $title ),
					'failed'    => sprintf( /* translators: %s: what the change does. */ __( '%s — did not run', 'ai-command-center' ), $title ),
					default     => sprintf( /* translators: %s: what the change does. */ __( '%s — done', 'ai-command-center' ), $title ),
				};
			}
		}

		$s = str_replace( [ '.', '_' ], ' ', $action );
		return ucfirst( trim( $s ) );
	}

	/**
	 * Customer-facing names for the audit events that actually reach the feed.
	 * Engine vocabulary — worker, execution, result — never appears; what the
	 * customer sees is what the product was doing on their behalf.
	 *
	 * @return array<string,string>
	 */
	private static function event_labels(): array {
		return [
			// The background queue. "Worker" is the engine's word for it.
			'operation.worker.started'    => __( 'Background processing started', 'ai-command-center' ),
			'operation.worker.completed'  => __( 'Background processing completed', 'ai-command-center' ),
			'operation.worker.failed'     => __( 'Background processing failed', 'ai-command-center' ),
			'operation.worker.locked'     => __( 'Background processing picked up an item', 'ai-command-center' ),
			// Applying an approved change.
			'operation.execution.started'   => __( 'Applying an approved change', 'ai-command-center' ),
			'operation.execution.completed' => __( 'Approved change applied', 'ai-command-center' ),
			'operation.execution.failed'    => __( 'A change could not be applied', 'ai-command-center' ),
			// Bookkeeping the customer does not need named as bookkeeping.
			'operation.result.created'    => __( 'Result recorded', 'ai-command-center' ),
			'operation.result.completed'  => __( 'Result recorded', 'ai-command-center' ),
			// Governance moments that matter to them.
			'operation.approval.required'        => __( 'Waiting for your approval', 'ai-command-center' ),
			'operation.approval.auto_requested'  => __( 'Sent for your approval', 'ai-command-center' ),
			'operation.request.approved'         => __( 'You approved a change', 'ai-command-center' ),
			'operation.request.rejected'         => __( 'You rejected a change', 'ai-command-center' ),
			// Built-in AI generation.
			'seo.generate.started'        => __( 'Generating SEO suggestions', 'ai-command-center' ),
			'seo.generate.completed'      => __( 'SEO suggestions generated', 'ai-command-center' ),
			'alt_text.generate.started'   => __( 'Generating alt text', 'ai-command-center' ),
			'alt_text.generate.completed' => __( 'Alt text generated', 'ai-command-center' ),
			'content.generate.started'    => __( 'Generating content suggestions', 'ai-command-center' ),
			'content.generate.completed'  => __( 'Content suggestions generated', 'ai-command-center' ),
			'proposal.created'            => __( 'Suggestion saved as a draft', 'ai-command-center' ),
			'proposal.applied'            => __( 'Suggestion applied', 'ai-command-center' ),
			'proposal.dismissed'          => __( 'Suggestion dismissed', 'ai-command-center' ),
		];
	}

	/**
	 * Recent AI-relevant activity, newest first.
	 *
	 * @return array<int,array{time:int,category:string,cat_label:string,color:string,label:string,actor:string}>
	 */
	public static function feed( int $limit = 20 ): array {
		$entries = ( new AuditLog() )->tail( max( $limit * 4, 60 ) ); // over-read, then filter.
		$out     = [];
		foreach ( $entries as $e ) {
			if ( ! is_array( $e ) || empty( $e['action'] ) ) {
				continue;
			}
			$action = (string) $e['action'];
			// Skip pure transport/noise; keep meaningful AI/operation/change/security events.
			if ( str_starts_with( $action, 'mcp.request' ) || str_starts_with( $action, 'mcp.authenticated' ) ) {
				continue;
			}
			$cat  = self::categorize( $action );
			$meta = self::category_meta( $cat );
			$ctx  = isset( $e['context'] ) && is_array( $e['context'] ) ? $e['context'] : [];
			$actor = '';
			if ( isset( $ctx['actor'] ) ) {
				$actor = is_array( $ctx['actor'] ) ? (string) ( $ctx['actor']['label'] ?? $ctx['actor']['type'] ?? '' ) : (string) $ctx['actor'];
			}
			$out[] = [
				'time'      => (int) ( $e['timestamp'] ?? 0 ),
				'category'  => $cat,
				'cat_label' => $meta[0],
				'color'     => $meta[1],
				'label'     => self::humanize( $action ),
				'actor'     => $actor,
			];
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Mission-control counters (honest; cost intentionally absent — see class doc).
	 *
	 * @return array{events:int,generations:int,rollbacks:int,changes:int,pending_approvals:int,cost_tracked:bool}
	 */
	public static function summary(): array {
		$feed = self::feed( 100 );
		$gen  = 0; $rb = 0; $ch = 0;
		foreach ( $feed as $f ) {
			if ( 'generation' === $f['category'] ) { $gen++; }
			elseif ( 'rollback' === $f['category'] ) { $rb++; }
			elseif ( 'change' === $f['category'] ) { $ch++; }
		}
		return [
			'events'            => count( $feed ),
			'generations'      => $gen,
			'rollbacks'        => $rb,
			'changes'          => $ch,
			'pending_approvals'=> self::pending_approvals(),
			'cost_tracked'     => false, // honest: per-token cost is not instrumented.
		];
	}

	/** Pending human-approval requests (read-only count; same source as the admin-bar badge). */
	public static function pending_approvals(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_requests';
		// Guard: table may not exist on a fresh install.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", OperationManager::STATUS_PENDING_REVIEW ) );
	}
}
