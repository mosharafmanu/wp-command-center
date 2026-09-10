<?php
/**
 * Plugin Name:       Action Steward
 * Description:       Safe AI operations for WordPress. Scope access, assess risk, require approval, and keep an audit trail with rollback for supported changes.
 * Version:           1.0.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Mosharaf Hossain
 * Author URI:        https://mosharafmanu.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       action-steward
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCC_VERSION', '1.0.2' );
define( 'WPCC_PLUGIN_FILE', __FILE__ );
define( 'WPCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// PHP 8.0 compatibility: array_is_list() was added in PHP 8.1, but the plugin
// supports PHP 8.0 (see "Requires PHP" above). Polyfill it so 8.0 hosts don't
// hit "Call to undefined function array_is_list()".
if ( ! function_exists( 'array_is_list' ) ) {
	/**
	 * @param array<mixed> $arr
	 */
	function array_is_list( array $arr ): bool {
		if ( [] === $arr ) {
			return true;
		}
		$expected = 0;
		foreach ( $arr as $key => $_ ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}
		return true;
	}
}

require_once WPCC_PLUGIN_DIR . 'includes/Core/Autoloader.php';

\WPCommandCenter\Core\Autoloader::register();

register_activation_hook( __FILE__, [ \WPCommandCenter\Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \WPCommandCenter\Core\Deactivator::class, 'deactivate' ] );

\WPCommandCenter\Core\Plugin::instance()->run();
