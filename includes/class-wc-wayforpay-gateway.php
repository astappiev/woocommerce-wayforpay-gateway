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

	protected $merchant_id;
	protected $secretKey;

	public function __construct() {
		$this->id                 = 'wayforpay';
		$this->method_title       = 'WayForPay';
		$this->method_description = __( 'Card payments, Apple Pay and Google Pay.', 'woocommerce-wayforpay-payments' );
		$this->has_fields         = false;

		$this->init_settings();
		if ( $this->settings['showlogo'] === 'yes' ) {
			$this->icon = WAYFORPAY_PATH . 'public/images/w4p.png';
		}
		$this->init_form_fields();

		$this->title       = $this->settings['title'];
		$this->description = $this->settings['description'];
		$this->merchant_id = $this->settings['merchant_account'];
		$this->secretKey   = $this->settings['secret_key'];

		add_action( 'woocommerce_api_' . $this->id . '_callback', array( $this, 'receive_service_callback' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( &$this, 'receipt_page' ) );
	}

	function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'          => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-wayforpay-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable WayForPay Payment Module.', 'woocommerce-wayforpay-payments' ),
				'default'     => 'no',
				'description' => __( 'Show in the Payment List as a payment option', 'woocommerce-wayforpay-payments' ),
			),
			'title'            => array(
				'title'       => __( 'Title:', 'woocommerce-wayforpay-payments' ),
				'type'        => 'text',
				'default'     => __( 'Internet acquiring', 'woocommerce-wayforpay-payments' ),
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-wayforpay-payments' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description:', 'woocommerce-wayforpay-payments' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely by Credit or Debit Card or Internet Banking through wayforpay.com service.', 'woocommerce-wayforpay-payments' ),
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-wayforpay-payments' ),
				'desc_tip'    => true,
			),
			'merchant_account' => array(
				'title'       => __( 'Merchant Login', 'woocommerce-wayforpay-payments' ),
				'type'        => 'text',
				'description' => __( 'Given to Merchant by wayforpay.com', 'woocommerce-wayforpay-payments' ),
				'default'     => 'test_merch_n1',
				'desc_tip'    => true,
			),
			'secret_key'       => array(
				'title'       => __( 'Merchant Secret key', 'woocommerce-wayforpay-payments' ),
				'type'        => 'text',
				'description' => __( 'Given to Merchant by wayforpay.com', 'woocommerce-wayforpay-payments' ),
				'desc_tip'    => true,
				'default'     => 'flk3409refn54t54t*FNJRET',
			),
			'showlogo'         => array(
				'title'       => __( 'Show Logo', 'woocommerce-wayforpay-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show the wayforpay.com logo in the Payment Method section for the user', 'woocommerce-wayforpay-payments' ),
				'default'     => 'yes',
				'description' => __( 'Tick to show wayforpay.com logo', 'woocommerce-wayforpay-payments' ),
				'desc_tip'    => true,
			),
			'returnUrl'        => array(
				'title'       => __( 'Return URL', 'woocommerce-wayforpay-payments' ),
				'type'        => 'select',
				'options'     => $this->wayforpay_get_pages( __( 'Select Page', 'woocommerce-wayforpay-payments' ) ),
				'description' => __( 'URL of success page', 'woocommerce-wayforpay-payments' ),
				'desc_tip'    => true,
			),
			'returnUrl_m'      => array(
				'title'       => __( 'or specify', 'woocommerce-wayforpay-payments' ),
				'type'        => 'text',
				'description' => __( 'URL of success page', 'woocommerce-wayforpay-payments' ),
				'default'     => '',
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 */
	public function admin_options(): void {
		echo '<h3>' . __( 'WayForPay.com', 'woocommerce-wayforpay-payments' ) . '</h3>';
		echo '<p>' . __( 'Payment gateway', 'woocommerce-wayforpay-payments' ) . '</p>';
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

		echo '<p>' . __( 'Thank you for your order, you will now be redirected to the WayForPay payment page.', 'woocommerce-wayforpay-payments' ) . '</p>';
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
			return hash_hmac( 'md5', $hash, $this->secretKey );
		}
	}

	public function sign_gateway_form( $data ): void {
		$data['merchantAccount']               = $this->merchant_id;
		$data['merchantAuthType']              = 'simpleSignature';
		$data['merchantDomainName']            = $_SERVER['SERVER_NAME'];
		$data['merchantTransactionSecureType'] = 'AUTO';

		$data['merchantSignature'] = $this->get_signature( $data, self::SIGNATURE_KEYS );
		$data['signString']        = $this->get_signature( $data, self::SIGNATURE_KEYS, true );
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

		$this->sign_gateway_form( $wayforpay_args );
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
			throw new Exception( __( 'An error has occurred during payment. Please contact us to ensure your order has submitted.', 'woocommerce-wayforpay-payments' ) );
		}

		if ( $this->merchant_account !== $response['merchantAccount'] ) {
			throw new Exception( __( 'An error has occurred during payment. Merchant data is incorrect.', 'woocommerce-wayforpay-payments' ) );
		}

		$responseSignature = $response['merchantSignature'];

		if ( $this->get_signature( $response, self::SIGNATURE_KEYS_RESPONSE ) !== $responseSignature ) {
			die( __( 'An error has occurred during payment. Signature is not valid.', 'woocommerce-wayforpay-payments' ) );
		}

		$order_note = sprintf( __( 'Transaction updated, current status: %s', 'woocommerce-wayforpay-payments' ), $response['transactionStatus'] );
		switch ( $response['transactionStatus'] ) {
			case self::ORDER_APPROVED:
				$order->payment_complete();
				$order_note = sprintf( __( 'Payment successful: %1$s %2$s.', 'woocommerce-wayforpay-payments' ), $response['amount'], $response['currency'] );

				global $woocommerce;
				if ( $woocommerce->cart && ! $woocommerce->cart->is_empty() ) {
					$woocommerce->cart->empty_cart();
				}
				break;
			case self::ORDER_REFUNDED:
			case self::ORDER_VOIDED:
				$order->update_status( 'refunded' );
				$order_note = sprintf( __( 'Refunded %1$s %2$s.', 'woocommerce-wayforpay-payments' ), $response['amount'], $response['currency'] );
				break;
			case self::ORDER_EXPIRED:
				$order->update_status( 'failed' );
				$order_note = __( 'Payment expired.', 'woocommerce-wayforpay-payments' );
				break;
			case self::ORDER_DECLINED:
				$order->update_status( 'failed' );
				$order_note = sprintf( __( 'Payment failed: %1$s - %2$s.', 'woocommerce-wayforpay-payments' ), $response['reasonCode'] ?? 'N/A', $response['reason'] ?? 'N/A' );
				break;
		}

		$order_note .= '<br/>' . sprintf( __( 'WayForPay ID: %s', 'woocommerce-wayforpay-payments' ), $response['orderReference'] );
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
		$sign = hash_hmac( 'md5', $sign, $this->secretKey );

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
