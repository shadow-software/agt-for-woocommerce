<?php
/**
 * Uninstall cleanup for AGT Sync for WooCommerce.
 *
 * Runs only on plugin DELETION (not deactivation). WordPress loads this file in
 * isolation — the plugin's own classes are NOT guaranteed to be available — so it
 * is deliberately self-contained, and the option/meta names here are kept in step
 * with AgtSync\Lifecycle by hand.
 *
 * The most important decision in this file: by default it does NOT drop the sync
 * link table, and it NEVER touches a listing on American Gun Trader. Deleting the
 * link table would orphan every live listing — the plugin would forget which
 * product is which listing, and a reinstall would republish the whole catalogue
 * as duplicates. And deleting a plugin is not a statement about the merchant's
 * inventory: their listings are theirs, they live on American Gun Trader, and they
 * are removed from there, not from here.
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

	// Best effort: tell American Gun Trader to revoke this store's token, so a live
	// credential is not left sitting in their database after the plugin is gone.
	// Never blocks the uninstall — a slow or unreachable server must not wedge it.
	agt_sync_uninstall_revoke_token();

	// Cancel anything we still have on WooCommerce's Action Scheduler, so no ghost
	// job fires after the plugin's files are deleted (which would fatal). Guarded:
	// Action Scheduler is part of WooCommerce and may already be gone.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach (
			array(
				'agt_sync_push_product',
				'agt_sync_remove_listing',
				'agt_sync_restore_listing',
				'agt_sync_withdraw_listing',
				'agt_sync_poll_status',
				'agt_sync_refresh_taxonomy',
				'agt_sync_backfill',
			) as $hook
		) {
			as_unschedule_all_actions( $hook, array(), 'agt-sync' );
		}
	}

	// Every option the plugin owns (mirrors AgtSync\Lifecycle::options()).
	foreach (
		array(
			'agt_sync_settings',
			'agt_sync_client',
			'agt_sync_tokens',
			'agt_sync_account',
			'agt_sync_bucket',
			'agt_sync_refresh_lock',
			'agt_sync_bulk_sold_held',
			'agt_sync_db_version',
			'agt_sync_purge_on_uninstall',
		) as $option
	) {
		delete_option( $option );
	}

	// Caches.
	delete_transient( 'agt_sync_taxonomy' );
	delete_transient( 'agt_sync_pkce' );

	// The link table and the per-product meta, only if the merchant explicitly
	// asked — see the file header.
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
 * Ask American Gun Trader to revoke this store's token. Best effort only.
 *
 * @return void
 */
function agt_sync_uninstall_revoke_token() {
	$tokens = get_option( 'agt_sync_tokens', array() );
	$client = get_option( 'agt_sync_client', array() );

	$token     = is_array( $tokens ) && isset( $tokens['access_token'] ) ? (string) $tokens['access_token'] : '';
	$client_id = is_array( $client ) && isset( $client['client_id'] ) ? (string) $client['client_id'] : '';

	if ( '' === $token || '' === $client_id ) {
		return;
	}

	$base = defined( 'AGT_SYNC_API_BASE' ) ? AGT_SYNC_API_BASE : 'https://americanguntrader.com';

	wp_remote_post(
		rtrim( $base, '/' ) . '/oauth/dealer/revoke',
		array(
			'timeout'            => 5,
			'blocking'           => true,
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
			'headers'            => array( 'Accept' => 'application/json' ),
			'body'               => array(
				'token'     => $token,
				'client_id' => $client_id,
			),
		)
	);
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
