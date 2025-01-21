<?php

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

/**
 * WayForPay Payment Gateway
 *
 * Documentation:
 * - Pay https://wiki.wayforpay.com/en/view/852102
 * - Response codes https://wiki.wayforpay.com/en/view/852131
 */
class WC_Wayforpay_Gateway extends WC_Payment_Gateway {
	const WAYFORPAY_URL              = 'https://secure.wayforpay.com/pay';
	const WAYFORPAY_REFERENCE_SUFFIX = '_woo_w4p_';

	const WAYFORPAY_MERCHANT_TEST    = 'WAYFORPAY_MERCHANT_TEST';
	const WAYFORPAY_MERCHANT_ACCOUNT = 'WAYFORPAY_MERCHANT_ACCOUNT';
	const WAYFORPAY_MERCHANT_SECRET  = 'WAYFORPAY_MERCHANT_SECRET';

	const TEST_MERCHANT_ACCOUNT = 'test_merch_n1';
	const TEST_MERCHANT_SECRET  = 'flk3409refn54t54t*FNJRET';

	const ORDER_APPROVED = 'Approved';
	const ORDER_REFUNDED = 'Refunded';
	const ORDER_VOIDED   = 'Voided';
	const ORDER_DECLINED = 'Declined';
	const ORDER_EXPIRED  = 'Expired';

	const SIGNATURE_KEYS_RESPONSE = array(
		'merchantAccount',
		'orderReference',
		'amount',
		'currency',
		'authCode',
		'cardPan',
		'transactionStatus',
		'reasonCode',
	);

	const SIGNATURE_KEYS = array(
		'merchantAccount',
		'merchantDomainName',
		'orderReference',
		'orderDate',
		'amount',
		'currency',
		'productName',
		'productCount',
		'productPrice',
	);

	protected $merchant_account;
	protected $merchant_secret;

