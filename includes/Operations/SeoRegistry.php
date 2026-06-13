<?php
/**
 * STEP 91 — SEO Runtime registry: actions, risk, rollback support.
 */

namespace WPCommandCenter\Operations;

defined( 'ABSPATH' ) || exit;

final class SeoRegistry {

	const RISK_DIAGNOSTIC = 'diagnostic';
	const RISK_MEDIUM     = 'medium';

	const ACTION_GET      = 'seo_get';
	const ACTION_UPDATE   = 'seo_update';
	const ACTION_VALIDATE = 'seo_validate';
	const ACTION_ANALYZE  = 'seo_analyze';
	const ACTION_RESTORE  = 'seo_restore';

	const ACTIONS = [ 'seo_get', 'seo_update', 'seo_validate', 'seo_analyze', 'seo_restore' ];

	const ACTION_RISK = [
		self::ACTION_GET      => self::RISK_DIAGNOSTIC,
		self::ACTION_VALIDATE => self::RISK_DIAGNOSTIC,
		self::ACTION_ANALYZE  => self::RISK_DIAGNOSTIC,
		self::ACTION_UPDATE   => self::RISK_MEDIUM,
		self::ACTION_RESTORE  => self::RISK_MEDIUM,
	];

	const ACTION_ROLLBACK = [
		self::ACTION_UPDATE => true,
	];

	public static function get_risk( string $action ): string {
		return self::ACTION_RISK[ $action ] ?? self::RISK_MEDIUM;
	}

	public static function supports_rollback( string $action ): bool {
		return self::ACTION_ROLLBACK[ $action ] ?? false;
	}
}
