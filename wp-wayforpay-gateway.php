<?php
/**
 * Plugin Name: WooCommerce WayForPay Gateway
 * Description: Pay securely via the WayForPay Payment Engine.
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

use Automattic\WooCommerce\Utilities\FeaturesUtil;

define( 'WAYFORPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAYFORPAY_PATH', plugin_dir_url( __FILE__ ) );

add_action( 'init', 'wayforpay_gateway_i18n' );

function wayforpay_gateway_i18n(): void {
	load_plugin_textdomain( 'wp-wayforpay-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

add_action( 'before_woocommerce_init', 'wayforpay_gateway_declare_hpos_compatibility' );

function wayforpay_gateway_declare_hpos_compatibility(): void {
	if ( class_exists( FeaturesUtil::class ) ) {
		FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
	}
}

add_action( 'before_woocommerce_init', 'wayforpay_gateway_declare_cart_checkout_blocks_compatibility' );

function wayforpay_gateway_declare_cart_checkout_blocks_compatibility(): void {
	if ( class_exists( FeaturesUtil::class ) ) {
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}

add_action( 'woocommerce_blocks_loaded', 'wayforpay_gateway_register_payment_method' );

function wayforpay_gateway_register_payment_method(): void {
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			$payment_method_registry->register( new WC_WayForPay_Gateway_Blocks() );
		}
	);
}

add_action( 'plugins_loaded', 'wayforpay_gateway_init', 0 );

function wayforpay_gateway_init(): void {
	require_once WAYFORPAY_DIR . 'includes/class-wc-wayforpay-gateway.php';
	require_once WAYFORPAY_DIR . 'includes/class-wc-wayforpay-gateway-blocks.php';
}

add_filter( 'woocommerce_payment_gateways', 'wayforpay_gateway_add_gateway' );

function wayforpay_gateway_add_gateway( $methods ) {
	$methods[] = 'WC_Wayforpay_Gateway';
	return $methods;
}
