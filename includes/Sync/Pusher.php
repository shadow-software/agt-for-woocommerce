<?php
/**
 * WooCommerce -> American Gun Trader.
 *
 * @package AgtSync
 */

namespace AgtSync\Sync;

use AgtSync\Api\ApiException;
use AgtSync\Api\Client;
use AgtSync\Auth\Credentials;
use AgtSync\Logger;
use AgtSync\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Publishing, updating, removing and restoring one product's listing.
 */
final class Pusher {

	/**
	 * The API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Construct.
	 *
	 * @param Client|null $client Injected for tests.
	 */
	public function __construct( ?Client $client = null ) {
		$this->client = $client instanceof Client ? $client : new Client();
	}

	/**
	 * Push one product: create its listing, or update the existing one.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return void
	 * @throws ApiException On a retryable failure, so the queue can back off.
	 */
	public function push( int $product_id ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( ! Mapper::is_syncable( $product ) ) {
			$reason = Mapper::skip_reason( $product );

			LinkMap::save(
				$product_id,
				array(
					'state'      => LinkMap::STATE_SKIPPED,
					'last_error' => $reason,
				)
			);

			return;
		}

		// A dealer whose subscription lapsed, or whose account has no address, cannot
		// publish anything. Say so once rather than failing every product in turn.
		if ( ! Credentials::can_publish() ) {
			LinkMap::save(
				$product_id,
				array(
					'state'      => LinkMap::STATE_ERROR,
					'last_error' => __( 'Your American Gun Trader account cannot publish listings right now. Check the AGT Sync settings screen.', 'agt-sync-for-woocommerce' ),
				)
			);

			return;
		}

		$mapped = Mapper::to_listing( $product );

		if ( ! $mapped['ok'] ) {
			// The merchant has to fix something on the product. Record it verbatim so
			// the product screen can show them exactly what.
			LinkMap::save(
				$product_id,
				array(
					'state'      => LinkMap::STATE_ERROR,
					'last_error' => implode( ' ', $mapped['errors'] ),
				)
			);

			return;
		}

		$payload      = $mapped['payload'];
		$payload_hash = Mapper::payload_hash( $payload );
		$image_hash   = Mapper::image_hash( $product );

		$link       = LinkMap::get( $product_id );
		$listing_id = is_array( $link ) && ! empty( $link['listing_id'] ) ? (string) $link['listing_id'] : '';
		$state      = is_array( $link ) ? (string) ( $link['state'] ?? '' ) : '';

		// It sold on American Gun Trader. That is terminal: a buyer completed a
		// purchase there, and nothing this store pushes afterwards can be right.
		// Editing the product must not resurrect or rewrite the sold listing.
		if ( LinkMap::STATE_SOLD === $state ) {
			return;
		}

		// Nothing changed. Do not spend a request saying so.
		//
		// The state check is the load-bearing half. A hash match only means the
		// PAYLOAD is unchanged — it says nothing about whether the listing is
		// actually up. The delete paths (remove/withdraw) and the skip path
		// deliberately leave the hashes intact, so without this a listing that was
		// taken down would look "already in sync" against its own stale hash and
		// would never be restored: un-tick a product, re-tick it, and it would stay
		// dead on AGT forever with no way for the merchant to force it back short of
		// perturbing the title.
		$inactive = in_array(
			$state,
			array( LinkMap::STATE_ERROR, LinkMap::STATE_DELETED, LinkMap::STATE_SKIPPED ),
			true
		);

		if (
			'' !== $listing_id
			&& is_array( $link )
			&& ! $inactive
			&& (string) ( $link['payload_hash'] ?? '' ) === $payload_hash
			&& (string) ( $link['image_hash'] ?? '' ) === $image_hash
		) {
			return;
		}

		try {
			if ( '' === $listing_id ) {
				$this->create( $product, $product_id, $payload, $payload_hash, $image_hash );

				return;
			}

			// The listing is down — the product was trashed, unticked, or went out of
			// stock — and the merchant has now made it publishable again. Bring the
			// listing BACK rather than PATCHing it: a soft-deleted listing rejects an
			// update (409), and creating a fresh one would leave a duplicate of the
			// same gun on the marketplace. Restoring reuses the listing the store
			// already owns, with its views and its URL intact.
			if ( LinkMap::STATE_DELETED === $state ) {
				$this->restore( $product_id );

				return;
			}

			$images_changed = is_array( $link ) && (string) ( $link['image_hash'] ?? '' ) !== $image_hash;

			$this->update( $product, $product_id, $listing_id, $payload, $payload_hash, $image_hash, $images_changed );
		} catch ( ApiException $e ) {
			$this->record_failure( $product_id, $e );

			// A retryable failure goes back to the queue; a 422 does not, because
			// sending the same thing again would fail the same way.
			if ( $e->is_retryable() ) {
				throw $e;
			}
		}
	}

