<?php
/**
 * End-to-end tests for AGT Sync, run against a REAL American Gun Trader.
 *
 * These are not PHPUnit — they run inside a live WordPress (via `wp eval-file`)
 * so the plugin's real Client, Credentials, RateLimit, Multipart and Mapper
 * classes make real HTTP calls to AGT. That is the only way to prove the wire
 * format, the auth, the idempotency and the writeback actually work; the unit
 * suite mocks all of that away by design.
 *
 * Prerequisites, arranged by the harness (tests/e2e/e2e.sh):
 *   - A WordPress install with WooCommerce and this plugin active.
 *   - AGT running on AGT_SYNC_API_BASE (default http://localhost:8082).
 *   - A seeded FFL dealer + access token, from `php artisan dealer:e2e-seed`,
 *     passed in via the AGT_E2E_* environment variables.
 *
 * @package AgtSync
 */

declare(strict_types=1);

use AgtSync\Api\Client;
use AgtSync\Auth\Credentials;
use AgtSync\Sync\Mapper;

// The plugin (correctly) sends reject_unsafe_urls => true, and WordPress blocks
// loopback/private hosts by default — so a local AGT on http://localhost:8082 is
// refused. That protection is right for production (AGT is a public HTTPS host);
// here it just gets in the way of testing against a local instance, so allow it
// for THIS run only. This changes nothing about the plugin's own code.
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter(
	'http_request_args',
	static function ( array $args ): array {
		$args['reject_unsafe_urls'] = false;

		return $args;
	},
	999
);

// ---------------------------------------------------------------------------
// Tiny assertion harness.
// ---------------------------------------------------------------------------

final class E2E {
	public static int $pass = 0;
	public static int $fail = 0;
	/** @var array<int,string> */
	public static array $failures = array();

	public static function ok( bool $cond, string $label ): void {
		if ( $cond ) {
			++self::$pass;
			echo "  \033[32m✓\033[0m {$label}\n";
		} else {
			++self::$fail;
			self::$failures[] = $label;
			echo "  \033[31m✗ {$label}\033[0m\n";
		}
	}

	public static function same( mixed $expected, mixed $actual, string $label ): void {
		$got = is_scalar( $actual ) ? (string) $actual : gettype( $actual );
		self::ok( $expected === $actual, $label . " (got {$got})" );
	}

	public static function summary(): int {
		echo "\n";
		echo self::$fail === 0
			? "\033[32mE2E PASS — " . self::$pass . " assertions\033[0m\n"
			: "\033[31mE2E FAIL — " . self::$fail . ' failed, ' . self::$pass . " passed\033[0m\n";

		return self::$fail === 0 ? 0 : 1;
	}
}

/**
 * Read a required env var or die loudly.
 */
function e2e_env( string $key ): string {
	$v = getenv( $key );

	if ( false === $v || '' === $v ) {
		fwrite( STDERR, "Missing required env var {$key}\n" );
		exit( 2 );
	}

	return (string) $v;
}

// ---------------------------------------------------------------------------
// Wire the plugin up as if a dealer had just connected: drop the seeded token
// straight into Credentials, so the whole API layer runs for real.
// ---------------------------------------------------------------------------

Credentials::save_client( e2e_env( 'AGT_E2E_CLIENT_ID' ), 'unused-in-token-tests' );
Credentials::save_tokens(
	e2e_env( 'AGT_E2E_ACCESS_TOKEN' ),
	e2e_env( 'AGT_E2E_REFRESH_TOKEN' ),
	3600,
	'listings:read listings:write taxonomy:read profile:read'
);

$firearms_category = (int) e2e_env( 'AGT_E2E_FIREARMS_CATEGORY_ID' );
$client            = new Client();

echo "AGT base: " . AGT_SYNC_API_BASE . "\n\n";

/**
 * Call a Client method, retrying on a TRANSPORT error only.
 *
 * The local AGT under test runs on `php artisan serve`, which wraps PHP's
 * single-threaded built-in server — it intermittently drops a connection when
 * requests arrive back to back (cURL error 52). That is a property of the test
 * server, not the plugin, and in production the plugin's own queue retries
 * transport failures anyway. So retry here too, and ONLY on status 0; a real 4xx
 * from the app is a genuine result and must not be papered over.
 *
 * @param callable $fn A closure that performs one Client call.
 */
