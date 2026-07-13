<?php
/**
 * Install, activate, deactivate — the plugin's whole lifecycle in one place.
 *
 * @package AgtSync
 */

namespace AgtSync;

use AgtSync\Sync\LinkMap;
use AgtSync\Sync\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin creates, and how it is set up and torn down.
 *
 * Two rules run through all of it:
 *
 *   1. Deactivating stops the plugin from DOING anything — the scheduled jobs are
 *      cancelled so nothing keeps firing at American Gun Trader — but touches no
 *      data. A merchant who deactivates and reactivates finds their store exactly
 *      as they left it.
 *
 *   2. Uninstalling removes the plugin's own bookkeeping, but by default LEAVES
 *      the link between products and listings, and never touches a listing on
 *      American Gun Trader. Deleting a plugin is not a statement about the
 *      merchant's inventory. (uninstall.php handles that; the option names it
 *      cleans are the same ones listed here, kept in sync deliberately.)
 *
 * The whole thing is written to HEAL: activation and a per-request check both
 * make sure the table exists and is current, so a half-finished install, a manual
 * database edit, or a plugin updated by copying files can never leave the plugin
 * in a broken state.
 */
final class Lifecycle {

	/**
	 * The DB schema version this build expects. Bump when LinkMap's schema changes
	 * so the per-request heal re-runs dbDelta.
	 */
	private const DB_VERSION = '2';

	/**
	 * The option that records the installed schema version.
	 */
	private const DB_VERSION_OPTION = 'agt_sync_db_version';

	/**
	 * Every OPTION the plugin owns. The single source of truth: uninstall.php
	 * cleans exactly these, and the deactivate/reset paths use the operational
	 * subset.
	 *
	 * @return array<int,string>
	 */
	public static function options(): array {
		return array(
			'agt_sync_settings',       // Merchant configuration.
			'agt_sync_client',         // OAuth client registration.
			'agt_sync_tokens',         // OAuth access + refresh tokens.
			'agt_sync_account',        // Cached /me identity.
			'agt_sync_bucket',         // Rate-limiter token bucket.
			'agt_sync_refresh_lock',   // Single-flight token-refresh lock.
			'agt_sync_bulk_sold_held', // "A poll looked like a bulk-sold attack" flag.
			'agt_sync_purge_on_uninstall',
			self::DB_VERSION_OPTION,
		);
	}

	/**
	 * Options that are pure OPERATIONAL state — locks, buckets, transient-ish
	 * flags — safe to clear on deactivate without losing anything a merchant set.
	 *
	 * @return array<int,string>
	 */
	private static function operational_options(): array {
		return array(
			'agt_sync_bucket',
			'agt_sync_refresh_lock',
			'agt_sync_bulk_sold_held',
		);
	}

	/**
	 * Every TRANSIENT the plugin owns.
	 *
	 * @return array<int,string>
	 */
	public static function transients(): array {
		return array(
			'agt_sync_taxonomy', // Cached AGT taxonomy.
			'agt_sync_pkce',     // In-flight OAuth PKCE verifier + state.
		);
	}

	/**
	 * Activation. Runs once, when the plugin is switched on.
	 *
	 * Multisite-aware: activating across a network sets every blog up, so a table
	 * is never missing on a site the merchant later switches to.
	 *
	 * @param bool $network_wide True when activated network-wide on multisite.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( self::site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}

			return;
		}

		self::install();
	}

	/**
	 * A new blog was added to a network on which the plugin is network-active — set
	 * it up too, so it is never the odd one out with no table.
	 *
	 * @param int $blog_id The new blog id.
	 * @return void
	 */
	public static function on_new_blog( int $blog_id ): void {
		// is_plugin_active_for_network() lives in an admin include that is not always
		// loaded (this fires on wp_initialize_site, which can run outside admin).
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( AGT_SYNC_FILE ) ) ) {
			return;
		}

		switch_to_blog( $blog_id );
		self::install();
		restore_current_blog();
	}

	/**
	 * Create/upgrade the table and stamp the schema version. Idempotent — dbDelta
	 * only changes what needs changing, so this is safe to call on activation AND
	 * from the per-request heal.
	 *
	 * @return void
	 */
	public static function install(): void {
		LinkMap::install();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Heal, cheaply, on a normal request.
	 *
	 * The version option is the fast path: if it matches, do nothing. If it does
	 * not — a fresh copy, a manual DB edit, a file-copy update that skipped
	 * activation, a schema bump — run install() to bring the table into line. This
	 * is what lets the plugin recover from a half-finished install without the
	 * merchant ever seeing a broken screen.
	 *
	 * @return void
	 */
	public static function maybe_heal(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION && self::table_exists() ) {
			return;
		}

		self::install();
	}

	/**
	 * Deactivation. Stop the plugin DOING things; change no data.
	 *
	 * Cancels every scheduled job so nothing keeps calling American Gun Trader
	 * after the plugin is off, and clears the operational locks/buckets so a
	 * reactivation starts from a clean slate rather than inheriting a half-held
	 * lock. The connection, the settings, and the product<->listing links are all
	 * left untouched: reactivating resumes exactly where the merchant left off.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Queue::cancel_all();

		foreach ( self::operational_options() as $option ) {
			delete_option( $option );
		}

		foreach ( self::transients() as $transient ) {
			delete_transient( $transient );
		}
	}

	/**
	 * Does the link table exist? Cheap heal check.
	 *
	 * @return bool
	 */
	private static function table_exists(): bool {
		global $wpdb;

		$table = LinkMap::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema probe; no core API for it.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Every blog id on a multisite network.
	 *
	 * @return array<int,int>
	 */
	private static function site_ids(): array {
		$ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
