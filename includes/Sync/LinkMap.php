<?php
/**
 * The product <-> listing link table.
 *
 * @package AgtSync
 */

namespace AgtSync\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * One row per synced product: which AGT listing it is, what we last sent, and
 * where it stands.
 *
 * A custom table rather than post meta because the sync engine has to ask
 * questions post meta answers badly at catalogue scale — "everything whose
 * content changed", "everything currently live" — across thousands of products.
 *
 * `payload_hash` is what keeps the whole thing cheap: if the hash of what we
 * would send matches what we last sent, there is nothing to do and no request is
 * made. A merchant hammering Save on a product produces exactly zero API calls.
 */
final class LinkMap {

	/**
	 * States a link can be in.
	 */
	public const STATE_PENDING    = 'pending';
	public const STATE_LIVE       = 'live';
	public const STATE_MODERATION = 'moderation';
	public const STATE_REJECTED   = 'rejected';
	public const STATE_SOLD       = 'sold';
	public const STATE_DELETED    = 'deleted';
	public const STATE_ERROR      = 'error';
	public const STATE_SKIPPED    = 'skipped';

	/**
	 * The table name, with the site's prefix.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'agt_sync_links';
	}

	/**
	 * Create the table. Runs on activation.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			product_id BIGINT UNSIGNED NOT NULL,
			listing_id VARCHAR(36) NULL,
			payload_hash CHAR(64) NULL,
			image_hash CHAR(64) NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'pending',
			last_error TEXT NULL,
			listing_url VARCHAR(255) NULL,
			views INT UNSIGNED NOT NULL DEFAULT 0,
			bid_count INT UNSIGNED NOT NULL DEFAULT 0,
			last_pushed_at DATETIME NULL,
			last_pulled_at DATETIME NULL,
			PRIMARY KEY (product_id),
			KEY state (state),
			KEY listing_id (listing_id)
		) {$collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * The link row for a product, or null.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $product_id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; no core API for it.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d", $product_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The AGT listing id for a product, or '' if it has none.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return string
	 */
	public static function listing_id( int $product_id ): string {
		$row = self::get( $product_id );

		return is_array( $row ) && ! empty( $row['listing_id'] ) ? (string) $row['listing_id'] : '';
	}

	/**
	 * Insert or update a product's link row.
	 *
	 * @param int                 $product_id WooCommerce product id.
	 * @param array<string,mixed> $data       Columns to write.
	 * @return void
	 */
	public static function save( int $product_id, array $data ): void {
		global $wpdb;

		$table = self::table();

		$allowed = array(
			'listing_id',
			'payload_hash',
			'image_hash',
			'state',
			'last_error',
			'listing_url',
			'views',
			'bid_count',
			'last_pushed_at',
			'last_pulled_at',
		);

		$row = array();

		foreach ( $allowed as $column ) {
			if ( array_key_exists( $column, $data ) ) {
				$row[ $column ] = $data[ $column ];
			}
		}

		if ( empty( $row ) ) {
			return;
		}

		$exists = null !== self::get( $product_id );

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
			$wpdb->update( $table, $row, array( 'product_id' => $product_id ) );

			return;
		}

		$row['product_id'] = $product_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$wpdb->insert( $table, $row );
	}

	/**
	 * Forget a product entirely. Used when a product is permanently deleted AND
	 * its listing is gone — never merely because a listing was removed, since the
	 * listing id is what makes restoring it possible.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return void
	 */
	public static function forget( int $product_id ): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$wpdb->delete( $table, array( 'product_id' => $product_id ) );
	}

	/**
	 * Every listing id we currently track, so the status poll knows what to ask
	 * about. Deleted ones are included — a restore has to be able to find them.
	 *
	 * @param int $limit Maximum ids to return.
	 * @return array<string,int> Listing id => product id.
	 */
	public static function tracked_listings( int $limit = 200 ): array {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( $limit, 200 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, listing_id FROM {$table} WHERE listing_id IS NOT NULL AND listing_id <> '' ORDER BY COALESCE(last_pulled_at, '1970-01-01') ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
				$limit
			),
			ARRAY_A
		);

		$map = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (string) $row['listing_id'] ] = (int) $row['product_id'];
			}
		}

		return $map;
	}

	/**
	 * How many products are in each state — the numbers the settings screen shows.
	 *
	 * @return array<string,int>
	 */
	public static function counts(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$rows = $wpdb->get_results( "SELECT state, COUNT(*) AS total FROM {$table} GROUP BY state", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.

		$counts = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ (string) $row['state'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Products currently in an error state, for the admin notice.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function errors( int $limit = 20 ): array {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( $limit, 100 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, last_error FROM {$table} WHERE state = %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
				self::STATE_ERROR,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Drop the table. Only ever called from uninstall, and only when the merchant
	 * explicitly asked for their sync data to be removed — dropping it otherwise
	 * would orphan every live listing and make a reinstall duplicate the lot.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; DDL cannot be prepared.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
