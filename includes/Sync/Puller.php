<?php
/**
 * American Gun Trader -> WooCommerce.
 *
 * @package AgtSync
 */

namespace AgtSync\Sync;

use AgtSync\Api\ApiException;
use AgtSync\Api\Client;
use AgtSync\Logger;
use AgtSync\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The writeback.
 *
 * This is the reason two-way sync exists: when a gun sells on American Gun
 * Trader, the WooCommerce product is set out of stock, so the dealer cannot sell
 * the same firearm twice to two people.
 *
 * It works by POLLING, not by a webhook. Most dealer stores sit behind a WAF, on
 * a private host, or on a URL that is not reachable from the outside — AGT could
 * not call them even if it wanted to. One cheap bulk request per hundred listings
 * gets "it sold" into WooCommerce within the hour, and the store stays in control
 * of when it happens.
 */
final class Puller {

	/**
	 * How many listings one poll asks about. The API caps this at 200.
	 */
	private const BATCH = 100;

	/**
	 * The option set when a poll's sold count looked implausible, so the admin
	 * screen can surface it.
	 */
	public const BULK_SOLD_FLAG = 'agt_sync_bulk_sold_held';

	/**
	 * The bulk-sold guard only engages once a poll returned at least this many
	 * statuses — below it, ordinary variance is meaningless.
	 */
	private const BULK_SOLD_MIN_POLL = 8;

	/**
	 * ...and at least this many NEW solds. A tiny store must not be tripped by one
	 * or two genuine sales.
	 */
	private const BULK_SOLD_MIN_COUNT = 5;

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
	 * Poll American Gun Trader for the status of the listings we track, and act on
	 * what comes back.
	 *
	 * @return void
	 * @throws ApiException On a retryable failure, so the queue can back off.
	 */
	public function poll(): void {
		$tracked = LinkMap::tracked_listings( self::BATCH );

		if ( empty( $tracked ) ) {
			return;
		}

		$response = $this->client->get(
			'/listings/status',
			array( 'slugs' => implode( ',', array_keys( $tracked ) ) )
		);

		$statuses = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		// A sanity floor against a compromised or MITM'd server. Marking a listing
		// sold takes the WooCommerce product out of stock — the plugin trusts the
		// server for that. A hostile response that flips a large slice of the poll to
		// "sold" at once would silently pull the merchant's catalogue off sale in
		// their own store, which is exactly the kind of quiet damage they would not
		// notice until orders dried up. A genuine burst of real sales in a single
		// hour is possible but rare; the safe move on an implausible one is to hold
		// the stock writes and ask, not to obey.
		if ( $this->looks_like_a_bulk_sold_attack( $tracked, $statuses ) ) {
			update_option( self::BULK_SOLD_FLAG, current_time( 'mysql', true ), false );

			Logger::error(
				'American Gun Trader reported an unusually large number of listings sold in one poll. Holding the out-of-stock changes and flagging it for review, in case the response is not genuine.'
			);

			return;
		}

		delete_option( self::BULK_SOLD_FLAG );

		foreach ( $tracked as $listing_id => $product_id ) {
			if ( ! isset( $statuses[ $listing_id ] ) || ! is_array( $statuses[ $listing_id ] ) ) {
				continue;
			}

			$this->apply( (int) $product_id, $statuses[ $listing_id ] );
		}
	}

