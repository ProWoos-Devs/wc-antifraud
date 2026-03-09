<?php
/**
 * Plugin Name: WC Antifraud
 * Plugin URI:  https://github.com/ProWoos-Devs/wc-antifraud
 * Description: Multi-layer anti-fraud protection for WooCommerce: origin verification, blacklists (email, IP, phone), suspicious amount detection, rate limiting, REST API hardening, and automated fraud management with email alerts.
 * Version:     1.0.3
 * Author:      ProWoos
 * Author URI:  https://github.com/ProWoos-Devs
 * Text Domain: wc-antifraud
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WC_Antifraud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'WC Antifraud requires PHP 7.4 or higher.', 'wc-antifraud' )
			);
		}
	);
	return;
}

define( 'WCAF_VERSION', '1.0.3' );
define( 'WCAF_PLUGIN_FILE', __FILE__ );
define( 'WCAF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCAF_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Declare compatibility with WooCommerce HPOS (Custom Orders Table)
// and the Block-based Cart/Checkout.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

require_once WCAF_PLUGIN_DIR . 'includes/class-wc-antifraud.php';

wc_antifraud();
