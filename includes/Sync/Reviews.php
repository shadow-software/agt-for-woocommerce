<?php
/**
 * Read-only AGT listing reviews mirrored to WooCommerce product meta.
 *
 * @package AgtSync
 */

namespace AgtSync\Sync;

use AgtSync\Api\Client;

defined( 'ABSPATH' ) || exit;

/** Store the read-only review projection on the linked product. */
final class Reviews {
	public const REVIEWS_META = 'agt_sync_reviews';
	public const SUMMARY_META = 'agt_sync_reviews_summary';

	/**
	 * Pull and persist the current review projection for a listing.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $listing_id AGT listing identifier.
	 * @param Client $client     Authenticated AGT API client.
	 */
	public static function sync( int $product_id, string $listing_id, Client $client ): void {
		if ( $product_id <= 0 || '' === $listing_id ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return; }
		$response = $client->get( '/listings/' . rawurlencode( $listing_id ) . '/reviews' );
		$data     = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		if ( self::is_stale( $data ) ) {
			$product->delete_meta_data( self::REVIEWS_META );
			$product->delete_meta_data( self::SUMMARY_META );
			$product->save_meta_data();
			return;
		}
		$source = (string) ( $data['source'] ?? 'dealer' );
		$product->update_meta_data( self::REVIEWS_META, self::normalize_reviews( $data['reviews'] ?? array(), $source ) );
		$product->update_meta_data(
			self::SUMMARY_META,
			array(
				'source'     => $source,
				'average'    => (float) ( $data['summary']['average'] ?? 0 ),
				'count'      => (int) ( $data['summary']['count'] ?? 0 ),
				'updated_at' => isset( $data['summary']['updated_at'] ) ? (string) $data['summary']['updated_at'] : null,
				'synced_at'  => current_time( 'mysql', true ),
			)
		);
		$product->save_meta_data();
	}

	/**
	 * Determine whether the upstream listing no longer has usable reviews.
	 *
	 * @param array<string,mixed> $data Review endpoint payload.
	 * @return bool
	 */
	public static function is_stale( array $data ): bool {
		return ! empty( $data['stale'] ) || in_array( (string) ( $data['listing_status'] ?? '' ), array( 'deleted', 'pending', 'rejected' ), true );
	}

	/**
	 * Normalize only the public review fields allowed in product meta.
	 *
	 * @param mixed  $reviews Review payload.
	 * @param string $source  Upstream provenance label.
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_reviews( $reviews, string $source = 'dealer' ): array {
		if ( ! is_array( $reviews ) ) {
			return array();
		}
		$out = array();
		foreach ( $reviews as $review ) {
			if ( ! is_array( $review ) || ! isset( $review['id'], $review['rating'] ) ) {
				continue;
			}
			$out[] = array(
				'id'             => (string) $review['id'],
				'rating'         => (int) $review['rating'],
				'title'          => array_key_exists( 'title', $review ) ? ( null === $review['title'] ? null : (string) $review['title'] ) : null,
				'body'           => array_key_exists( 'body', $review ) ? ( null === $review['body'] ? null : (string) $review['body'] ) : null,
				'author_display' => isset( $review['author_display'] ) ? (string) $review['author_display'] : 'anonymous',
				'created_at'     => isset( $review['created_at'] ) ? (string) $review['created_at'] : null,
				'updated_at'     => isset( $review['updated_at'] ) ? (string) $review['updated_at'] : null,
				'source'         => $source,
			);
		}
		return $out;
	}
}
