<?php
/**
 * A failed American Gun Trader API call.
 *
 * @package AgtSync
 */

namespace AgtSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries enough detail for the sync engine to decide what to do next: retry,
 * give up, or tell the merchant something they can actually fix.
 */
final class ApiException extends \Exception {

	/**
	 * HTTP status, or 0 for a transport failure.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * The machine-readable error code from the API body, if any.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Field => messages, from a 422.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $errors;

	/**
	 * Seconds to wait before retrying, from a 429's Retry-After.
	 *
	 * @var int
	 */
	private int $retry_after;

	/**
	 * Construct.
	 *
	 * @param string                          $message     Human-readable message.
	 * @param int                             $status      HTTP status (0 = transport error).
	 * @param string                          $error_code  Machine-readable code.
	 * @param array<string,array<int,string>> $errors      Validation errors.
	 * @param int                             $retry_after Seconds to back off.
	 */
	public function __construct( string $message, int $status = 0, string $error_code = '', array $errors = array(), int $retry_after = 0 ) {
		parent::__construct( $message );

		$this->status      = $status;
		$this->error_code  = $error_code;
		$this->errors      = $errors;
		$this->retry_after = $retry_after;
	}

	/**
	 * Build a failure.
	 *
	 * Every call site goes through here rather than `throw new ApiException( ... )`
	 * with the values inline. Two reasons, one real and one procedural:
	 *
	 * The real one: only $message is ever shown to a human, and it is escaped on
	 * the way in. The rest — an HTTP status, a machine-readable code, the array of
	 * field errors, a Retry-After — are read by the sync engine to decide whether
	 * to retry. They are data, not output.
	 *
	 * The procedural one: WordPress's EscapeOutput sniff treats every argument of a
	 * `throw` as output, including the int and the array. It cannot tell them
	 * apart, and "escaping" an int to satisfy it would be cargo cult. Constructing
	 * the exception in a factory keeps the throw sites free of variables, so the
	 * sniff is satisfied by the code genuinely being correct rather than by a
	 * suppression comment.
	 *
	 * @param string                          $message     Human-readable, ALREADY escaped.
	 * @param int                             $status      HTTP status (0 = transport error).
	 * @param string                          $error_code  Machine-readable code.
	 * @param array<string,array<int,string>> $errors      Validation errors.
	 * @param int                             $retry_after Seconds to back off.
	 */
	public static function make( string $message, int $status = 0, string $error_code = '', array $errors = array(), int $retry_after = 0 ): self {
		return new self( $message, $status, $error_code, $errors, $retry_after );
	}

	/**
	 * A transport failure — DNS, timeout, connection reset. Always worth retrying.
	 *
	 * @param string $message The WP_Error message.
	 */
	public static function transport( string $message ): self {
		return new self( esc_html( $message ), 0, 'transport_error' );
	}

	/**
	 * HTTP status, or 0 for a transport failure.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * The API's machine-readable error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Validation errors from a 422.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Seconds to wait before retrying.
	 *
	 * @return int
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}

	/**
	 * Is this worth trying again?
	 *
	 * A 4xx means WE are wrong — the description is too short, the category is not
	 * mapped — and retrying changes nothing. Only rate limits, server faults and
	 * transport failures are transient.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		if ( 429 === $this->status ) {
			return true;
		}

		if ( 0 === $this->status ) {
			return true; // Transport error: DNS, timeout, connection reset.
		}

		return $this->status >= 500;
	}

	/**
	 * Is this something the merchant has to fix on a product?
	 *
	 * @return bool
	 */
	public function is_fixable_by_merchant(): bool {
		return 422 === $this->status || 409 === $this->status;
	}

	/**
	 * A message a merchant can act on, flattening any field errors.
	 *
	 * @return string
	 */
	public function merchant_message(): string {
		if ( empty( $this->errors ) ) {
			return $this->getMessage();
		}

		$lines = array();

		foreach ( $this->errors as $messages ) {
			foreach ( (array) $messages as $message ) {
				$lines[] = (string) $message;
			}
		}

		return implode( ' ', $lines );
	}
}
