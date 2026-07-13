<?php
/**
 * Constants PHPStan needs to know about — the ones the plugin's main file
 * define()s at bootstrap.
 *
 * @package AgtSync
 */

declare(strict_types=1);

define( 'AGT_SYNC_VERSION', '1.0.0' );
define( 'AGT_SYNC_FILE', __FILE__ );
define( 'AGT_SYNC_PATH', __DIR__ );
define( 'AGT_SYNC_URL', 'https://example.test/' );
define( 'AGT_SYNC_API_BASE', 'https://americanguntrader.com' );
