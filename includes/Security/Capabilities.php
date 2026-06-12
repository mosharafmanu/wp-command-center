<?php
/**
 * §10 Security Model — administrator-only access / capability checks.
 */

namespace WPCommandCenter\Security;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const REQUIRED_CAPABILITY = 'manage_options';

	public static function current_user_can_manage(): bool {
		return current_user_can( self::REQUIRED_CAPABILITY );
	}
}
