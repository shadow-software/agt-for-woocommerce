<?php
/**
 * Thin wrapper over the WooCommerce logger.
 *
 * All plugin logs land in the 'agt-sync' source so a merchant can read them under
 * WooCommerce → Status → Logs. Falls back to error_log() only when the WC logger
 * is unavailable (e.g. during very early bootstrap).
 *
 * @package AgtSync
 */

namespace AgtSync;

defined( 'ABSPATH' ) || exit;

/**
 * Static log facade.
 */
final class Logger {

	/**
	 * The log channel/source name.
	 */
	private const SOURCE = 'agt-sync';

	/**
	 * Log an informational message.
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	public static function info( string $message ): void {
		self::log( 'info', $message );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	public static function warn( string $message ): void {
		self::log( 'warning', $message );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	public static function error( string $message ): void {
		self::log( 'error', $message );
	}

	/**
	 * Route a message to the WooCommerce logger at the given level.
	 *
	 * Redacts anything that looks like a bearer token or a client secret before it
	 * reaches the log. A merchant's WooCommerce logs are readable by any admin and
	 * are routinely pasted into support threads — a leaked token there is a live
	 * credential for their listings.
	 *
	 * @param string $level   PSR-3 level name.
	 * @param string $message Message text.
	 * @return void
	 */
	private static function log( string $level, string $message ): void {
		$message = self::redact( $message );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );

			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback only when the WC logger is unavailable and WP_DEBUG is on.
			error_log( '[agt-sync] ' . $level . ': ' . $message );
		}
	}

	/**
	 * Strip credentials out of a log line.
	 *
	 * @param string $message Message text.
	 * @return string
	 */
	private static function redact( string $message ): string {
		// Bearer tokens and our 40/64-char random credentials.
		$message = (string) preg_replace( '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i', '$1[redacted]', $message );

		return (string) preg_replace(
			'/("?(?:access_token|refresh_token|client_secret|code|code_verifier)"?\s*[:=]\s*"?)[A-Za-z0-9\-\._~\+\/]{8,}("?)/i',
			'$1[redacted]$2',
			$message
		);
	}
}
