<?php
/**
 * PKCE.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Auth\Pkce;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * The thing standing between an intercepted authorization code and a working
 * token, so it is worth being sure of.
 */
final class PkceTest extends TestCase {

	/**
	 * The fake transient store.
	 *
	 * @var array<string,mixed>
	 */
	private array $transients = array();

	/**
	 * Set up Brain Monkey and an in-memory transient store.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) {
				$this->transients[ $key ] = $value;

				return true;
			}
		);

		Functions\when( 'get_transient' )->alias(
			fn( string $key ) => $this->transients[ $key ] ?? false
		);

		Functions\when( 'delete_transient' )->alias(
			function ( string $key ) {
				unset( $this->transients[ $key ] );

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
	 * The challenge is base64url(sha256(verifier)) with no padding, exactly as
	 * RFC 7636 says. Verified against the RFC's own worked example.
	 */
	public function test_challenge_matches_the_rfc_example(): void {
		$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			Pkce::challenge( $verifier )
		);
	}

	/**
	 * A round trip returns the verifier we generated.
	 */
	public function test_begin_then_complete_returns_the_verifier(): void {
		$begun = Pkce::begin();

		$verifier = Pkce::complete( $begun['state'] );

		$this->assertNotSame( '', $verifier );
		$this->assertSame( $begun['challenge'], Pkce::challenge( $verifier ) );
	}

	/**
	 * A callback carrying the wrong state is rejected. This is the CSRF check: it
	 * means a callback that did not come from a flow THIS store started cannot
	 * connect anything.
	 */
	public function test_a_wrong_state_is_rejected(): void {
		Pkce::begin();

		$this->assertSame( '', Pkce::complete( 'not-the-state-we-issued' ) );
	}

	/**
	 * A state cannot be replayed: the pending flow is consumed on the first
	 * attempt, right or wrong.
	 */
	public function test_a_state_cannot_be_replayed(): void {
		$begun = Pkce::begin();

		$this->assertNotSame( '', Pkce::complete( $begun['state'] ) );
		$this->assertSame( '', Pkce::complete( $begun['state'] ) );
	}

	/**
	 * A callback arriving with no flow in progress is rejected.
	 */
	public function test_no_pending_flow_is_rejected(): void {
		$this->assertSame( '', Pkce::complete( 'anything' ) );
	}

	/**
	 * The verifier is long enough to satisfy RFC 7636 (43-128 characters).
	 */
	public function test_the_verifier_is_long_enough(): void {
		Pkce::begin();

		$stored = $this->transients['agt_sync_pkce'];

		$this->assertIsArray( $stored );
		$this->assertGreaterThanOrEqual( 43, strlen( (string) $stored['verifier'] ) );
		$this->assertLessThanOrEqual( 128, strlen( (string) $stored['verifier'] ) );
	}
}
