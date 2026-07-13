<?php
/**
 * The AGT Sync box on a product.
 *
 * @package AgtSync
 */

namespace AgtSync\Admin;

use AgtSync\Settings;
use AgtSync\Sync\LinkMap;
use AgtSync\Sync\Mapper;
use AgtSync\Sync\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Where a merchant finds out what happened to this product.
 *
 * A sync that fails silently is worse than one that does not run: the merchant
 * believes their inventory is listed when it is not. So every product says what
 * its listing is doing, and — when it will not publish — exactly what to fix.
 */
final class ProductMetaBox {

	/**
	 * The per-product opt-in/out meta key.
	 */
	private const META_ENABLED = '_agt_sync_enabled';

	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 1 );
	}

	/**
	 * Add the box.
	 *
	 * @return void
	 */
	public function add_box(): void {
		add_meta_box(
			'agt-sync-box',
			__( 'American Gun Trader', 'agt-sync-for-woocommerce' ),
			array( $this, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post The product post.
	 * @return void
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		wp_nonce_field( 'agt_sync_product', 'agt_sync_product_nonce' );

		if ( ! Settings::ready_to_sync() ) {
			echo '<p>';
			printf(
				/* translators: %s: link to the AGT Sync settings screen. */
				esc_html__( 'AGT Sync is not set up yet. %s', 'agt-sync-for-woocommerce' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '">'
					. esc_html__( 'Finish setting it up', 'agt-sync-for-woocommerce' ) . '</a>'
			);
			echo '</p>';

			return;
		}

		// A variable product is never published, and the merchant deserves to be told
		// so on the product itself rather than wondering why nothing happened.
		$skip_reason = Mapper::skip_reason( $product );

		if ( '' !== $skip_reason ) {
			echo '<p class="agt-sync-status agt-sync-status--skipped">' . esc_html( $skip_reason ) . '</p>';

			return;
		}

		$this->render_opt_in( $product );
		$this->render_state( $post->ID );
		$this->render_problems( $product, $post->ID );
	}

	/**
	 * The per-product toggle.
	 *
	 * @param \WC_Product $product The product.
	 * @return void
	 */
	private function render_opt_in( \WC_Product $product ): void {
		$meta = (string) $product->get_meta( self::META_ENABLED );

		// In opt-in mode the box is the switch. In "all" mode it is an opt-OUT.
		$opt_in = 'opt_in' === Settings::str( 'sync_mode' );

		$checked = $opt_in ? ( '1' === $meta ) : ( '0' !== $meta );

		echo '<p><label>';
		printf(
			'<input type="checkbox" name="agt_sync_enabled" value="1" %s> ',
			checked( $checked, true, false )
		);
		echo esc_html__( 'Publish this product to American Gun Trader', 'agt-sync-for-woocommerce' );
		echo '</label></p>';
	}

	/**
	 * The listing's current state.
	 *
	 * @param int $product_id The product id.
	 * @return void
	 */
	private function render_state( int $product_id ): void {
		$link = LinkMap::get( $product_id );

		if ( ! is_array( $link ) ) {
			echo '<p class="agt-sync-status">' . esc_html__( 'Not published yet.', 'agt-sync-for-woocommerce' ) . '</p>';

			return;
		}

		$state = (string) ( $link['state'] ?? '' );

		$labels = array(
			LinkMap::STATE_LIVE       => __( 'Live on American Gun Trader', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_MODERATION => __( 'Waiting to be reviewed', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_REJECTED   => __( 'Rejected by a moderator', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_SOLD       => __( 'Sold on American Gun Trader', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_DELETED    => __( 'Removed from American Gun Trader', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_ERROR      => __( 'Needs attention', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_SKIPPED    => __( 'Not published', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_PENDING    => __( 'Queued', 'agt-sync-for-woocommerce' ),
		);

		$label = isset( $labels[ $state ] ) ? $labels[ $state ] : __( 'Unknown', 'agt-sync-for-woocommerce' );

		printf(
			'<p class="agt-sync-status agt-sync-status--%s"><strong>%s</strong></p>',
			esc_attr( $state ),
			esc_html( $label )
		);

		if ( LinkMap::STATE_SOLD === $state ) {
			echo '<p class="description">' . esc_html__( 'This product was set out of stock automatically, so you do not sell it twice.', 'agt-sync-for-woocommerce' ) . '</p>';
		}

		$url = (string) ( $link['listing_url'] ?? '' );

		if ( '' !== $url ) {
			echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'View the listing', 'agt-sync-for-woocommerce' ) . '</a></p>';
		}

		$views = (int) ( $link['views'] ?? 0 );
		$bids  = (int) ( $link['bid_count'] ?? 0 );

		if ( $views > 0 || $bids > 0 ) {
			echo '<p class="description">';
			printf(
				/* translators: 1: number of views, 2: number of offers. */
				esc_html__( '%1$d views · %2$d offers', 'agt-sync-for-woocommerce' ),
				(int) $views,
				(int) $bids
			);
			echo '</p>';
		}
	}

	/**
	 * Anything stopping this product from publishing.
	 *
	 * Checked live, not just read from the last error, so a merchant fixing a
	 * description sees the problem disappear as soon as they save.
	 *
	 * @param \WC_Product $product    The product.
	 * @param int         $product_id The product id.
	 * @return void
	 */
	private function render_problems( \WC_Product $product, int $product_id ): void {
		$mapped = Mapper::to_listing( $product );

		if ( $mapped['ok'] ) {
			$link = LinkMap::get( $product_id );

			// Nothing wrong with the product, but the last push still failed — a
			// network problem, or something on the American Gun Trader side.
			if ( is_array( $link ) && LinkMap::STATE_ERROR === (string) ( $link['state'] ?? '' ) && ! empty( $link['last_error'] ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $link['last_error'] ) . '</p></div>';
			}

			return;
		}

		echo '<div class="notice notice-warning inline"><p><strong>'
			. esc_html__( 'This product will not publish yet:', 'agt-sync-for-woocommerce' )
			. '</strong></p><ul class="agt-sync-problems">';

		foreach ( $mapped['errors'] as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}

		echo '</ul></div>';
	}

	/**
	 * Save the per-product toggle.
	 *
	 * @param int $post_id The product id.
	 * @return void
	 */
	public function save( $post_id ): void {
		$post_id = (int) $post_id;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['agt_sync_product_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['agt_sync_product_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'agt_sync_product' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$enabled = isset( $_POST['agt_sync_enabled'] ) ? '1' : '0';

		$product->update_meta_data( self::META_ENABLED, $enabled );
		$product->save_meta_data();

		if ( ! Settings::ready_to_sync() ) {
			return;
		}

		// Turning it off should take the listing down, not merely stop updating it —
		// otherwise the product sits on American Gun Trader forever, unmanaged.
		if ( '0' === $enabled ) {
			if ( '' !== LinkMap::listing_id( $post_id ) ) {
				Queue::remove( $post_id );
			}

			return;
		}

		// Turning it back on has to actively put the listing BACK. Saving the product
		// also fires woocommerce_update_product -> Queue::push(), but that alone is
		// not enough: if nothing else about the product changed, its payload hash
		// still matches the one we stored before it was taken down, and the push
		// would see "already in sync" against a listing that is not actually up.
		// Queue the restore explicitly so re-ticking the box always means something.
		if ( '' !== LinkMap::listing_id( $post_id ) ) {
			Queue::restore( $post_id );
		}
	}
}
