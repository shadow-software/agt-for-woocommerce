<?php
/**
 * The WordPress-side rate limiter.
 *
 * @package AgtSync
 */

namespace AgtSync\Api;

use AgtSync\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A token bucket, so this store paces ITSELF.
 *
 * The sync is initiated and rate-limited here, on the WordPress side. American
 * Gun Trader has its own ceiling, but a plugin that only discovers a limit by
 * being 429'd is a plugin that hammers someone else's server until it is told to
 * stop. This keeps us comfortably underneath it, so a well-behaved store never
 * sees a 429 at all.
 *
 * When the server does push back (a 429 with Retry-After), the bucket is halved
 * — additive-increase/multiplicative-decrease, the same instinct TCP has. It
 * recovers as the minute rolls over.
 */
final class RateLimit {

	/**
	 * The bucket state.
	 */
	private const OPTION = 'agt_sync_bucket';

	/**
	 * Take a token. Returns false if the bucket is empty, in which case the caller
	 * must reschedule rather than block a PHP worker waiting.
	 *
	 * @return bool
	 */
	public static function consume(): bool {
		$bucket = self::bucket();

		if ( $bucket['tokens'] < 1 ) {
			return false;
		}

		--$bucket['tokens'];
		self::put( $bucket );

		return true;
	}

	/**
	 * Seconds until the bucket refills. What a caller should wait before trying
	 * again.
	 *
	 * @return int
	 */
	public static function seconds_until_refill(): int {
		$bucket = self::bucket();
		$age    = time() - (int) $bucket['window_start'];

		return max( 1, MINUTE_IN_SECONDS - $age );
	}

	/**
	 * The server told us to slow down. Halve the allowance for this window so we
	 * stop walking into the same wall.
	 *
	 * @return void
	 */
	public static function back_off(): void {
		$bucket = self::bucket();

		$bucket['tokens']  = 0;
		$bucket['penalty'] = max( 1, (int) floor( self::limit() / 2 ) );

		self::put( $bucket );
	}

	/**
	 * Reset the limiter. Used when settings change.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The current bucket, refilled if the window has rolled over.
	 *
	 * @return array{tokens:int,window_start:int,penalty:int}
	 */
	private static function bucket(): array {
		$stored = get_option( self::OPTION, array() );

		$bucket = array(
			'tokens'       => self::limit(),
			'window_start' => time(),
			'penalty'      => 0,
		);

		if ( is_array( $stored ) && isset( $stored['window_start'] ) ) {
			$bucket = array_merge( $bucket, $stored );
		}

		// A new minute: refill. A penalty from the last window decays away.
		if ( time() - (int) $bucket['window_start'] >= MINUTE_IN_SECONDS ) {
			$allowance = self::limit();

			if ( (int) $bucket['penalty'] > 0 ) {
				$allowance = max( 1, (int) $bucket['penalty'] );
			}

			$bucket = array(
				'tokens'       => $allowance,
				'window_start' => time(),
				'penalty'      => 0,
			);
		}

		return array(
			'tokens'       => (int) $bucket['tokens'],
			'window_start' => (int) $bucket['window_start'],
			'penalty'      => (int) $bucket['penalty'],
		);
	}

	/**
	 * Persist the bucket.
	 *
	 * @param array<string,int> $bucket The bucket.
	 * @return void
	 */
	private static function put( array $bucket ): void {
		update_option( self::OPTION, $bucket, false );
	}

	/**
	 * Requests per minute this store allows itself.
	 *
	 * @return int
	 */
	private static function limit(): int {
		$limit = Settings::int( 'rate_limit_per_min' );

		// Never above AGT's own ceiling, and never so low it cannot make progress.
		return max( 1, min( $limit > 0 ? $limit : 60, 120 ) );
	}
}
