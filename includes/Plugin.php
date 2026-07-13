<?php
/**
 * Plugin bootstrap.
 *
 * @package AgtSync
 */

namespace AgtSync;

use AgtSync\Admin\Notices;
use AgtSync\Admin\ProductMetaBox;
use AgtSync\Admin\SettingsPage;
use AgtSync\Lifecycle;
use AgtSync\Sync\LinkMap;
use AgtSync\Sync\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Wires everything together.
 */
final class Plugin {

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The background queue.
	 *
	 * @var Queue|null
	 */
	private ?Queue $queue = null;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * The singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register all hooks. Called once WooCommerce is confirmed active.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->queue = new Queue();
		$this->queue->register();

		// Heal on a normal admin request: the table is created on activation, but a
		// plugin updated by copying files, a manual DB edit, or a schema bump can
		// leave it missing or stale. Lifecycle::maybe_heal() is the fast-path check
		// that quietly repairs it before the merchant ever touches a broken screen.
		add_action( 'admin_init', array( Lifecycle::class, 'maybe_heal' ) );

		if ( is_admin() ) {
			( new SettingsPage() )->register();
			( new ProductMetaBox() )->register();
			( new Notices() )->register();
		}

		add_filter( 'plugin_action_links_' . plugin_basename( AGT_SYNC_FILE ), array( $this, 'plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

		$this->register_product_hooks();
	}

	/**
	 * Watch the product lifecycle.
	 *
	 * Every one of these only ever QUEUES work — nothing here talks to American
	 * Gun Trader in the request that saved the product, so a merchant never waits
	 * on a network call and a slow API can never make their admin feel broken.
	 *
	 * @return void
	 */
	private function register_product_hooks(): void {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ), 20, 1 );

		// Trash a product -> the listing goes. Restore it -> the listing comes back.
		// Deletion is a soft delete on AGT, which is what makes the round trip work.
		add_action( 'wp_trash_post', array( $this, 'on_product_trashed' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_product_untrashed' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_product_deleted' ), 10, 1 );

		// Sold here -> withdraw it there, so the same gun is not offered twice.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 2 );
	}

	/**
	 * A product was created or updated.
	 *
	 * @param int $product_id WooCommerce product id.
	 * @return void
	 */
	public function on_product_saved( $product_id ): void {
		if ( ! Settings::ready_to_sync() ) {
			return;
		}

		Queue::push( (int) $product_id );
	}

	/**
	 * A product was trashed.
	 *
	 * @param int $post_id The post id.
	 * @return void
	 */
	public function on_product_trashed( $post_id ): void {
		if ( ! $this->is_product( (int) $post_id ) || ! Settings::ready_to_sync() ) {
			return;
		}

		Queue::remove( (int) $post_id );
	}

	/**
	 * A product was restored from the trash.
	 *
	 * @param int $post_id The post id.
	 * @return void
	 */
	public function on_product_untrashed( $post_id ): void {
		if ( ! $this->is_product( (int) $post_id ) || ! Settings::ready_to_sync() ) {
			return;
		}

		Queue::restore( (int) $post_id );
	}

	/**
	 * A product is about to be deleted for good.
	 *
	 * @param int $post_id The post id.
	 * @return void
	 */
	public function on_product_deleted( $post_id ): void {
		$post_id = (int) $post_id;

		if ( ! $this->is_product( $post_id ) ) {
			return;
		}

		if ( Settings::ready_to_sync() ) {
			Queue::remove( $post_id );
		}
	}

	/**
	 * A product's stock status changed.
	 *
	 * @param int    $product_id WooCommerce product id.
	 * @param string $status     The new stock status.
	 * @return void
	 */
	public function on_stock_status( $product_id, $status = '' ): void {
		if ( ! Settings::ready_to_sync() || ! Settings::bool( 'mark_sold_on_oos' ) ) {
			return;
		}

		$product_id = (int) $product_id;

		if ( 'outofstock' === $status ) {
			// It sold somewhere else. Stop offering it on American Gun Trader.
			//
			// Except when AGT is where it sold: the poller sets the product out of
			// stock itself, and withdrawing the listing in response would be a loop
			// chasing its own tail.
			$link = LinkMap::get( $product_id );

			if ( is_array( $link ) && LinkMap::STATE_SOLD === (string) ( $link['state'] ?? '' ) ) {
				return;
			}

			Queue::withdraw( $product_id );

			return;
		}

		if ( 'instock' === $status ) {
			$link  = LinkMap::get( $product_id );
			$state = is_array( $link ) ? (string) ( $link['state'] ?? '' ) : '';

			// Restocked after we withdrew its listing (it had gone out of stock here).
			// Bring the same listing back.
			if ( LinkMap::STATE_DELETED === $state ) {
				Queue::restore( $product_id );

				return;
			}

			// Restocked after it SOLD on American Gun Trader. That old listing is
			// genuinely done — a buyer bought it there — so it must not be reused. But
			// the merchant clearly has another unit, so publish a FRESH listing rather
			// than leaving the product permanently stuck as "sold" with no way back.
			// Forgetting the link makes the next push a clean create.
			if ( LinkMap::STATE_SOLD === $state ) {
				LinkMap::forget( $product_id );
				Queue::push( $product_id );
			}
		}
	}

	/**
	 * Is this post a product?
	 *
	 * @param int $post_id The post id.
	 * @return bool
	 */
	private function is_product( int $post_id ): bool {
		return 'product' === get_post_type( $post_id );
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array<int,string> $links Existing action links.
	 * @return array<int,string>
	 */
	public function plugin_action_links( array $links ): array {
		$settings_url = admin_url( 'admin.php?page=agt-sync' );

		$plugin_links = array(
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'agt-sync-for-woocommerce' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Add resource links to the plugin's row on the Plugins screen.
	 *
	 * @param array<int,string> $links Existing row meta links.
	 * @param string            $file  Plugin file being rendered.
	 * @return array<int,string>
	 */
	public function plugin_row_meta( array $links, string $file ): array {
		if ( plugin_basename( AGT_SYNC_FILE ) !== $file ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=agt-sync&tab=how-to' ) ) . '">'
			. esc_html__( 'How it works', 'agt-sync-for-woocommerce' ) . '</a>';
		$links[] = '<a href="' . esc_url( 'https://github.com/shadow-software/agt-sync-for-woocommerce#readme' ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Documentation', 'agt-sync-for-woocommerce' ) . '</a>';
		$links[] = '<a href="' . esc_url( 'https://shadowsoftware.com/contact' ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Support', 'agt-sync-for-woocommerce' ) . '</a>';

		return $links;
	}
}