	/**
	 * Would this poll flip an implausible number of listings to "sold"?
	 *
	 * Counts only NEW sold transitions — listings we did not already have recorded
	 * as sold — so a stable set of genuinely-sold listings never trips it. The trip
	 * is on both a fraction of the poll AND an absolute floor, so a two-listing
	 * store is not held hostage by a single real sale.
	 *
	 * @param array<string,int>                 $tracked  Listing id => product id.
	 * @param array<string,array<string,mixed>> $statuses The server's per-listing status.
	 * @return bool
	 */
	private function looks_like_a_bulk_sold_attack( array $tracked, array $statuses ): bool {
		$polled     = 0;
		$newly_sold = 0;

		foreach ( $tracked as $listing_id => $product_id ) {
			if ( ! isset( $statuses[ $listing_id ] ) || ! is_array( $statuses[ $listing_id ] ) ) {
				continue;
			}

			++$polled;

			$is_sold = 'sold' === ( isset( $statuses[ $listing_id ]['status'] ) ? (string) $statuses[ $listing_id ]['status'] : '' );

			if ( ! $is_sold ) {
				continue;
			}

			$link = LinkMap::get( (int) $product_id );

			if ( ! is_array( $link ) || LinkMap::STATE_SOLD !== (string) ( $link['state'] ?? '' ) ) {
				++$newly_sold;
			}
		}

		if ( $polled < self::BULK_SOLD_MIN_POLL || $newly_sold < self::BULK_SOLD_MIN_COUNT ) {
			return false;
		}

		/**
		 * Filters the fraction of a single poll that must newly turn "sold" before the
		 * plugin treats the response as suspicious and holds the stock changes.
		 *
		 * @since 1.0.0
		 *
		 * @param float $fraction Between 0 and 1. Default 0.5.
		 */
		$threshold = (float) apply_filters( 'agt_sync_bulk_sold_threshold', 0.5 );

		return ( $newly_sold / $polled ) >= $threshold;
	}

	/**
	 * Apply one listing's status to its WooCommerce product.
	 *
	 * @param int                 $product_id WooCommerce product id.
	 * @param array<string,mixed> $status     The status payload.
	 * @return void
	 */
	private function apply( int $product_id, array $status ): void {
		$state = Pusher::state_from_status( isset( $status['status'] ) ? (string) $status['status'] : '' );

		$link      = LinkMap::get( $product_id );
		$was_state = is_array( $link ) ? (string) ( $link['state'] ?? '' ) : '';

		LinkMap::save(
			$product_id,
			array(
				'state'          => $state,
				'listing_url'    => isset( $status['url'] ) ? (string) $status['url'] : null,
				'views'          => isset( $status['views'] ) ? (int) $status['views'] : 0,
				'bid_count'      => isset( $status['bid_count'] ) ? (int) $status['bid_count'] : 0,
				'last_error'     => isset( $status['moderation_reason'] ) && '' !== (string) $status['moderation_reason']
					? (string) $status['moderation_reason']
					: null,
				'last_pulled_at' => current_time( 'mysql', true ),
			)
		);

		// THE feature: it sold on AGT, so it must stop being sellable here.
		// Only act on the TRANSITION, so a merchant who deliberately restocks a
		// product does not have it yanked out of stock again on the next poll.
		if ( LinkMap::STATE_SOLD === $state && LinkMap::STATE_SOLD !== $was_state ) {
			$this->mark_out_of_stock( $product_id );
		}
	}

	/**
	 * Set a product out of stock because its listing sold on American Gun Trader.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return void
	 */
	private function mark_out_of_stock( int $product_id ): void {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		/**
		 * Fires just before a product is taken out of stock because it sold on
		 * American Gun Trader. Return false to leave the product alone.
		 *
		 * @since 1.0.0
		 *
		 * @param bool        $should  Whether to set the product out of stock.
		 * @param \WC_Product $product The product.
		 */
		$should = apply_filters( 'agt_sync_should_mark_out_of_stock', true, $product );

		if ( ! $should ) {
			return;
		}

		$product->set_stock_status( 'outofstock' );

		if ( $product->managing_stock() ) {
			$product->set_stock_quantity( 0 );
		}

		$product->save();

		// Leave a trail. A product silently going out of stock is the kind of thing
		// that makes a merchant distrust an integration; a note saying exactly what
		// happened, and why, is the difference.
		$product->add_meta_data( '_agt_sync_sold_at', current_time( 'mysql', true ), true );
		$product->save_meta_data();

		Logger::info(
			sprintf(
				'Product #%d sold on American Gun Trader; set out of stock in WooCommerce.',
				$product_id
			)
		);

		/**
		 * Fires after a product has been set out of stock because it sold on American
		 * Gun Trader.
		 *
		 * @since 1.0.0
		 *
		 * @param \WC_Product $product The product.
		 */
		do_action( 'agt_sync_product_sold_on_agt', $product );
	}

	/**
	 * Is the writeback switched on?
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return Settings::ready_to_sync();
	}
}
