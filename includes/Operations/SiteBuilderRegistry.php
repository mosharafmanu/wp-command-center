<?php
/**
 * STEP 95 — Site Builder registry: actions, risk, rollback support.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SiteBuilderRegistry {

	const RISK_DIAGNOSTIC = 'diagnostic';
	const RISK_MEDIUM     = 'medium';

	// Pages
	const ACTION_PAGE_LIST   = 'page_list';
	const ACTION_PAGE_GET    = 'page_get';
	const ACTION_PAGE_CREATE = 'page_create';
	const ACTION_PAGE_UPDATE = 'page_update';
	const ACTION_PAGE_DELETE = 'page_delete';
	// Templates
	const ACTION_TEMPLATE_LIST   = 'template_list';
	const ACTION_TEMPLATE_ASSIGN = 'template_assign';
	// Block patterns / reusable blocks
	const ACTION_PATTERN_CREATE = 'pattern_create';
	const ACTION_PATTERN_LIST   = 'pattern_list';
	// Block-theme navigation
	const ACTION_NAVIGATION_MANAGE = 'navigation_manage';
	// Menus (delegated to menu_manage)
	const ACTION_MENU_CREATE = 'menu_create';
	const ACTION_MENU_UPDATE = 'menu_update';
	const ACTION_MENU_ASSIGN = 'menu_assign';

	const ACTIONS = [
		'page_list', 'page_get', 'page_create', 'page_update', 'page_delete',
		'template_list', 'template_assign',
		'pattern_create', 'pattern_list',
		'navigation_manage',
		'menu_create', 'menu_update', 'menu_assign',
	];

	const ACTION_RISK = [
		self::ACTION_PAGE_LIST       => self::RISK_DIAGNOSTIC,
		self::ACTION_PAGE_GET        => self::RISK_DIAGNOSTIC,
		self::ACTION_TEMPLATE_LIST   => self::RISK_DIAGNOSTIC,
		self::ACTION_PATTERN_LIST    => self::RISK_DIAGNOSTIC,
		self::ACTION_PAGE_CREATE     => self::RISK_MEDIUM,
		self::ACTION_PAGE_UPDATE     => self::RISK_MEDIUM,
		self::ACTION_PAGE_DELETE     => self::RISK_MEDIUM,
		self::ACTION_TEMPLATE_ASSIGN => self::RISK_MEDIUM,
		self::ACTION_PATTERN_CREATE  => self::RISK_MEDIUM,
		self::ACTION_NAVIGATION_MANAGE => self::RISK_MEDIUM,
		self::ACTION_MENU_CREATE     => self::RISK_MEDIUM,
		self::ACTION_MENU_UPDATE     => self::RISK_MEDIUM,
		self::ACTION_MENU_ASSIGN     => self::RISK_MEDIUM,
	];

	const ACTION_ROLLBACK = [
		self::ACTION_PAGE_CREATE     => true,
		self::ACTION_PAGE_UPDATE     => true,
		self::ACTION_PAGE_DELETE     => true,
		self::ACTION_TEMPLATE_ASSIGN => true,
		self::ACTION_NAVIGATION_MANAGE => true,
	];

	public static function get_risk( string $a ): string {
		return self::ACTION_RISK[ $a ] ?? self::RISK_MEDIUM;
	}

	public static function supports_rollback( string $a ): bool {
		return self::ACTION_ROLLBACK[ $a ] ?? false;
	}
}
