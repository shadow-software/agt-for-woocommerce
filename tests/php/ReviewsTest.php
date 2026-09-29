<?php
namespace AgtSync\Tests;
use AgtSync\Sync\Reviews;
use PHPUnit\Framework\TestCase;

final class ReviewsTest extends TestCase {
	public function test_normalizes_public_fields_without_private_data(): void {
		$result = Reviews::normalize_reviews( array( array( 'id' => 'r-1', 'rating' => 5, 'body' => 'Great', 'author_display' => 'buyer', 'email' => 'private', 'actor_id' => 'private' ) ) );
		self::assertSame( 'dealer', $result[0]['source'] );
		self::assertArrayNotHasKey( 'email', $result[0] );
		self::assertArrayNotHasKey( 'actor_id', $result[0] );
	}

	public function test_invalid_rows_are_ignored(): void {
		self::assertSame( array(), Reviews::normalize_reviews( array( array( 'body' => 'no id' ), 'bad' ) ) );
	}

	public function test_deleted_and_stale_contracts_clear_copied_data(): void {
		self::assertTrue( Reviews::is_stale( array( 'listing_status' => 'deleted' ) ) );
		self::assertTrue( Reviews::is_stale( array( 'stale' => true, 'listing_status' => 'live' ) ) );
		self::assertFalse( Reviews::is_stale( array( 'listing_status' => 'live' ) ) );
	}
}
