<?php
/**
 * PHPUnit bootstrap.
 *
 * The plugin's logic is unit-tested against Brain Monkey, which mocks WordPress
 * rather than booting it — so the suite runs in a second, with no database and no
 * WordPress install.
 *
 * @package AgtSync
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// The constants the plugin's main file would define.
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'AGT_SYNC_VERSION', '1.0.0' );
define( 'AGT_SYNC_FILE', dirname( __DIR__, 2 ) . '/agt-sync-for-woocommerce.php' );
define( 'AGT_SYNC_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'AGT_SYNC_URL', 'https://example.test/wp-content/plugins/agt-sync-for-woocommerce/' );
define( 'AGT_SYNC_API_BASE', 'https://americanguntrader.test' );

// WordPress time constants.
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/**
 * A minimal WC_Product stand-in.
 *
 * WooCommerce is not loaded here — the suite mocks WordPress rather than booting
 * it, which is what keeps it running in under a second. The plugin only ever
 * calls a handful of getters on a product, so declaring them is enough to let the
 * mapping logic be tested for real. Every method a test needs must be listed, or
 * PHPUnit cannot mock it.
 */
if ( ! class_exists( 'WC_Product' ) ) {
	// phpcs:disable
	class WC_Product {
		public function get_id() {}
		public function get_name() {}
		public function get_status() {}
		public function get_description() {}
		public function get_short_description() {}
		public function get_regular_price() {}
		public function get_price() {}
		public function get_weight() {}
		public function get_meta( $key = '' ) {}
		public function get_attribute( $name = '' ) {}
		public function get_category_ids() {}
		public function get_image_id() {}
		public function get_gallery_image_ids() {}
		public function is_type( $type = '' ) {}
		public function managing_stock() {}
		public function set_stock_status( $status = '' ) {}
		public function set_stock_quantity( $qty = 0 ) {}
		public function save() {}
	}
	// phpcs:enable
}

// The plugin's own autoloader, so tests exercise the real one.
spl_autoload_register(
	static function ( string $classname ): void {
		$prefix = 'AgtSync\\';

		if ( 0 !== strpos( $classname, $prefix ) ) {
			return;
		}

		$relative = substr( $classname, strlen( $prefix ) );

		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$file = AGT_SYNC_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);
