<?php
/**
 * Uninstall cleanup for AGT Sync for WooCommerce.
 *
 * Runs only on plugin deletion (not deactivation).
 *
 * Removes the settings, the credentials and the caches. Does NOT remove the sync
 * link table, and does NOT touch a single American Gun Trader listing.
 *
 * That is deliberate, and it is the most important decision in this file.
 * Deleting the link table would orphan every live listing the merchant has: the
 * plugin would no longer know which product is which listing, and reinstalling it
 * would republish the entire catalogue as duplicates. And deleting a plugin is not
 * a statement about the merchant's inventory — their listings are theirs, they
 * live on American Gun Trader, and they are removed from there, not from here.
 *
 * A merchant who genuinely wants the sync data gone ticks the box in the settings
 * before deleting, which sets agt_sync_purge_on_uninstall.
 *
 * @package AgtSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove this site's plugin data.
 *
 * @return void
 */
function agt_sync_uninstall_cleanup() {
	$purge = (bool) get_option( 'agt_sync_purge_on_uninstall', false );

	// Settings, credentials, and the local rate-limit bucket.
	delete_option( 'agt_sync_settings' );
	delete_option( 'agt_sync_client' );
	delete_option( 'agt_sync_tokens' );
	delete_option( 'agt_sync_account' );
	delete_option( 'agt_sync_bucket' );
	delete_option( 'agt_sync_db_version' );
	delete_option( 'agt_sync_purge_on_uninstall' );

	// Caches.
	delete_transient( 'agt_sync_taxonomy' );
	delete_transient( 'agt_sync_pkce' );
	delete_option( 'agt_sync_refresh_lock' );
	delete_option( 'agt_sync_bulk_sold_held' );

	// The link table, and the per-product meta, only if the merchant asked.
	if ( $purge ) {
		global $wpdb;

		$table = $wpdb->prefix . 'agt_sync_links';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table, only when the merchant explicitly asked for it.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

		delete_post_meta_by_key( '_agt_sync_enabled' );
		delete_post_meta_by_key( '_agt_sync_sold_at' );
	}
}

/**
 * Run cleanup for the current site, and for every site on multisite.
 */
if ( is_multisite() ) {
	$agt_sync_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $agt_sync_site_ids as $agt_sync_site_id ) {
		switch_to_blog( (int) $agt_sync_site_id );
		agt_sync_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	agt_sync_uninstall_cleanup();
}
