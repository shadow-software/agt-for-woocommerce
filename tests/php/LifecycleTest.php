<?php
/**
 * The install / deactivate lifecycle.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Lifecycle;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * The DB-touching paths (activate/heal/uninstall) are exercised against a real
 * WordPress in tests/e2e; here we pin the invariants that keep the plugin's
 * self-description honest — the option inventory that uninstall.php mirrors, and
 * the rule that deactivation only ever clears operational state, never a
 * merchant's data.
 */
final class LifecycleTest extends TestCase {

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
	 * The option inventory is complete: every agt_sync_* option the code writes is
	 * declared, so uninstall.php (which mirrors this list) cannot miss one and
	 * leave a stray row behind.
	 */
	public function test_option_inventory_covers_every_option_the_code_uses(): void {
		$declared = Lifecycle::options();

		// Every option name written anywhere in includes/ (grepped and pinned here).
		$used = array(
			'agt_sync_settings',
			'agt_sync_client',
			'agt_sync_tokens',
			'agt_sync_account',
			'agt_sync_bucket',
			'agt_sync_refresh_lock',
			'agt_sync_bulk_sold_held',
			'agt_sync_purge_on_uninstall',
			'agt_sync_db_version',
		);

		foreach ( $used as $option ) {
			$this->assertContains(
				$option,
				$declared,
				"Option {$option} is written by the code but not declared in Lifecycle::options() — uninstall would leak it."
			);
		}
	}

	/**
	 * Deactivation clears ONLY operational state, and never an option that holds a
	 * merchant's configuration, their connection, or their data. This is the
	 * "reactivate and nothing changed" guarantee.
	 */
	public function test_deactivation_never_touches_a_data_option(): void {
		$deleted = array();

		Functions\when( 'delete_option' )->alias(
			function ( string $key ) use ( &$deleted ) {
				$deleted[] = $key;

				return true;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		// Action Scheduler absent in the unit env — cancel_all no-ops.

		Lifecycle::deactivate();

		// The things a merchant would be furious to lose on a deactivate/reactivate.
		$must_survive = array(
			'agt_sync_settings',            // their configuration
			'agt_sync_client',              // their OAuth registration
			'agt_sync_tokens',              // their connection
			'agt_sync_account',             // cached identity
			'agt_sync_purge_on_uninstall',  // their stated intent
			'agt_sync_db_version',          // schema bookkeeping
		);

		foreach ( $must_survive as $option ) {
			$this->assertNotContains(
				$option,
				$deleted,
				"Deactivation deleted {$option}, but it must survive so reactivation resumes cleanly."
			);
		}

		// And it DID clear the operational locks/buckets.
		$this->assertContains( 'agt_sync_bucket', $deleted );
		$this->assertContains( 'agt_sync_refresh_lock', $deleted );
		$this->assertContains( 'agt_sync_bulk_sold_held', $deleted );
	}

	/**
	 * Both caches are cleared on deactivate — including the PKCE transient, so an
	 * in-flight connect attempt cannot be resumed across a deactivate.
	 */
	public function test_deactivation_clears_the_caches(): void {
		Functions\when( 'delete_option' )->justReturn( true );

		$cleared = array();

		Functions\when( 'delete_transient' )->alias(
			function ( string $key ) use ( &$cleared ) {
				$cleared[] = $key;

				return true;
			}
		);

		Lifecycle::deactivate();

		$this->assertContains( 'agt_sync_taxonomy', $cleared );
		$this->assertContains( 'agt_sync_pkce', $cleared );
	}
}
