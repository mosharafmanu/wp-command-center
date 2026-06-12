<?php
/**
 * Plugin Name:       WP Command Center
 * Description:       Connect Claude, Codex, GPT, and other AI agents to WordPress using only WordPress Admin access — Site Intelligence, Diagnostics, Safe File Patching, and Rollback Protection.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Mosharaf Hossain
 * Author URI:        https://mosharafmanu.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-command-center
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCC_VERSION', '0.1.0' );
define( 'WPCC_PLUGIN_FILE', __FILE__ );
define( 'WPCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WPCC_PLUGIN_DIR . 'includes/Core/Autoloader.php';

\WPCommandCenter\Core\Autoloader::register();

register_activation_hook( __FILE__, [ \WPCommandCenter\Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \WPCommandCenter\Core\Deactivator::class, 'deactivate' ] );

\WPCommandCenter\Core\Plugin::instance()->run();
