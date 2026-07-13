<?php
/**
 * The background sync queue.
 *
 * @package AgtSync
 */

namespace AgtSync\Sync;

use AgtSync\Api\ApiException;
use AgtSync\Logger;
use AgtSync\Settings;
use AgtSync\Taxonomy\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing talks to American Gun Trader in the request that saved a product.
 *
 * Every unit of work is queued and runs in the background, through WooCommerce's
 * own Action Scheduler — the same DB-backed, retrying scheduler WooCommerce uses
 * for its own jobs. A merchant saving a product never waits on a network call,
 * and a 5,000-product catalogue becomes 250 short jobs instead of one that times
 * out and leaves the store half-synced.
 */
final class Queue {

	/**
	 * Hook names.
	 */
	public const HOOK_PUSH     = 'agt_sync_push_product';
	public const HOOK_REMOVE   = 'agt_sync_remove_listing';
	public const HOOK_RESTORE  = 'agt_sync_restore_listing';
	public const HOOK_WITHDRAW = 'agt_sync_withdraw_listing';
	public const HOOK_POLL     = 'agt_sync_poll_status';
	public const HOOK_TAXONOMY = 'agt_sync_refresh_taxonomy';
	public const HOOK_BACKFILL = 'agt_sync_backfill';

	/**
	 * The Action Scheduler group, so a merchant can see our jobs apart from
	 * WooCommerce's own.
	 */
	private const GROUP = 'agt-sync';

	/**
	 * How long to wait after each failed attempt. The last value is reused for
	 * every attempt beyond the list, and then we stop.
	 *
	 * @var array<int,int>
	 */
	private const BACKOFF = array( 60, 300, 900, 3600, 21600 );

	/**
	 * Give up after this many attempts and park the product in an error state.
	 */
	private const MAX_ATTEMPTS = 6;

	/**
	 * Register the job handlers and the recurring schedules.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK_PUSH, array( $this, 'run_push' ), 10, 2 );
		add_action( self::HOOK_REMOVE, array( $this, 'run_remove' ), 10, 2 );
		add_action( self::HOOK_RESTORE, array( $this, 'run_restore' ), 10, 2 );
		add_action( self::HOOK_WITHDRAW, array( $this, 'run_withdraw' ), 10, 2 );
		add_action( self::HOOK_POLL, array( $this, 'run_poll' ) );
		add_action( self::HOOK_TAXONOMY, array( $this, 'run_taxonomy' ) );
		add_action( self::HOOK_BACKFILL, array( $this, 'run_backfill' ), 10, 1 );

		add_action( 'init', array( $this, 'ensure_recurring' ) );
	}

	/**
	 * Make sure the recurring jobs exist. Cheap to call on every request; Action
	 * Scheduler no-ops if they are already scheduled.
	 *
	 * @return void
	 */
	public function ensure_recurring(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! Settings::ready_to_sync() ) {
			$this->cancel_recurring();

			return;
		}

		if ( ! as_has_scheduled_action( self::HOOK_POLL, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK_POLL, array(), self::GROUP );
		}

