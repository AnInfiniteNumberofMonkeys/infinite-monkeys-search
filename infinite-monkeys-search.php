<?php
/**
 * Plugin Name: Infinite Monkeys Search
 * Plugin URI: https://infinitemonkeys.ca
 * Description: Fast live search for WooCommerce and WordPress content with a custom index, fuzzy matching, synonyms, stopwords, a shortcode and Bricks Builder elements.
 * Version: 1.0.6
 * Author: An Infinite Number of Monkeys
 * Author URI: https://infinitemonkeys.ca
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * License: Proprietary - internal use only
 * Text Domain: imsearch
 *
 * Not distributed on WordPress.org. Updates come from the private GitHub
 * repository through Plugin Update Checker (includes/class-imsearch-updater.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IMSEARCH_VERSION', '1.0.6' );
define( 'IMSEARCH_FILE', __FILE__ );
define( 'IMSEARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMSEARCH_URL', plugin_dir_url( __FILE__ ) );

require_once IMSEARCH_DIR . 'includes/class-imsearch-text.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-engine.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-db.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-settings.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-indexer.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-render.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-frontend.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-admin.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-bricks.php';
require_once IMSEARCH_DIR . 'includes/class-imsearch-updater.php';

register_activation_hook( __FILE__, array( 'IMSearch_Indexer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'IMSearch_Indexer', 'deactivate' ) );

IMSearch_Updater::init();

add_action(
	'plugins_loaded',
	static function () {
		IMSearch_DB::maybe_upgrade();
		IMSearch_Indexer::init();
		IMSearch_Frontend::init();
		IMSearch_Bricks::init();
		if ( is_admin() ) {
			IMSearch_Admin::init();
		}
	}
);
