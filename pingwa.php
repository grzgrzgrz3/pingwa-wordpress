<?php
/**
 * Plugin Name:       pingwa – WhatsApp alerts for forms and orders
 * Plugin URI:        https://pingwa.grzgrzgrz3.ovh/woocommerce
 * Description:       Sends Contact Form 7, WPForms, Elementor Pro and Gravity Forms submissions and new WooCommerce orders to your WhatsApp through a pingwa hook URL.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            pingwa
 * Author URI:        https://pingwa.grzgrzgrz3.ovh/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pingwa
 * WC requires at least: 7.1
 * WC tested up to:   10.3
 *
 * @package pingwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PINGWA_VERSION', '0.1.0' );
define( 'PINGWA_FILE', __FILE__ );
// The pingwa server the setup steps and links point to.
define( 'PINGWA_SERVER_URL', 'https://pingwa.grzgrzgrz3.ovh' );
// WhatsApp link prefilled with "join wp" (signup tag `wp`). assets/join-wp-qr.svg is its QR.
define( 'PINGWA_JOIN_URL', 'https://wa.me/48797882513?text=join%20wp' );

require_once __DIR__ . '/includes/class-pingwa-hook-url.php';
require_once __DIR__ . '/includes/class-pingwa-payload.php';
require_once __DIR__ . '/includes/class-pingwa-fields.php';
require_once __DIR__ . '/includes/class-pingwa-triggers.php';
require_once __DIR__ . '/includes/class-pingwa-sender.php';
require_once __DIR__ . '/includes/class-pingwa-settings.php';

/**
 * Create the options, not autoloaded (they are read only when something is sent or on
 * the settings page).
 */
function pingwa_activate() {
	add_option( Pingwa_Settings::OPTION_URL, '', '', false );
	add_option( Pingwa_Triggers::OPTION, array(), '', false );
}
register_activation_hook( __FILE__, 'pingwa_activate' );

/**
 * Declare compatibility with WooCommerce's order tables (HPOS) and block checkout.
 */
function pingwa_declare_wc_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'pingwa_declare_wc_compatibility' );

Pingwa_Triggers::register();
if ( is_admin() ) {
	Pingwa_Settings::register();
}
