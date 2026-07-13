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
		// A token in a log is a live credential — a merchant's WooCommerce logs are
		// readable by any admin and get pasted into support threads. So redaction
		// over-reaches on purpose: better to blank a harmless value than to leak a
		// real one.

		// 1. Anything after a Bearer scheme.
		$message = (string) preg_replace( '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i', '$1[redacted]', $message );

		// 2. A credential value after a known key, in any shape a log line takes:
		// "key":"val"  key=val  key => val  (the last is a print_r / var_export
		// dump of the tokens option). `token` is in the list because that is the
		// field name the /revoke request body uses.
		$keys = 'access_token|refresh_token|client_secret|code_verifier|code|token';

		$message = (string) preg_replace(
			'/(["\']?(?:' . $keys . ')["\']?\s*(?:=>|[:=])\s*["\']?)[A-Za-z0-9\-\._~\+\/\|]{8,}(["\']?)/i',
			'$1[redacted]$2',
			$message
		);

		// 3. A last-resort catch for a bare, label-less credential. The plugin's own
		// tokens are 40- or 64-char base64url strings, and Laravel PATs look like
		// "12|<40+ chars>". Redact those shapes even with no surrounding key, so a
		// stray dump cannot slip a token through on a format we did not anticipate.
		$message = (string) preg_replace( '/\b\d+\|[A-Za-z0-9]{40,}\b/', '[redacted]', $message );

		return (string) preg_replace( '/\b[A-Za-z0-9\-_]{40,}\b/', '[redacted]', $message );
	}
}
