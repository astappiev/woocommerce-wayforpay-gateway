<?php

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Enums\PaymentGatewayFeature;

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

class Wayforpay_Gateway extends WC_Payment_Gateway {
	const string WAYFORPAY_MERCHANT_TEST    = 'WAYFORPAY_MERCHANT_TEST';
	const string WAYFORPAY_MERCHANT_ACCOUNT = 'WAYFORPAY_MERCHANT_ACCOUNT';
	const string WAYFORPAY_MERCHANT_SECRET  = 'WAYFORPAY_MERCHANT_SECRET';

	protected Wayforpay $wayforpay;

	public function __construct() {
		$this->id                 = 'wayforpay';
		$this->method_title       = 'WayForPay';
		$this->method_description = __( 'Accept card payments, Apple Pay and Google Pay via WayForPay payment gateway.', 'woocommerce-wayforpay-gateway' );
		$this->has_fields         = false;
		$this->supports           = array(
			PaymentGatewayFeature::PRODUCTS,
			PaymentGatewayFeature::REFUNDS,       // you need to reach WayForPay support to activate refunds
			PaymentGatewayFeature::SUBSCRIPTIONS, // you need to reach WayForPay support to activate recurrent payments
			PaymentGatewayFeature::SUBSCRIPTION_CANCELLATION,
			PaymentGatewayFeature::SUBSCRIPTION_SUSPENSION,
			PaymentGatewayFeature::SUBSCRIPTION_REACTIVATION,
			PaymentGatewayFeature::SUBSCRIPTION_AMOUNT_CHANGES,
			PaymentGatewayFeature::SUBSCRIPTION_DATE_CHANGES,
			// PaymentGatewayFeature::SUBSCRIPTION_PAYMENT_METHOD_CHANGE,
			// PaymentGatewayFeature::SUBSCRIPTION_PAYMENT_METHOD_CHANGE_CUSTOMER,
			// PaymentGatewayFeature::SUBSCRIPTION_PAYMENT_METHOD_CHANGE_ADMIN,
			PaymentGatewayFeature::MULTIPLE_SUBSCRIPTIONS,
		);

		$this->init_settings();
		if ( ! empty( $this->settings['showlogo'] ) && $this->settings['showlogo'] !== 'no' ) {
			$this->icon = WAYFORPAY_PATH . 'public/images/' . $this->settings['showlogo'];
		}

		if ( defined( self::WAYFORPAY_MERCHANT_TEST ) && constant( self::WAYFORPAY_MERCHANT_TEST ) ) {
			$this->settings['merchant_account'] = Wayforpay::TEST_MERCHANT_ACCOUNT;
			$this->settings['merchant_secret']  = Wayforpay::TEST_MERCHANT_SECRET;
		} elseif ( defined( self::WAYFORPAY_MERCHANT_ACCOUNT ) && defined( self::WAYFORPAY_MERCHANT_SECRET ) ) {
			$this->settings['merchant_account'] = constant( self::WAYFORPAY_MERCHANT_ACCOUNT );
			$this->settings['merchant_secret']  = constant( self::WAYFORPAY_MERCHANT_SECRET );
		}
		$this->init_form_fields();

		$this->title       = $this->settings['title'];
		$this->description = $this->settings['description'];
		$this->wayforpay   = new Wayforpay( $this->settings['merchant_account'], $this->settings['merchant_secret'] );

		add_action( 'woocommerce_api_' . $this->id . '_callback', array( $this, 'receive_service_callback' ) );
		add_action( 'woocommerce_api_' . $this->id . '_return', array( $this, 'receive_return_callback' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'post_payment_request' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'process_subscription_payment' ), 10, 2 );
	}

