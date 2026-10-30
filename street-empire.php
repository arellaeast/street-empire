<?php
/**
 * Plugin Name: Crabs in a Barrel
 * Description: A turn-based Chesapeake strategy game built on WordPress users. Drop pots, haul in clams, raid rival crabs, and claw your way to the top of the barrel. Turns accrue over time, can be purchased via WooCommerce, and every game term (units, currency, verbs) is admin-editable.
 * Version: 0.5.0
 * Author: Captain Baltimore
 * Requires PHP: 7.4
 * Text Domain: street-empire
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SE_VERSION', '0.5.0' );
define( 'SE_PLUGIN_FILE', __FILE__ );
define( 'SE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SE_PLUGIN_DIR . 'includes/class-se-install.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-settings.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-labels.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-themes.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-player.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-game.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-crews.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-admin.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-frontend.php';
require_once SE_PLUGIN_DIR . 'includes/class-se-wc.php';

register_activation_hook( __FILE__, [ 'SE_Install', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SE_Install', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	SE_Install::maybe_upgrade();
	SE_Admin::init();
	SE_Frontend::init();
	if ( class_exists( 'WooCommerce' ) ) {
		SE_WC::init();
	}
	// Optional scheduled round reset.
	add_action( 'se_round_reset_event', [ 'SE_Game', 'reset_round' ] );
} );
