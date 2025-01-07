<?php
/**
 * Plugin Name: WooCommerce WayForPay Gateway
 * Description: WayForPay Payment Gateway for WooCommerce.
 * Version: 2.0.0-alpha1
 * Author: Dev team WayForPay, Oleh Astappiev
 * Author: support@wayforpay.com, oleh@astappiev.me
 * Plugin URI: https://github.com/astappiev/wp-wayforpay-gateway
 * Requires PHP: 7.2
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires Plugins: woocommerce
 * WC requires at least: 7.6
 * WC tested up to: 9.5
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

define( 'WAYFORPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAYFORPAY_PATH', plugin_dir_url( __FILE__ ) );

add_action( 'init', 'wayforpay_gateway_i18n' );

function wayforpay_gateway_i18n(): void {
	load_plugin_textdomain( 'woocommerce-wayforpay-payments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

add_action( 'before_woocommerce_init', 'wayforpay_gateway_declare_hpos_compatibility' );

function wayforpay_gateway_declare_hpos_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
	}
}

add_action( 'plugins_loaded', 'wayforpay_gateway_init', 0 );

function wayforpay_gateway_init(): void {
	require_once WAYFORPAY_DIR . 'includes/class-wc-wayforpay-gateway.php';
}

add_filter( 'woocommerce_payment_gateways', 'wayforpay_gateway_add_gateway' );

function wayforpay_gateway_add_gateway( $methods ) {
	$methods[] = 'WC_Wayforpay_Gateway';
	return $methods;
}
