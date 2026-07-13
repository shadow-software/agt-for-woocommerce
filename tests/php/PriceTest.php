<?php
/**
 * Which price gets published.
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
 * A listing carries one price, and it has to be the right one — a listing that
 * shows more than the dealer's own store reads as bait-and-switch, and a product
 * refused for "having no price" when it plainly has one is a bug the merchant
 * cannot diagnose.
 */
final class PriceTest extends TestCase {

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
	 * Point Settings at a price source.
	 *
	 * @param string $source current|regular.
	 */
	private function withSource( string $source ): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $key, $fallback = false ) => 'agt_sync_settings' === $key
				? array( 'price_source' => $source )
				: $fallback
		);
	}

	/**
	 * A stub product with the two prices.
	 *
	 * @param string $regular The regular price.
	 * @param string $current The current (possibly sale) price.
	 */
	private function product( string $regular, string $current ): \WC_Product {
		$product = $this->getMockBuilder( \WC_Product::class )
			->disableOriginalConstructor()
			->onlyMethods( array( "get_regular_price", "get_price" ) )
			->getMock();

		$product->method( 'get_regular_price' )->willReturn( $regular );
		$product->method( 'get_price' )->willReturn( $current );

		return $product;
	}

	/**
	 * The default: publish what a buyer actually pays today, sale included.
	 */
	public function test_current_mode_publishes_the_sale_price(): void {
		$this->withSource( 'current' );

		$this->assertSame( 799.0, Mapper::price( $this->product( '899.00', '799.00' ) ) );
	}

	/**
	 * Regular mode ignores the sale.
	 */
	public function test_regular_mode_publishes_the_regular_price(): void {
		$this->withSource( 'regular' );

		$this->assertSame( 899.0, Mapper::price( $this->product( '899.00', '799.00' ) ) );
	}

	/**
	 * A product priced ONLY through a sale price has an empty regular price. In
	 * regular mode the fallback must reach for the other value — not re-read the
	 * same empty one, which is what it used to do, silently refusing the product
	 * for "having no price" when it plainly had one.
	 */
	public function test_regular_mode_falls_back_to_the_current_price(): void {
		$this->withSource( 'regular' );

		$this->assertSame( 799.0, Mapper::price( $this->product( '', '799.00' ) ) );
	}

	/**
	 * And the other way round.
	 */
	public function test_current_mode_falls_back_to_the_regular_price(): void {
		$this->withSource( 'current' );

		$this->assertSame( 899.0, Mapper::price( $this->product( '899.00', '' ) ) );
	}

	/**
	 * A product with no price at all really does have no price.
	 */
	public function test_no_price_at_all_is_null(): void {
		$this->withSource( 'current' );

		$this->assertNull( Mapper::price( $this->product( '', '' ) ) );
	}
}
