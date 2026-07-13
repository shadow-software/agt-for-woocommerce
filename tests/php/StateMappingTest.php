<?php
/**
 * The server-status -> link-state mapping.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Sync\LinkMap;
use AgtSync\Sync\Pusher;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Pusher::state_from_status() turns the server's status word into the plugin's
 * internal state. Both the Pusher (after a write) and the Puller (after a poll)
 * key off it, so getting it wrong drives the wrong stock behaviour.
 */
final class StateMappingTest extends TestCase {

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
	 * Each server status maps to the state that drives the right behaviour.
	 *
	 * @dataProvider status_provider
	 *
	 * @param string $server_status The status word from the API.
	 * @param string $expected      The internal LinkMap state.
	 */
	public function test_status_maps_to_state( string $server_status, string $expected ): void {
		$this->assertSame( $expected, Pusher::state_from_status( $server_status ) );
	}

	/**
	 * The full status vocabulary, plus the unknown fallback.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function status_provider(): array {
		return array(
			'live'     => array( 'live', LinkMap::STATE_LIVE ),
			'pending'  => array( 'pending', LinkMap::STATE_MODERATION ),
			'rejected' => array( 'rejected', LinkMap::STATE_REJECTED ),
			'sold'     => array( 'sold', LinkMap::STATE_SOLD ),
			'deleted'  => array( 'deleted', LinkMap::STATE_DELETED ),
			// Anything unrecognised is treated as pending, never silently "live" —
			// the safe default is "not confirmed up".
			'unknown'  => array( 'something-new', LinkMap::STATE_PENDING ),
			'empty'    => array( '', LinkMap::STATE_PENDING ),
		);
	}

	/**
	 * The states that mean "the listing is not up, push it back" are exactly the
	 * ones the skip guard treats as inactive — and SOLD is NOT among them, because
	 * sold is terminal, not something to re-publish.
	 */
	public function test_sold_is_distinct_from_the_pushable_inactive_states(): void {
		$pushable_inactive = array(
			LinkMap::STATE_ERROR,
			LinkMap::STATE_DELETED,
			LinkMap::STATE_SKIPPED,
		);

		$this->assertNotContains( LinkMap::STATE_SOLD, $pushable_inactive );
		$this->assertContains( LinkMap::STATE_DELETED, $pushable_inactive );
	}
}
