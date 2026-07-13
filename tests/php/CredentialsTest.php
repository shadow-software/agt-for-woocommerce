<?php
/**
 * The credential store.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Auth\Credentials;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Where the store's tokens live. The behaviour that matters: expiry is computed
 * with a safety margin, disconnect forgets the tokens but can keep the client,
 * and can_publish() mirrors what the server said.
 */
final class CredentialsTest extends TestCase {

	/**
	 * The in-memory option store.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	/**
	 * Set up Brain Monkey.
	 *
	 * time() is a PHP internal Patchwork cannot redefine, so these tests use the
	 * real clock and compute expiry relative to it rather than pinning "now".
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
		Functions\when( 'delete_option' )->alias(
			function ( string $key ) {
				unset( $this->options[ $key ] );

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
	 * A fresh store is not connected.
	 */
	public function test_a_fresh_store_is_not_connected(): void {
		$this->assertFalse( Credentials::is_connected() );
		$this->assertFalse( Credentials::has_client() );
		$this->assertSame( '', Credentials::access_token() );
	}

	/**
	 * Saved tokens are readable, and the store is connected once a refresh token
	 * exists.
	 */
	public function test_saving_tokens_connects_the_store(): void {
		Credentials::save_tokens( 'access-abc', 'refresh-xyz', 3600, 'listings:read' );

		$this->assertSame( 'access-abc', Credentials::access_token() );
		$this->assertSame( 'refresh-xyz', Credentials::refresh_token() );
		$this->assertTrue( Credentials::is_connected() );
	}

	/**
	 * A token good for an hour is not yet expired; a token whose expiry is in the
	 * past is. And the stored expiry carries a safety margin — a 3600s TTL expires
	 * a bit before a real hour, so a request never races the true expiry.
	 */
	public function test_expiry_is_computed_with_a_margin(): void {
		Credentials::save_tokens( 'a', 'r', 3600, '' );
		$stored = $this->options['agt_sync_tokens']['expires_at'];

		// The margin: expiry is set at least a few seconds before now + TTL.
		$this->assertLessThan( time() + 3600, $stored );
		$this->assertGreaterThan( time() + 3600 - 120, $stored );

		// A fresh hour-long token is live.
		$this->assertFalse( Credentials::access_token_expired() );

		// A token whose expiry is already in the past is expired.
		$this->options['agt_sync_tokens']['expires_at'] = time() - 10;
		$this->assertTrue( Credentials::access_token_expired() );
	}

	/**
	 * An access token with no stored expiry is treated as expired, so a
	 * half-written state forces a refresh rather than sending a stale token.
	 */
	public function test_missing_expiry_counts_as_expired(): void {
		$this->assertTrue( Credentials::access_token_expired() );
	}

	/**
	 * can_publish() reflects the cached /me answer.
	 */
	public function test_can_publish_mirrors_the_account(): void {
		$this->assertFalse( Credentials::can_publish() );

		Credentials::save_account( array( 'can_publish' => true ) );
		$this->assertTrue( Credentials::can_publish() );

		Credentials::save_account( array( 'can_publish' => false ) );
		$this->assertFalse( Credentials::can_publish() );
	}

	/**
	 * forget_tokens() drops the tokens and the account, but KEEPS the client
	 * registration — so reconnecting does not have to register a second client for
	 * the same store.
	 */
	public function test_forget_tokens_keeps_the_client(): void {
		Credentials::save_client( 'client-id', 'secret' );
		Credentials::save_tokens( 'a', 'r', 3600, '' );
		Credentials::save_account( array( 'can_publish' => true ) );

		Credentials::forget_tokens();

		$this->assertFalse( Credentials::is_connected() );
		$this->assertFalse( Credentials::can_publish() );
		$this->assertTrue( Credentials::has_client() );
		$this->assertSame( 'client-id', Credentials::client_id() );
	}

	/**
	 * forget_all() drops everything, client included — the full disconnect.
	 */
	public function test_forget_all_drops_the_client_too(): void {
		Credentials::save_client( 'client-id', 'secret' );
		Credentials::save_tokens( 'a', 'r', 3600, '' );

		Credentials::forget_all();

		$this->assertFalse( Credentials::has_client() );
		$this->assertFalse( Credentials::is_connected() );
	}
}
