<?php

class WayforpayException extends Exception {
	private ?array $response;

	/**
	 * @param string|array $message  The exception message or the full response array from WayForPay API.
	 *   If $message is an array, it will be treated as the response and the message will be extracted from the 'reason' key if available.
	 * @param array|null   $response Optional. The full response array from WayForPay API if $message is a string.
	 */
	public function __construct( string|array $message, ?array $response = null ) {
		if ( is_array( $message ) ) {
			$response = $message;
			$message  = empty( $response['reason'] ) ? '' : $response['reason'];
		}

		parent::__construct( $message );
		$this->response = $response;
	}

	public function getResponse(): ?array {
		return $this->response;
	}
}
