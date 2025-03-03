<?php

class Wayforpay {
	private const WAYFORPAY_URL = 'https://secure.wayforpay.com/pay';
	private const WAYFORPAY_API = 'https://api.wayforpay.com/api';

	const TEST_MERCHANT_ACCOUNT = 'test_merch_n1';
	const TEST_MERCHANT_SECRET  = 'flk3409refn54t54t*FNJRET';

	// https://wiki.wayforpay.com/en/view/852131
	const TRANSACTION_APPROVED             = 'Approved';
	const TRANSACTION_REFUNDED             = 'Refunded';
	const TRANSACTION_REFUND_IN_PROCESSING = 'RefundInProcessing'; // In case when not enough funds on shop balance
	const TRANSACTION_VOIDED               = 'Voided';
	const TRANSACTION_DECLINED             = 'Declined';
	const TRANSACTION_EXPIRED              = 'Expired';

	private const SIGNATURE_KEYS_PURCHASE = array(
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

	private const SIGNATURE_KEYS_REFUND = array(
		'merchantAccount',
		'orderReference',
		'amount',
		'currency',
	);

	private const SIGNATURE_KEYS_SERVICE_CALLBACK = array(
		'merchantAccount',
		'orderReference',
		'amount',
		'currency',
		'authCode',
		'cardPan',
		'transactionStatus',
		'reasonCode',
	);

	private const SIGNATURE_KEYS_SERVICE_RESPONSE = array(
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
	 *
	 * Documentation:
	 * https://wiki.wayforpay.com/en/view/852102
	 *
	 * @throws Exception
	 */
	public function purchase( $payload ): array {
		$payload['merchantDomainName']            = $payload['merchantDomainName'] ?? $_SERVER['SERVER_NAME'];
		$payload['apiVersion']                    = 2;
		$payload['merchantTransactionSecureType'] = 'AUTO';
		$payload['merchantAccount']               = $this->merchant_account;
		$payload['merchantSignature']             = $this->hash_payload( $payload, self::SIGNATURE_KEYS_PURCHASE );

		// this hack with ?offline is needed to avoid POST form and client-side submit
		$result = $this->send_request( $payload, self::WAYFORPAY_URL . '?behavior=offline' );

		if ( ! empty( $result['transactionStatus'] ) && $result['transactionStatus'] == self::TRANSACTION_DECLINED ) {
			throw new Exception( $result['reason'] );
		}

		return $result;
	}

	/**
	 * Refund request is to be used for making of assets' refund or cancellation of payment.
	 *
	 * Documentation:
	 * https://wiki.wayforpay.com/en/view/852115
	 *
	 * @throws Exception
	 */
	public function refund( $payload ): array {
		$payload['transactionType']   = 'REFUND';
		$payload['apiVersion']        = 1;
		$payload['merchantAccount']   = $this->merchant_account;
		$payload['merchantSignature'] = $this->hash_payload( $payload, self::SIGNATURE_KEYS_REFUND );

		$result = $this->send_request( $payload, self::WAYFORPAY_API );

		if ( ! empty( $result['transactionStatus'] ) && $result['transactionStatus'] == self::TRANSACTION_DECLINED ) {
			throw new Exception( $result['reason'] );
		}

		return $result;
	}

	/**
	 * @throws Exception
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
			throw new Exception( $response->get_error_message() );
		}

		return json_decode( $response['body'], true );
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

	private function hash_payload( $payload, $keys, bool $hashOnly = false ): string {
		$hash = array();
		foreach ( $keys as $dataKey ) {
			if ( ! isset( $payload[ $dataKey ] ) ) {
				continue;
			}
			if ( is_array( $payload[ $dataKey ] ) ) {
				foreach ( $payload[ $dataKey ] as $v ) {
					$hash[] = $v;
				}
			} else {
				$hash [] = $payload[ $dataKey ];
			}
		}
		$hash = implode( ';', $hash );
		if ( $hashOnly ) {
			return base64_encode( $hash );
		} else {
			return hash_hmac( 'md5', $hash, $this->merchant_secret );
		}
	}
}
