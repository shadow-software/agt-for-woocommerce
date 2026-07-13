<?php
/**
 * The WordPress-side rate limiter.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Api\RateLimit;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * The store paces itself, so a well-behaved plugin never has to be told to stop.
 */
final class RateLimitTest extends TestCase {

	/**
	 * The fake option store.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	/**
	 * Set up Brain Monkey and an in-memory option store.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array(
			// The Settings lookup the limiter makes.
			'agt_sync_settings' => array( 'rate_limit_per_min' => 5 ),
		);

		Functions\when( 'get_option' )->alias(
			fn( string $key, $fallback = false ) => $this->options[ $key ] ?? $fallback
		);

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) {
				$this->options[ $key ] = $value;

				return true;
			}
		);

		Functions\when( 'delete_option' )->alias(
			function ( string $key ) {
				unset( $this->options[ $key ] );

				return true;
			}
		);
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The bucket allows exactly its limit, then stops.
	 */
	public function test_the_bucket_empties_at_the_limit(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( RateLimit::consume(), "Request {$i} should have been allowed." );
		}

		$this->assertFalse( RateLimit::consume(), 'The sixth request should have been held back.' );
	}

	/**
	 * When the bucket is empty, the caller is told how long to wait — so it can
	 * reschedule instead of blocking a PHP worker on a sleep.
	 */
	public function test_it_says_how_long_to_wait(): void {
		$wait = RateLimit::seconds_until_refill();

		$this->assertGreaterThan( 0, $wait );
		$this->assertLessThanOrEqual( 60, $wait );
	}

	/**
	 * Being told to slow down empties the bucket at once, rather than letting the
	 * plugin keep walking into the same wall.
	 */
	public function test_backing_off_empties_the_bucket(): void {
		$this->assertTrue( RateLimit::consume() );

		RateLimit::back_off();

		$this->assertFalse( RateLimit::consume() );
	}

	/**
	 * Resetting refills it — what happens when the merchant changes the setting.
	 */
	public function test_reset_refills_the_bucket(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			RateLimit::consume();
		}

		$this->assertFalse( RateLimit::consume() );

		RateLimit::reset();

		$this->assertTrue( RateLimit::consume() );
	}

	/**
	 * The limit is clamped to AGT's own ceiling however the setting is abused, so
	 * a merchant cannot configure their store into being rate-limited.
	 */
	public function test_the_limit_is_clamped(): void {
		$this->options['agt_sync_settings'] = array( 'rate_limit_per_min' => 100000 );

		RateLimit::reset();

		$allowed = 0;

		while ( RateLimit::consume() && $allowed < 500 ) {
			++$allowed;
		}

		$this->assertLessThanOrEqual( 120, $allowed );
	}
}
