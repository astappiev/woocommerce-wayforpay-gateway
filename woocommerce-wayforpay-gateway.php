<?php
/**
 * Plugin Name: WooCommerce WayForPay Gateway
 * Description: Pay securely via the WayForPay Payment Engine.
 * Version: 2.0.1
 * Author: Dev team WayForPay, Oleh Astappiev
 * Author: support@wayforpay.com, oleh@astappiev.me
 * Plugin URI: https://github.com/astappiev/woocommerce-wayforpay-gateway
 * Text Domain: woocommerce-wayforpay-gateway
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.2
 * Tested up to: 6.7
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 9.6
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

define( 'WAYFORPAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAYFORPAY_PATH', plugin_dir_url( __FILE__ ) );

add_action( 'init', 'woocommerce_wayforpay_gateway_i18n' );

/**
 * Register the plugin's text domain.
 */
function woocommerce_wayforpay_gateway_i18n(): void {
	load_plugin_textdomain( 'woocommerce-wayforpay-gateway', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

add_action( 'plugins_loaded', 'woocommerce_wayforpay_gateway_init', 0 );

/**
 * Initialize the WayForPay gateway.
 */
function woocommerce_wayforpay_gateway_init(): void {
	require_once WAYFORPAY_DIR . 'includes/class-wayforpay.php';
	require_once WAYFORPAY_DIR . 'includes/class-wayforpay-gateway.php';
	require_once WAYFORPAY_DIR . 'includes/class-wayforpay-gateway-block.php';
}

add_filter( 'woocommerce_payment_gateways', 'woocommerce_wayforpay_gateway_add_gateway' );

/**
 * Register the WayForPay payment gateway.
 */
function woocommerce_wayforpay_gateway_add_gateway( array $methods ): array {
	$methods[] = 'Wayforpay_Gateway';
	return $methods;
}

add_action( 'before_woocommerce_init', 'woocommerce_wayforpay_gateway_declare_hpos_compatibility' );

/**
 * Declare compatibility with WooCommerce HPOS.
 */
function woocommerce_wayforpay_gateway_declare_hpos_compatibility(): void {
	if ( class_exists( FeaturesUtil::class ) ) {
		FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
	}
}

add_action( 'before_woocommerce_init', 'woocommerce_wayforpay_gateway_declare_cart_checkout_blocks_compatibility' );

/**
 * Declare compatibility with WooCommerce Cart & Checkout Blocks.
 */
function woocommerce_wayforpay_gateway_declare_cart_checkout_blocks_compatibility(): void {
	if ( class_exists( FeaturesUtil::class ) ) {
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}

add_action( 'woocommerce_blocks_loaded', 'woocommerce_wayforpay_gateway_register_payment_method' );

/**
 * Register the WayForPay block payment method.
 */
function woocommerce_wayforpay_gateway_register_payment_method(): void {
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $payment_method_registry ) {
			$payment_method_registry->register( new WayForPay_Gateway_Block() );
		}
	);
}
