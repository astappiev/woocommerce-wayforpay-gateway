<?php

class WayforpayException extends Exception {
	protected final int|string|null $statusCode;
	protected final array|null $body;

	public function __construct( $message, $statusCode = null, $body = null ) {
		parent::__construct( $message );
		$this->statusCode = $statusCode;
		$this->body       = $body;
	}

	public function getStatusCode(): int|string|null {
		return $this->statusCode;
	}

	public function getBody(): array|null {
		return $this->body;
	}
}
