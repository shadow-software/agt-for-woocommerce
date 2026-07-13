<?php
/**
 * Plugin Name:       AGT Sync for WooCommerce
 * Plugin URI:        https://github.com/shadow-software/agt-sync-for-woocommerce
 * Description:       Publish your WooCommerce products as listings on American Gun Trader, and keep them in step. When a gun sells on AGT, the WooCommerce product is set out of stock automatically — so you never sell the same firearm twice. Free and open source; requires an American Gun Trader dealer account.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Shadow Software LLC
 * Author URI:        https://shadowsoftware.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agt-sync-for-woocommerce
 * Domain Path:       /languages
 *
 * WC requires at least: 8.2
 * WC tested up to:      10.8
 *
 * @package AgtSync
 */

defined( 'ABSPATH' ) || exit;

// Keep in lockstep with the "Version:" header above and readme.txt's
// "Stable tag:" + changelog.
define( 'AGT_SYNC_VERSION', '1.0.0' );
define( 'AGT_SYNC_FILE', __FILE__ );
define( 'AGT_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'AGT_SYNC_URL', plugin_dir_url( __FILE__ ) );

/**
 * The American Gun Trader site this plugin talks to.
 *
 * Overridable with the AGT_SYNC_API_BASE constant so the plugin can be developed
 * against a local AGT. Not a setting: a merchant has no reason to point their
 * catalogue at a different host, and making it one would turn a misconfiguration
 * into a data leak.
 */
if ( ! defined( 'AGT_SYNC_API_BASE' ) ) {
	define( 'AGT_SYNC_API_BASE', 'https://americanguntrader.com' );
}

/**
 * Minimal PSR-4-ish autoloader for the plugin's own classes. The plugin ships no
 * Composer dependencies in the distributed build so it stays drop-in and
 * wp.org-friendly.
 *
 * Hardened against path traversal: the namespace prefix is stripped, the
 * remaining class name is validated to contain only class-name characters, and
 * only files that resolve inside our own includes/ tree are ever required.
 *
 * @param string $classname Fully-qualified class name being autoloaded.
 * @return void
 */
spl_autoload_register(
	static function ( $classname ) {
		$prefix = 'AgtSync\\';

		if ( 0 !== strpos( $classname, $prefix ) ) {
			return;
		}

		$relative = substr( $classname, strlen( $prefix ) );

		// A valid class name here is only [A-Za-z0-9_\]; anything else (a '.' from
		// ./ or ../, a separator) means it is not one of ours.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$base     = AGT_SYNC_PATH . 'includes' . DIRECTORY_SEPARATOR;
		$file     = $base . $relative . '.php';

		$real_base = realpath( $base );
		$real_file = realpath( $file );

		if ( false === $real_base || false === $real_file || 0 !== strpos( $real_file, $real_base ) ) {
			return;
		}

		require $real_file;
	}
);

/*
 * There is deliberately no load_plugin_textdomain() call here.
 *
 * WordPress has loaded a plugin's translations automatically since 4.6, and for a
 * plugin hosted on WordPress.org the just-in-time loader handles the whole thing
 * from the text domain alone. Calling it by hand is flagged as discouraged by the
 * WordPress.org Plugin Check, and it is: it would only duplicate what core already
 * does. The `Domain Path: /languages` header and the .pot in that directory are
 * what translators and the directory need.
 */

/**
 * Declare compatibility with WooCommerce features. We only touch products, not
 * orders, but declaring HPOS + Blocks compatibility explicitly is what stops
 * WooCommerce warning the merchant that this plugin might be incompatible.
 *
 * @return void
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			AGT_SYNC_FILE,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			AGT_SYNC_FILE,
			true
		);
	}
);

/**
 * Set the plugin up on activation (multisite-aware).
 *
 * @param bool $network_wide True when activated network-wide.
 * @return void
 */
register_activation_hook(
	AGT_SYNC_FILE,
	static function ( $network_wide = false ) {
		\AgtSync\Lifecycle::activate( (bool) $network_wide );
	}
);

/**
 * On deactivation, stop the plugin DOING anything — cancel every scheduled job so
 * nothing keeps calling American Gun Trader — but change no data. Reactivating
 * resumes exactly where the merchant left off.
 *
 * @return void
 */
register_deactivation_hook(
	AGT_SYNC_FILE,
	static function () {
		\AgtSync\Lifecycle::deactivate();
	}
);

/**
 * A blog added to a network on which the plugin is network-active needs its own
 * table, or it would be the one site with the plugin "on" and nothing to write to.
 */
add_action(
	'wp_initialize_site',
	static function ( $new_site ) {
		if ( $new_site instanceof \WP_Site ) {
			\AgtSync\Lifecycle::on_new_blog( (int) $new_site->blog_id );
		}
	},
	100
);

/**
 * Boot the plugin once all plugins are loaded, but only when WooCommerce is
 * active. If WooCommerce is missing, show an admin notice and stand down so the
 * site never fatals.
 *
 * @return void
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'AGT Sync for WooCommerce requires WooCommerce to be installed and active.', 'agt-sync-for-woocommerce' );
					echo '</p></div>';
				}
			);

			return;
		}

		\AgtSync\Plugin::instance()->init();
	}
);
