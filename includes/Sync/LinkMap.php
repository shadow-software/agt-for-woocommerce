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
			last_pulled_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY (product_id),
			KEY state (state),
			KEY listing_id (listing_id),
			KEY last_pulled_at (last_pulled_at)
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; there is no core API for it.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE product_id = %d', $table, $product_id ),
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

		// ONE atomic upsert, not SELECT-then-INSERT-or-UPDATE.
		//
		// Action Scheduler runs jobs concurrently, and two workers can easily touch the
		// same product at once (a save and a status poll, say). With a read followed by
		// a write, both see "no row", both INSERT, and the second one violates the
		// PRIMARY KEY on product_id. wpdb swallows that, so the loser's write is lost
		// silently — and if the lost write was the one carrying the listing_id from a
		// successful create, the next push sees no listing_id and publishes the same
		// gun a SECOND time.
		//
		// INSERT first, and fall back to UPDATE only when the row already exists.
		//
		// NOT the other way round, and NOT a SELECT followed by a decision. Action
		// Scheduler runs jobs concurrently, and two workers can easily touch the same
		// product at once — a product save and a status poll, say. With a read and
		// then a write, both see "no row", both INSERT, and the loser's write is
		// silently swallowed by the PRIMARY KEY. If the write it lost was the one
		// carrying the listing_id from a successful create, the next push sees no
		// listing_id and publishes the same gun a SECOND time.
		//
		// Letting the INSERT race and catching the duplicate makes the primary key
		// itself the arbiter, which is the only thing here that is actually atomic.
		// Both branches use $wpdb->insert()/update(), which derive a format per value
		// and so write a null as SQL NULL — a hand-built statement binding null to %s
		// would write an EMPTY STRING instead, quietly turning "last_error => null"
		// ("this product is fine") into "last_error = ''".
		$insert = array_merge( array( 'product_id' => $product_id ), $row );

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; there is no core API for it.
		$inserted = $wpdb->insert( $table, $insert );

		$wpdb->suppress_errors( $suppress );

		if ( false !== $inserted ) {
			return;
		}

		// The row was already there (or another worker won the race). Merge into it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; there is no core API for it.
		$wpdb->update( $table, $row, array( 'product_id' => $product_id ) );
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
				// ORDER BY last_pulled_at (now NOT NULL, indexed) so the hourly poll picks
				// the least-recently-checked listings using the index and stops after
				// LIMIT rows — no full scan + filesort of every tracked listing.
				"SELECT product_id, listing_id FROM %i WHERE listing_id IS NOT NULL AND listing_id <> '' ORDER BY last_pulled_at ASC LIMIT %d",
				$table,
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
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT state, COUNT(*) AS total FROM %i GROUP BY state', $table ),
			ARRAY_A
		);

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
				'SELECT product_id, last_error FROM %i WHERE state = %s LIMIT %d',
				$table,
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table on an explicit purge; there is no core API for it.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}
