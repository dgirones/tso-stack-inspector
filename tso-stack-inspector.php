<?php
/**
 * Plugin Name: TSO Stack Inspector
 * Description: Find where plugin shortcodes, blocks, and metadata are used before you deactivate or uninstall. CA / ES / EN admin UI.
 * Version:     1.1.0
 * Author:      Tu Soporte Online
 * Author URI:  https://www.tusoporteonline.es/blog
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * Tested up to: 7.1
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tso-stack-inspector
 * Domain Path: /languages
 * Contributors: deadko
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_FILE' ) ) {
	define( 'TSOSI_FILE', __FILE__ );
}

if ( ! defined( 'TSOSI_VERSION' ) ) {
	define( 'TSOSI_VERSION', '1.1.0' );
}

if ( ! defined( 'TSOSI_PATH' ) ) {
	define( 'TSOSI_PATH', plugin_dir_path( TSOSI_FILE ) );
}

if ( ! defined( 'TSOSI_URL' ) ) {
	define( 'TSOSI_URL', plugin_dir_url( TSOSI_FILE ) );
}

if ( ! defined( 'TSOSI_NONCE_AJAX' ) ) {
	define( 'TSOSI_NONCE_AJAX', 'tsosi_stack_inspector_ajax' );
}

if ( ! defined( 'TSOSI_NONCE_FORM' ) ) {
	define( 'TSOSI_NONCE_FORM', 'tsosi_stack_inspector_form' );
}

if ( ! defined( 'TSOSI_SCAN_BATCH_SIZE' ) ) {
	define( 'TSOSI_SCAN_BATCH_SIZE', 25 );
}

require_once TSOSI_PATH . 'includes/tsosi-storage.php';
require_once TSOSI_PATH . 'includes/tsosi-i18n.php';
require_once TSOSI_PATH . 'includes/tsosi-plugin-profile.php';
require_once TSOSI_PATH . 'includes/tsosi-scanner.php';
require_once TSOSI_PATH . 'includes/tsosi-scan-cache.php';
require_once TSOSI_PATH . 'includes/tsosi-reports.php';
require_once TSOSI_PATH . 'includes/tsosi-admin-assets.php';
require_once TSOSI_PATH . 'includes/tsosi-admin.php';

/**
 * Plugin activation: schema bump and default settings.
 *
 * @return void
 */
function tsosi_activate() {
	tsosi_migrate_storage();
	update_option( TSOSI_OPTION_DB_SCHEMA, TSOSI_DB_SCHEMA );
}
register_activation_hook( TSOSI_FILE, 'tsosi_activate' );

/**
 * Bootstrap hooks.
 *
 * @return void
 */
function tsosi_bootstrap() {
	tsosi_migrate_storage();
	tsosi_load_textdomain();
}
add_action( 'plugins_loaded', 'tsosi_bootstrap' );
