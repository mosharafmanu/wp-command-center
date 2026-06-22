<?php
/**
 * Step 13 — Agent Timeline & Runtime Visibility.
 *
 * Aggregates lifecycle events from the Audit Log and database tables
 * into a single, traceable timeline.
 */

namespace WPCommandCenter\AiAgent;

use WPCommandCenter\Security\AuditLog;
use WPCommandCenter\Security\Redactor;

defined( 'ABSPATH' ) || exit;

final class TimelineBuilder {

	/**
	 * Upper bound on baseline rows pulled per source table. Set far above any single
	 * page the timeline ever requests (the Runtime screen asks for 300; the REST default
	 * is 100), so it does not change output at current scale, while capping work and
	 * memory as the agent/patch tables grow. The newest rows (by auto-increment id) are
	 * kept and presented in ascending id order — the same order an unbounded full scan
	 * returned — so output is byte-identical whenever a table is within the cap.
	 */
	private const BASELINE_LIMIT = 5000;

	/**
	 * Build the unified timeline.
	 *
	 * @param array{
	 *     session_id?: string,
	 *     task_id?: string,
	 *     action_id?: string,
	 *     plan_id?: string,
	 *     patch_id?: string,
	 *     limit?: int,
	 *     offset?: int
	 * } $filters
	 *
	 * @return array<int, array{
	 *     timestamp: int,
	 *     type: string,
	 *     label: string,
	 *     status: string,
	 *     actor: ?array,
	 *     session_id: ?string,
	 *     task_id: ?string,
	 *     action_id: ?string,
	 *     plan_id: ?string,
	 *     patch_id: ?string,
	 *     summary: string
	 * }>
	 */
	public function build( array $filters = [] ): array {
		$events = [];

		// 1. Gather events from the Audit Log.
		$audit_log = new AuditLog();
		$entries   = $audit_log->tail( 2000 ); // Read a large chunk to allow filtering.

		foreach ( $entries as $entry ) {
			$normalized = $this->normalize_audit_entry( $entry );
			if ( $normalized ) {
				$events[] = $normalized;
			}
		}

		// 2. Supplement with events from the database (e.g. legacy sessions/tasks created before logging).
		//
		// Duplicate detection bucketed by identity key. An audit event and a DB baseline
		// event are "the same" when they share type + label + every id field AND their
		// timestamps fall within a 5s window. The previous implementation compared each
		// DB event against the entire collected list (O(db * events)); on a mature site
		// that is tens of millions of comparisons. Bucketing the timestamps already seen
		// for each identity key collapses the per-event check to the (tiny) bucket for that
		// key, which is behaviour-identical — same boolean decision, same retained events,
		// same insertion order — but runs in ~O(db + events). See is_duplicate().
		$seen_timestamps = [];
		foreach ( $events as $existing ) {
			$seen_timestamps[ $this->event_identity_key( $existing ) ][] = (int) $existing['timestamp'];
		}

		$db_events = $this->get_db_baseline_events();
		foreach ( $db_events as $event ) {
			$key = $this->event_identity_key( $event );
			if ( ! $this->is_duplicate( $event, $seen_timestamps[ $key ] ?? [] ) ) {
				$events[]                  = $event;
				$seen_timestamps[ $key ][] = (int) $event['timestamp'];
			}
		}

		// 3. Apply Filters.
		$events = array_filter( $events, function ( array $event ) use ( $filters ): bool {
			foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id', 'patch_id' ] as $key ) {
				if ( ! empty( $filters[ $key ] ) && $event[ $key ] !== $filters[ $key ] ) {
					return false;
				}
			}
			return true;
		} );

		// 4. Sort (newest first).
		usort( $events, static fn ( array $a, array $b ): int => $b['timestamp'] <=> $a['timestamp'] );

		// 5. Redact summaries.
		$redactor = new Redactor();
		foreach ( $events as &$event ) {
			$result           = $redactor->redact( $event['summary'] );
			$event['summary'] = $result['text'];
		}
		unset( $event );

		// 6. Pagination.
		$limit  = isset( $filters['limit'] ) ? max( 1, (int) $filters['limit'] ) : 100;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;

