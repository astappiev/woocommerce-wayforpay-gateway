<?php

class Wayforpay {
	private const string WAYFORPAY_URL = 'https://secure.wayforpay.com/pay';
	private const string WAYFORPAY_API = 'https://api.wayforpay.com/api';

	const string TEST_MERCHANT_ACCOUNT = 'test_merch_n1';
	const string TEST_MERCHANT_SECRET  = 'flk3409refn54t54t*FNJRET';

	// https://wiki.wayforpay.com/en/view/852131
	const string TRANSACTION_APPROVED             = 'Approved';
	const string TRANSACTION_REFUNDED             = 'Refunded';
	const string TRANSACTION_REFUND_IN_PROCESSING = 'RefundInProcessing'; // In case when not enough funds on shop balance
	const string TRANSACTION_VOIDED               = 'Voided';
	const string TRANSACTION_DECLINED             = 'Declined';
	const string TRANSACTION_EXPIRED              = 'Expired';

	// https://wiki.wayforpay.com/en/view/852131
	const int RESPONSE_CODE_OK = 1100;

	// Charge (host-to-host) uses the same signature fields as Purchase.
	private const array SIGNATURE_KEYS_PURCHASE = array(
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

	private const array SIGNATURE_KEYS_REFUND = array(
		'merchantAccount',
		'orderReference',
		'amount',
		'currency',
	);

	private const array SIGNATURE_KEYS_SERVICE_CALLBACK = array(
		'merchantAccount',
		'orderReference',
		'amount',
		'currency',
		'authCode',
		'cardPan',
		'transactionStatus',
		'reasonCode',
	);

	private const array SIGNATURE_KEYS_REFUND_RESPONSE = array(
		'merchantAccount',
		'orderReference',
		'transactionStatus',
		'reasonCode',
	);

	private const array SIGNATURE_KEYS_CHECK_STATUS = array(
		'merchantAccount',
		'orderReference',
	);

	private const array SIGNATURE_KEYS_SERVICE_RESPONSE = array(
		'orderReference',
		'status',
		'time',
	);

	protected string $merchant_account;
	protected string $merchant_secret;

	public function __construct( $account, $secret ) {
		$this->merchant_account = strval( $account );
		$this->merchant_secret  = strval( $secret );
	}

	/**
	 * Purchase request is used to initiate payment with a client on the protected wayforpay site.
	 * If sent with `recToken`, it returns a url to wayforpay on which the user card details is already prefilled and only CVV is required.
	 *
	 * Required payload:
	 *  - merchantAccount, merchantDomainName, merchantTransactionSecureType, merchantSignature, orderDate, amount, currency, productName[], productPrice[], productCount[]
	 *
	 * Documentation:
	 * https://wiki.wayforpay.com/en/view/852102
	 *
	 * @param boolean $offline When true, the request will use mobile application flow to receive the payment link directly without POST form and client-side submit.
	 *
	 * @throws WayforpayException
	 */
	public function purchase( $payload, bool $offline = true ): array {
		$payload['merchantDomainName']            = $payload['merchantDomainName'] ?? $_SERVER['SERVER_NAME'];
		$payload['apiVersion']                    = 2;
		$payload['merchantTransactionSecureType'] = 'AUTO';
		$payload['merchantAccount']               = $this->merchant_account;
		$payload['merchantSignature']             = $this->hash_payload( $payload, self::SIGNATURE_KEYS_PURCHASE );

		$result = $this->send_request( $payload, self::WAYFORPAY_URL . ($offline ? '?behavior=offline' : '') );

		if ( ! empty( $result['transactionStatus'] ) && $result['transactionStatus'] === self::TRANSACTION_DECLINED ) {
			throw new WayforpayException( $result['reason'], null, $result );
		}

		return $result;
	}

	/**
	 * Charge a card using a previously obtained recToken (server-to-server, no customer redirect).
	 * Used for subscription renewal payments.
	 *
	 * Required payload:
	 *  - transactionType, merchantAccount, merchantDomainName, merchantTransactionType, merchantTransactionSecureType, merchantSignature,
	 *    apiVersion, orderReference, orderDate, amount, currency, productName[], productPrice[], productCount[], clientFirstName,
	 *    clientLastName, clientEmail, clientPhone, clientCountry, clientIpAddress
	 *
	 * Documentation:
	 * https://wiki.wayforpay.com/en/view/852194
	 *
	 * @throws WayforpayException
	 */
	public function charge( $payload ): array {
		$payload['transactionType']               = 'CHARGE';
		$payload['merchantDomainName']            = $payload['merchantDomainName'] ?? $_SERVER['SERVER_NAME'];
		$payload['apiVersion']                    = 2;
		$payload['merchantTransactionType']       = 'SALE';
		$payload['merchantTransactionSecureType'] = 'NON3DS'; // important, otherwise WayForPay want us to redirect customer to 3DS page
		$payload['merchantAccount']               = $this->merchant_account;
		$payload['merchantSignature']             = $this->hash_payload( $payload, self::SIGNATURE_KEYS_PURCHASE );

		$result = $this->send_request( $payload );

		if ( ! empty( $result['transactionStatus'] ) && $result['transactionStatus'] === self::TRANSACTION_DECLINED ) {
			throw new WayforpayException( $result['reason'], null, $result );
		}

		return $result;
	}

	/**
	 * Refund request is to be used for making of assets' refund or cancellation of payment.
	 *
	 * Documentation:
	 * https://wiki.wayforpay.com/en/view/852115
	 *
	 * @throws WayforpayException
	 */
	public function refund( $payload ): array {
		$payload['transactionType']   = 'REFUND';
		$payload['apiVersion']        = 1;
		$payload['merchantAccount']   = $this->merchant_account;
		$payload['merchantSignature'] = $this->hash_payload( $payload, self::SIGNATURE_KEYS_REFUND );

		$result = $this->send_request( $payload );

		if ( ! empty( $result['merchantSignature'] ) && ! $this->verify_payload( $result, self::SIGNATURE_KEYS_REFUND_RESPONSE ) ) {
			throw new WayforpayException( __( 'Refund response signature is not valid.', 'woocommerce-wayforpay-gateway' ) );
		}

		if ( ! empty( $result['transactionStatus'] ) && $result['transactionStatus'] === self::TRANSACTION_DECLINED ) {
			throw new WayforpayException( $result['reason'], null, $result );
		}

		return $result;
	}

	/**
	 * Check the status of a previously created order.
	 *
	 * Documentation:
	 * https://wiki.wayforpay.com/en/view/852117
	 *
	 * @throws WayforpayException
	 */
	public function check_status( string $order_reference ): array {
		$payload                      = array(
			'transactionType' => 'CHECK_STATUS',
			'merchantAccount' => $this->merchant_account,
			'orderReference'  => $order_reference,
			'apiVersion'      => 1,
		);
		$payload['merchantSignature'] = $this->hash_payload( $payload, self::SIGNATURE_KEYS_CHECK_STATUS );

		return $this->send_request( $payload );
	}

	/**
	 * @throws WayforpayException
	 */
	private function send_request( $body, $endpoint = self::WAYFORPAY_API ): array {
		$args = array(
			'method'  => 'POST',
			'body'    => json_encode( $body ),
			'headers' => array(
				'Content-Type' => 'application/json;charset=utf-8',
			),
		);

		$response = wp_safe_remote_post( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			throw new WayforpayException( $response->get_error_message(), wp_remote_retrieve_response_code( $response ) );
		}

		$result = json_decode( $response['body'], true );

		if ( ! empty( $result['reasonCode'] ) && $result['reasonCode'] != self::RESPONSE_CODE_OK ) {
			throw new WayforpayException( $result['reason'], wp_remote_retrieve_response_code( $response ), $result );
		}

		return $result;
	}

	public function verify_callback( $payload ): bool {
		return $this->verify_payload( $payload, self::SIGNATURE_KEYS_SERVICE_CALLBACK );
	}

	public function respond_callback( $payload ): array {
		$payload = array(
			'orderReference' => $payload['orderReference'],
			'status'         => 'accept',
			'time'           => time(),
		);

		$payload['signature'] = $this->hash_payload( $payload, self::SIGNATURE_KEYS_SERVICE_RESPONSE );
		return $payload;
	}

	private function verify_payload( $payload, $keys ): bool {
		if ( empty( $payload['merchantAccount'] ) || empty( $payload['merchantSignature'] ) || $payload['merchantAccount'] !== $this->merchant_account ) {
			return false;
		}

		if ( $this->hash_payload( $payload, $keys ) === $payload['merchantSignature'] ) {
			return true;
		}

		return false;
	}

	private function hash_payload( $payload, $keys, bool $hash_only = false ): string {
		$hash = array();
		foreach ( $keys as $key ) {
			if ( ! isset( $payload[ $key ] ) ) {
				continue;
			}
			if ( is_array( $payload[ $key ] ) ) {
				foreach ( $payload[ $key ] as $v ) {
					$hash[] = $v;
				}
			} else {
				$hash [] = $payload[ $key ];
			}
		}
		$hash = implode( ';', $hash );
		if ( $hash_only ) {
			return base64_encode( $hash );
		} else {
			return hash_hmac( 'md5', $hash, $this->merchant_secret );
		}
	}
}