function e2e_retry( callable $fn ): mixed {
	$last = null;

	for ( $i = 0; $i < 5; $i++ ) {
		try {
			return $fn();
		} catch ( \AgtSync\Api\ApiException $e ) {
			if ( 0 !== $e->status() ) {
				throw $e; // A real HTTP status — do not retry, it is the answer.
			}

			$last = $e;
			usleep( 200000 * ( $i + 1 ) );
		}
	}

	throw $last;
}

// ---------------------------------------------------------------------------
// 1. /me — the dealer we seeded can publish immediately.
// ---------------------------------------------------------------------------

echo "── /me ──\n";
$me = e2e_retry( static fn () => $client->get( '/me' ) );
$data = $me['data'] ?? array();
E2E::same( true, (bool) ( $data['ffl_verified'] ?? false ), 'dealer is FFL verified' );
E2E::same( true, (bool) ( $data['can_publish'] ?? false ), 'dealer can publish' );
E2E::same( true, (bool) ( $data['listings_publish_immediately'] ?? false ), 'dealer skips the moderation queue' );
E2E::same( 80, (int) ( $data['limits']['title_max'] ?? 0 ), '/me advertises the title limit the Mapper enforces' );
Credentials::save_account( $data );

// ---------------------------------------------------------------------------
// 2. Taxonomy — the category the plugin will map to actually exists.
// ---------------------------------------------------------------------------

echo "\n── /taxonomy ──\n";
$tax = e2e_retry( static fn () => $client->get( '/taxonomy' ) );
$categories = $tax['data']['categories'] ?? array();
$found_firearms = false;
foreach ( $categories as $cat ) {
	if ( (int) ( $cat['id'] ?? 0 ) === $firearms_category ) {
		$found_firearms = true;
		E2E::same( true, (bool) ( $cat['requires_manufacturer_and_caliber'] ?? false ), 'firearms category requires manufacturer + caliber' );
	}
}
E2E::ok( $found_firearms, 'seeded firearms category is present in the taxonomy' );
E2E::ok( ! empty( $tax['data']['conditions'] ), 'taxonomy carries the condition list' );

// ---------------------------------------------------------------------------
// 3. Publish a listing — real multipart upload with a real image.
// ---------------------------------------------------------------------------

echo "\n── create a listing (multipart) ──\n";

// A non-firearm category is simplest: no manufacturer/caliber needed. Find one.
$accessory_category = 0;
foreach ( $categories as $cat ) {
	if ( empty( $cat['requires_manufacturer_and_caliber'] ) ) {
		$accessory_category = (int) $cat['id'];
		break;
	}
}
E2E::ok( $accessory_category > 0, 'found a non-firearm category to publish into' );

// A real on-disk JPEG.
$img = wp_tempnam( 'agt-e2e.jpg' );
$gd  = imagecreatetruecolor( 400, 300 );
imagejpeg( $gd, $img, 80 );
imagedestroy( $gd );

$idem = wp_generate_uuid4();

$create = e2e_retry( static fn () => $client->post_multipart(
	'/listings',
	array(
		'title'       => 'E2E Kydex Holster ' . substr( $idem, 0, 8 ),
		'description' => 'An end-to-end test holster listing with more than eighty characters of description so it clears the minimum-length rule the server enforces on publish.',
		'price'       => '49.99',
		'condition'   => (string) Mapper::CONDITION_USED,
		'category_id' => (string) $accessory_category,
	),
	array(
		array( 'name' => 'images[]', 'filename' => 'holster.jpg', 'path' => $img, 'type' => 'image/jpeg' ),
	),
	array( 'Idempotency-Key' => $idem )
) );