		return array_slice( array_values( $events ), $offset, $limit );
	}

	/**
	 * Map an Audit Log action to a timeline item.
	 *
	 * @param array{timestamp: int, action: string, context: array} $entry
	 * @return array|null
	 */
	private function normalize_audit_entry( array $entry ): ?array {
		$action  = $entry['action'];
		$context = $entry['context'];
		$type    = explode( '.', $action )[0];

		// Map actions to labels and default statuses.
		$map = [
			'session.created'        => [ 'label' => 'Session created', 'status' => 'active' ],
			'session.status_updated' => [ 'label' => 'Session closed', 'status' => 'closed' ],
			'task.created'           => [ 'label' => 'Task created', 'status' => 'draft' ],
			'task.status_updated'    => [ 'label' => 'Task status updated', 'status' => $context['status'] ?? 'unknown' ],
			'action.created'         => [ 'label' => 'Action proposed', 'status' => 'proposed' ],
			'action.accepted'        => [ 'label' => 'Action accepted', 'status' => 'accepted' ],
			'action.rejected'        => [ 'label' => 'Action rejected', 'status' => 'rejected' ],
			'action.cancelled'       => [ 'label' => 'Action cancelled', 'status' => 'cancelled' ],
			'action.completed'       => [ 'label' => 'Action completed', 'status' => 'completed' ],
			'recommendation.created' => [ 'label' => 'Recommendation created', 'status' => 'open' ],
			'recommendation.updated' => [ 'label' => 'Recommendation updated', 'status' => 'open' ],
			'recommendation.dismissed' => [ 'label' => 'Recommendation dismissed', 'status' => 'dismissed' ],
			'recommendation.resolved' => [ 'label' => 'Recommendation resolved', 'status' => 'resolved' ],
			'recommendation.converted_to_action' => [ 'label' => 'Recommendation converted to action', 'status' => 'converted_to_action' ],
			'recommendation.action_created' => [ 'label' => 'Recommendation action created', 'status' => 'converted_to_action' ],
			'recommendation.plan_created' => [ 'label' => 'Recommendation plan created', 'status' => 'plan_created' ],
			'recommendation.approved' => [ 'label' => 'Recommendation approved', 'status' => 'approved' ],
			'recommendation.executing' => [ 'label' => 'Recommendation executing', 'status' => 'executing' ],
			'health.verification.started' => [ 'label' => 'Health verification started', 'status' => 'running' ],
			'health.verification.completed' => [ 'label' => 'Health verification completed', 'status' => $context['status'] ?? 'passed' ],
			'health.verification.failed' => [ 'label' => 'Health verification failed', 'status' => 'failed' ],
			'system.environment.updated' => [ 'label' => 'Environment mode updated', 'status' => $context['mode'] ?? 'unknown' ],
			'system.cleanup.started' => [ 'label' => 'System cleanup started', 'status' => empty( $context['dry_run'] ) ? 'running' : 'dry_run' ],
			'system.cleanup.completed' => [ 'label' => 'System cleanup completed', 'status' => empty( $context['dry_run'] ) ? 'completed' : 'dry_run' ],
			'system.cleanup.blocked' => [ 'label' => 'System cleanup blocked', 'status' => 'blocked' ],
			'claude.config.generated'  => [ 'label' => 'AI Client config generated', 'status' => 'completed' ],
			'claude.discovery'         => [ 'label' => 'AI Client discovery', 'status' => 'completed' ],
			'claude.tool.invoked'      => [ 'label' => 'AI Client tool invoked', 'status' => 'completed' ],
			'ai_client.config.generated' => [ 'label' => 'AI Client config generated', 'status' => 'completed' ],
			'content.update.failed'     => [ 'label' => 'Content update failed', 'status' => 'failed' ],
			'plugin.rollback'           => [ 'label' => 'Plugin rolled back', 'status' => 'completed' ],
			'theme.rollback'            => [ 'label' => 'Theme rolled back', 'status' => 'completed' ],
			'operation.approval.required' => [ 'label' => 'Approval required', 'status' => 'pending_review' ],
			'user.list'                => [ 'label' => 'Users listed', 'status' => 'completed' ],
			'user.get'                 => [ 'label' => 'User details retrieved', 'status' => 'completed' ],
			'user.search'              => [ 'label' => 'Users searched', 'status' => 'completed' ],
			'user.created'             => [ 'label' => 'User created', 'status' => 'completed' ],
			'user.updated'             => [ 'label' => 'User updated', 'status' => 'completed' ],
			'user.deleted'             => [ 'label' => 'User deleted', 'status' => 'completed' ],
			'user.suspended'           => [ 'label' => 'User suspended', 'status' => 'completed' ],
			'user.password.reset'      => [ 'label' => 'Password reset', 'status' => 'completed' ],
			'user.role.assigned'       => [ 'label' => 'Role assigned', 'status' => 'completed' ],
			'user.role.removed'        => [ 'label' => 'Role removed', 'status' => 'completed' ],
			'user.rollback.applied'    => [ 'label' => 'User rollback applied', 'status' => 'completed' ],
			'user.create.failed'       => [ 'label' => 'User creation failed', 'status' => 'failed' ],
			'user.update.failed'       => [ 'label' => 'User update failed', 'status' => 'failed' ],
			'user.delete.failed'       => [ 'label' => 'User deletion failed', 'status' => 'failed' ],
			'user.create.administrator' => [ 'label' => 'Administrator created', 'status' => 'completed' ],
			'user.role.administrator_assign' => [ 'label' => 'Administrator role assigned', 'status' => 'completed' ],
			'operation.user_manage.started'   => [ 'label' => 'User management started', 'status' => 'running' ],
			'operation.user_manage.completed' => [ 'label' => 'User management completed', 'status' => 'completed' ],
			'operation.user_manage.failed'    => [ 'label' => 'User management failed', 'status' => 'failed' ],
			'media.list'               => [ 'label' => 'Media listed', 'status' => 'completed' ],
			'media.get'                => [ 'label' => 'Media details retrieved', 'status' => 'completed' ],
			'media.search'             => [ 'label' => 'Media searched', 'status' => 'completed' ],
			'media.uploaded'           => [ 'label' => 'Media uploaded', 'status' => 'completed' ],
			'media.replaced'           => [ 'label' => 'Media replaced', 'status' => 'completed' ],
			'media.deleted'            => [ 'label' => 'Media deleted', 'status' => 'completed' ],
			'media.restored'           => [ 'label' => 'Media restored', 'status' => 'completed' ],
			'featured_image.assigned'  => [ 'label' => 'Featured image assigned', 'status' => 'completed' ],
			'featured_image.removed'   => [ 'label' => 'Featured image removed', 'status' => 'completed' ],
			'media.metadata_regenerated' => [ 'label' => 'Media metadata regenerated', 'status' => 'completed' ],
			'media.rollback.applied'   => [ 'label' => 'Media rollback applied', 'status' => 'completed' ],
			'operation.media_manage.started'   => [ 'label' => 'Media management started', 'status' => 'running' ],
			'operation.media_manage.completed' => [ 'label' => 'Media management completed', 'status' => 'completed' ],
			'operation.media_manage.failed'    => [ 'label' => 'Media management failed', 'status' => 'failed' ],
			'product.created'      => [ 'label' => 'Product created', 'status' => 'completed' ],
			'product.updated'      => [ 'label' => 'Product updated', 'status' => 'completed' ],
			'product.deleted'      => [ 'label' => 'Product deleted', 'status' => 'completed' ],
			'product.published'    => [ 'label' => 'Product published', 'status' => 'completed' ],
			'product.unpublished'  => [ 'label' => 'Product unpublished', 'status' => 'completed' ],
			'stock.updated'        => [ 'label' => 'Stock updated', 'status' => 'completed' ],
			'stock.bulk_updated'   => [ 'label' => 'Bulk stock updated', 'status' => 'completed' ],
			'price.updated'        => [ 'label' => 'Price updated', 'status' => 'completed' ],
			'variation.created'    => [ 'label' => 'Variation created', 'status' => 'completed' ],
			'variation.updated'    => [ 'label' => 'Variation updated', 'status' => 'completed' ],
			'variation.deleted'    => [ 'label' => 'Variation deleted', 'status' => 'completed' ],
			'coupon.created'       => [ 'label' => 'Coupon created', 'status' => 'completed' ],
			'coupon.updated'       => [ 'label' => 'Coupon updated', 'status' => 'completed' ],
			'coupon.deleted'       => [ 'label' => 'Coupon deleted', 'status' => 'completed' ],
			'product.list'         => [ 'label' => 'Products listed', 'status' => 'completed' ],
			'product.get'          => [ 'label' => 'Product retrieved', 'status' => 'completed' ],
			'order.list'           => [ 'label' => 'Orders listed', 'status' => 'completed' ],
			'operation.woocommerce_manage.started'   => [ 'label' => 'WooCommerce operation started', 'status' => 'running' ],
			'operation.woocommerce_manage.completed' => [ 'label' => 'WooCommerce operation completed', 'status' => 'completed' ],
			'operation.woocommerce_manage.failed'    => [ 'label' => 'WooCommerce operation failed', 'status' => 'failed' ],
			'plan.created'           => [ 'label' => 'Plan created', 'status' => 'pending_review' ],
			'plan.approved'          => [ 'label' => 'Plan approved', 'status' => 'approved' ],
			'plan.rejected'          => [ 'label' => 'Plan rejected', 'status' => 'rejected' ],
			'plan.cancelled'         => [ 'label' => 'Plan cancelled', 'status' => 'cancelled' ],
			'patch.created'          => [ 'label' => 'Patch created', 'status' => 'pending_approval' ],
			'patch.approved'         => [ 'label' => 'Patch approved', 'status' => 'approved' ],
			'patch.rejected'         => [ 'label' => 'Patch rejected', 'status' => 'rejected' ],
			'patch.applied'          => [ 'label' => 'Patch applied', 'status' => 'applied' ],
			'patch.failed'           => [ 'label' => 'Patch application failed', 'status' => 'failed' ],
			'patch.rolled_back'      => [ 'label' => 'Patch rolled back', 'status' => 'rolled_back' ],
			'patch.rollback_failed'  => [ 'label' => 'Patch rollback failed', 'status' => 'applied' ],
			'operation.content_seed.started'   => [ 'label' => 'Content seeding started', 'status' => 'running' ],
			'operation.content_seed.completed' => [ 'label' => 'Content seeding completed', 'status' => 'completed' ],
			'operation.content_seed.failed'    => [ 'label' => 'Content seeding failed', 'status' => 'failed' ],
			'operation.acf_seed.started'       => [ 'label' => 'ACF seeding started', 'status' => 'running' ],
			'operation.acf_seed.completed'     => [ 'label' => 'ACF seeding completed', 'status' => 'completed' ],
			'operation.acf_seed.failed'        => [ 'label' => 'ACF seeding failed', 'status' => 'failed' ],
			'operation.cf7_seed.started'       => [ 'label' => 'CF7 seeding started', 'status' => 'running' ],
			'operation.cf7_seed.completed'     => [ 'label' => 'CF7 seeding completed', 'status' => 'completed' ],
			'operation.cf7_seed.failed'        => [ 'label' => 'CF7 seeding failed', 'status' => 'failed' ],
			'operation.woo_product_seed.started'   => [ 'label' => 'WooCommerce seeding started', 'status' => 'running' ],
			'operation.woo_product_seed.completed' => [ 'label' => 'WooCommerce seeding completed', 'status' => 'completed' ],
			'operation.woo_product_seed.failed'    => [ 'label' => 'WooCommerce seeding failed', 'status' => 'failed' ],
			'operation.safe_search_replace.started'   => [ 'label' => 'Search and replace started', 'status' => 'running' ],
			'operation.safe_search_replace.completed' => [ 'label' => 'Search and replace completed', 'status' => 'completed' ],
			'operation.safe_search_replace.failed'    => [ 'label' => 'Search and replace failed', 'status' => 'failed' ],
			'operation.media_import.started'       => [ 'label' => 'Media import started', 'status' => 'running' ],
			'operation.media_import.completed'     => [ 'label' => 'Media import completed', 'status' => 'completed' ],
			'operation.media_import.failed'        => [ 'label' => 'Media import failed', 'status' => 'failed' ],
			'operation.safe_updates.started'       => [ 'label' => 'Safe update started', 'status' => 'running' ],
			'operation.safe_updates.completed'     => [ 'label' => 'Safe update completed', 'status' => 'completed' ],
			'operation.safe_updates.failed'        => [ 'label' => 'Safe update failed', 'status' => 'failed' ],
			'operation.wp_cli_bridge.started'      => [ 'label' => 'WP-CLI operation started', 'status' => 'running' ],
			'operation.wp_cli_bridge.completed'    => [ 'label' => 'WP-CLI operation completed', 'status' => 'completed' ],
			'operation.wp_cli_bridge.failed'       => [ 'label' => 'WP-CLI operation failed', 'status' => 'failed' ],
			'operation.wp_cli_bridge.blocked'      => [ 'label' => 'WP-CLI command blocked', 'status' => 'blocked' ],
			'operation.wp_cli_bridge.denied'       => [ 'label' => 'WP-CLI command denied', 'status' => 'denied' ],
			'option.read'                          => [ 'label' => 'Option read', 'status' => 'completed' ],
			'option.update.started'                => [ 'label' => 'Option update started', 'status' => 'running' ],
			'option.update.completed'              => [ 'label' => 'Option updated', 'status' => 'completed' ],
			'option.update.failed'                 => [ 'label' => 'Option update failed', 'status' => 'failed' ],
			'option.update.rolled_back'            => [ 'label' => 'Option update rolled back', 'status' => 'completed' ],
			'operation.option_manage.started'      => [ 'label' => 'Option management started', 'status' => 'running' ],
			'operation.option_manage.completed'    => [ 'label' => 'Option management completed', 'status' => 'completed' ],
			'operation.option_manage.failed'       => [ 'label' => 'Option management failed', 'status' => 'failed' ],
			'plugin.list'                          => [ 'label' => 'Plugin list requested', 'status' => 'completed' ],
			'plugin.install.started'               => [ 'label' => 'Plugin install started', 'status' => 'running' ],
			'plugin.install.completed'             => [ 'label' => 'Plugin installed', 'status' => 'completed' ],
			'plugin.install.failed'                => [ 'label' => 'Plugin install failed', 'status' => 'failed' ],
			'plugin.activate'                      => [ 'label' => 'Plugin activated', 'status' => 'completed' ],
			'plugin.activate.failed'               => [ 'label' => 'Plugin activation failed', 'status' => 'failed' ],
			'plugin.deactivate'                    => [ 'label' => 'Plugin deactivated', 'status' => 'completed' ],
			'plugin.update.started'                => [ 'label' => 'Plugin update started', 'status' => 'running' ],
			'plugin.update'                        => [ 'label' => 'Plugin updated', 'status' => 'completed' ],
			'plugin.update.failed'                 => [ 'label' => 'Plugin update failed', 'status' => 'failed' ],
			'plugin.delete.started'                => [ 'label' => 'Plugin delete started', 'status' => 'running' ],
			'plugin.delete'                        => [ 'label' => 'Plugin deleted', 'status' => 'completed' ],
			'plugin.delete.failed'                 => [ 'label' => 'Plugin delete failed', 'status' => 'failed' ],
			'plugin.health.failed'                 => [ 'label' => 'Plugin health check failed', 'status' => 'failed' ],
			'plugin.health.warning'                => [ 'label' => 'Plugin health check warning', 'status' => 'warning' ],
			'operation.plugin_manage.started'      => [ 'label' => 'Plugin management started', 'status' => 'running' ],
			'operation.plugin_manage.completed'    => [ 'label' => 'Plugin management completed', 'status' => 'completed' ],
			'operation.plugin_manage.failed'       => [ 'label' => 'Plugin management failed', 'status' => 'failed' ],
			'theme.list'                           => [ 'label' => 'Theme list requested', 'status' => 'completed' ],
			'theme.install'                        => [ 'label' => 'Theme installed', 'status' => 'completed' ],
			'theme.install.started'                => [ 'label' => 'Theme install started', 'status' => 'running' ],
			'theme.install.failed'                 => [ 'label' => 'Theme install failed', 'status' => 'failed' ],
			'theme.activate.started'               => [ 'label' => 'Theme activate started', 'status' => 'running' ],
			'theme.activate'                       => [ 'label' => 'Theme activated', 'status' => 'completed' ],
			'theme.update.started'                 => [ 'label' => 'Theme update started', 'status' => 'running' ],
			'theme.update'                         => [ 'label' => 'Theme updated', 'status' => 'completed' ],
			'theme.update.failed'                  => [ 'label' => 'Theme update failed', 'status' => 'failed' ],
			'theme.delete.started'                 => [ 'label' => 'Theme delete started', 'status' => 'running' ],
			'theme.delete'                         => [ 'label' => 'Theme deleted', 'status' => 'completed' ],
			'theme.delete.failed'                  => [ 'label' => 'Theme delete failed', 'status' => 'failed' ],
			'theme.health.failed'                  => [ 'label' => 'Theme health check failed', 'status' => 'failed' ],
			'operation.theme_manage.started'       => [ 'label' => 'Theme management started', 'status' => 'running' ],
			'operation.theme_manage.completed'     => [ 'label' => 'Theme management completed', 'status' => 'completed' ],
			'operation.theme_manage.failed'        => [ 'label' => 'Theme management failed', 'status' => 'failed' ],
			'snapshot.create'                      => [ 'label' => 'Snapshot created', 'status' => 'completed' ],
			'snapshot.create.started'              => [ 'label' => 'Snapshot creation started', 'status' => 'running' ],
			'snapshot.create.failed'               => [ 'label' => 'Snapshot creation failed', 'status' => 'failed' ],
			'snapshot.list'                        => [ 'label' => 'Snapshot list requested', 'status' => 'completed' ],
			'snapshot.verify'                      => [ 'label' => 'Snapshot verified', 'status' => 'completed' ],
			'snapshot.restore.started'             => [ 'label' => 'Snapshot restore started', 'status' => 'running' ],
			'snapshot.restore.completed'           => [ 'label' => 'Snapshot restored', 'status' => 'completed' ],
			'snapshot.restore.failed'              => [ 'label' => 'Snapshot restore failed', 'status' => 'failed' ],
			'operation.snapshot_manage.started'    => [ 'label' => 'Snapshot management started', 'status' => 'running' ],
			'operation.snapshot_manage.completed'  => [ 'label' => 'Snapshot management completed', 'status' => 'completed' ],
			'operation.snapshot_manage.failed'     => [ 'label' => 'Snapshot management failed', 'status' => 'failed' ],
			'content.create'                       => [ 'label' => 'Content created', 'status' => 'completed' ],
			'content.update'                       => [ 'label' => 'Content updated', 'status' => 'completed' ],
			'content.delete'                       => [ 'label' => 'Content deleted', 'status' => 'completed' ],
			'content.publish'                      => [ 'label' => 'Content published', 'status' => 'completed' ],
			'content.unpublish'                    => [ 'label' => 'Content unpublished', 'status' => 'completed' ],
			'content.schedule'                     => [ 'label' => 'Content scheduled', 'status' => 'completed' ],
			'content.list'                         => [ 'label' => 'Content list requested', 'status' => 'completed' ],
			'taxonomy.assign'                      => [ 'label' => 'Taxonomy assigned', 'status' => 'completed' ],
			'featured_image.assign'                => [ 'label' => 'Featured image assigned', 'status' => 'completed' ],
			'content.create.failed'                => [ 'label' => 'Content creation failed', 'status' => 'failed' ],
			'content.delete.failed'                => [ 'label' => 'Content delete failed', 'status' => 'failed' ],
			'operation.content_manage.started'     => [ 'label' => 'Content management started', 'status' => 'running' ],
			'operation.content_manage.completed'   => [ 'label' => 'Content management completed', 'status' => 'completed' ],
			'operation.content_manage.failed'      => [ 'label' => 'Content management failed', 'status' => 'failed' ],
			'database.inspect.started'             => [ 'label' => 'Database inspection started', 'status' => 'running' ],
			'database.inspect.completed'           => [ 'label' => 'Database inspection completed', 'status' => 'completed' ],
			'database.inspect.failed'              => [ 'label' => 'Database inspection failed', 'status' => 'failed' ],
			'database.inspect.blocked'             => [ 'label' => 'Database inspection blocked', 'status' => 'blocked' ],
			'operation.database_inspect.started'   => [ 'label' => 'DB inspection started', 'status' => 'running' ],
			'operation.database_inspect.completed' => [ 'label' => 'DB inspection completed', 'status' => 'completed' ],
			'operation.database_inspect.failed'    => [ 'label' => 'DB inspection failed', 'status' => 'failed' ],
			'capability.assigned'                  => [ 'label' => 'Capability assigned', 'status' => 'completed' ],
			'capability.removed'                   => [ 'label' => 'Capability removed', 'status' => 'completed' ],
			'capability.denied'                    => [ 'label' => 'Operation denied', 'status' => 'denied' ],
			'capability.validated'                 => [ 'label' => 'Policy validation', 'status' => 'completed' ],
			'operation.capability_manage.started'  => [ 'label' => 'Capability management started', 'status' => 'running' ],
			'operation.capability_manage.completed'=> [ 'label' => 'Capability management completed', 'status' => 'completed' ],
			'operation.capability_manage.failed'   => [ 'label' => 'Capability management failed', 'status' => 'failed' ],
			'mcp.request'                          => [ 'label' => 'MCP request received', 'status' => 'completed' ],
			'mcp.tool.invoke'                      => [ 'label' => 'MCP tool invoked', 'status' => 'running' ],
			'mcp.resource.read'                    => [ 'label' => 'MCP resource read', 'status' => 'completed' ],
			'mcp.denied'                           => [ 'label' => 'MCP operation denied', 'status' => 'denied' ],
			'mcp.approval.required'                => [ 'label' => 'MCP approval required', 'status' => 'pending_review' ],
			'operation.request.created'            => [ 'label' => 'Operation request created', 'status' => 'pending_review' ],
			'operation.request.approved'           => [ 'label' => 'Operation request approved', 'status' => 'approved' ],
			'operation.request.rejected'           => [ 'label' => 'Operation request rejected', 'status' => 'rejected' ],
			'operation.request.executing'          => [ 'label' => 'Operation executing', 'status' => 'running' ],
			'operation.request.executed'           => [ 'label' => 'Operation executed', 'status' => 'executed' ],
			'operation.request.failed'             => [ 'label' => 'Operation request failed', 'status' => 'failed' ],
			'operation.request.cancelled'          => [ 'label' => 'Operation request cancelled', 'status' => 'cancelled' ],
			'operation.execution.started'          => [ 'label' => 'Operation execution started', 'status' => 'running' ],
			'operation.execution.completed'        => [ 'label' => 'Operation execution completed', 'status' => 'completed' ],
			'operation.execution.failed'           => [ 'label' => 'Operation execution failed', 'status' => 'failed' ],
			'operation.queue.created'              => [ 'label' => 'Operation queued', 'status' => 'queued' ],
			'operation.queue.running'              => [ 'label' => 'Operation queue running', 'status' => 'running' ],
			'operation.queue.completed'            => [ 'label' => 'Operation queue completed', 'status' => 'completed' ],
			'operation.queue.failed'               => [ 'label' => 'Operation queue failed', 'status' => 'failed' ],
			'operation.queue.cancelled'            => [ 'label' => 'Operation queue cancelled', 'status' => 'cancelled' ],
			'operation.queue.retry_requested'      => [ 'label' => 'Operation retry requested', 'status' => 'running' ],
			'operation.queue.retry_queued'         => [ 'label' => 'Operation retry queued', 'status' => 'queued' ],
			'operation.queue.retry_failed'         => [ 'label' => 'Operation retry failed', 'status' => 'failed' ],
			'operation.worker.started'             => [ 'label' => 'Operation worker started', 'status' => 'running' ],
			'operation.worker.completed'           => [ 'label' => 'Operation worker completed', 'status' => 'completed' ],
			'operation.worker.failed'              => [ 'label' => 'Operation worker failed', 'status' => 'failed' ],
			'operation.worker.locked'              => [ 'label' => 'Operation worker locked item', 'status' => 'running' ],
			'operation.result.created'             => [ 'label' => 'Operation result recorded', 'status' => 'completed' ],
			'operation.result.completed'            => [ 'label' => 'Operation execution finished', 'status' => 'completed' ],
			'operation.result.failed'               => [ 'label' => 'Operation execution failed', 'status' => 'failed' ],
			'acf.group.created'     => [ 'label' => 'ACF group created', 'status' => 'completed' ],
			'acf.group.updated'     => [ 'label' => 'ACF group updated', 'status' => 'completed' ],
			'acf.group.deleted'     => [ 'label' => 'ACF group deleted', 'status' => 'completed' ],
			'acf.field.created'     => [ 'label' => 'ACF field created', 'status' => 'completed' ],
			'acf.field.updated'     => [ 'label' => 'ACF field updated', 'status' => 'completed' ],
			'acf.field.deleted'     => [ 'label' => 'ACF field deleted', 'status' => 'completed' ],
			'acf.json.imported'     => [ 'label' => 'ACF JSON imported', 'status' => 'completed' ],
			'acf.json.synced'       => [ 'label' => 'ACF JSON synced', 'status' => 'completed' ],
			'acf.value.updated'     => [ 'label' => 'ACF value updated', 'status' => 'completed' ],
			'acf.group.list'        => [ 'label' => 'ACF groups listed', 'status' => 'completed' ],
			'acf.group.get'         => [ 'label' => 'ACF group retrieved', 'status' => 'completed' ],
			'operation.acf_manage.started'   => [ 'label' => 'ACF operation started', 'status' => 'running' ],
			'operation.acf_manage.completed' => [ 'label' => 'ACF operation completed', 'status' => 'completed' ],
			'operation.acf_manage.failed'    => [ 'label' => 'ACF operation failed', 'status' => 'failed' ],
			'form.created'     => [ 'label' => 'Form created', 'status' => 'completed' ],
			'form.updated'     => [ 'label' => 'Form updated', 'status' => 'completed' ],
			'form.deleted'     => [ 'label' => 'Form deleted', 'status' => 'completed' ],
			'form.duplicated'  => [ 'label' => 'Form duplicated', 'status' => 'completed' ],
			'form.activated'   => [ 'label' => 'Form activated', 'status' => 'completed' ],
			'form.deactivated' => [ 'label' => 'Form deactivated', 'status' => 'completed' ],
			'notification.updated' => [ 'label' => 'Notification updated', 'status' => 'completed' ],
			'notification.tested'  => [ 'label' => 'Notification tested', 'status' => 'completed' ],
			'entry.exported'       => [ 'label' => 'Entries exported', 'status' => 'completed' ],
			'form.analyzed'        => [ 'label' => 'Form analyzed', 'status' => 'completed' ],
			'operation.forms_manage.started'   => [ 'label' => 'Forms operation started', 'status' => 'running' ],
			'operation.forms_manage.completed' => [ 'label' => 'Forms operation completed', 'status' => 'completed' ],
			'operation.forms_manage.failed'    => [ 'label' => 'Forms operation failed', 'status' => 'failed' ],
			'menu.created'       => [ 'label' => 'Menu created', 'status' => 'completed' ],
			'menu.updated'       => [ 'label' => 'Menu updated', 'status' => 'completed' ],
			'menu.deleted'       => [ 'label' => 'Menu deleted', 'status' => 'completed' ],
			'menu.duplicated'    => [ 'label' => 'Menu duplicated', 'status' => 'completed' ],
			'menu.imported'      => [ 'label' => 'Menu imported', 'status' => 'completed' ],
			'menu.item.added'    => [ 'label' => 'Menu item added', 'status' => 'completed' ],
			'menu.item.updated'  => [ 'label' => 'Menu item updated', 'status' => 'completed' ],
			'menu.item.removed'  => [ 'label' => 'Menu item removed', 'status' => 'completed' ],
			'menu.item.reordered' => [ 'label' => 'Items reordered', 'status' => 'completed' ],
			'menu.location.assigned' => [ 'label' => 'Location assigned', 'status' => 'completed' ],
			'menu.location.removed'  => [ 'label' => 'Location removed', 'status' => 'completed' ],
			'menu.analyzed'      => [ 'label' => 'Menu analyzed', 'status' => 'completed' ],
			'operation.menu_manage.started'   => [ 'label' => 'Menu operation started', 'status' => 'running' ],
			'operation.menu_manage.completed' => [ 'label' => 'Menu operation completed', 'status' => 'completed' ],
			'operation.menu_manage.failed'    => [ 'label' => 'Menu operation failed', 'status' => 'failed' ],
			'settings.general.updated' => [ 'label' => 'Site settings updated', 'status' => 'completed' ],
			'settings.reading.updated' => [ 'label' => 'Reading settings updated', 'status' => 'completed' ],
			'settings.discussion.updated' => [ 'label' => 'Discussion settings updated', 'status' => 'completed' ],
			'settings.media.updated' => [ 'label' => 'Media settings updated', 'status' => 'completed' ],
			'settings.permalink.updated' => [ 'label' => 'Permalink updated', 'status' => 'completed' ],
			'settings.privacy.updated' => [ 'label' => 'Privacy settings updated', 'status' => 'completed' ],
			'settings.analyzed' => [ 'label' => 'Settings analyzed', 'status' => 'completed' ],
			'operation.settings_manage.started' => [ 'label' => 'Settings operation started', 'status' => 'running' ],
			'operation.settings_manage.completed' => [ 'label' => 'Settings operation completed', 'status' => 'completed' ],
			'operation.settings_manage.failed' => [ 'label' => 'Settings operation failed', 'status' => 'failed' ],
			'search.search_all'          => [ 'label' => 'Site-wide search performed', 'status' => 'completed' ],
			'search.search_content'      => [ 'label' => 'Content searched', 'status' => 'completed' ],
			'search.search_media'        => [ 'label' => 'Media searched', 'status' => 'completed' ],
			'search.search_users'        => [ 'label' => 'Users searched', 'status' => 'completed' ],
			'search.report_orphans'      => [ 'label' => 'Orphan content check', 'status' => 'completed' ],
			'search.report_site_summary' => [ 'label' => 'Site summary generated', 'status' => 'completed' ],
			'operation.search_manage.started'   => [ 'label' => 'Search operation started', 'status' => 'running' ],
			'operation.search_manage.completed' => [ 'label' => 'Search operation completed', 'status' => 'completed' ],
			'operation.search_manage.failed'    => [ 'label' => 'Search operation failed', 'status' => 'failed' ],
			'operation.bulk_manage.started'     => [ 'label' => 'Bulk operation started', 'status' => 'running' ],
			'operation.bulk_manage.completed'   => [ 'label' => 'Bulk operation completed', 'status' => 'completed' ],
			'operation.bulk_manage.failed'      => [ 'label' => 'Bulk operation failed', 'status' => 'failed' ],
			'bulk.bulk_content'                 => [ 'label' => 'Bulk content updated', 'status' => 'completed' ],
			'bulk.bulk_publish'                 => [ 'label' => 'Bulk publish completed', 'status' => 'completed' ],
			'bulk.bulk_unpublish'               => [ 'label' => 'Bulk unpublish completed', 'status' => 'completed' ],
			'bulk.bulk_media'                   => [ 'label' => 'Bulk media updated', 'status' => 'completed' ],
			'bulk.bulk_woocommerce'             => [ 'label' => 'Bulk WooCommerce action', 'status' => 'completed' ],
			'bulk.bulk_acf'                     => [ 'label' => 'Bulk ACF updated', 'status' => 'completed' ],
			'bulk.batch_execute'                => [ 'label' => 'Batch execute completed', 'status' => 'completed' ],
			'workflow.list'           => [ 'label' => 'Workflows listed', 'status' => 'completed' ],
			'workflow.get'            => [ 'label' => 'Workflow retrieved', 'status' => 'completed' ],
			'workflow.create'         => [ 'label' => 'Workflow created', 'status' => 'completed' ],
			'workflow.update'         => [ 'label' => 'Workflow updated', 'status' => 'completed' ],
			'workflow.delete'         => [ 'label' => 'Workflow deleted', 'status' => 'completed' ],
			'workflow.execute'        => [ 'label' => 'Workflow executed', 'status' => 'completed' ],
			'workflow.import'         => [ 'label' => 'Workflow imported', 'status' => 'completed' ],
			'workflow.export'         => [ 'label' => 'Workflow exported', 'status' => 'completed' ],
			'workflow.history'        => [ 'label' => 'Workflow history viewed', 'status' => 'completed' ],
			'operation.workflow_manage.started'   => [ 'label' => 'Workflow operation started', 'status' => 'running' ],
			'operation.workflow_manage.completed' => [ 'label' => 'Workflow operation completed', 'status' => 'completed' ],
			'operation.workflow_manage.failed'    => [ 'label' => 'Workflow operation failed', 'status' => 'failed' ],
			'comment.list'              => [ 'label' => 'Comments listed', 'status' => 'completed' ],
			'comment.get'               => [ 'label' => 'Comment retrieved', 'status' => 'completed' ],
			'comment.approved'          => [ 'label' => 'Comment approved', 'status' => 'completed' ],
			'comment.unapproved'        => [ 'label' => 'Comment unapproved', 'status' => 'completed' ],
			'comment.spammed'           => [ 'label' => 'Comment marked spam', 'status' => 'completed' ],
			'comment.trashed'           => [ 'label' => 'Comment trashed', 'status' => 'completed' ],
			'comment.deleted'           => [ 'label' => 'Comment deleted', 'status' => 'completed' ],
			'comment.replied'           => [ 'label' => 'Comment replied', 'status' => 'completed' ],
			'comment.rollback.applied'  => [ 'label' => 'Comment rollback applied', 'status' => 'completed' ],
			'operation.comments_manage.started'   => [ 'label' => 'Comments operation started', 'status' => 'running' ],
			'operation.comments_manage.completed' => [ 'label' => 'Comments operation completed', 'status' => 'completed' ],
			'operation.comments_manage.failed'    => [ 'label' => 'Comments operation failed', 'status' => 'failed' ],
			'widgets.list'             => [ 'label' => 'Widgets listed', 'status' => 'completed' ],
			'widgets.get'              => [ 'label' => 'Widget retrieved', 'status' => 'completed' ],
			'widgets.added'            => [ 'label' => 'Widget added', 'status' => 'completed' ],
			'widgets.updated'          => [ 'label' => 'Widget updated', 'status' => 'completed' ],
			'widgets.removed'          => [ 'label' => 'Widget removed', 'status' => 'completed' ],
			'widgets.sidebar_assigned' => [ 'label' => 'Sidebar assigned', 'status' => 'completed' ],
			'widgets.sidebar_removed'  => [ 'label' => 'Sidebar removed', 'status' => 'completed' ],
			'widgets.rollback.applied' => [ 'label' => 'Widgets rollback applied', 'status' => 'completed' ],
			'operation.widgets_manage.started'   => [ 'label' => 'Widgets operation started', 'status' => 'running' ],
			'operation.widgets_manage.completed' => [ 'label' => 'Widgets operation completed', 'status' => 'completed' ],
			'operation.widgets_manage.failed'    => [ 'label' => 'Widgets operation failed', 'status' => 'failed' ],
			'cpt.list'               => [ 'label' => 'CPTs listed', 'status' => 'completed' ],
			'cpt.get'                => [ 'label' => 'CPT retrieved', 'status' => 'completed' ],
			'cpt.created'            => [ 'label' => 'CPT created', 'status' => 'completed' ],
			'cpt.updated'            => [ 'label' => 'CPT updated', 'status' => 'completed' ],
			'cpt.disabled'           => [ 'label' => 'CPT disabled', 'status' => 'completed' ],
			'cpt.taxonomy_list'      => [ 'label' => 'Taxonomies listed', 'status' => 'completed' ],
			'cpt.taxonomy_created'   => [ 'label' => 'Taxonomy created', 'status' => 'completed' ],
			'cpt.taxonomy_updated'   => [ 'label' => 'Taxonomy updated', 'status' => 'completed' ],
			'cpt.rollback.applied'   => [ 'label' => 'CPT rollback applied', 'status' => 'completed' ],
			'operation.cpt_manage.started'   => [ 'label' => 'CPT operation started', 'status' => 'running' ],
			'operation.cpt_manage.completed' => [ 'label' => 'CPT operation completed', 'status' => 'completed' ],
			'operation.cpt_manage.failed'    => [ 'label' => 'CPT operation failed', 'status' => 'failed' ],
			];

		if ( ! isset( $map[ $action ] ) ) {
			return null;
		}

		return [
			'timestamp'  => $entry['timestamp'],
			'type'       => $type,
			'label'      => $map[ $action ]['label'],
			'status'     => $map[ $action ]['status'],
			'actor'      => $context['actor'] ?? null,
			'session_id' => $context['session_id'] ?? null,
			'task_id'    => $context['task_id'] ?? null,
			'action_id'  => $context['action_id'] ?? null,
			'plan_id'    => $context['plan_id'] ?? null,
			'patch_id'   => $context['patch_id'] ?? null,
			'summary'    => $this->build_summary( $action, $context ),
		];
	}

	private function build_summary( string $action, array $context ): string {
		switch ( $action ) {
			case 'session.created':
				return sprintf( 'Source: %s, Label: %s', $context['source'] ?? 'unknown', $context['label'] ?? '' );
			case 'task.created':
				return $context['user_prompt'] ?? '';
			case 'action.created':
				return sprintf( '[%s] %s', $context['type'] ?? 'unknown', $context['title'] ?? '' );
			case 'recommendation.created':
			case 'recommendation.updated':
				return sprintf( '[%s/%s] %s', $context['type'] ?? 'unknown', $context['severity'] ?? 'unknown', $context['title'] ?? '' );
			case 'recommendation.dismissed':
			case 'recommendation.resolved':
				return sprintf( '%s: %s', ucfirst( $context['status'] ?? 'updated' ), $context['title'] ?? '' );
			case 'recommendation.converted_to_action':
				return sprintf( '%s -> Action %s', $context['title'] ?? 'Recommendation', $context['action_id'] ?? 'unknown' );
			case 'recommendation.action_created':
				return sprintf( '%s -> Action %s', $context['title'] ?? 'Recommendation', $context['action_id'] ?? 'unknown' );
			case 'recommendation.plan_created':
				return sprintf( '%s -> Plan %s', $context['title'] ?? 'Recommendation', $context['plan_id'] ?? 'unknown' );
			case 'recommendation.approved':
				return sprintf( 'Approved plan %s for %s', $context['plan_id'] ?? 'unknown', $context['title'] ?? 'recommendation' );
			case 'recommendation.executing':
				return sprintf( 'Executing plan %s for %s', $context['plan_id'] ?? 'unknown', $context['title'] ?? 'recommendation' );
			case 'health.verification.started':
				return sprintf( 'Verification %s started', $context['verification_id'] ?? 'unknown' );
			case 'health.verification.completed':
			case 'health.verification.failed':
				$summary = $context['summary'] ?? [];
				return sprintf( 'Verification %s: %d passed, %d warnings, %d failed', $context['verification_id'] ?? 'unknown', $summary['passed'] ?? 0, $summary['warnings'] ?? 0, $summary['failed'] ?? 0 );
			case 'system.environment.updated':
				return sprintf( 'Environment changed from %s to %s', $context['previous_mode'] ?? 'unknown', $context['mode'] ?? 'unknown' );
			case 'system.cleanup.started':
				return sprintf( '%s cleanup started for records older than %d days', empty( $context['dry_run'] ) ? 'Live' : 'Dry-run', $context['older_than_days'] ?? 0 );
			case 'system.cleanup.completed':
				return sprintf( '%s cleanup completed', empty( $context['dry_run'] ) ? 'Live' : 'Dry-run' );
			case 'system.cleanup.blocked':
				return sprintf( 'Cleanup blocked: %s', $context['reason'] ?? 'safety policy' );
			case 'plan.created':
				return sprintf( '%s: %s', $context['title'] ?? '', $context['objective'] ?? '' );
			case 'patch.created':
				return sprintf( 'Modified %d files. Risk: %s', count( $context['files'] ?? [] ), $context['risk_level'] ?? 'low' );
			case 'task.status_updated':
				return sprintf( 'Status changed from %s to %s', $context['previous_status'] ?? 'unknown', $context['status'] ?? 'unknown' );
			case 'patch.applied':
				return sprintf( 'Applied to: %s', implode( ', ', array_keys( $context['snapshot_ids'] ?? [] ) ) );
			case 'patch.rolled_back':
				return sprintf( 'Restored: %s', implode( ', ', array_keys( $context['results'] ?? [] ) ) );
			case 'operation.content_seed.started':
				return sprintf( 'Started: %d %s(s)', $context['params']['count'] ?? 0, $context['params']['type'] ?? 'unknown' );
			case 'operation.content_seed.completed':
				return sprintf( 'Created %d %s(s)', $context['result']['count'] ?? 0, $context['result']['type'] ?? 'unknown' );
			case 'operation.content_seed.failed':
				return sprintf( 'Failed: %s', $context['error_code'] ?? 'unknown' );
			case 'operation.acf_seed.started':
				return sprintf( 'Post ID: %d', $context['params']['post_id'] ?? $context['post_id'] ?? 0 );
			case 'operation.acf_seed.completed':
				$res = $context['result'] ?? $context;
				return sprintf( 'Updated %d field(s) on post %d', $res['field_count'] ?? 0, $res['post_id'] ?? 0 );
			case 'operation.acf_seed.failed':
				return sprintf( 'Failed for post %d: %s', $context['post_id'] ?? 0, $context['error_code'] ?? 'unknown' );
			case 'operation.cf7_seed.started':
				return sprintf( 'Template: %s', $context['template'] ?? 'unknown' );
			case 'operation.cf7_seed.completed':
				return sprintf( 'Created form ID: %d', $context['id'] ?? 0 );
			case 'operation.cf7_seed.failed':
				return sprintf( 'Failed: %s', $context['error_code'] ?? 'unknown' );
			case 'operation.woo_product_seed.started':
				return sprintf( 'Product: %s (SKU: %s)', $context['product_name'] ?? 'unknown', $context['sku'] ?? 'N/A' );
			case 'operation.woo_product_seed.completed':
				return sprintf( "Created WooCommerce product '%s' (SKU: %s)", $context['product_name'] ?? 'unknown', $context['sku'] ?? 'N/A' );
			case 'operation.woo_product_seed.failed':
				return sprintf( 'Failed for %s: %s', $context['product_name'] ?? 'unknown', $context['error_code'] ?? 'unknown' );
			case 'operation.safe_search_replace.started':
				return sprintf( 'Mode: %s', empty( $context['params']['dry_run'] ) ? 'Live' : 'Dry Run' );
			case 'operation.safe_search_replace.completed':
				$res = $context['result'] ?? $context;
				$mode = empty( $res['dry_run'] ) ? 'Live' : 'Dry Run';
				return sprintf( '%s: %d matches, %d rows affected', $mode, $res['matches_found'] ?? 0, $res['rows_affected'] ?? 0 );
			case 'operation.safe_search_replace.failed':
				return sprintf( 'Failed: %s', $context['error_code'] ?? 'unknown' );
			case 'operation.media_import.started':
				return sprintf( 'Source: %s', $context['params']['source_url'] ?? 'unknown' );
			case 'operation.media_import.completed':
				$res = $context['result'] ?? $context;
				return sprintf( 'Imported media attachment ID %s', $res['id'] ?? 'unknown' );
			case 'operation.media_import.failed':
				return sprintf( 'Failed to import media: %s', $context['error_code'] ?? 'unknown' );
			case 'operation.safe_updates.started':
				return sprintf( 'Updating %s: %s', $context['params']['type'] ?? 'unknown', $context['params']['slug'] ?? 'unknown' );
			case 'operation.safe_updates.completed':
				$res = $context['result'] ?? $context;
				$mode = empty( $res['dry_run'] ) ? 'Updated' : 'Dry run update for';
				return sprintf( '%s %s %s from %s to %s', $mode, $res['type'] ?? 'unknown', $res['slug'] ?? 'unknown', $res['before_version'] ?? 'unknown', $res['after_version'] ?? 'unknown' );
			case 'operation.safe_updates.failed':
				return sprintf( 'Failed to update: %s', $context['error_code'] ?? 'unknown' );
			case 'operation.wp_cli_bridge.started':
			case 'operation.wp_cli_bridge.completed':
				$cmd = $context['params']['command_id'] ?? $context['params']['command'] ?? 'unknown';
				return sprintf( 'Ran WP-CLI command: %s', $cmd );
			case 'operation.wp_cli_bridge.failed':
				return sprintf( 'WP-CLI failed: %s', $context['error_code'] ?? 'unknown' );
			case 'operation.wp_cli_bridge.blocked':
				$cmd = $context['command_id'] ?? 'unknown';
				return sprintf( 'WP-CLI command blocked: %s', $cmd );
			case 'operation.wp_cli_bridge.denied':
				$cmd = $context['command_id'] ?? 'unknown';
				return sprintf( 'WP-CLI command denied: %s', $cmd );
			case 'option.read':
				$opt_id = $context['option_id'] ?? $context['params']['option_id'] ?? 'unknown';
				return sprintf( 'Read option: %s', $opt_id );
			case 'option.update.started':
				$opt_id = $context['option_id'] ?? $context['params']['option_id'] ?? 'unknown';
				return sprintf( 'Updating option: %s', $opt_id );
			case 'option.update.completed':
				$opt_id = $context['option_id'] ?? $context['params']['option_id'] ?? 'unknown';
				$old    = $context['old_value'] ?? $context['result']['old_value'] ?? '';
				$new    = $context['new_value'] ?? $context['result']['new_value'] ?? '';
				if ( is_scalar( $old ) && is_scalar( $new ) ) {
					return sprintf( 'Updated %s from "%s" to "%s"', $opt_id, $old, $new );
				}
				return sprintf( 'Updated option: %s', $opt_id );
			case 'option.update.failed':
				return sprintf( 'Option update failed: %s', $context['error_code'] ?? 'unknown' );
			case 'option.update.rolled_back':
				$opt_id = $context['option_id'] ?? $context['params']['option_id'] ?? 'unknown';
				return sprintf( 'Rolled back option: %s', $opt_id );
			case 'operation.option_manage.started':
				$action = $context['params']['action'] ?? 'unknown';
				return sprintf( 'Option %s started', $action );
			case 'operation.option_manage.completed':
				$action = $context['params']['action'] ?? 'unknown';
				return sprintf( 'Option %s completed', $action );
			case 'operation.option_manage.failed':
				return sprintf( 'Option management failed: %s', $context['error_code'] ?? 'unknown' );
			case 'plugin.list':
				return 'Listed installed plugins';
			case 'plugin.install.started':
				return sprintf( 'Installing plugin: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'plugin.install.completed':
				return sprintf( 'Installed plugin: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'plugin.install.failed':
				return sprintf( 'Plugin install failed: %s', $context['error'] ?? $context['error_code'] ?? 'unknown' );
			case 'plugin.activate':
				return sprintf( 'Activated plugin: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'plugin.deactivate':
				return sprintf( 'Deactivated plugin: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'plugin.update.started':
				return sprintf( 'Updating plugin: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'plugin.update':
				$slug = $context['slug'] ?? $context['params']['slug'] ?? 'unknown';
				$old  = $context['old_version'] ?? '';
				$new  = $context['new_version'] ?? '';
				if ( $old && $new ) {
					return sprintf( 'Updated %s from %s to %s', $slug, $old, $new );
				}
				return sprintf( 'Updated plugin: %s', $slug );
			case 'plugin.update.failed':
				return sprintf( 'Plugin update failed: %s', $context['error'] ?? $context['error_code'] ?? 'unknown' );
			case 'plugin.delete':
				return sprintf( 'Deleted plugin: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'plugin.delete.failed':
				return sprintf( 'Plugin delete failed: %s', $context['error'] ?? $context['error_code'] ?? 'unknown' );
			case 'plugin.health.warning':
				return sprintf( 'Health warning after plugin operation on: %s', $context['slug'] ?? 'unknown' );
			case 'operation.plugin_manage.started':
				return sprintf( 'Plugin %s started', $context['params']['action'] ?? 'unknown' );
			case 'operation.plugin_manage.completed':
				return sprintf( 'Plugin %s completed', $context['params']['action'] ?? 'unknown' );
			case 'operation.plugin_manage.failed':
				return sprintf( 'Plugin management failed: %s', $context['error_code'] ?? 'unknown' );
			case 'theme.list':
				return 'Listed installed themes';
			case 'theme.install':
				return sprintf( 'Installed theme: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'theme.activate.started':
				return sprintf( 'Activating theme: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'theme.activate':
				$slug = $context['slug'] ?? $context['params']['slug'] ?? 'unknown';
				$prev = $context['previous_name'] ?? $context['previous_slug'] ?? '';
				if ( $prev ) {
					return sprintf( 'Activated %s (was %s)', $slug, $prev );
				}
				return sprintf( 'Activated theme: %s', $slug );
			case 'theme.update':
				$slug = $context['slug'] ?? $context['params']['slug'] ?? 'unknown';
				$old  = $context['old_version'] ?? '';
				$new  = $context['new_version'] ?? '';
				return $old && $new ? sprintf( 'Updated %s from %s to %s', $slug, $old, $new ) : sprintf( 'Updated theme: %s', $slug );
			case 'theme.delete':
				return sprintf( 'Deleted theme: %s', $context['slug'] ?? $context['params']['slug'] ?? 'unknown' );
			case 'operation.theme_manage.started':
				return sprintf( 'Theme %s started', $context['params']['action'] ?? 'unknown' );
			case 'operation.theme_manage.completed':
				return sprintf( 'Theme %s completed', $context['params']['action'] ?? 'unknown' );
			case 'operation.theme_manage.failed':
				return sprintf( 'Theme management failed: %s', $context['error_code'] ?? 'unknown' );
			case 'snapshot.create':
				$label = $context['label'] ?? $context['params']['label'] ?? '';
				return $label ? sprintf( 'Created snapshot: %s', $label ) : 'Created snapshot';
			case 'snapshot.create.started':
				return sprintf( 'Creating snapshot: %s', $context['params']['path'] ?? 'unknown' );
			case 'snapshot.list':
				return 'Listed snapshots';
			case 'snapshot.verify':
				$sid = $context['snapshot_id'] ?? $context['params']['snapshot_id'] ?? 'unknown';
				$ok  = $context['valid'] ?? 'unknown';
				return sprintf( 'Verified snapshot %s: %s', $sid, $ok ? 'valid' : 'invalid' );
			case 'snapshot.restore.started':
				return sprintf( 'Restoring snapshot: %s', $context['snapshot_id'] ?? $context['params']['snapshot_id'] ?? 'unknown' );
			case 'snapshot.restore.completed':
				return sprintf( 'Restored snapshot: %s', $context['snapshot_id'] ?? $context['params']['snapshot_id'] ?? 'unknown' );
			case 'snapshot.restore.failed':
				return sprintf( 'Snapshot restore failed: %s', $context['error'] ?? 'unknown' );
			case 'operation.snapshot_manage.started':
				return sprintf( 'Snapshot %s started', $context['params']['action'] ?? 'unknown' );
			case 'operation.snapshot_manage.completed':
				return sprintf( 'Snapshot %s completed', $context['params']['action'] ?? 'unknown' );
			case 'operation.snapshot_manage.failed':
				return sprintf( 'Snapshot management failed: %s', $context['error_code'] ?? 'unknown' );
			case 'content.create':
				return sprintf( 'Created %s: %s', $context['type'] ?? 'post', $context['title'] ?? 'untitled' );
			case 'content.update':
				return sprintf( 'Updated content #%d', $context['content_id'] ?? 0 );
			case 'content.delete':
				return sprintf( 'Trashed content: %s', $context['title'] ?? 'unknown' );
			case 'content.publish':
				return sprintf( 'Published content #%d', $context['content_id'] ?? 0 );
			case 'content.unpublish':
				return sprintf( 'Unpublished content #%d', $context['content_id'] ?? 0 );
			case 'content.schedule':
				return sprintf( 'Scheduled content #%d for %s', $context['content_id'] ?? 0, $context['publish_at'] ?? 'unknown' );
			case 'content.list':
				return 'Listed content';
			case 'taxonomy.assign':
				return sprintf( 'Assigned %s to content #%d', $context['taxonomy'] ?? 'terms', $context['content_id'] ?? 0 );
			case 'featured_image.assign':
				return sprintf( 'Assigned featured image #%d to content #%d', $context['attachment_id'] ?? 0, $context['content_id'] ?? 0 );
			case 'operation.content_manage.started':
				return sprintf( 'Content %s started', $context['params']['action'] ?? 'unknown' );
			case 'operation.content_manage.completed':
				return sprintf( 'Content %s completed', $context['params']['action'] ?? 'unknown' );
			case 'operation.content_manage.failed':
				return sprintf( 'Content management failed: %s', $context['error_code'] ?? 'unknown' );
			case 'database.inspect.started':
				return sprintf( 'Inspecting database: %s', $context['action'] ?? 'unknown' );
			case 'database.inspect.completed':
				return sprintf( 'Database inspection complete: %s (%dms)', $context['action'] ?? 'unknown', $context['duration_ms'] ?? 0 );
			case 'database.inspect.failed':
				return sprintf( 'Database inspection failed: %s', $context['error'] ?? 'unknown' );
			case 'operation.database_inspect.started':
			case 'operation.database_inspect.completed':
			case 'operation.database_inspect.failed':
				return sprintf( 'DB inspection %s', $context['params']['action'] ?? 'unknown' );
			case 'operation.request.created':
				return sprintf( 'Request: %s (Risk: %s)', $context['operation_id'] ?? 'unknown', $context['risk_level'] ?? 'medium' );
			case 'operation.request.approved':
				return sprintf( 'Approved request for %s', $context['operation_id'] ?? 'unknown' );
			case 'operation.request.rejected':
				return sprintf( 'Rejected request for %s', $context['operation_id'] ?? 'unknown' );
			case 'operation.request.executed':
				return sprintf( 'Executed request for %s', $context['operation_id'] ?? 'unknown' );
			case 'operation.request.failed':
				return sprintf( 'Request execution failed for %s: %s', $context['operation_id'] ?? 'unknown', $context['error'] ?? 'unknown' );
			case 'operation.execution.started':
				return sprintf( 'Executing %s...', $context['operation_id'] ?? 'unknown' );
			case 'operation.execution.completed':
				return sprintf( 'Successfully executed %s', $context['operation_id'] ?? 'unknown' );
			case 'operation.execution.failed':
				return sprintf( 'Execution failed for %s: %s', $context['operation_id'] ?? 'unknown', $context['error_code'] ?? 'unknown' );
			case 'operation.queue.created':
				return sprintf( 'Queued %s (ID: %s)', $context['operation_id'] ?? 'unknown', $context['queue_id'] ?? 'unknown' );
			case 'operation.queue.running':
				return sprintf( 'Running queued %s', $context['operation_id'] ?? 'unknown' );
			case 'operation.queue.completed':
				return sprintf( 'Completed queued %s', $context['operation_id'] ?? 'unknown' );
			case 'operation.queue.failed':
				return sprintf( 'Queue failure for %s: %s', $context['operation_id'] ?? 'unknown', $context['error'] ?? 'unknown' );
			case 'operation.queue.cancelled':
				return sprintf( 'Cancelled queue item %s', $context['queue_id'] ?? 'unknown' );
			case 'operation.queue.retry_requested':
				return sprintf( 'Retry requested for %s', $context['operation_id'] ?? 'unknown' );
			case 'operation.queue.retry_queued':
				return sprintf( 'Retry queued for %s', $context['operation_id'] ?? 'unknown' );
			case 'operation.queue.retry_failed':
				return sprintf( 'Retry failed for %s: %s', $context['operation_id'] ?? 'unknown', $context['error'] ?? 'unknown' );
			case 'operation.worker.started':
				return sprintf( 'Worker started (limit: %d)', $context['limit'] ?? 0 );
			case 'operation.worker.completed':
				return sprintf( 'Worker processed: %d, locked: %d', $context['processed'] ?? 0, $context['locked'] ?? 0 );
			case 'operation.worker.failed':
				return sprintf( 'Worker failed: %s', $context['error'] ?? 'unknown' );
			case 'operation.worker.locked':
				return sprintf( 'Worker locked queue item: %s', $context['queue_id'] ?? 'unknown' );
			case 'operation.result.created':
				return sprintf( 'Result ID: %s (%s)', $context['result_id'] ?? 'unknown', $context['operation_id'] ?? 'unknown' );
			case 'operation.result.completed':
				return sprintf( 'Finished %s: Result %s', $context['operation_id'] ?? 'unknown', $context['result_id'] ?? 'unknown' );
			case 'operation.result.failed':
				return sprintf( 'Failed %s: Result %s', $context['operation_id'] ?? 'unknown', $context['result_id'] ?? 'unknown' );
			case 'workflow.list':
				return 'Workflows listed';
			case 'workflow.create':
				return 'Workflow created';
			case 'workflow.delete':
				return 'Workflow deleted';
			case 'workflow.execute':
				return 'Workflow execution completed';
			case 'workflow.import':
				return 'Workflow imported';
			case 'workflow.history':
				return 'Workflow history viewed';
			case 'operation.workflow_manage.started':
				return sprintf( 'Workflow %s started', $context['params']['action'] ?? 'unknown' );
			case 'operation.workflow_manage.completed':
				return sprintf( 'Workflow %s completed', $context['params']['action'] ?? $context['action'] ?? 'unknown' );
			case 'operation.workflow_manage.failed':
				return sprintf( 'Workflow operation failed: %s', $context['error_code'] ?? 'unknown' );
			default:
				return '';
		}
	}

	/**
	 * Query the database tables for core lifecycle creation/timestamp points
	 * to ensure the timeline is complete even if logs are missing.
	 */
	private function get_db_baseline_events(): array {
		global $wpdb;
		$events = [];
		$limit  = self::BASELINE_LIMIT;

		// Sessions — newest rows by primary key, restored to ascending id order so the
		// result is byte-identical to the previous unordered full scan whenever the table
		// is within the cap (InnoDB full scans return clustered-index / id-ascending order).
		$sessions = $wpdb->get_results(
			"SELECT session_id, label, status, source, created_at FROM (
				SELECT id, session_id, label, status, source, created_at
				FROM {$wpdb->prefix}wpcc_agent_sessions ORDER BY id DESC LIMIT {$limit}
			) t ORDER BY id ASC",
			ARRAY_A
		);
		foreach ( $sessions ?: [] as $s ) {
			$events[] = [
				'timestamp'  => (int) $s['created_at'],
				'type'       => 'session',
				'label'      => 'Session created',
				'status'     => $s['status'],
				'actor'      => null,
				'session_id' => $s['session_id'],
				'task_id'    => null,
				'action_id'  => null,
				'plan_id'    => null,
				'patch_id'   => null,
				'summary'    => sprintf( 'Source: %s, Label: %s', $s['source'], $s['label'] ),
			];
		}

		// Tasks — same bounded/ordered pattern as sessions.
		$tasks = $wpdb->get_results(
			"SELECT task_id, session_id, user_prompt, status, created_at FROM (
				SELECT id, task_id, session_id, user_prompt, status, created_at
				FROM {$wpdb->prefix}wpcc_agent_tasks ORDER BY id DESC LIMIT {$limit}
			) t ORDER BY id ASC",
			ARRAY_A
		);
		foreach ( $tasks ?: [] as $t ) {
			$events[] = [
				'timestamp'  => (int) $t['created_at'],
				'type'       => 'task',
				'label'      => 'Task created',
				'status'     => $t['status'],
				'actor'      => null,
				'session_id' => $t['session_id'],
				'task_id'    => $t['task_id'],
				'action_id'  => null,
				'plan_id'    => null,
				'patch_id'   => null,
				'summary'    => $t['user_prompt'],
			];
		}

		// Patches (created, approved, applied, rolled back) — explicit column list (no
		// SELECT *; only these columns are consumed below), bounded and ordered as above.
		$patches = $wpdb->get_results(
			"SELECT patch_id, source, risk_level, created_at, approved_at, applied_at, rolled_back_at, session_id, task_id, plan_id FROM (
				SELECT id, patch_id, source, risk_level, created_at, approved_at, applied_at, rolled_back_at, session_id, task_id, plan_id
				FROM {$wpdb->prefix}wpcc_patches ORDER BY id DESC LIMIT {$limit}
			) t ORDER BY id ASC",
			ARRAY_A
		);
		foreach ( $patches ?: [] as $p ) {
			$base = [
				'session_id' => $p['session_id'],
				'task_id'    => $p['task_id'],
				'action_id'  => null,
				'plan_id'    => $p['plan_id'],
				'patch_id'   => $p['patch_id'],
				'actor'      => null,
			];

			$events[] = array_merge( $base, [
				'timestamp' => (int) $p['created_at'],
				'type'      => 'patch',
				'label'     => 'Patch created',
				'status'    => 'pending_approval',
				'summary'   => sprintf( 'Source: %s, Risk: %s', $p['source'], $p['risk_level'] ),
			] );

			if ( $p['approved_at'] ) {
				$events[] = array_merge( $base, [
					'timestamp' => (int) $p['approved_at'],
					'type'      => 'patch',
					'label'     => 'Patch approved',
					'status'    => 'approved',
					'summary'   => '',
				] );
			}

			if ( $p['applied_at'] ) {
				$events[] = array_merge( $base, [
					'timestamp' => (int) $p['applied_at'],
					'type'      => 'patch',
					'label'     => 'Patch applied',
					'status'    => 'applied',
					'summary'   => '',
				] );
			}

			if ( $p['rolled_back_at'] ) {
				$events[] = array_merge( $base, [
					'timestamp' => (int) $p['rolled_back_at'],
					'type'      => 'patch',
					'label'     => 'Patch rolled back',
					'status'    => 'rolled_back',
					'summary'   => '',
				] );
			}
		}

		// Plans and Actions can be added similarly, but Audit Log is usually reliable for them.

		return $events;
	}

	/**
	 * Stable identity key for duplicate detection: event type + label + every id field.
	 *
	 * Two events can only be duplicates if these all match exactly, so events sharing a key
	 * are the only pairs the timestamp-window test in is_duplicate() ever needs to compare.
	 * null ids are encoded distinctly from the empty string and the unit-separator delimiter
	 * keeps the parts unambiguous, so this matches the previous strict (!==) per-field
	 * comparison exactly (all id fields are string|null and type/label are fixed-vocabulary).
	 */
	private function event_identity_key( array $event ): string {
		$parts = [ (string) $event['type'], (string) $event['label'] ];
		foreach ( [ 'session_id', 'task_id', 'action_id', 'plan_id', 'patch_id' ] as $id_key ) {
			$value   = $event[ $id_key ] ?? null;
			$parts[] = null === $value ? "\0NULL" : (string) $value;
		}
		return implode( "\x1f", $parts );
	}

	/**
	 * Whether $event duplicates an already-collected event of the same identity key.
	 *
	 * Identity (type + label + all id fields) is established by the caller bucketing on
	 * event_identity_key(); this only applies the 5-second timestamp window (compensating
	 * for time() drift between the audit log and the DB rows) against the timestamps already
	 * seen for that key. Behaviour-identical to the previous full-list scan.
	 *
	 * @param array<int,int> $existing_timestamps Timestamps already collected for this event's identity key.
	 */
	private function is_duplicate( array $event, array $existing_timestamps ): bool {
		$timestamp = (int) $event['timestamp'];
		foreach ( $existing_timestamps as $existing_timestamp ) {
			if ( abs( $timestamp - $existing_timestamp ) <= 5 ) {
				return true;
			}
		}
		return false;
	}
}
