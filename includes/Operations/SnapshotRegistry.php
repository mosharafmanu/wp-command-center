<?php
/**
 * Step 41 — Snapshot Registry.
 *
 * Exposes the existing Snapshot/Rollback Engines as structured operations.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SnapshotRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_CRITICAL = 'critical';

	const ACTION_CREATE  = 'snapshot_create';
	const ACTION_LIST    = 'snapshot_list';
	const ACTION_DETAILS = 'snapshot_details';
	const ACTION_RESTORE = 'snapshot_restore';
	const ACTION_VERIFY  = 'snapshot_verify';

	const ACTIONS = [ 'snapshot_create', 'snapshot_list', 'snapshot_details', 'snapshot_restore', 'snapshot_verify' ];

	public function action_risk( string $action ): string {
		return match ( $action ) {
			self::ACTION_LIST, self::ACTION_DETAILS => self::RISK_LOW,
			self::ACTION_CREATE, self::ACTION_VERIFY => self::RISK_MEDIUM,
			self::ACTION_RESTORE => self::RISK_CRITICAL,
			default => self::RISK_MEDIUM,
		};
	}

	public function requires_approval( string $action ): bool {
		return self::ACTION_RESTORE === $action;
	}

	public function requires_health_check( string $action ): bool {
		return self::ACTION_RESTORE === $action;
	}
}
