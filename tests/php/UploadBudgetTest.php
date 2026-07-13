<?php
/**
 * The image-upload memory budget.
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
 * Mapper::upload_budget_kb() is what stops ten full-resolution photos from being
 * buffered into memory all at once and OOMing a small host. It has to scale with
 * the host's memory_limit, and stay within sane floor/ceiling bounds.
 */
final class UploadBudgetTest extends TestCase {

	/**
	 * Set up Brain Monkey.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// The budget is filterable; pass it straight through.
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
	 * The budget never drops below a floor that still allows a real listing (at
	 * least one decent photo), even on a tiny host.
	 */
	public function test_budget_has_a_floor(): void {
		$budget = Mapper::upload_budget_kb();

		$this->assertGreaterThanOrEqual( 8 * 1024, $budget );
	}

	/**
	 * The budget never exceeds what ten max-size images could possibly total —
	 * beyond that there is nothing more to send.
	 */
	public function test_budget_has_a_ceiling(): void {
		$budget = Mapper::upload_budget_kb();

		$this->assertLessThanOrEqual( Mapper::IMAGES_MAX * Mapper::IMAGE_MAX_KB, $budget );
	}

	/**
	 * The per-image ceiling is AGT's own 10 MB limit — anything larger the server
	 * would reject anyway, so it is skipped before being read into memory.
	 */
	public function test_per_image_limit_matches_the_server(): void {
		$this->assertSame( 10240, Mapper::IMAGE_MAX_KB );
	}
}
