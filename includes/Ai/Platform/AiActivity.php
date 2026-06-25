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
			'rollback'   => [ __( 'Rollback', 'wp-command-center' ), '#7b3fbf' ],
			'connection' => [ __( 'Connection', 'wp-command-center' ), '#2271b1' ],
			'generation' => [ __( 'AI generation', 'wp-command-center' ), '#0a7a33' ],
			'agent'      => [ __( 'AI agent', 'wp-command-center' ), '#1d62b0' ],
			'change'     => [ __( 'Change', 'wp-command-center' ), '#8c5e00' ],
			'operation'  => [ __( 'Operation', 'wp-command-center' ), '#50575e' ],
			'security'   => [ __( 'Security', 'wp-command-center' ), '#d63638' ],
			'patch'      => [ __( 'Patch', 'wp-command-center' ), '#2c3a4f' ],
			'activity'   => [ __( 'Activity', 'wp-command-center' ), '#646970' ],
		];
		return $map[ $cat ] ?? $map['activity'];
	}

	/** Humanize a raw action string ("ai.connection.test" → "Ai connection test"). */
	public static function humanize( string $action ): string {
		$s = str_replace( [ '.', '_' ], ' ', $action );
		return ucfirst( trim( $s ) );
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
