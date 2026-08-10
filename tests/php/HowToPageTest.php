<?php
/**
 * The "How it works" screen's outbound links.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Admin\HowToPage;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the AGT.com URLs this page links to — americanguntrader.com
 * moved its privacy/terms pages to `/privacy-policy` and `/terms-of-service`
 * (see shadow-software/agt-for-woocommerce#1), and this is the only place in the
 * plugin's own UI that links to them.
 */
final class HowToPageTest extends TestCase {

	/**
	 * Set up Brain Monkey and the escaping/translation function stand-ins.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Render captures the AGT.com URLs the plugin links to.
	 */
	public function test_links_to_the_current_agt_privacy_and_terms_urls(): void {
		ob_start();
		( new HowToPage() )->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'https://americanguntrader.com/privacy-policy', $html );
		self::assertStringContainsString( 'https://americanguntrader.com/terms-of-service', $html );

		self::assertStringNotContainsString( 'https://americanguntrader.com/privacy"', $html );
		self::assertStringNotContainsString( 'https://americanguntrader.com/terms"', $html );
	}
}
