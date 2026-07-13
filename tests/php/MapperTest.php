<?php
/**
 * The field map.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Sync\Mapper;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Mapper: the rules that decide what a product becomes, and whether it can be a
 * listing at all.
 */
final class MapperTest extends TestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => trim( strip_tags( $text ) )
		);

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A condition attribute maps onto an AGT condition id.
	 *
	 * @dataProvider condition_provider
	 *
	 * @param string $text     The attribute value.
	 * @param int    $expected The AGT condition id.
	 */
	public function test_condition_from_text( string $text, int $expected ): void {
		$this->assertSame( $expected, Mapper::condition_from_text( $text ) );
	}

	/**
	 * Attribute values and what they should map to.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function condition_provider(): array {
		return array(
			'new'                => array( 'New', Mapper::CONDITION_NEW ),
			'new in box'         => array( 'New In Box', Mapper::CONDITION_NEW ),
			'nib'                => array( 'NIB', Mapper::CONDITION_NEW ),
			'like new'           => array( 'Like New', Mapper::CONDITION_LIKE_NEW ),
			'excellent'          => array( 'Excellent', Mapper::CONDITION_LIKE_NEW ),
			'used'               => array( 'Used', Mapper::CONDITION_USED ),
			'good'               => array( 'Good', Mapper::CONDITION_USED ),
			'pre-owned'          => array( 'Pre-Owned', Mapper::CONDITION_USED ),
			'damaged'            => array( 'Damaged', Mapper::CONDITION_DAMAGED ),
			'for parts'          => array( 'For Parts', Mapper::CONDITION_DAMAGED ),
			'case insensitive'   => array( 'lIkE nEw', Mapper::CONDITION_LIKE_NEW ),
			'whitespace ignored' => array( '  Used  ', Mapper::CONDITION_USED ),
			// Anything we do not recognise must NOT be guessed at. Listing a used
			// firearm as new because we took a punt is not acceptable.
			'unknown'            => array( 'Mint-ish', 0 ),
			'empty'              => array( '', 0 ),
		);
	}

	/**
	 * A description's length is measured in real text, not markup. Ten empty
	 * paragraphs are not a description.
	 */
	public function test_plain_length_ignores_markup(): void {
		// html_entity_decode is a PHP internal, not a WordPress function — it runs
		// for real here.
		$this->assertSame( 0, Mapper::plain_length( '<p></p><p></p><br>' ) );
		$this->assertSame( 5, Mapper::plain_length( '<p>Hello</p>' ) );

		// A description that is nothing but markup is empty, however many bytes it
		// weighs — which is exactly the case the 80-character rule exists to catch.
		$this->assertLessThan(
			Mapper::DESCRIPTION_MIN,
			Mapper::plain_length( str_repeat( '<p></p>', 100 ) )
		);
	}

	/**
	 * The payload hash is stable regardless of key order — otherwise a product
	 * would look "changed" on every save and we would re-push the whole catalogue
	 * for nothing.
	 */
	public function test_payload_hash_is_order_independent(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data ): string => (string) json_encode( $data )
		);

		$a = Mapper::payload_hash(
			array(
				'title' => 'Rifle',
				'price' => 999.0,
			)
		);

		$b = Mapper::payload_hash(
			array(
				'price' => 999.0,
				'title' => 'Rifle',
			)
		);

		$this->assertSame( $a, $b );
	}

	/**
	 * A different payload really does hash differently, or nothing would ever
	 * sync twice.
	 */
	public function test_payload_hash_changes_with_the_payload(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $data ): string => (string) json_encode( $data )
		);

		$before = Mapper::payload_hash( array( 'price' => 999.0 ) );
		$after  = Mapper::payload_hash( array( 'price' => 899.0 ) );

		$this->assertNotSame( $before, $after );
	}
}
