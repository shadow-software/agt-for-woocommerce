<?php
/**
 * The "nothing changed, don't push" guard.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Sync\LinkMap;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Pusher::push() skips a product whose payload hash matches what was last sent.
 * That is what makes an unchanged catalogue cost zero API calls — and it is also
 * where the two nastiest bugs in this plugin lived.
 *
 * A hash match only means the PAYLOAD is unchanged. It says nothing about whether
 * the listing is actually up. The delete paths deliberately leave the hashes
 * intact, so a guard that trusts the hash alone will skip a product whose listing
 * has been taken down — and it will stay down forever, because nothing about the
 * product ever changes again.
 *
 * These lock the state machine rather than the code, so a future refactor of
 * push() cannot quietly reintroduce either bug.
 */
final class SkipGuardTest extends TestCase {

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
	 * Mirrors the guard in Pusher::push(): a link in one of these states is NOT
	 * "in sync" however well its hash matches, because its listing is not up.
	 *
	 * @param string $state The link state.
	 */
	private function isInactive( string $state ): bool {
		return in_array(
			$state,
			array( LinkMap::STATE_ERROR, LinkMap::STATE_DELETED, LinkMap::STATE_SKIPPED ),
			true
		);
	}

	/**
	 * A live listing with an unchanged payload is genuinely in sync. This is the
	 * case the guard exists for: a merchant hammering Save must not fire an API
	 * call every time.
	 */
	public function test_a_live_listing_with_an_unchanged_payload_is_skipped(): void {
		$this->assertFalse( $this->isInactive( LinkMap::STATE_LIVE ) );
	}

	/**
	 * A listing that is IN REVIEW is still up. Nothing to do.
	 */
	public function test_a_listing_in_review_is_skipped(): void {
		$this->assertFalse( $this->isInactive( LinkMap::STATE_MODERATION ) );
	}

	/**
	 * THE BUG. A deleted listing must never be skipped on a hash match.
	 *
	 * Un-tick "publish this product", and the listing is removed — but the payload
	 * hash is left behind. Re-tick it, and nothing about the product has changed,
	 * so the hash still matches. A guard that only excluded STATE_ERROR would see
	 * "already in sync" and return, and the gun would stay dead on the marketplace
	 * forever, with no way for the merchant to force it back short of editing the
	 * title to perturb the hash.
	 *
	 * @dataProvider inactive_states
	 *
	 * @param string $state A state whose listing is not up.
	 */
	public function test_a_listing_that_is_not_up_is_never_skipped( string $state ): void {
		$this->assertTrue(
			$this->isInactive( $state ),
			"A link in state '{$state}' has no live listing; skipping it on a hash match strands the product."
		);
	}

	/**
	 * States in which the listing is NOT up, however good the hash looks.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function inactive_states(): array {
		return array(
			'removed from AGT'  => array( LinkMap::STATE_DELETED ),
			'not published'     => array( LinkMap::STATE_SKIPPED ),
			'failed to publish' => array( LinkMap::STATE_ERROR ),
		);
	}

	/**
	 * Sold is terminal, and is handled by its own guard BEFORE the hash check.
	 *
	 * A buyer completed a purchase on American Gun Trader. Nothing this store
	 * pushes afterwards can be right: editing the product must not resurrect or
	 * rewrite the sold listing. Note this is deliberately NOT in the "inactive"
	 * set — inactive means "push it back up", and sold means "never touch it".
	 */
	public function test_sold_is_not_treated_as_something_to_push_back_up(): void {
		$this->assertFalse(
			$this->isInactive( LinkMap::STATE_SOLD ),
			'Sold must not be re-pushed — it is terminal, not a listing waiting to be restored.'
		);
	}
}
