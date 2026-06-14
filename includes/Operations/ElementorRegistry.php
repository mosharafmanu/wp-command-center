<?php
/**
 * STEP 96 — Elementor registry: actions, risk, rollback support.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class ElementorRegistry {

	const RISK_DIAGNOSTIC = 'diagnostic';
	const RISK_MEDIUM     = 'medium';

	// Read
	const ACTION_GET_PAGE         = 'elementor_get_page';
	const ACTION_EXPORT_STRUCTURE = 'elementor_export_structure';
	const ACTION_LIST_WIDGETS     = 'elementor_list_widgets';
	// Edit
	const ACTION_UPDATE_TEXT   = 'elementor_update_text';
	const ACTION_UPDATE_IMAGE  = 'elementor_update_image';
	const ACTION_UPDATE_BUTTON = 'elementor_update_button';

	const ACTIONS = [
		'elementor_get_page', 'elementor_export_structure', 'elementor_list_widgets',
		'elementor_update_text', 'elementor_update_image', 'elementor_update_button',
	];

	const ACTION_RISK = [
		self::ACTION_GET_PAGE         => self::RISK_DIAGNOSTIC,
		self::ACTION_EXPORT_STRUCTURE => self::RISK_DIAGNOSTIC,
		self::ACTION_LIST_WIDGETS     => self::RISK_DIAGNOSTIC,
		self::ACTION_UPDATE_TEXT      => self::RISK_MEDIUM,
		self::ACTION_UPDATE_IMAGE     => self::RISK_MEDIUM,
		self::ACTION_UPDATE_BUTTON    => self::RISK_MEDIUM,
	];

	const ACTION_ROLLBACK = [
		self::ACTION_UPDATE_TEXT   => true,
		self::ACTION_UPDATE_IMAGE  => true,
		self::ACTION_UPDATE_BUTTON => true,
	];

	public static function get_risk( string $a ): string {
		return self::ACTION_RISK[ $a ] ?? self::RISK_MEDIUM;
	}

	public static function supports_rollback( string $a ): bool {
		return self::ACTION_ROLLBACK[ $a ] ?? false;
	}
}