		if ( ! as_has_scheduled_action( self::HOOK_TAXONOMY, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK_TAXONOMY, array(), self::GROUP );
		}
	}

	/**
	 * Stop the recurring jobs — when the merchant disconnects or turns sync off.
	 *
	 * @return void
	 */
	public function cancel_recurring(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK_POLL, array(), self::GROUP );
		as_unschedule_all_actions( self::HOOK_TAXONOMY, array(), self::GROUP );
	}

	/**
	 * Queue a product to be pushed.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @param int $attempt    Which attempt this is.
	 * @param int $delay      Seconds to wait.
	 * @return void
	 */
	public static function push( int $product_id, int $attempt = 1, int $delay = 0 ): void {
		self::enqueue( self::HOOK_PUSH, $product_id, $attempt, $delay );
	}

	/**
	 * Queue a listing to be removed.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @param int $attempt    Which attempt this is.
	 * @param int $delay      Seconds to wait.
	 * @return void
	 */
	public static function remove( int $product_id, int $attempt = 1, int $delay = 0 ): void {
		self::enqueue( self::HOOK_REMOVE, $product_id, $attempt, $delay );
	}

	/**
	 * Queue a listing to be restored.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @param int $attempt    Which attempt this is.
	 * @param int $delay      Seconds to wait.
	 * @return void
	 */
	public static function restore( int $product_id, int $attempt = 1, int $delay = 0 ): void {
		self::enqueue( self::HOOK_RESTORE, $product_id, $attempt, $delay );
	}

	/**
	 * Queue a listing to be withdrawn (the product went out of stock here).
	 *
	 * @param int $product_id WooCommerce product id.
	 * @param int $attempt    Which attempt this is.
	 * @param int $delay      Seconds to wait.
	 * @return void
	 */
	public static function withdraw( int $product_id, int $attempt = 1, int $delay = 0 ): void {
		self::enqueue( self::HOOK_WITHDRAW, $product_id, $attempt, $delay );
	}

	/**
	 * Queue a full catalogue sync, one batch at a time.
	 *
	 * @param int $offset Where to start.
	 * @return void
	 */
	public static function backfill( int $offset = 0 ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action( time() + 5, self::HOOK_BACKFILL, array( 'offset' => $offset ), self::GROUP );
	}

	/**
	 * Push one product.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @param int $attempt    Which attempt this is.
	 * @return void
	 */
	public function run_push( $product_id, $attempt = 1 ): void {
		$this->run(
			self::HOOK_PUSH,
			(int) $product_id,
			(int) $attempt,
			static function ( int $id ): void {
				( new Pusher() )->push( $id );
			}
		);
	}

	/**
	 * Remove one listing.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @param int $attempt    Which attempt this is.
	 * @return void
	 */
	public function run_remove( $product_id, $attempt = 1 ): void {
		$this->run(
			self::HOOK_REMOVE,
			(int) $product_id,
			(int) $attempt,
			static function ( int $id ): void {
				( new Pusher() )->remove( $id );
			}
		);
	}

	/**
	 * Restore one listing.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @param int $attempt    Which attempt this is.
	 * @return void
	 */
	public function run_restore( $product_id, $attempt = 1 ): void {
		$this->run(
			self::HOOK_RESTORE,
			(int) $product_id,
			(int) $attempt,
			static function ( int $id ): void {
				( new Pusher() )->restore( $id );
			}
		);
	}

	/**
	 * Withdraw one listing.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @param int $attempt    Which attempt this is.
	 * @return void
	 */
	public function run_withdraw( $product_id, $attempt = 1 ): void {
		$this->run(
			self::HOOK_WITHDRAW,
			(int) $product_id,
			(int) $attempt,
			static function ( int $id ): void {
				( new Pusher() )->withdraw( $id );
			}
		);
	}

	/**
	 * The hourly status poll.
	 *
	 * @return void
	 */
	public function run_poll(): void {
		if ( ! Settings::ready_to_sync() ) {
			return;
		}

		try {
			( new Puller() )->poll();
		} catch ( ApiException $e ) {
			// The next hourly run will try again; there is nothing to reschedule.
			Logger::warn( 'Status poll failed: ' . $e->getMessage() );
		}
	}

	/**
	 * The daily taxonomy refresh.
	 *
	 * @return void
	 */
	public function run_taxonomy(): void {
		if ( ! Settings::ready_to_sync() ) {
			return;
		}

		Repository::all( true );
	}

	/**
	 * One batch of a full catalogue sync.
	 *
	 * @param int $offset Where this batch starts.
	 * @return void
	 */
	public function run_backfill( $offset = 0 ): void {
		if ( ! Settings::ready_to_sync() ) {
			return;
		}

		$offset = max( 0, (int) $offset );
		$size   = max( 1, min( Settings::int( 'batch_size' ), 50 ) );

		$found = 0;

		try {
			// OFFSET pagination, ordered by the primary key. A deep offset is not free
			// — MySQL still walks the rows it skips — but ordering by ID keeps that a
			// PK-index scan rather than a filesort, which is cheap enough for a
			// background job. wc_get_products has no keyset ("ID > last") option, so
			// this is the honest tool.
			$ids = wc_get_products(
				array(
					'status'  => 'publish',
					'type'    => 'simple',
					'limit'   => $size,
					'offset'  => $offset,
					'return'  => 'ids',
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			if ( is_array( $ids ) ) {
				$found = count( $ids );

				foreach ( $ids as $id ) {
					self::push( (int) $id );
				}
			}
		} catch ( \Throwable $e ) {
			// A fatal in ONE batch — a third-party woocommerce_product_query filter,
			// an OOM on an odd product — must not strand the rest of the catalogue.
			// run_backfill is NOT wrapped by Queue::run(), so without this the chain
			// would die on the failed action and the remaining batches would simply
			// never be scheduled: thousands of products silently unsynced while the
			// settings screen still reads "syncing". Log it, and chain onward past the
			// bad batch rather than stopping dead.
			Logger::error( sprintf( 'Backfill batch at offset %d failed: %s', $offset, $e->getMessage() ) );

			$found = $size; // Assume a full batch so the chain advances past it.
		}

		if ( 0 === $found ) {
			Logger::info( 'Full catalogue sync finished.' );

			return;
		}

		// Chain the next batch. Each job stays short, so a big catalogue cannot time
		// out mid-way and leave the store half-synced.
		self::backfill( $offset + $size );
	}

	/**
	 * Run one job, with the rate limit and the retry policy around it.
	 *
	 * @param string   $hook       The hook to reschedule on failure.
	 * @param int      $product_id WooCommerce product id.
	 * @param int      $attempt    Which attempt this is.
	 * @param callable $work       The work to do.
	 * @return void
	 */
	private function run( string $hook, int $product_id, int $attempt, callable $work ): void {
		if ( $product_id <= 0 || ! Settings::ready_to_sync() ) {
			return;
		}

		// The Client takes a token for each request it makes, and throws a 429 with a
		// Retry-After when the bucket is dry — which reschedule() then honours. So
		// the limiter is enforced in exactly one place, and a job never blocks a PHP
		// worker on a sleep waiting for it.
		try {
			$work( $product_id );
		} catch ( ApiException $e ) {
			$this->reschedule( $hook, $product_id, $attempt, $e );
		} catch ( \Throwable $e ) {
			// A bug in our own code, not a network failure. Do not retry it forever.
			Logger::error( sprintf( 'Product #%d hit an unexpected error: %s', $product_id, $e->getMessage() ) );

			LinkMap::save(
				$product_id,
				array(
					'state'      => LinkMap::STATE_ERROR,
					'last_error' => __( 'An unexpected error stopped this product from syncing. See WooCommerce → Status → Logs.', 'agt-sync-for-woocommerce' ),
				)
			);
		}
	}

	/**
	 * Put a failed job back on the queue, backing off — or give up.
	 *
	 * @param string       $hook       The hook.
	 * @param int          $product_id WooCommerce product id.
	 * @param int          $attempt    Which attempt just failed.
	 * @param ApiException $e          The failure.
	 * @return void
	 */
	private function reschedule( string $hook, int $product_id, int $attempt, ApiException $e ): void {
		// Being held back by our OWN rate limiter is not a failure — the work has not
		// been tried yet. Re-queue it at the same attempt number, or a busy catalogue
		// would burn through its retries on the throttle and give up on products that
		// were never actually rejected.
		if ( 'local_rate_limit' === $e->error_code() ) {
			self::enqueue( $hook, $product_id, $attempt, max( 1, $e->retry_after() ) );

			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			Logger::error(
				sprintf( 'Product #%d failed %d times; giving up. Last error: %s', $product_id, $attempt, $e->getMessage() )
			);

			LinkMap::save(
				$product_id,
				array(
					'state'      => LinkMap::STATE_ERROR,
					'last_error' => $e->merchant_message(),
				)
			);

			return;
		}

		// Honour the server's own Retry-After when it gave us one; it knows better
		// than our schedule does.
		$delay = $e->retry_after() > 0
			? $e->retry_after()
			: self::BACKOFF[ min( $attempt - 1, count( self::BACKOFF ) - 1 ) ];

		// A little jitter, so a hundred products that all failed on the same outage
		// do not all come back at the same instant and cause another one.
		$delay += wp_rand( 0, 30 );

		self::enqueue( $hook, $product_id, $attempt + 1, $delay );
	}

	/**
	 * Schedule one job.
	 *
	 * @param string $hook       The hook.
	 * @param int    $product_id WooCommerce product id.
	 * @param int    $attempt    Which attempt this is.
	 * @param int    $delay      Seconds to wait.
	 * @return void
	 */
	private static function enqueue( string $hook, int $product_id, int $attempt, int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$args = array(
			'product_id' => $product_id,
			'attempt'    => $attempt,
		);

		// Do not queue the same work twice — a merchant hammering Save would
		// otherwise pile up a dozen identical jobs for one product.
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			return;
		}

		as_schedule_single_action( time() + max( 0, $delay ), $hook, $args, self::GROUP );
	}
}