	function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-wayforpay-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable WayForPay Payment Engine', 'woocommerce-wayforpay-gateway' ),
				'default' => 'yes',
			),
			'title'            => array(
				'title'       => __( 'Title', 'woocommerce-wayforpay-gateway' ),
				'type'        => 'text',
				'default'     => __( 'Pay with card, Apple Pay, Google Pay', 'woocommerce-wayforpay-gateway' ),
				'description' => __( 'This controls the title which user sees during checkout.', 'woocommerce-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'woocommerce-wayforpay-gateway' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely via the WayForPay Payment Engine.', 'woocommerce-wayforpay-gateway' ),
				'description' => __( 'This controls the description which user sees during checkout.', 'woocommerce-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'merchant_account' => array(
				'title'       => __( 'Merchant Account', 'woocommerce-wayforpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'Seller identifier. This value is assigned to you by WayForPay.', 'woocommerce-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'merchant_secret'  => array(
				'title'       => __( 'Merchant Secret key', 'woocommerce-wayforpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'Signature secret key. This value is assigned to you by WayForPay.', 'woocommerce-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'showlogo'         => array(
				'title'       => __( 'Display Logo', 'woocommerce-wayforpay-gateway' ),
				'type'        => 'select',
				'options'     => array(
					''        => __( 'No logo', 'woocommerce-wayforpay-gateway' ),
					'w4p.png' => __( 'WayForPay Logo', 'woocommerce-wayforpay-gateway' ),
					'4pp.png' => __( 'Payment Processors Logos', 'woocommerce-wayforpay-gateway' ),
				),
				'default'     => '',
				'description' => __( 'Determines which logo is shown near this payment method on checkout.', 'woocommerce-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'returnUrl'        => array(
				'title'       => __( 'Return page', 'woocommerce-wayforpay-gateway' ),
				'type'        => 'select',
				'options'     => $this->wayforpay_get_pages( __( 'Select Page', 'woocommerce-wayforpay-gateway' ) ),
				'description' => __( 'The page where the user will be directed after payment. By default, it is the order status page.', 'woocommerce-wayforpay-gateway' ),
				'desc_tip'    => false,
			),
			'returnUrl_m'      => array(
				'title'       => __( 'or specify the address', 'woocommerce-wayforpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'Alternatively, you can enter the URL of the page to redirect. Have a precedence over the previous option.', 'woocommerce-wayforpay-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);

		$constant_merchant        = false;
		$constant_controlled_hint = __( 'The value is controlled by the %s constant.', 'woocommerce-wayforpay-gateway' );
		if ( defined( self::WAYFORPAY_MERCHANT_TEST ) && constant( self::WAYFORPAY_MERCHANT_TEST ) ) {
			$this->form_fields['merchant_account']['description'] = sprintf( $constant_controlled_hint, self::WAYFORPAY_MERCHANT_TEST );
			$this->form_fields['merchant_secret']['description']  = sprintf( $constant_controlled_hint, self::WAYFORPAY_MERCHANT_TEST );
			$constant_merchant                                    = true;
		} elseif ( defined( self::WAYFORPAY_MERCHANT_ACCOUNT ) && defined( self::WAYFORPAY_MERCHANT_SECRET ) ) {
			$this->form_fields['merchant_account']['description'] = sprintf( $constant_controlled_hint, self::WAYFORPAY_MERCHANT_ACCOUNT );
			$this->form_fields['merchant_secret']['description']  = sprintf( $constant_controlled_hint, self::WAYFORPAY_MERCHANT_SECRET );
			$constant_merchant                                    = true;
		}

		if ( $constant_merchant ) {
			$this->form_fields['merchant_account']['disabled'] = true;
			$this->form_fields['merchant_account']['desc_tip'] = false;
			$this->form_fields['merchant_secret']['disabled']  = true;
			$this->form_fields['merchant_secret']['desc_tip']  = false;
		}
	}

	private function get_gateway_currency(): string {
		return str_replace(
			array( 'ГРН', 'uah' ),
			array( 'UAH', 'UAH' ),
			get_woocommerce_currency()
		);
	}

	private function get_gateway_language(): string {
		$lang = substr( get_bloginfo( 'language' ), 0, 2 );
		if ( $lang === 'uk' ) {
			$lang = 'ua';
		}
		return $lang;
	}

	private function transform_order_to_payload( $order, ?int $amount = null ): array {
		/**
		 * Filters the order reference suffix.
		 *
		 * @param string $suffix the suffix to be appended to the order reference.
		 */
		$reference_suffix = apply_filters( 'woocommerce_wayforpay_gateway_order_reference_suffix', 'woo_' . time() );

		$amount  = $amount ?: $order->get_total();
		$payload = array(
			'orderReference' => $order->get_id() . '_' . $reference_suffix,
			'orderDate'      => $order->get_date_created()->getTimestamp(),
			'amount'         => $amount,
			'currency'       => $this->get_gateway_currency(),
		);

		$items              = $order->get_items();
		$use_order_fallback = empty( $items );

		if ( ! $use_order_fallback ) {
			$product_sum = 0;
			foreach ( $items as $item ) {
				$payload['productName'][]  = $item->get_name();
				$payload['productCount'][] = $item->get_quantity();
				$price                     = round( ( $item->get_total() + $item->get_total_tax() ) / $item->get_quantity(), 2 );
				$payload['productPrice'][] = $price;
				$product_sum              += $price * $item->get_quantity();
			}

			if ( $order->get_shipping_total() > 0 ) {
				$payload['productName'][]  = __( 'Shipping', 'woocommerce' );
				$payload['productCount'][] = 1;
				$price                     = round( $order->get_shipping_total() + $order->get_shipping_tax(), 2 );
				$payload['productPrice'][] = $price;
				$product_sum              += $price;
			}

			foreach ( $order->get_fees() as $fee ) {
				$payload['productName'][]  = $fee->get_name();
				$payload['productCount'][] = 1;
				$price                     = round( $fee->get_total() + $fee->get_total_tax(), 2 );
				$payload['productPrice'][] = $price;
				$product_sum              += $price;
			}

			$diff = round( $amount - $product_sum, 2 );
			if ( $diff != 0 ) {
				$use_order_fallback = true;
			}
		}

		if ( $use_order_fallback ) {
			// Prevent wrong prices or empty item list by overriding products with "Order #" reference
			$payload['productName']  = array( sprintf( __( 'Order %s', 'woocommerce-wayforpay-gateway' ), $order->get_order_number() ) );
			$payload['productCount'] = array( 1 );
			$payload['productPrice'] = array( $amount );
		}

		$client_args = array(
			'clientFirstName' => $order->get_billing_first_name(),
			'clientLastName'  => $order->get_billing_last_name(),
			'clientAddress'   => $order->get_billing_address_1(),
			'clientCity'      => $order->get_billing_city(),
			'clientPhone'     => $order->get_billing_phone(),
			'clientEmail'     => $order->get_billing_email(),
			'clientCountry'   => $this->country_alpha2_to_alpha3( $order->get_billing_country() ),
			'clientZipCode'   => $order->get_billing_postcode(),
		);

		if ( $order->get_customer_id() > 0 ) {
			$client_args['clientAccountId'] = (string) $order->get_customer_id();
		}

		return array_merge( $payload, $client_args );
	}

	function process_payment( $order_id ): array|bool {
		try {
			$order = wc_get_order( $order_id );

			$payload               = $this->transform_order_to_payload( $order );
			$payload['returnUrl']  = wc_get_endpoint_url( 'wc-api', $this->id . '_return', get_home_url() );
			$payload['serviceUrl'] = wc_get_endpoint_url( 'wc-api', $this->id . '_callback', get_home_url() );
			$payload['language']   = $this->get_gateway_language();
			$result                = $this->wayforpay->purchase( $payload );

			return array(
				'result'   => 'success',
				'redirect' => $result['url'],
			);
		} catch ( \Exception $e ) {
			wc_add_notice( 'Request error (' . $e->getMessage() . ')', 'error' );
			return false;
		}
	}

	function process_refund( $order_id, $amount = null, $reason = '' ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		try {
			if ( empty( $order->get_transaction_id() ) ) {
				throw new Exception( __( 'Transaction not found.', 'woocommerce-wayforpay-gateway' ) );
			}

			$amount   = $amount ?: $order->get_total();
			$currency = $this->get_gateway_currency();
			$reason   = $reason ?: __( 'Not provided', 'woocommerce-wayforpay-gateway' );

			$payload = array(
				'orderReference' => $order->get_transaction_id(),
				'amount'         => $amount,
				'currency'       => $currency,
				'comment'        => $reason,
			);

			$result = $this->wayforpay->refund( $payload );

			switch ( $result['transactionStatus'] ) {
				case Wayforpay::TRANSACTION_REFUNDED:
				case Wayforpay::TRANSACTION_VOIDED:
					wc_add_notice( __( 'Order refunded', 'woocommerce-wayforpay-gateway' ), 'notice' );
					$order->add_order_note( sprintf( __( 'Refunded %1$s %2$s.', 'woocommerce-wayforpay-gateway' ), $amount, $currency ) );
					return true;
				default:
					throw new Exception( sprintf( __( 'Refund failed: %1$s', 'woocommerce-wayforpay-gateway' ), $result ) );
			}
		} catch ( \Exception $e ) {
			wc_add_notice( 'Refund error (' . $e->getMessage() . ')', 'error' );
			return false;
		}
	}

	private function get_callback_url( $order ): string {
		$redirect_url = $this->get_return_url( $order );
		if ( isset( $this->settings['returnUrl_m'] ) && trim( $this->settings['returnUrl_m'] ) !== '' ) {
			$redirect_url = trim( $this->settings['returnUrl_m'] );
		} elseif ( $this->settings['returnUrl'] ) {
			$redirect_url = get_permalink( $this->settings['returnUrl'] );
		}
		return add_query_arg( 'key', $order->get_order_key(), $redirect_url );
	}

	/**
	 * Process the service callback and update the order status accordingly.
	 *
	 * @throws Exception
	 */
	protected function handle_service_callback( $response ): WC_Order {
		[$order_id, $suffix] = explode( '_', $response['orderReference'], 2 );
		$order               = wc_get_order( $order_id );
		if ( $order === false ) {
			throw new Exception( __( 'An error has occurred during processing. Please contact us to ensure your order has submitted.', 'woocommerce-wayforpay-gateway' ) );
		}

		switch ( $response['transactionStatus'] ) {
			case Wayforpay::TRANSACTION_APPROVED:
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $response['orderReference'] );
					$order->add_order_note( sprintf( __( 'Payment successful: %1$s %2$s.', 'woocommerce-wayforpay-gateway' ), $response['amount'], $response['currency'] ) );
				} elseif ( $order->get_transaction_id() !== $response['orderReference'] ) { // ignore duplicates
					$order->add_order_note( sprintf( __( 'Unexpected payment received: %1$s. The order could be double paid.', 'woocommerce-wayforpay-gateway' ), $response['orderReference'] ) );
				}

				// Handle recurring token for subscriptions
				if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
					$subscriptions = wcs_get_subscriptions_for_order( $order );
					if ( ! empty( $subscriptions ) ) {
						if ( ! empty( $response['recToken'] ) ) {
							// Save token using WooCommerce Payment Token API
							foreach ( $subscriptions as $subscription ) {
								$subscription->update_meta_data( '_wayforpay_rec_token', $response['recToken'] );
								$subscription->save();
							}

							$order->add_order_note( __( 'Recurring payment token saved for automatic renewals.', 'woocommerce-wayforpay-gateway' ) );
						} else {
							// No recToken received — switch subscriptions to manual renewal
							foreach ( $subscriptions as $subscription ) {
								$subscription->update_manual( true );
								$subscription->add_order_note( __( 'Switched to manual renewal: no recurring payment token found.', 'woocommerce-wayforpay-gateway' ) );
								$subscription->save();
							}

							$order->add_order_note( __( 'Warning: Subscription(s) switched to manual renewal because no recurring payment token (recToken) was received from WayForPay.', 'woocommerce-wayforpay-gateway' ) );
						}
					}
				}
				break;
			case Wayforpay::TRANSACTION_REFUNDED:
			case Wayforpay::TRANSACTION_VOIDED:
				if ( $order->get_status() !== OrderStatus::REFUNDED && $order->get_transaction_id() === $response['orderReference'] ) {
					$order->update_status( OrderStatus::REFUNDED );
					$order->add_order_note( sprintf( __( 'Refunded %1$s %2$s.', 'woocommerce-wayforpay-gateway' ), $response['amount'], $response['currency'] ) );
				} elseif ( $order->get_status() !== OrderStatus::REFUNDED ) { // ignore duplicates
					$order->add_order_note( sprintf( __( 'Got a refund, with a wrong order reference: %1$s.', 'woocommerce-wayforpay-gateway' ), $response['orderReference'] ) );
				}
				break;
			case Wayforpay::TRANSACTION_DECLINED:
				if ( $order->get_status() !== OrderStatus::FAILED && ( ! $order->is_paid() || ( $order->is_paid() && $order->get_transaction_id() === $response['orderReference'] ) ) ) {
					$order->update_status( OrderStatus::FAILED );
					$order->add_order_note( sprintf( __( 'Payment failed: %1$s - %2$s.', 'woocommerce-wayforpay-gateway' ), $response['reasonCode'] ?? 'N/A', $response['reason'] ?? 'N/A' ) );
				}
				break;
			case Wayforpay::TRANSACTION_EXPIRED:
				if ( $order->get_status() === OrderStatus::PENDING ) { // required to prevent changing order status by session expired callbacks when the order is already processed in another transaction
					$order->update_status( OrderStatus::FAILED );
					$order->add_order_note( __( 'Payment expired.', 'woocommerce-wayforpay-gateway' ) );
				}
				break;
			default:
				$order->add_order_note( sprintf( __( 'Transaction updated, current status: %s', 'woocommerce-wayforpay-gateway' ), $response['transactionStatus'] ) );
		}

		return $order;
	}

	/**
	 * This will be called when the user returns from gateway.
	 */
	function receive_return_callback(): void {
		try {
			if ( empty( $_POST ) ) {
				throw new Exception( __( 'An error has occurred during redirect, if it persists, please contact us.', 'woocommerce-wayforpay-gateway' ) );
			}

			if ( ! $this->wayforpay->verify_callback( $_POST ) ) {
				throw new Exception( __( 'An error has occurred during processing. Signature is not valid.', 'woocommerce-wayforpay-gateway' ) );
			}

			$order = $this->handle_service_callback( $_POST );
			wp_safe_redirect( $this->get_callback_url( $order ) );
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
		}
		exit;
	}

	function post_payment_request( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		if ( $order->is_paid() ) {
			global $woocommerce;
			if ( $woocommerce->cart && ! $woocommerce->cart->is_empty() ) {
				$woocommerce->cart->empty_cart();
			}
		}

		if ( $order->get_status() === OrderStatus::FAILED ) {
			wc_add_notice( __( 'Payment failed', 'woocommerce-wayforpay-gateway' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * This will be called when the service callback is received.
	 */
	function receive_service_callback(): void {
		$payload = json_decode( file_get_contents( 'php://input' ), true );

		try {
			if ( ! $this->wayforpay->verify_callback( $payload ) ) {
				throw new Exception( __( 'An error has occurred during processing. Signature is not valid.', 'woocommerce-wayforpay-gateway' ) );
			}

			$this->handle_service_callback( $payload );

			$result = $this->wayforpay->respond_callback( $payload );
			echo json_encode( $result );
		} catch ( Exception $e ) {
			echo $e->getMessage();
		}
		exit;
	}

	/**
	 * Called by WooCommerce Subscriptions for each scheduled renewal.
	 * Charges the customer's saved card using the recToken obtained during the initial payment.
	 */
	public function process_subscription_payment( float $amount, WC_Order $renewal_order ): void {
		// Free-trial renewals have zero amount — complete immediately without charging.
		if ( $amount == 0 ) {
			$renewal_order->payment_complete();
			return;
		}

		if ( $renewal_order->is_paid() ) {
			$renewal_order->add_order_note( __( 'Unexpected payment for subscription renewal: order is already marked as paid.', 'woocommerce-wayforpay-gateway' ) );
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order );
		$subscription  = reset( $subscriptions );

		if ( ! $subscription ) {
			$renewal_order->update_status( OrderStatus::FAILED, __( 'Subscription not found for this renewal order.', 'woocommerce-wayforpay-gateway' ) );
			return;
		}

		$rec_token = $subscription->get_meta( '_wayforpay_rec_token' );
		if ( empty( $rec_token ) ) {
			$renewal_order->update_status( OrderStatus::FAILED, __( 'No saved payment token found. The customer must update their payment method.', 'woocommerce-wayforpay-gateway' ) );
			return;
		}

		try {
			$payload                    = $this->transform_order_to_payload( $renewal_order, $amount );
			$payload['recToken']        = $rec_token;
			$payload['clientIpAddress'] = $renewal_order->get_customer_ip_address() ?: ( $_SERVER['SERVER_ADDR'] ?? '127.0.0.1' );

			if (str_contains($payload['clientIpAddress'], ':')) {
				$payload['clientIpAddress'] = '127.0.0.1';
			}
			if (empty ($payload['clientAddress'])) {
				$payload['clientAddress'] = 'unknown';
			}
			if (empty ($payload['clientCity'])) {
				$payload['clientCity'] = 'unknown';
			}
			if (empty ($payload['clientZipCode'])) {
				$payload['clientZipCode'] = 'unknown';
			}
			if (empty ($payload['clientCountry'])) {
				$payload['clientCountry'] = 'UKR';
			}

			$result = $this->wayforpay->charge( $payload );

			$renewal_order->payment_complete( $result['orderReference'] );
			$renewal_order->add_order_note(
				sprintf(
					__( 'Subscription renewal payment successful: %1$s %2$s.', 'woocommerce-wayforpay-gateway' ),
					$result['amount'],
					$result['currency']
				)
			);

			// WayForPay may rotate the recToken; update the stored token if a new one is returned.
			if ( ! empty( $result['recToken'] ) ) {
				$subscription->update_meta_data( '_wayforpay_rec_token', $result['recToken'] );
				$subscription->save();
			}
		} catch ( Exception $e ) {
			$renewal_order->update_status( OrderStatus::FAILED);
			$renewal_order->add_order_note( sprintf( __( 'Subscription renewal failed: %s', 'woocommerce-wayforpay-gateway' ), $e->getMessage() ) );
		}
	}

	function wayforpay_get_pages( $title = false, $indent = true ): array {
		$wp_pages  = get_pages( 'sort_column=menu_order' );
		$page_list = array();
		if ( $title ) {
			$page_list[] = $title;
		}
		foreach ( $wp_pages as $page ) {
			$prefix = '';
			// show indented child pages?
			if ( $indent ) {
				$has_parent = $page->post_parent;
				while ( $has_parent ) {
					$prefix    .= ' - ';
					$next_page  = get_post( $has_parent );
					$has_parent = $next_page->post_parent;
				}
			}
			// add to a page list array
			$page_list[ $page->ID ] = $prefix . $page->post_title;
		}
		return $page_list;
	}

	private function country_alpha2_to_alpha3( string $alpha2 ): string {
		static $map = array(
			'AF' => 'AFG', 'AX' => 'ALA', 'AL' => 'ALB', 'DZ' => 'DZA', 'AS' => 'ASM',
			'AD' => 'AND', 'AO' => 'AGO', 'AI' => 'AIA', 'AQ' => 'ATA', 'AG' => 'ATG',
			'AR' => 'ARG', 'AM' => 'ARM', 'AW' => 'ABW', 'AU' => 'AUS', 'AT' => 'AUT',
			'AZ' => 'AZE', 'BS' => 'BHS', 'BH' => 'BHR', 'BD' => 'BGD', 'BB' => 'BRB',
			'BY' => 'BLR', 'BE' => 'BEL', 'BZ' => 'BLZ', 'BJ' => 'BEN', 'BM' => 'BMU',
			'BT' => 'BTN', 'BO' => 'BOL', 'BQ' => 'BES', 'BA' => 'BIH', 'BW' => 'BWA',
			'BV' => 'BVT', 'BR' => 'BRA', 'IO' => 'IOT', 'BN' => 'BRN', 'BG' => 'BGR',
			'BF' => 'BFA', 'BI' => 'BDI', 'CV' => 'CPV', 'KH' => 'KHM', 'CM' => 'CMR',
			'CA' => 'CAN', 'KY' => 'CYM', 'CF' => 'CAF', 'TD' => 'TCD', 'CL' => 'CHL',
			'CN' => 'CHN', 'CX' => 'CXR', 'CC' => 'CCK', 'CO' => 'COL', 'KM' => 'COM',
			'CG' => 'COG', 'CD' => 'COD', 'CK' => 'COK', 'CR' => 'CRI', 'CI' => 'CIV',
			'HR' => 'HRV', 'CU' => 'CUB', 'CW' => 'CUW', 'CY' => 'CYP', 'CZ' => 'CZE',
			'DK' => 'DNK', 'DJ' => 'DJI', 'DM' => 'DMA', 'DO' => 'DOM', 'EC' => 'ECU',
			'EG' => 'EGY', 'SV' => 'SLV', 'GQ' => 'GNQ', 'ER' => 'ERI', 'EE' => 'EST',
			'SZ' => 'SWZ', 'ET' => 'ETH', 'FK' => 'FLK', 'FO' => 'FRO', 'FJ' => 'FJI',
			'FI' => 'FIN', 'FR' => 'FRA', 'GF' => 'GUF', 'PF' => 'PYF', 'TF' => 'ATF',
			'GA' => 'GAB', 'GM' => 'GMB', 'GE' => 'GEO', 'DE' => 'DEU', 'GH' => 'GHA',
			'GI' => 'GIB', 'GR' => 'GRC', 'GL' => 'GRL', 'GD' => 'GRD', 'GP' => 'GLP',
			'GU' => 'GUM', 'GT' => 'GTM', 'GG' => 'GGY', 'GN' => 'GIN', 'GW' => 'GNB',
			'GY' => 'GUY', 'HT' => 'HTI', 'HM' => 'HMD', 'VA' => 'VAT', 'HN' => 'HND',
			'HK' => 'HKG', 'HU' => 'HUN', 'IS' => 'ISL', 'IN' => 'IND', 'ID' => 'IDN',
			'IR' => 'IRN', 'IQ' => 'IRQ', 'IE' => 'IRL', 'IM' => 'IMN', 'IL' => 'ISR',
			'IT' => 'ITA', 'JM' => 'JAM', 'JP' => 'JPN', 'JE' => 'JEY', 'JO' => 'JOR',
			'KZ' => 'KAZ', 'KE' => 'KEN', 'KI' => 'KIR', 'KP' => 'PRK', 'KR' => 'KOR',
			'KW' => 'KWT', 'KG' => 'KGZ', 'LA' => 'LAO', 'LV' => 'LVA', 'LB' => 'LBN',
			'LS' => 'LSO', 'LR' => 'LBR', 'LY' => 'LBY', 'LI' => 'LIE', 'LT' => 'LTU',
			'LU' => 'LUX', 'MO' => 'MAC', 'MG' => 'MDG', 'MW' => 'MWI', 'MY' => 'MYS',
			'MV' => 'MDV', 'ML' => 'MLI', 'MT' => 'MLT', 'MH' => 'MHL', 'MQ' => 'MTQ',
			'MR' => 'MRT', 'MU' => 'MUS', 'YT' => 'MYT', 'MX' => 'MEX', 'FM' => 'FSM',
			'MD' => 'MDA', 'MC' => 'MCO', 'MN' => 'MNG', 'ME' => 'MNE', 'MS' => 'MSR',
			'MA' => 'MAR', 'MZ' => 'MOZ', 'MM' => 'MMR', 'NA' => 'NAM', 'NR' => 'NRU',
			'NP' => 'NPL', 'NL' => 'NLD', 'NC' => 'NCL', 'NZ' => 'NZL', 'NI' => 'NIC',
			'NE' => 'NER', 'NG' => 'NGA', 'NU' => 'NIU', 'NF' => 'NFK', 'MK' => 'MKD',
			'MP' => 'MNP', 'NO' => 'NOR', 'OM' => 'OMN', 'PK' => 'PAK', 'PW' => 'PLW',
			'PS' => 'PSE', 'PA' => 'PAN', 'PG' => 'PNG', 'PY' => 'PRY', 'PE' => 'PER',
			'PH' => 'PHL', 'PN' => 'PCN', 'PL' => 'POL', 'PT' => 'PRT', 'PR' => 'PRI',
			'QA' => 'QAT', 'RE' => 'REU', 'RO' => 'ROU', 'RU' => 'RUS', 'RW' => 'RWA',
			'BL' => 'BLM', 'SH' => 'SHN', 'KN' => 'KNA', 'LC' => 'LCA', 'MF' => 'MAF',
			'PM' => 'SPM', 'VC' => 'VCT', 'WS' => 'WSM', 'SM' => 'SMR', 'ST' => 'STP',
			'SA' => 'SAU', 'SN' => 'SEN', 'RS' => 'SRB', 'SC' => 'SYC', 'SL' => 'SLE',
			'SG' => 'SGP', 'SX' => 'SXM', 'SK' => 'SVK', 'SI' => 'SVN', 'SB' => 'SLB',
			'SO' => 'SOM', 'ZA' => 'ZAF', 'GS' => 'SGS', 'SS' => 'SSD', 'ES' => 'ESP',
			'LK' => 'LKA', 'SD' => 'SDN', 'SR' => 'SUR', 'SJ' => 'SJM', 'SE' => 'SWE',
			'CH' => 'CHE', 'SY' => 'SYR', 'TW' => 'TWN', 'TJ' => 'TJK', 'TZ' => 'TZA',
			'TH' => 'THA', 'TL' => 'TLS', 'TG' => 'TGO', 'TK' => 'TKL', 'TO' => 'TON',
			'TT' => 'TTO', 'TN' => 'TUN', 'TR' => 'TUR', 'TM' => 'TKM', 'TC' => 'TCA',
			'TV' => 'TUV', 'UG' => 'UGA', 'UA' => 'UKR', 'AE' => 'ARE', 'GB' => 'GBR',
			'US' => 'USA', 'UM' => 'UMI', 'UY' => 'URY', 'UZ' => 'UZB', 'VU' => 'VUT',
			'VE' => 'VEN', 'VN' => 'VNM', 'VG' => 'VGB', 'VI' => 'VIR', 'WF' => 'WLF',
			'EH' => 'ESH', 'YE' => 'YEM', 'ZM' => 'ZMB', 'ZW' => 'ZWE',
		);
		return $map[ strtoupper( $alpha2 ) ] ?? 'UKR';
	}
}
