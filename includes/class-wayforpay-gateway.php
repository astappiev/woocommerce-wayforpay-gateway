<?php

use Automattic\WooCommerce\Enums\OrderStatus;

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

class Wayforpay_Gateway extends WC_Payment_Gateway {
	const WAYFORPAY_REFERENCE_SUFFIX = '_woo_';

	const WAYFORPAY_MERCHANT_TEST    = 'WAYFORPAY_MERCHANT_TEST';
	const WAYFORPAY_MERCHANT_ACCOUNT = 'WAYFORPAY_MERCHANT_ACCOUNT';
	const WAYFORPAY_MERCHANT_SECRET  = 'WAYFORPAY_MERCHANT_SECRET';

	protected Wayforpay $wayforpay;

	public function __construct() {
		$this->id                 = 'wayforpay';
		$this->method_title       = 'WayForPay';
		$this->method_description = __( 'Accept card payments, Apple Pay and Google Pay via WayForPay payment gateway.', 'woocommerce-wayforpay-gateway' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );

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

	private function transform_order_to_payload( $order ): array {
		/**
		 * Filters the order reference suffix.
		 *
		 * @param string $suffix the suffix to be appended to the order reference.
		 */
		$reference_suffix = apply_filters( 'woocommerce_wayforpay_gateway_order_reference_suffix', 'woo_' . time() );

		$order_args = array(
			'orderReference' => $order->get_id() . '_' . $reference_suffix,
			'orderDate'      => $order->get_date_created()->getTimestamp(),
			'amount'         => $order->get_total(),
			'currency'       => $this->get_gateway_currency(),
		);

		$items = $order->get_items();
		if ( is_array( $items ) && ! empty( $items ) ) {
			foreach ( $items as $item ) {
				$order_args['productName'][]  = $item['name'];
				$order_args['productCount'][] = $item['qty'];
				$order_args['productPrice'][] = round( $item['line_total'] / $item['qty'], 2 );
			}
		} else {
			$order_args['productName'][]  = $order_args['orderReference'];
			$order_args['productCount'][] = 1;
			$order_args['productPrice'][] = $order_args['amount'];
		}

		$client_args = array(
			'clientFirstName' => $order->get_billing_first_name(),
			'clientLastName'  => $order->get_billing_last_name(),
			'clientAddress'   => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
			'clientCity'      => $order->get_billing_city(),
			'clientPhone'     => $order->get_billing_phone(),
			'clientEmail'     => $order->get_billing_email(),
			'clientCountry'   => strlen( $order->get_billing_country() ) !== 3 ? 'UKR' : $order->get_billing_country(),
			'clientZipCode'   => $order->get_billing_postcode(),
		);

		return array_merge( $order_args, $client_args );
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
				}
				break;
			case Wayforpay::TRANSACTION_REFUNDED:
			case Wayforpay::TRANSACTION_VOIDED:
				if ( $order->get_status() !== OrderStatus::REFUNDED ) {
					$order->update_status( OrderStatus::REFUNDED );
					$order->add_order_note( sprintf( __( 'Refunded %1$s %2$s.', 'woocommerce-wayforpay-gateway' ), $response['amount'], $response['currency'] ) );
				}
				break;
			case Wayforpay::TRANSACTION_DECLINED:
				if ( $order->get_status() !== OrderStatus::FAILED ) {
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
				die( __( 'An error has occurred during redirect, if it persists, please contact us.', 'woocommerce-wayforpay-gateway' ) );
			}

			if ( ! $this->wayforpay->verify_callback( $_POST ) ) {
				die( __( 'An error has occurred during processing. Signature is not valid.', 'woocommerce-wayforpay-gateway' ) );
			}

			$order = $this->handle_service_callback( $_POST );
			wp_safe_redirect( $this->get_callback_url( $order ) );
		} catch ( Exception $e ) {
			echo $e->getMessage();
		}
		exit;
	}

	function post_payment_request( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
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
}
