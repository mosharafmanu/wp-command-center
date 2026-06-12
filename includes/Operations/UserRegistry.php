<?php
/**
 * Step 61 — User Management Registry.
 * Defines supported user operations, risk levels, approval requirements,
 * and rollback support metadata.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class UserRegistry {

	const RISK_LOW      = 'low';
	const RISK_MEDIUM   = 'medium';
	const RISK_HIGH     = 'high';
	const RISK_CRITICAL = 'critical';

	const RISK_LEVELS = [ 'low', 'medium', 'high', 'critical' ];

	const ACTION_LIST       = 'user_list';
	const ACTION_GET        = 'user_get';
	const ACTION_SEARCH     = 'user_search';
	const ACTION_CREATE     = 'user_create';
	const ACTION_UPDATE     = 'user_update';
	const ACTION_DELETE     = 'user_delete';
	const ACTION_SUSPEND    = 'user_suspend';
	const ACTION_RESET_PASSWORD = 'user_reset_password';
	const ACTION_ASSIGN_ROLE    = 'user_assign_role';
	const ACTION_REMOVE_ROLE    = 'user_remove_role';

	const ACTIONS = [
		'user_list',
		'user_get',
		'user_search',
		'user_create',
		'user_update',
		'user_delete',
		'user_suspend',
		'user_reset_password',
		'user_assign_role',
		'user_remove_role',
	];

	const ACTION_RISK = [
		self::ACTION_LIST            => self::RISK_LOW,
		self::ACTION_GET             => self::RISK_LOW,
		self::ACTION_SEARCH          => self::RISK_LOW,
		self::ACTION_UPDATE          => self::RISK_MEDIUM,
		self::ACTION_ASSIGN_ROLE     => self::RISK_MEDIUM,
		self::ACTION_REMOVE_ROLE     => self::RISK_MEDIUM,
		self::ACTION_CREATE          => self::RISK_HIGH,
		self::ACTION_DELETE          => self::RISK_HIGH,
		self::ACTION_RESET_PASSWORD  => self::RISK_HIGH,
		self::ACTION_SUSPEND         => self::RISK_HIGH,
	];

	const ACTION_APPROVAL = [
		self::ACTION_LIST            => false,
		self::ACTION_GET             => false,
		self::ACTION_SEARCH          => false,
		self::ACTION_UPDATE          => true,
		self::ACTION_ASSIGN_ROLE     => true,
		self::ACTION_REMOVE_ROLE     => true,
		self::ACTION_CREATE          => true,
		self::ACTION_DELETE          => true,
		self::ACTION_RESET_PASSWORD  => true,
		self::ACTION_SUSPEND         => true,
	];

	const ACTION_ROLLBACK = [
		self::ACTION_CREATE          => true,
		self::ACTION_DELETE          => true,
		self::ACTION_UPDATE          => true,
		self::ACTION_ASSIGN_ROLE     => true,
		self::ACTION_REMOVE_ROLE     => true,
		self::ACTION_SUSPEND         => true,
		self::ACTION_RESET_PASSWORD  => false,
	];

	public static function get_risk( string $action ): string {
		return self::ACTION_RISK[ $action ] ?? self::RISK_MEDIUM;
	}

	public static function requires_approval( string $action ): bool {
		return self::ACTION_APPROVAL[ $action ] ?? true;
	}

	public static function supports_rollback( string $action ): bool {
		return self::ACTION_ROLLBACK[ $action ] ?? false;
	}
}