	/**
	 * Create a listing.
	 *
	 * @param \WC_Product         $product      The product.
	 * @param int                 $product_id   The product id.
	 * @param array<string,mixed> $payload      The listing payload.
	 * @param string              $payload_hash Hash of the payload.
	 * @param string              $image_hash   Hash of the image set.
	 * @return void
	 * @throws ApiException On failure.
	 */
	private function create( \WC_Product $product, int $product_id, array $payload, string $payload_hash, string $image_hash ): void {
		$images = Mapper::images( $product );

		// An idempotency key derived from the product AND what we are sending. If a
		// response is lost and the job retries, AGT replays the original listing
		// instead of creating a second one — the plugin cannot double-list a gun.
		$idempotency_key = substr( hash( 'sha256', $product_id . ':' . $payload_hash . ':' . $image_hash ), 0, 64 );

		$response = $this->client->post_multipart(
			'/listings',
			$payload,
			$images,
			array( 'Idempotency-Key' => $idempotency_key )
		);

		$this->record_success( $product_id, $response, $payload_hash, $image_hash );

		Logger::info( sprintf( 'Published product #%d to American Gun Trader.', $product_id ) );
	}

	/**
	 * Update a listing.
	 *
	 * @param \WC_Product         $product        The product.
	 * @param int                 $product_id     The product id.
	 * @param string              $listing_id     The AGT listing id.
	 * @param array<string,mixed> $payload        The listing payload.
	 * @param string              $payload_hash   Hash of the payload.
	 * @param string              $image_hash     Hash of the image set.
	 * @param bool                $images_changed Whether to re-upload the images.
	 * @return void
	 * @throws ApiException On failure.
	 */
	private function update( \WC_Product $product, int $product_id, string $listing_id, array $payload, string $payload_hash, string $image_hash, bool $images_changed ): void {
		if ( $images_changed ) {
			// Images can only go over multipart, and sending them means resending the
			// fields alongside. PATCH cannot carry a multipart body through
			// wp_remote_request reliably, so the API accepts POST for this.
			$images = Mapper::images( $product );

			$response = $this->client->post_multipart(
				'/listings/' . rawurlencode( $listing_id ),
				array_merge( $payload, array( '_method' => 'PATCH' ) ),
				$images
			);
		} else {
			// The common case by far: a price or a description changed. A small JSON
			// body, and none of the ten photos go over the wire again.
			$response = $this->client->patch( '/listings/' . rawurlencode( $listing_id ), $payload );
		}

		$this->record_success( $product_id, $response, $payload_hash, $image_hash );

		Logger::info( sprintf( 'Updated product #%d on American Gun Trader.', $product_id ) );
	}

	/**
	 * Remove a listing. Trashing a product in WooCommerce lands here.
	 *
	 * The listing is soft-deleted on AGT and the link row is KEPT — the listing id
	 * is what makes restoring it possible when the merchant untrashes the product.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return void
	 * @throws ApiException On a retryable failure.
	 */
	public function remove( int $product_id ): void {
		if ( 'unlink' === Settings::str( 'delete_behavior' ) ) {
			// The merchant would rather manage removals by hand. Forget the link but
			// leave the listing alone.
			LinkMap::save(
				$product_id,
				array(
					'state'      => LinkMap::STATE_SKIPPED,
					'last_error' => __( 'The product was removed in WooCommerce. Its American Gun Trader listing was left alone, because deletion is set to "unlink".', 'agt-sync-for-woocommerce' ),
				)
			);

			return;
		}

		$listing_id = LinkMap::listing_id( $product_id );

		if ( '' === $listing_id ) {
			return;
		}

		try {
			$this->client->delete( '/listings/' . rawurlencode( $listing_id ) );

			LinkMap::save(
				$product_id,
				array(
					'state'          => LinkMap::STATE_DELETED,
					'last_error'     => null,
					'last_pushed_at' => current_time( 'mysql', true ),
				)
			);

			Logger::info( sprintf( 'Removed product #%d from American Gun Trader.', $product_id ) );
		} catch ( ApiException $e ) {
			// Already gone is not a failure.
			if ( 404 === $e->status() ) {
				LinkMap::save( $product_id, array( 'state' => LinkMap::STATE_DELETED ) );

				return;
			}

			$this->record_failure( $product_id, $e );

			if ( $e->is_retryable() ) {
				throw $e;
			}
		}
	}