	public function __construct() {
		$this->id                 = 'wayforpay';
		$this->method_title       = 'WayForPay';
		$this->method_description = __( 'Card payments, Apple Pay and Google Pay.', 'wp-wayforpay-gateway' );
		$this->has_fields         = false;

		$this->init_settings();
		if ( ! empty( $this->settings['showlogo'] ) && $this->settings['showlogo'] !== 'no' ) {
			$this->icon = WAYFORPAY_PATH . 'public/images/' . $this->settings['showlogo'];
		}

		if ( defined( self::WAYFORPAY_MERCHANT_TEST ) && constant( self::WAYFORPAY_MERCHANT_TEST ) ) {
			$this->settings['merchant_account'] = self::TEST_MERCHANT_ACCOUNT;
			$this->settings['merchant_secret']  = self::TEST_MERCHANT_SECRET;
		} elseif ( defined( self::WAYFORPAY_MERCHANT_ACCOUNT ) && defined( self::WAYFORPAY_MERCHANT_SECRET ) ) {
			$this->settings['merchant_account'] = constant( self::WAYFORPAY_MERCHANT_ACCOUNT );
			$this->settings['merchant_secret']  = constant( self::WAYFORPAY_MERCHANT_SECRET );
		}
		$this->init_form_fields();

		$this->title            = $this->settings['title'];
		$this->description      = $this->settings['description'];
		$this->merchant_account = $this->settings['merchant_account'];
		$this->merchant_secret  = $this->settings['merchant_secret'];

		add_action( 'woocommerce_api_' . $this->id . '_callback', array( $this, 'receive_service_callback' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( &$this, 'receipt_page' ) );
	}

	function init_form_fields(): void {
		$this->form_fields        = array(
			'enabled'          => array(
				'title'       => __( 'Enable/Disable', 'wp-wayforpay-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable WayForPay Payment Engine', 'wp-wayforpay-gateway' ),
				'default'     => 'yes',
			),
			'title'            => array(
				'title'       => __( 'Title', 'wp-wayforpay-gateway' ),
				'type'        => 'text',
				'default'     => __( 'Card or Apple Pay, Google Pay', 'wp-wayforpay-gateway' ),
				'description' => __( 'This controls the title which user sees during checkout.', 'wp-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'wp-wayforpay-gateway' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely via the WayForPay Payment Engine.', 'wp-wayforpay-gateway' ),
				'description' => __( 'This controls the description which user sees during checkout.', 'wp-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'merchant_account' => array(
				'title'       => __( 'Merchant Account', 'wp-wayforpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'Seller identifier. This value is assigned to you by WayForPay.', 'wp-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'merchant_secret'  => array(
				'title'       => __( 'Merchant Secret key', 'wp-wayforpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'Signature secret key. This value is assigned to you by WayForPay.', 'wp-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'showlogo'         => array(
				'title'       => __( 'Display Logo', 'wp-wayforpay-gateway' ),
				'type'        => 'select',
				'options'     => array(
					''        => __( 'No logo', 'wp-wayforpay-gateway' ),
					'w4p.png' => __( 'WayForPay Logo', 'wp-wayforpay-gateway' ),
					'4pp.png' => __( 'Payment Processors Logos', 'wp-wayforpay-gateway' ),
				),
				'default'     => '',
				'description' => __( 'Determines which logo is shown near this payment method on checkout.', 'wp-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'returnUrl'        => array(
				'title'       => __( 'Return URL', 'wp-wayforpay-gateway' ),
				'type'        => 'select',
				'options'     => $this->wayforpay_get_pages( __( 'Select Page', 'wp-wayforpay-gateway' ) ),
				'description' => __( 'The page where the user will be directed after payment.', 'wp-wayforpay-gateway' ),
				'desc_tip'    => true,
			),
			'returnUrl_m'      => array(
				'title'       => __( 'or specify', 'wp-wayforpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'The URL of the page where the user will be directed after payment.', 'wp-wayforpay-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);
		$constant_merchant        = false;
		$constant_controlled_hint = __( 'The value is controlled by the %s constant.', 'wp-wayforpay-gateway' );
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

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 */
	public function admin_options(): void {
		echo '<h3>' . __( 'WayForPay.com', 'wp-wayforpay-gateway' ) . '</h3>';
		echo '<p>' . __( 'Payment gateway', 'wp-wayforpay-gateway' ) . '</p>';
		echo '<table class="form-table">';
		// Generate the HTML For the settings form.
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 *  There are no payment fields for techpro, but we want to show the description if set.
	 */
	function payment_fields(): void {
		if ( $this->description ) {
			echo wpautop( wptexturize( $this->description ) );
		}
	}

	/**
	 * Receipt Page
	 */
	function receipt_page( $order ): void {
		global $woocommerce;

		echo '<p>' . __( 'Thank you for your order, you will now be redirected to the WayForPay payment page.', 'wp-wayforpay-gateway' ) . '</p>';
		echo $this->generate_wayforpay_form( $order );

		$woocommerce->cart->empty_cart();
	}

	public function get_signature( $option, $keys, bool $hashOnly = false ): string {
		$hash = array();
		foreach ( $keys as $dataKey ) {
			if ( ! isset( $option[ $dataKey ] ) ) {
				continue;
			}
			if ( is_array( $option[ $dataKey ] ) ) {
				foreach ( $option[ $dataKey ] as $v ) {
					$hash[] = $v;
				}
			} else {
				$hash [] = $option[ $dataKey ];
			}
		}
		$hash = implode( ';', $hash );
		if ( $hashOnly ) {
			return base64_encode( $hash );
		} else {
			return hash_hmac( 'md5', $hash, $this->merchant_secret );
		}
	}

	public function sign_gateway_form( $data ): array {
		$data['merchantAccount']               = $this->merchant_account;
		$data['merchantAuthType']              = 'simpleSignature';
		$data['merchantDomainName']            = $_SERVER['SERVER_NAME'];
		$data['merchantTransactionSecureType'] = 'AUTO';

		$data['merchantSignature'] = $this->get_signature( $data, self::SIGNATURE_KEYS );
		$data['signString']        = $this->get_signature( $data, self::SIGNATURE_KEYS, true );
		return $data;
	}

	/**
	 * Generate form with fields
	 */
	protected function render_gateway_form( $data ): string {
		$form = '<form method="post" id="form_wayforpay" action="' . self::WAYFORPAY_URL . '" accept-charset="utf-8">';
		foreach ( $data as $k => $v ) {
			$form .= $this->print_input( $k, $v );
		}

		$button = "<img style='position:absolute; top:50%; left:47%; margin-top:-125px; margin-left:-60px;' src='' alt=''>
	        <script>function submitWayForPayForm(){document.getElementById('form_wayforpay').submit()}setTimeout(submitWayForPayForm,1);</script>";

		return $form .
			"<input type='submit' style='display:none;' /></form>"
			. $button;
	}

	/**
	 * Print inputs in a form
	 */
	protected function print_input( $name, $val ): string {
		$str = '';
		if ( ! is_array( $val ) ) {
			return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars( $val ) . '">' . "\n<br />";
		}
		foreach ( $val as $v ) {
			$str .= $this->print_input( $name . '[]', $v );
		}
		return $str;
	}

	/**
	 * Generate wayforpay button link
	 */
	function generate_wayforpay_form( $order_id ): string {
		$order = wc_get_order( $order_id );

		$currency = str_replace(
			array( 'ГРН', 'uah' ),
			array( 'UAH', 'UAH' ),
			get_woocommerce_currency()
		);

		$wayforpay_args = array(
			'orderReference' => $order->get_id() . self::WAYFORPAY_REFERENCE_SUFFIX . time(),
			'orderDate'      => strtotime( $order->get_date_created() ),
			'currency'       => $currency,
			'amount'         => $order->get_total(),
			'returnUrl'      => $this->get_callback_url( $order ),
			'serviceUrl'     => wc_get_endpoint_url( 'wc-api', $this->id . '_callback', get_site_url() ),
			'language'       => $this->get_gateway_language(),
		);

		$items = $order->get_items();
		if ( is_array( $items ) && ! empty( $items ) ) {
			foreach ( $items as $item ) {
				$wayforpay_args['productName'][]  = $item['name'];
				$wayforpay_args['productCount'][] = $item['qty'];
				$wayforpay_args['productPrice'][] = round( $item['line_total'] / $item['qty'], 2 );
			}
		} else {
			$wayforpay_args['productName'][]  = $wayforpay_args['orderReference'];
			$wayforpay_args['productCount'][] = 1;
			$wayforpay_args['productPrice'][] = $wayforpay_args['amount'];
		}
		$phone = $order->get_billing_phone();
		$phone = str_replace( array( '+', ' ', '(', ')' ), array( '', '', '', '' ), $phone );
		if ( strlen( $phone ) === 10 ) {
			$phone = '38' . $phone;
		} elseif ( strlen( $phone ) === 11 ) {
			$phone = '3' . $phone;
		}
		$client         = array(
			'clientFirstName' => $order->get_billing_first_name(),
			'clientLastName'  => $order->get_billing_last_name(),
			'clientAddress'   => $order->get_billing_address_1() . ' ' . $order->get_billing_address_2(),
			'clientCity'      => $order->get_billing_city(),
			'clientPhone'     => $phone,
			'clientEmail'     => $order->get_billing_email(),
			'clientCountry'   => strlen( $order->get_billing_country() ) !== 3 ? 'UKR' : $order->get_billing_country(),
			'clientZipCode'   => $order->get_billing_postcode(),
		);
		$wayforpay_args = array_merge( $wayforpay_args, $client );

		$wayforpay_args = $this->sign_gateway_form( $wayforpay_args );
		return $this->render_gateway_form( $wayforpay_args );
	}

	/**
	 * Process the payment and return the result
	 */
	function process_payment( $order_id ): array {
		$order                = wc_get_order( $order_id );
		$checkout_payment_url = $order->get_checkout_payment_url( true );

		return array(
			'result'   => 'success',
			'redirect' => $checkout_payment_url,
		);
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

	private function get_gateway_language(): string {
		$lang = substr( get_bloginfo( 'language' ), 0, 2 );
		if ( $lang == 'uk' ) {
			$lang = 'ua';
		}
		return $lang;
	}

	/**
	 * Process the service callback and update the order status accordingly.
	 *
	 * @throws Exception
	 */
	protected function handle_service_callback( $response ): void {
		list($orderId,) = explode( self::WAYFORPAY_REFERENCE_SUFFIX, $response['orderReference'] );
		$order          = wc_get_order( $orderId );
		if ( $order === false ) {
			throw new Exception( __( 'An error has occurred during payment. Please contact us to ensure your order has submitted.', 'wp-wayforpay-gateway' ) );
		}

		if ( $this->merchant_account !== $response['merchantAccount'] ) {
			throw new Exception( __( 'An error has occurred during payment. Merchant data is incorrect.', 'wp-wayforpay-gateway' ) );
		}

		$responseSignature = $response['merchantSignature'];

		if ( $this->get_signature( $response, self::SIGNATURE_KEYS_RESPONSE ) !== $responseSignature ) {
			die( __( 'An error has occurred during payment. Signature is not valid.', 'wp-wayforpay-gateway' ) );
		}

		$order_note = sprintf( __( 'Transaction updated, current status: %s', 'wp-wayforpay-gateway' ), $response['transactionStatus'] );
		switch ( $response['transactionStatus'] ) {
			case self::ORDER_APPROVED:
				$order->payment_complete();
				$order_note = sprintf( __( 'Payment successful: %1$s %2$s.', 'wp-wayforpay-gateway' ), $response['amount'], $response['currency'] );

				global $woocommerce;
				if ( $woocommerce->cart && ! $woocommerce->cart->is_empty() ) {
					$woocommerce->cart->empty_cart();
				}
				break;
			case self::ORDER_REFUNDED:
			case self::ORDER_VOIDED:
				$order->update_status( 'refunded' );
				$order_note = sprintf( __( 'Refunded %1$s %2$s.', 'wp-wayforpay-gateway' ), $response['amount'], $response['currency'] );
				break;
			case self::ORDER_EXPIRED:
				$order->update_status( 'failed' );
				$order_note = __( 'Payment expired.', 'wp-wayforpay-gateway' );
				break;
			case self::ORDER_DECLINED:
				$order->update_status( 'failed' );
				$order_note = sprintf( __( 'Payment failed: %1$s - %2$s.', 'wp-wayforpay-gateway' ), $response['reasonCode'] ?? 'N/A', $response['reason'] ?? 'N/A' );
				break;
		}

		$order_note .= '<br/>' . sprintf( __( 'WayForPay ID: %s', 'wp-wayforpay-gateway' ), $response['orderReference'] );
		$order->add_order_note( $order_note );
	}

	/**
	 * Generates response to the service callback, to let the WayForPay know that the callback was processed.
	 */
	public function respond_service_callback( $data ): string {
		$responseToGateway = array(
			'orderReference' => $data['orderReference'],
			'status'         => 'accept',
			'time'           => time(),
		);

		$sign = array();
		foreach ( $responseToGateway as $dataValue ) {
			$sign [] = $dataValue;
		}
		$sign = implode( ';', $sign );
		$sign = hash_hmac( 'md5', $sign, $this->merchant_secret );

		$responseToGateway['signature'] = $sign;
		return json_encode( $responseToGateway );
	}

	/**
	 * This will be called when the service callback is received.
	 */
	function receive_service_callback(): void {
		$data = json_decode( file_get_contents( 'php://input' ), true );

		try {
			$this->handle_service_callback( $data );
			echo $this->respond_service_callback( $data );
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
