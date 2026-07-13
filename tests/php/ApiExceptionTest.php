<?php
/**
 * How a failure is classified.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Api\ApiException;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Getting this wrong is how a plugin either gives up on work it should have
 * retried, or hammers a server with a request that will never succeed.
 */
final class ApiExceptionTest extends TestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A rate limit, a server fault and a transport failure are all transient, so
	 * they are worth trying again.
	 *
	 * @dataProvider retryable_provider
	 *
	 * @param int  $status   The HTTP status.
	 * @param bool $expected Whether it should be retried.
	 */
	public function test_what_is_worth_retrying( int $status, bool $expected ): void {
		$e = new ApiException( 'x', $status );

		$this->assertSame( $expected, $e->is_retryable() );
	}

	/**
	 * Statuses and whether they are transient.
	 *
	 * @return array<string,array{0:int,1:bool}>
	 */
	public static function retryable_provider(): array {
		return array(
			'transport failure' => array( 0, true ),
			'rate limited'      => array( 429, true ),
			'server error'      => array( 500, true ),
			'bad gateway'       => array( 502, true ),
			// A 4xx means WE are wrong. Sending the same thing again changes nothing,
			// and doing it six times just to find that out is rude.
			'unauthorised'      => array( 401, false ),
			'forbidden'         => array( 403, false ),
			'not found'         => array( 404, false ),
			'conflict'          => array( 409, false ),
			'validation'        => array( 422, false ),
		);
	}

	/**
	 * A validation failure is something the MERCHANT has to fix, and is surfaced
	 * to them on the product rather than retried.
	 */
	public function test_a_validation_failure_is_the_merchants_to_fix(): void {
		$e = new ApiException( 'The listing is not valid.', 422 );

		$this->assertTrue( $e->is_fixable_by_merchant() );
		$this->assertFalse( $e->is_retryable() );
	}

	/**
	 * Field errors are flattened into something a merchant can read, rather than
	 * a nested structure they cannot.
	 */
	public function test_it_flattens_field_errors_for_the_merchant(): void {
		$e = new ApiException(
			'The listing is not valid.',
			422,
			'',
			array(
				'description' => array( 'The description must be at least 80 characters.' ),
				'images'      => array( 'A listing needs at least one photo.' ),
			)
		);

		$message = $e->merchant_message();

		$this->assertStringContainsString( 'at least 80 characters', $message );
		$this->assertStringContainsString( 'at least one photo', $message );
	}

	/**
	 * With no field errors, the message stands on its own.
	 */
	public function test_it_falls_back_to_the_plain_message(): void {
		$e = new ApiException( 'Something broke.', 500 );

		$this->assertSame( 'Something broke.', $e->merchant_message() );
	}

	/**
	 * A Retry-After is carried through, so the queue can honour the server's own
	 * instruction rather than guessing.
	 */
	public function test_it_carries_retry_after(): void {
		$e = new ApiException( 'Slow down.', 429, 'rate_limited', array(), 42 );

		$this->assertSame( 42, $e->retry_after() );
	}
}