$listing = $create['data'] ?? array();
$slug    = (string) ( $listing['id'] ?? '' );
E2E::ok( '' !== $slug, 'create returned a listing id (url_slug)' );
E2E::same( 'live', (string) ( $listing['status'] ?? '' ), 'listing published LIVE (moderation skipped)' );
E2E::same( 49.99, (float) ( $listing['price'] ?? 0 ), 'price round-tripped' );
E2E::ok( ! empty( $listing['images'] ), 'the uploaded image is on the listing' );

// ---------------------------------------------------------------------------
// 4. Idempotency — the SAME idempotency key must NOT create a second listing.
// ---------------------------------------------------------------------------

echo "\n── idempotency ──\n";
$replay = e2e_retry( static fn () => $client->post_multipart(
	'/listings',
	array(
		'title'       => 'E2E Kydex Holster ' . substr( $idem, 0, 8 ),
		'description' => 'An end-to-end test holster listing with more than eighty characters of description so it clears the minimum-length rule the server enforces on publish.',
		'price'       => '49.99',
		'condition'   => (string) Mapper::CONDITION_USED,
		'category_id' => (string) $accessory_category,
	),
	array(
		array( 'name' => 'images[]', 'filename' => 'holster.jpg', 'path' => $img, 'type' => 'image/jpeg' ),
	),
	array( 'Idempotency-Key' => $idem )
) );
E2E::same( $slug, (string) ( $replay['data']['id'] ?? '' ), 'a replayed idempotency key returns the SAME listing, not a duplicate' );

// ---------------------------------------------------------------------------
// 5. Update — a price change stays LIVE (the re-moderation trap must not fire).
// ---------------------------------------------------------------------------

echo "\n── price update stays live ──\n";
$updated = e2e_retry( static fn () => $client->patch( '/listings/' . rawurlencode( $slug ), array( 'price' => '59.99' ) ) );
E2E::same( 59.99, (float) ( $updated['data']['price'] ?? 0 ), 'price updated' );
E2E::same( 'live', (string) ( $updated['data']['status'] ?? '' ), 'a price change did NOT push the listing back into moderation' );

// ---------------------------------------------------------------------------
// 6. Status poll — the writeback channel returns this listing.
// ---------------------------------------------------------------------------

echo "\n── status poll ──\n";
$status = e2e_retry( static fn () => $client->get( '/listings/status', array( 'slugs' => $slug ) ) );
$row    = $status['data'][ $slug ] ?? array();
E2E::same( 'live', (string) ( $row['status'] ?? '' ), 'status poll reports the listing live' );
E2E::same( false, (bool) ( $row['in_moderation'] ?? true ), 'status poll reports it out of moderation' );

// ---------------------------------------------------------------------------
// 7. Delete + restore — the full round trip, images intact.
// ---------------------------------------------------------------------------

echo "\n── delete + restore ──\n";
e2e_retry( static fn () => $client->delete( '/listings/' . rawurlencode( $slug ) ) );
$after_delete = e2e_retry( static fn () => $client->get( '/listings/' . rawurlencode( $slug ) ) );
E2E::same( 'deleted', (string) ( $after_delete['data']['status'] ?? '' ), 'listing is soft-deleted' );

$restored = e2e_retry( static fn () => $client->post( '/listings/' . rawurlencode( $slug ) . '/restore' ) );
E2E::same( 'live', (string) ( $restored['data']['status'] ?? '' ), 'listing restored to live' );
E2E::ok( ! empty( $restored['data']['images'] ), 'images survived the delete/restore round trip' );

// ---------------------------------------------------------------------------
// 8. Auth failure — a bad token is rejected, not silently accepted.
// ---------------------------------------------------------------------------

echo "\n── auth ──\n";
Credentials::save_tokens( 'definitely-not-a-real-token', 'x', 3600 );
$bad = new Client();
$rejected = false;
try {
	$bad->get( '/me' );
} catch ( \AgtSync\Api\ApiException $e ) {
	$rejected = ( 401 === $e->status() );
}
E2E::ok( $rejected, 'a bad access token is rejected with 401' );

// Clean up the temp image.
if ( file_exists( $img ) ) {
	wp_delete_file( $img );
}

exit( E2E::summary() );
