<?php
/**
 * Settings accessors and the "ready to sync" gate.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * The typed accessors, the category-map sanitiser, and ready_to_sync() — the
 * gate that decides whether ANY sync happens at all.
 */
final class SettingsTest extends TestCase {

	/**
	 * The in-memory option store.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

		Functions\when( 'get_option' )->alias(
			fn ( string $key, $fallback = false ) => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ) {
				$this->options[ $key ] = $value;

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
	 * Defaults are returned when nothing is stored.
	 */
	public function test_defaults_apply_when_nothing_is_stored(): void {
		$this->assertFalse( Settings::bool( 'enabled' ) );
		$this->assertSame( 'all', Settings::str( 'sync_mode' ) );
		$this->assertSame( 'current', Settings::str( 'price_source' ) );
		$this->assertSame( 60, Settings::int( 'rate_limit_per_min' ) );
	}

	/**
	 * Stored values override the defaults.
	 */
	public function test_stored_values_override_defaults(): void {
		$this->options['agt_sync_settings'] = array(
			'enabled'   => true,
			'sync_mode' => 'opt_in',
		);

		$this->assertTrue( Settings::bool( 'enabled' ) );
		$this->assertSame( 'opt_in', Settings::str( 'sync_mode' ) );
		// A key not stored still falls back to its default.
		$this->assertSame( 'current', Settings::str( 'price_source' ) );
	}

	/**
	 * A corrupt (non-array) option is ignored rather than fatal.
	 */
	public function test_a_corrupt_option_falls_back_to_defaults(): void {
		$this->options['agt_sync_settings'] = 'not-an-array';

		$this->assertSame( 'all', Settings::str( 'sync_mode' ) );
	}

	/**
	 * The category map keeps only positive int => positive int pairs.
	 */
	public function test_category_map_is_sanitised(): void {
		$this->options['agt_sync_settings'] = array(
			'category_map' => array(
				'12'  => '340',  // valid, string-typed
				'0'   => '5',    // invalid term id
				'7'   => '0',    // "do not publish"
				'abc' => 'xyz',  // junk
			),
		);

		$this->assertSame( array( 12 => 340 ), Settings::category_map() );
	}

	/**
	 * ready_to_sync() requires enabled + a confirmed condition + a connection.
	 * Missing any one of them means no sync — the safety interlock.
	 *
	 * @dataProvider ready_provider
	 *
	 * @param bool $enabled   The enabled flag.
	 * @param bool $confirmed The condition-confirmed flag.
	 * @param bool $connected Whether a refresh token is stored.
	 * @param bool $expected  Whether the plugin should sync.
	 */
	public function test_ready_to_sync_interlock( bool $enabled, bool $confirmed, bool $connected, bool $expected ): void {
		$this->options['agt_sync_settings'] = array(
			'enabled'             => $enabled,
			'condition_confirmed' => $confirmed,
		);

		// Credentials::is_connected() reads the tokens option.
		$this->options['agt_sync_tokens'] = $connected ? array( 'refresh_token' => 'r' ) : array();

		$this->assertSame( $expected, Settings::ready_to_sync() );
	}

	/**
	 * Every combination of the three interlock conditions.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool,3:bool}>
	 */
	public static function ready_provider(): array {
		return array(
			'all set'              => array( true, true, true, true ),
			'not enabled'          => array( false, true, true, false ),
			'condition unconfirmed' => array( true, false, true, false ),
			'not connected'        => array( true, true, false, false ),
			'nothing set'          => array( false, false, false, false ),
		);
	}
}
