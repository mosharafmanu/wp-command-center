<?php
/**
 * Step 25 — Background Worker Using WP-Cron.
 *
 * Processes queued operations automatically.
 */

namespace WPCommandCenter\Operations;

use WPCommandCenter\Security\AuditLog;

defined( 'ABSPATH' ) || exit;

final class OperationWorker {

	public const CRON_HOOK = 'wpcc_process_operation_queue';

	/**
	 * Process a batch of queued items.
	 *
	 * @param int $limit Default 5, Max 20.
	 * @param array $context Additional context for execution.
	 * @return array{processed: int, results: array, locked: int}
	 */
	public function process( int $limit = 5, array $context = [] ): array {
		$limit = min( 20, max( 1, $limit ) );
		$queue = new OperationQueue();
		$audit = new AuditLog();

		$audit->record( 'operation.worker.started', [
			'limit' => $limit,
			'actor' => $context['actor'] ?? null,
		] );

		$items = $queue->list_items( [
			'status' => OperationQueue::STATUS_QUEUED,
			'limit'  => $limit,
		] );

		$processed = 0;
		$locked    = 0;
		$results   = [];

		foreach ( $items as $item ) {
			$queue_id = $item['queue_id'];
			$lock_key = 'wpcc_queue_lock_' . $queue_id;

			// Acquire lock for 5 minutes.
			if ( false === set_transient( $lock_key, true, 5 * MINUTE_IN_SECONDS ) ) {
				$locked++;
				$audit->record( 'operation.worker.locked', [
					'queue_id' => $queue_id,
					'actor'    => $context['actor'] ?? null,
				] );
				continue;
			}

			// Try to run the item
			$result = $queue->run_item( $queue_id, $context );

			// Release lock
			delete_transient( $lock_key );

			$processed++;
			$results[] = [
				'queue_id' => $queue_id,
				'result'   => $result,
			];
		}

		$audit->record( 'operation.worker.completed', [
			'processed' => $processed,
			'locked'    => $locked,
			'actor'     => $context['actor'] ?? null,
		] );

		return [
			'processed' => $processed,
			'locked'    => $locked,
			'results'   => $results,
		];
	}

	/**
	 * Run by the WP-Cron hook.
	 */
	public function handle_cron(): void {
		// STEP 105.5 — cron-executed queue items have no human/token actor; tag
		// them so the change log records "System (Cron)" instead of "unknown".
		$this->process( 5, [ 'actor' => null, 'system_via' => 'cron' ] );
	}

	/**
	 * Get current queue statistics for context.
	 */
	public function get_stats(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpcc_operation_queue';

		$stats = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status", ARRAY_A );

		$counts = [
			'queued'    => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'cancelled' => 0,
		];

		foreach ( $stats ?: [] as $row ) {
			$counts[ $row['status'] ] = (int) $row['count'];
		}

		return [
			'queue_worker_status'  => wp_next_scheduled( self::CRON_HOOK ) ? 'active' : 'inactive',
			'pending_queue_count'  => $counts['queued'],
			'running_queue_count'  => $counts['running'],
			'failed_queue_count'   => $counts['failed'],
		];
	}
}
