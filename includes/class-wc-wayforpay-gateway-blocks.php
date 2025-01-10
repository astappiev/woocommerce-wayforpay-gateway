<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_WayForPay_Gateway_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name defined by payment methods extending this class.
	 *
	 * @var string
	 */
	protected $name = 'wayforpay';

	/**
	 * @var WC_Wayforpay_Gateway
	 */
	private $gateway;

	public function initialize(): void {
		$this->gateway  = new WC_Wayforpay_Gateway();
		$this->settings = $this->gateway->settings;
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active(): bool {
		return $this->gateway->is_available();
	}

	/**
	 * Returns an array of script handles to enqueue for this payment method in the frontend context
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$block_asset = require WAYFORPAY_DIR . 'public/block/index.asset.php';
		wp_register_script(
			'wc-wayforpay-gateway-blocks',
			WAYFORPAY_PATH . 'public/block/index.js',
			$block_asset['dependencies'],
			$block_asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-wayforpay-gateway-blocks', 'wp-wayforpay-gateway', WAYFORPAY_DIR . 'languages/' );
		}

		return array( 'wc-wayforpay-gateway-blocks' );
	}

	/**
	 * An array of key, value pairs of data made available to payment methods client side.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->gateway->title,
			'description' => $this->gateway->description,
			'supports'    => array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ),
			'logo_url'    => $this->gateway->icon,
		);
	}
}