	/**
	 * Restore a listing. Untrashing a product in WooCommerce lands here, which is
	 * what makes deletion reversible on both sides.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return void
	 * @throws ApiException On a retryable failure.
	 */
	public function restore( int $product_id ): void {
		$listing_id = LinkMap::listing_id( $product_id );

		if ( '' === $listing_id ) {
			// Never had a listing, or it was unlinked. Publish it fresh instead.
			$this->push( $product_id );

			return;
		}

		try {
			$response = $this->client->post( '/listings/' . rawurlencode( $listing_id ) . '/restore' );

			$link = LinkMap::get( $product_id );

			$this->record_success(
				$product_id,
				$response,
				is_array( $link ) ? (string) ( $link['payload_hash'] ?? '' ) : '',
				is_array( $link ) ? (string) ( $link['image_hash'] ?? '' ) : ''
			);

			Logger::info( sprintf( 'Restored product #%d on American Gun Trader.', $product_id ) );
		} catch ( ApiException $e ) {
			// The listing is gone for good on AGT. Publish a new one.
			if ( 404 === $e->status() ) {
				LinkMap::save(
					$product_id,
					array(
						'listing_id'   => null,
						'payload_hash' => null,
						'image_hash'   => null,
						'state'        => LinkMap::STATE_PENDING,
					)
				);

				$this->push( $product_id );

				return;
			}

			$this->record_failure( $product_id, $e );

			if ( $e->is_retryable() ) {
				throw $e;
			}
		}
	}

	/**
	 * The product went out of stock in WooCommerce — it sold somewhere else, so it
	 * must stop being offered on American Gun Trader.
	 *
	 * The dealer API has no "mark sold": a sale is something a BUYER completes on
	 * AGT, and a store claiming one that never happened there would poison AGT's
	 * sold data. What a store can honestly say is "this is no longer available",
	 * which is a removal — and because removal is a soft delete, restocking the
	 * product restores the listing rather than creating a duplicate.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return void
	 * @throws ApiException On a retryable failure.
	 */
	public function withdraw( int $product_id ): void {
		$listing_id = LinkMap::listing_id( $product_id );

		if ( '' === $listing_id ) {
			return;
		}

		$link = LinkMap::get( $product_id );

		// It already sold on AGT, or it is already gone. Nothing to do.
		if ( is_array( $link ) && in_array( (string) ( $link['state'] ?? '' ), array( LinkMap::STATE_SOLD, LinkMap::STATE_DELETED ), true ) ) {
			return;
		}

		try {
			$this->client->delete( '/listings/' . rawurlencode( $listing_id ) );

			LinkMap::save(
				$product_id,
				array(
					'state'          => LinkMap::STATE_DELETED,
					'last_error'     => null,
					'last_pushed_at' => current_time( 'mysql', true ),
				)
			);

			Logger::info( sprintf( 'Product #%d is out of stock; withdrew its American Gun Trader listing.', $product_id ) );
		} catch ( ApiException $e ) {
			if ( 404 === $e->status() ) {
				LinkMap::save( $product_id, array( 'state' => LinkMap::STATE_DELETED ) );

				return;
			}

			$this->record_failure( $product_id, $e );

			if ( $e->is_retryable() ) {
				throw $e;
			}
		}
	}

	/**
	 * Record a successful push.
	 *
	 * @param int                 $product_id   The product id.
	 * @param array<string,mixed> $response     The API response.
	 * @param string              $payload_hash Hash of what we sent.
	 * @param string              $image_hash   Hash of the images we sent.
	 * @return void
	 */
	private function record_success( int $product_id, array $response, string $payload_hash, string $image_hash ): void {
		$listing = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		LinkMap::save(
			$product_id,
			array(
				'listing_id'     => isset( $listing['id'] ) ? (string) $listing['id'] : null,
				'payload_hash'   => $payload_hash,
				'image_hash'     => $image_hash,
				'state'          => self::state_from_status( isset( $listing['status'] ) ? (string) $listing['status'] : '' ),
				'listing_url'    => isset( $listing['url'] ) ? (string) $listing['url'] : null,
				'views'          => isset( $listing['views'] ) ? (int) $listing['views'] : 0,
				'bid_count'      => isset( $listing['bid_count'] ) ? (int) $listing['bid_count'] : 0,
				'last_error'     => null,
				'last_pushed_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Record a failed push, in words the merchant can act on.
	 *
	 * @param int          $product_id The product id.
	 * @param ApiException $e          The failure.
	 * @return void
	 */
	private function record_failure( int $product_id, ApiException $e ): void {
		LinkMap::save(
			$product_id,
			array(
				'state'      => LinkMap::STATE_ERROR,
				'last_error' => $e->merchant_message(),
			)
		);

		Logger::error( sprintf( 'Product #%d did not sync: %s', $product_id, $e->merchant_message() ) );
	}

	/**
	 * Map AGT's listing status onto a link state.
	 *
	 * @param string $status The status from the API.
	 * @return string
	 */
	public static function state_from_status( string $status ): string {
		switch ( $status ) {
			case 'live':
				return LinkMap::STATE_LIVE;
			case 'pending':
				return LinkMap::STATE_MODERATION;
			case 'rejected':
				return LinkMap::STATE_REJECTED;
			case 'sold':
				return LinkMap::STATE_SOLD;
			case 'deleted':
				return LinkMap::STATE_DELETED;
			default:
				return LinkMap::STATE_PENDING;
		}
	}
}
