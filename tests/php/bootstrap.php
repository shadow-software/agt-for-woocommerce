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
