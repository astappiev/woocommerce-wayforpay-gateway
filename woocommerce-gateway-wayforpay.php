<?php
/**
 * Plugin Name: WooCommerce WayForPay Gateway
 * Description: WayForPay Payment Gateway for WooCommerce.
 * Version: 2.0.0-alpha1
 * Author: Dev team WayForPay, Oleh Astappiev
 * Author: support@wayforpay.com, oleh@astappiev.me
 * Plugin URI: https://github.com/astappiev/wp-wayforpay-gateway
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * Requires Plugins: woocommerce
 * WC requires at least: 7.6
 * WC tested up to: 9.5
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

define( 'IMGDIR', WP_PLUGIN_URL . '/' . plugin_basename( __DIR__ ) . '/assets/' );

add_action( 'init', 'woocommerce_wayforpay_i18n' );

function woocommerce_wayforpay_i18n() {
	load_plugin_textdomain( 'woocommerce-wayforpay-payments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'before_woocommerce_init', 'woocommerce_wayforpay_declare_hpos_compatibility' );

function woocommerce_wayforpay_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
	}
}

add_action( 'plugins_loaded', 'woocommerce_wayforpay_init', 0 );

function woocommerce_wayforpay_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	/**
	 * Gateway class
	 */
	class WC_wayforpay extends WC_Payment_Gateway {
		protected $url = 'https://secure.wayforpay.com/pay';

		const ORDER_APPROVED = 'Approved';
		const ORDER_REFUNDED = 'Refunded';
		const ORDER_SUFFIX   = '_woo_w4p_';

		protected $response_signature_keys = array(
			'merchantAccount',
			'orderReference',
			'amount',
			'currency',
			'authCode',
			'cardPan',
			'transactionStatus',
			'reasonCode',
		);

		protected $signature_keys = array(
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

		protected $msg;
		protected $redirect_page_id;

		public function __construct() {
			$this->id                 = 'wayforpay';
			$this->method_title       = 'WayForPay';
			$this->method_description = __( 'Card payments, Apple Pay and Google Pay.', 'woocommerce-wayforpay-payments' );
			$this->has_fields         = false;
			$this->init_form_fields();
			$this->init_settings();
			if ( $this->settings['showlogo'] === 'yes' ) {
				$this->icon = IMGDIR . 'w4p.png';
			}
			$this->title            = $this->settings['title'];
			$this->redirect_page_id = $this->settings['returnUrl'];

			$this->merchant_id = $this->settings['merchant_account'];
			$this->secretKey   = $this->settings['secret_key'];
			$this->description = $this->settings['description'];

			add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_wayforpay_response' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_receipt_wayforpay', array( &$this, 'receipt_page' ) );
		}

		function init_form_fields() {
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
				'serviceUrl'       => array(
					'title'       => __( 'Service URL', 'woocommerce-wayforpay-payments' ),
					'options'     => $this->wayforpay_get_pages( __( 'Select Page', 'woocommerce-wayforpay-payments' ) ),
					'type'        => 'select',
					'description' => __( 'URL with result of transaction page', 'woocommerce-wayforpay-payments' ),
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 */
		public function admin_options() {
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
		function payment_fields() {
			if ( $this->description ) {
				echo wpautop( wptexturize( $this->description ) );
			}
		}

		/**
		 * Receipt Page
		 */
		function receipt_page( $order ) {
			global $woocommerce;

			echo '<p>' . __( 'Thank you for your order, you will now be redirected to the WayForPay payment page.', 'woocommerce-wayforpay-payments' ) . '</p>';
			echo $this->generate_wayforpay_form( $order );

			$woocommerce->cart->empty_cart();
		}

		public function getRequestSignature( $options ): string {
			return $this->getSignature( $options, $this->signature_keys );
		}

		public function getResponseSignature( $options ): string {
			return $this->getSignature( $options, $this->response_signature_keys );
		}

		public function getSignature( $option, $keys, bool $hashOnly = false ): string {
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

		public function fillPayForm( $data ): string {
			$data['merchantAccount']               = $this->merchant_id;
			$data['merchantAuthType']              = 'simpleSignature';
			$data['merchantDomainName']            = $_SERVER['SERVER_NAME'];
			$data['merchantTransactionSecureType'] = 'AUTO';

			$data['merchantSignature'] = $this->getRequestSignature( $data );
			$data['signString']        = $this->getSignature( $data, $this->signature_keys, true );
			return $this->generateForm( $data );
		}

		/**
		 * Generate form with fields
		 */
		protected function generateForm( $data ): string {
			$form = '<form method="post" id="form_wayforpay" action="' . $this->url . '" accept-charset="utf-8">';
			foreach ( $data as $k => $v ) {
				$form .= $this->printInput( $k, $v );
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
		protected function printInput( $name, $val ): string {
			$str = '';
			if ( ! is_array( $val ) ) {
				return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars( $val ) . '">' . "\n<br />";
			}
			foreach ( $val as $v ) {
				$str .= $this->printInput( $name . '[]', $v );
			}
			return $str;
		}

		public function getAnswerToGateWay( $data ): string {
			$time              = time();
			$responseToGateway = array(
				'orderReference' => $data['orderReference'],
				'status'         => 'accept',
				'time'           => $time,
			);
			$sign              = array();
			foreach ( $responseToGateway as $dataValue ) {
				$sign [] = $dataValue;
			}
			$sign                           = implode( ';', $sign );
			$sign                           = hash_hmac( 'md5', $sign, $this->secretKey );
			$responseToGateway['signature'] = $sign;

			return json_encode( $responseToGateway );
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
				'orderReference' => $order->get_id() . self::ORDER_SUFFIX . time(),
				'orderDate'      => strtotime( $order->get_date_created() ),
				'currency'       => $currency,
				'amount'         => $order->get_total(),
				'returnUrl'      => $this->getCallbackUrl() . '?key=' . $order->get_order_key() . '&order=' . $order_id,
				'serviceUrl'     => $this->getCallbackUrl( true ),
				'language'       => $this->getLanguage(),
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

			return $this->fillPayForm( $wayforpay_args );
		}

		/**
		 * Process the payment and return the result
		 */
		function process_payment( $order_id ): array {
			$order                = wc_get_order( $order_id );
			$checkout_payment_url = $order->get_checkout_payment_url( true );

			return array(
				'result'   => 'success',
				'redirect' => add_query_arg( 'order', $order->get_id(), add_query_arg( 'key', $order->get_order_key(), $checkout_payment_url ) ),
			);
		}

		private function getCallbackUrl( bool $service = false ) {
			$redirect_url = ( $this->redirect_page_id === '' || $this->redirect_page_id === 0 ) ? get_site_url() . '/' : get_permalink( $this->redirect_page_id );
			if ( ! $service ) {
				if ( isset( $this->settings['returnUrl_m'] ) && trim( $this->settings['returnUrl_m'] ) !== '' ) {
					return trim( $this->settings['returnUrl_m'] );
				}
				return $redirect_url;
			}

			return add_query_arg( 'wc-api', get_class( $this ), $redirect_url );
		}

		private function getLanguage() {
			return substr( get_bloginfo( 'language' ), 0, 2 );
		}

		protected function isPaymentValid( $response ) {
			global $woocommerce;

			list($orderId,) = explode( self::ORDER_SUFFIX, $response['orderReference'] );
			$order          = wc_get_order( $orderId );
			if ( $order === false ) {
				return __( 'An error has occurred during payment. Please contact us to ensure your order has submitted.', 'woocommerce-wayforpay-payments' );
			}

			if ( $this->merchant_id !== $response['merchantAccount'] ) {
				return __( 'An error has occurred during payment. Merchant data is incorrect.', 'woocommerce-wayforpay-payments' );
			}

			$responseSignature = $response['merchantSignature'];

			if ( $this->getResponseSignature( $response ) !== $responseSignature ) {
				die( __( 'An error has occurred during payment. Signature is not valid.', 'woocommerce-wayforpay-payments' ) );
			}

			if ( $response['transactionStatus'] === self::ORDER_APPROVED ) {
				// $order->update_status('processing');
				$order->update_status( 'completed' );
				$order->payment_complete();
				$order->add_order_note( __( 'WayForPay payment successful.<br/>WayForPay ID: ', 'woocommerce-wayforpay-payments' ) . ' (' . ( $response['orderReference'] ?? '-' ) . ')' );
				return true;
			} elseif ( $response['transactionStatus'] === self::ORDER_REFUNDED ) {
				$order->update_status( 'cancelled' );
				$order->add_order_note( __( 'Refund payment.', 'woocommerce-wayforpay-payments' ) );
				return true;
			}

			$woocommerce->cart->empty_cart();

			return false;
		}

		/**
		 * Check response on service url
		 */
		function check_wayforpay_response() {
			$data        = json_decode( file_get_contents( 'php://input' ), true );
			$paymentInfo = $this->isPaymentValid( $data );
			if ( $paymentInfo === true ) {
				echo $this->getAnswerToGateWay( $data );
			}
			exit;
		}

		// get all pages
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

	/**
	 * Add the Gateway to WooCommerce
	 */
	function woocommerce_add_wayforpay_gateway( $methods ) {
		$methods[] = 'WC_wayforpay';
		return $methods;
	}

	add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_wayforpay_gateway' );
}
