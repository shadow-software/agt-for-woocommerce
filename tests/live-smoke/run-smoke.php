<?php
/**
 * Thin live smoke for AGT Sync — runs inside WordPress via `wp eval-file`.
 *
 * Uses the store's saved OAuth credentials for the official AGT sandbox.
 * Read-only: /me + /taxonomy.
 *
 * @package AgtSync
 */

declare(strict_types=1);

use AgtSync\Api\ApiException;
use AgtSync\Api\Client;
use AgtSync\Auth\Credentials;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress (wp eval-file).\n" );
	exit( 2 );
}

$fail = static function ( string $msg ): void {
	fwrite( STDERR, "SMOKE FAIL: {$msg}\n" );
	exit( 1 );
};

if ( ! is_plugin_active( 'agt-sync-for-woocommerce/agt-sync-for-woocommerce.php' ) ) {
	$fail( 'agt-sync-for-woocommerce is not active' );
}

if ( ! Credentials::is_connected() ) {
	$fail( 'store is not connected to AGT (missing refresh token)' );
}

$base = defined( 'AGT_SYNC_API_BASE' ) ? AGT_SYNC_API_BASE : 'https://americanguntrader.com';
echo "AGT base: {$base}\n";

$client = new Client();

/**
 * Retry on plugin local rate-limit (429) only.
 *
 * @param callable():mixed $fn
 */
$retry = static function ( callable $fn ) {
	for ( $i = 0; $i < 5; $i++ ) {
		try {
			return $fn();
		} catch ( ApiException $e ) {
			if ( 429 !== $e->status() || $i >= 4 ) {
				throw $e;
			}
			sleep( 15 * ( $i + 1 ) );
		}
	}

	throw new \RuntimeException( 'unreachable' );
};

try {
	$me   = $retry( static fn () => $client->get( '/me' ) );
	$data = $me['data'] ?? array();
	if ( empty( $data['ffl_verified'] ) ) {
		$fail( 'dealer is not FFL verified' );
	}
	if ( empty( $data['can_publish'] ) ) {
		$fail( 'dealer cannot publish' );
	}
	echo "✓ /me OK (dealer id " . (int) ( $data['id'] ?? 0 ) . ")\n";
	Credentials::save_account( $data );
} catch ( ApiException $e ) {
	$fail( '/me: HTTP ' . $e->status() . ' — ' . $e->getMessage() );
} catch ( Throwable $e ) {
	$fail( '/me: ' . $e->getMessage() );
}

try {
	$tax        = $retry( static fn () => $client->get( '/taxonomy' ) );
	$categories = $tax['data']['categories'] ?? array();
	if ( empty( $categories ) ) {
		$fail( 'taxonomy returned no categories' );
	}
	echo "✓ /taxonomy OK (" . count( $categories ) . " categories)\n";
} catch ( ApiException $e ) {
	$fail( '/taxonomy: HTTP ' . $e->status() . ' — ' . $e->getMessage() );
} catch ( Throwable $e ) {
	$fail( '/taxonomy: ' . $e->getMessage() );
}

echo "SMOKE PASS\n";
exit( 0 );
