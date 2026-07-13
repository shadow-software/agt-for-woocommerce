<?php
/**
 * The settings screen.
 *
 * @package AgtSync
 */

namespace AgtSync\Admin;

use AgtSync\Api\ApiException;
use AgtSync\Api\RateLimit;
use AgtSync\Auth\Credentials;
use AgtSync\Auth\OAuthClient;
use AgtSync\Logger;
use AgtSync\Settings;
use AgtSync\Sync\LinkMap;
use AgtSync\Sync\Mapper;
use AgtSync\Sync\Queue;
use AgtSync\Taxonomy\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Connect, map, configure.
 */
final class SettingsPage {

	/**
	 * The menu slug.
	 */
	public const SLUG = 'agt-sync';

	/**
	 * The capability required to touch any of this.
	 */
	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Register the screen.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_agt_sync_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_agt_sync_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_agt_sync_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_agt_sync_sync_all', array( $this, 'handle_sync_all' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_callback' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the menu entry, under WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'AGT Sync', 'agt-sync-for-woocommerce' ),
			__( 'AGT Sync', 'agt-sync-for-woocommerce' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Load the stylesheet, on our screen only.
	 *
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'agt-sync-admin',
			AGT_SYNC_URL . 'assets/css/admin.css',
			array(),
			AGT_SYNC_VERSION
		);
	}

	/**
	 * The OAuth callback comes back to this screen. Catch it before anything is
	 * rendered so we can redirect cleanly.
	 *
	 * @return void
	 */
	public function maybe_handle_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- This is an OAuth callback from an external site; it cannot carry our nonce. It is authenticated by the `state` parameter, which is checked below.
		if ( ! isset( $_GET['page'], $_GET['agt_oauth'] ) ) {
			return;
		}

		if ( self::SLUG !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( 'callback' !== sanitize_text_field( wp_unslash( $_GET['agt_oauth'] ) ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'agt-sync-for-woocommerce' ) );
		}

		// The dealer declined on American Gun Trader.
		if ( isset( $_GET['error'] ) ) {
			$this->redirect_with( 'error', __( 'The connection was cancelled.', 'agt-sync-for-woocommerce' ) );
		}

		if ( ! isset( $_GET['code'], $_GET['state'] ) ) {
			$this->redirect_with( 'error', __( 'American Gun Trader did not send back a valid response.', 'agt-sync-for-woocommerce' ) );
		}

		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		try {
			// complete() checks `state` against the one this store generated, which is
			// what proves the callback belongs to a flow we started.
			OAuthClient::complete( $code, $state );

			// Warm the taxonomy so the category mapping table has something in it the
			// moment the merchant lands back here.
			Repository::all( true );

			$this->redirect_with( 'connected', '' );
		} catch ( ApiException $e ) {
			Logger::error( 'Connect failed: ' . $e->getMessage() );

			$this->redirect_with( 'error', $e->getMessage() );
		}
	}

	/**
	 * Start the connect flow.
	 *
	 * @return void
	 */
	public function handle_connect(): void {
		$this->guard( 'agt_sync_connect' );

		try {
			$url = OAuthClient::authorize_url();
		} catch ( ApiException $e ) {
			Logger::error( 'Could not start the connect flow: ' . $e->getMessage() );

			// redirect_with() exits.
			$this->redirect_with( 'error', $e->getMessage() );
		}

		// Off to American Gun Trader to approve it.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Deliberately leaving the site: this is the OAuth authorization endpoint.
		exit;
	}

	/**
	 * Disconnect the store.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		$this->guard( 'agt_sync_disconnect' );

		OAuthClient::disconnect();

		Settings::update( array( 'enabled' => false ) );

		( new Queue() )->cancel_recurring();

		$this->redirect_with( 'disconnected', '' );
	}

	/**
	 * Save the settings.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->guard( 'agt_sync_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() verified the nonce.
		$changes = array();

		$changes['enabled']          = isset( $_POST['enabled'] );
		$changes['mark_sold_on_oos'] = isset( $_POST['mark_sold_on_oos'] );

		$sync_mode            = isset( $_POST['sync_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['sync_mode'] ) ) : 'all';
		$changes['sync_mode'] = in_array( $sync_mode, array( 'all', 'opt_in' ), true ) ? $sync_mode : 'all';

		$price_source            = isset( $_POST['price_source'] ) ? sanitize_text_field( wp_unslash( $_POST['price_source'] ) ) : 'current';
		$changes['price_source'] = in_array( $price_source, array( 'current', 'regular' ), true ) ? $price_source : 'current';

		$delete_behavior            = isset( $_POST['delete_behavior'] ) ? sanitize_text_field( wp_unslash( $_POST['delete_behavior'] ) ) : 'delete';
		$changes['delete_behavior'] = in_array( $delete_behavior, array( 'delete', 'unlink' ), true ) ? $delete_behavior : 'delete';

		$condition = isset( $_POST['default_condition'] ) ? (int) $_POST['default_condition'] : 0;

		if ( in_array( $condition, array( 1, 2, 3, 4 ), true ) ) {
			$changes['default_condition'] = $condition;
			// The merchant has now made a deliberate choice, so we may fall back to it.
			$changes['condition_confirmed'] = true;
		}

		$rate                          = isset( $_POST['rate_limit_per_min'] ) ? (int) $_POST['rate_limit_per_min'] : 60;
		$changes['rate_limit_per_min'] = max( 1, min( $rate, 120 ) );

		$batch                 = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 20;
		$changes['batch_size'] = max( 1, min( $batch, 50 ) );

		foreach ( array( 'attr_condition', 'attr_manufacturer', 'attr_caliber' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$changes[ $key ] = sanitize_key( wp_unslash( $_POST[ $key ] ) );
			}
		}

		// The category map: WooCommerce term id => AGT category id.
		$map = array();

		if ( isset( $_POST['category_map'] ) && is_array( $_POST['category_map'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Both key and value are cast to int below.
			foreach ( wp_unslash( $_POST['category_map'] ) as $term_id => $agt_id ) {
				$term_id = (int) $term_id;
				$agt_id  = (int) $agt_id;

				if ( $term_id > 0 && $agt_id > 0 ) {
					$map[ $term_id ] = $agt_id;
				}
			}
		}

		$changes['category_map'] = $map;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Settings::update( $changes );

		RateLimit::reset();

		( new Queue() )->ensure_recurring();

		$this->redirect_with( 'saved', '' );
	}

	/**
	 * Queue a full catalogue sync.
	 *
	 * @return void
	 */
	public function handle_sync_all(): void {
		$this->guard( 'agt_sync_sync_all' );

		if ( ! Settings::ready_to_sync() ) {
			$this->redirect_with( 'error', __( 'Finish connecting and configuring the plugin first.', 'agt-sync-for-woocommerce' ) );
		}

		Queue::backfill( 0 );

		$this->redirect_with( 'syncing', '' );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		echo '<div class="wrap agt-sync">';
		echo '<h1>' . esc_html__( 'AGT Sync for WooCommerce', 'agt-sync-for-woocommerce' ) . '</h1>';

		$this->render_notice();
		$this->render_tabs( $tab );

		if ( 'how-to' === $tab ) {
			( new HowToPage() )->render();
		} else {
			$this->render_settings();
		}

		echo '</div>';
	}

	/**
	 * The tab strip.
	 *
	 * @param string $current The active tab.
	 * @return void
	 */
	private function render_tabs( string $current ): void {
		$tabs = array(
			'settings' => __( 'Settings', 'agt-sync-for-woocommerce' ),
			'how-to'   => __( 'How it works', 'agt-sync-for-woocommerce' ),
		);

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ),
				esc_attr( $current === $slug ? 'nav-tab-active' : '' ),
				esc_html( $label )
			);
		}

		echo '</h2>';
	}

	/**
	 * The settings tab.
	 *
	 * @return void
	 */
	private function render_settings(): void {
		$connected = Credentials::is_connected();

		$this->render_connection( $connected );

		if ( ! $connected ) {
			return;
		}

		$this->render_status();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'agt_sync_save' );
		echo '<input type="hidden" name="action" value="agt_sync_save">';

		$this->render_general();
		$this->render_condition();
		$this->render_category_map();
		$this->render_advanced();

		submit_button( __( 'Save settings', 'agt-sync-for-woocommerce' ) );

		echo '</form>';

		$this->render_sync_all();
	}

	/**
	 * The connect / disconnect card.
	 *
	 * @param bool $connected Whether the store is connected.
	 * @return void
	 */
	private function render_connection( bool $connected ): void {
		echo '<div class="agt-sync-card">';

		if ( ! $connected ) {
			echo '<h2>' . esc_html__( 'Connect your store', 'agt-sync-for-woocommerce' ) . '</h2>';
			echo '<p>' . esc_html__( 'AGT Sync publishes your products to American Gun Trader. You need a free American Gun Trader account with an approved FFL and an active dealer subscription.', 'agt-sync-for-woocommerce' ) . '</p>';
			echo '<p>' . esc_html__( 'There is nothing to copy and paste. Click Connect, approve it on American Gun Trader, and you are done.', 'agt-sync-for-woocommerce' ) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'agt_sync_connect' );
			echo '<input type="hidden" name="action" value="agt_sync_connect">';
			echo '<button type="submit" class="button button-primary button-hero">'
				. esc_html__( 'Connect to American Gun Trader', 'agt-sync-for-woocommerce' )
				. '</button>';
			echo '</form>';
			echo '</div>';

			return;
		}

		$account = Credentials::account();
		$name    = isset( $account['business_name'] ) && '' !== (string) $account['business_name']
			? (string) $account['business_name']
			: (string) ( $account['username'] ?? '' );

		echo '<h2>' . esc_html__( 'Connected', 'agt-sync-for-woocommerce' ) . '</h2>';

		echo '<p>';
		printf(
			/* translators: %s: the dealer's business name on American Gun Trader. */
			esc_html__( 'This store is connected to the American Gun Trader account %s.', 'agt-sync-for-woocommerce' ),
			'<strong>' . esc_html( $name ) . '</strong>'
		);
		echo '</p>';

		// If the account cannot publish, say so loudly and link to the fix. Otherwise
		// the merchant turns sync on and watches every product fail for no visible
		// reason.
		if ( ! Credentials::can_publish() ) {
			$blockers = isset( $account['blockers'] ) && is_array( $account['blockers'] ) ? $account['blockers'] : array();

			echo '<div class="notice notice-error inline"><p><strong>'
				. esc_html__( 'This account cannot publish listings yet.', 'agt-sync-for-woocommerce' )
				. '</strong></p>';

			foreach ( $blockers as $blocker ) {
				if ( ! is_array( $blocker ) ) {
					continue;
				}

				echo '<p>' . esc_html( (string) ( $blocker['message'] ?? '' ) );

				if ( ! empty( $blocker['fix_url'] ) ) {
					echo ' <a href="' . esc_url( (string) $blocker['fix_url'] ) . '" target="_blank" rel="noopener noreferrer">'
						. esc_html__( 'Fix this on American Gun Trader', 'agt-sync-for-woocommerce' ) . '</a>';
				}

				echo '</p>';
			}

			echo '</div>';
		} elseif ( ! empty( $account['listings_publish_immediately'] ) ) {
			echo '<p class="agt-sync-good">'
				. esc_html__( 'Your listings publish straight away — no review queue.', 'agt-sync-for-woocommerce' )
				. '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'agt_sync_disconnect' );
		echo '<input type="hidden" name="action" value="agt_sync_disconnect">';
		echo '<button type="submit" class="button">' . esc_html__( 'Disconnect', 'agt-sync-for-woocommerce' ) . '</button>';
		echo ' <span class="description">' . esc_html__( 'Your listings stay on American Gun Trader.', 'agt-sync-for-woocommerce' ) . '</span>';
		echo '</form>';

		echo '</div>';
	}

	/**
	 * The counts.
	 *
	 * @return void
	 */
	private function render_status(): void {
		$counts = LinkMap::counts();

		if ( empty( $counts ) ) {
			return;
		}

		$labels = array(
			LinkMap::STATE_LIVE       => __( 'Live', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_MODERATION => __( 'In review', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_SOLD       => __( 'Sold on AGT', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_DELETED    => __( 'Removed', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_ERROR      => __( 'Needs attention', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_SKIPPED    => __( 'Skipped', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_PENDING    => __( 'Queued', 'agt-sync-for-woocommerce' ),
			LinkMap::STATE_REJECTED   => __( 'Rejected', 'agt-sync-for-woocommerce' ),
		);

		echo '<div class="agt-sync-card"><h2>' . esc_html__( 'Your listings', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<ul class="agt-sync-counts">';

		foreach ( $labels as $state => $label ) {
			if ( empty( $counts[ $state ] ) ) {
				continue;
			}

			printf(
				'<li><strong>%d</strong> %s</li>',
				(int) $counts[ $state ],
				esc_html( $label )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * General settings.
	 *
	 * @return void
	 */
	private function render_general(): void {
		echo '<div class="agt-sync-card"><h2>' . esc_html__( 'Syncing', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->checkbox_row(
			'enabled',
			__( 'Sync my products to American Gun Trader', 'agt-sync-for-woocommerce' ),
			Settings::bool( 'enabled' ),
			__( 'Nothing is published until you switch this on.', 'agt-sync-for-woocommerce' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Which products', 'agt-sync-for-woocommerce' ) . '</th><td>';
		$this->radio( 'sync_mode', 'all', __( 'All eligible products', 'agt-sync-for-woocommerce' ), Settings::str( 'sync_mode' ) );
		$this->radio( 'sync_mode', 'opt_in', __( 'Only products I tick individually', 'agt-sync-for-woocommerce' ), Settings::str( 'sync_mode' ) );
		echo '<p class="description">' . esc_html__( 'Variable products are never synced — each variation would need its own listing.', 'agt-sync-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Price to publish', 'agt-sync-for-woocommerce' ) . '</th><td>';
		$this->radio( 'price_source', 'current', __( 'The price a buyer pays today, including any sale', 'agt-sync-for-woocommerce' ), Settings::str( 'price_source' ) );
		$this->radio( 'price_source', 'regular', __( 'Always the regular price', 'agt-sync-for-woocommerce' ), Settings::str( 'price_source' ) );
		echo '</td></tr>';

		$this->checkbox_row(
			'mark_sold_on_oos',
			__( 'Remove the listing when a product goes out of stock here', 'agt-sync-for-woocommerce' ),
			Settings::bool( 'mark_sold_on_oos' ),
			__( 'So a gun you sold in your store stops being offered on American Gun Trader. Restocking it brings the listing back.', 'agt-sync-for-woocommerce' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'When I delete a product', 'agt-sync-for-woocommerce' ) . '</th><td>';
		$this->radio( 'delete_behavior', 'delete', __( 'Remove its American Gun Trader listing (restoring the product brings it back)', 'agt-sync-for-woocommerce' ), Settings::str( 'delete_behavior' ) );
		$this->radio( 'delete_behavior', 'unlink', __( 'Leave the listing alone; I will remove it myself', 'agt-sync-for-woocommerce' ), Settings::str( 'delete_behavior' ) );
		echo '</td></tr>';

		echo '</tbody></table></div>';
	}

	/**
	 * The condition mapping — deliberately its own section, because getting it
	 * wrong means advertising a used firearm as new.
	 *
	 * @return void
	 */
	private function render_condition(): void {
		$conditions = Repository::conditions();

		if ( empty( $conditions ) ) {
			$conditions = array(
				Mapper::CONDITION_NEW      => __( 'New', 'agt-sync-for-woocommerce' ),
				Mapper::CONDITION_LIKE_NEW => __( 'Like New', 'agt-sync-for-woocommerce' ),
				Mapper::CONDITION_USED     => __( 'Used', 'agt-sync-for-woocommerce' ),
				Mapper::CONDITION_DAMAGED  => __( 'Damaged', 'agt-sync-for-woocommerce' ),
			);
		}

		$confirmed = Settings::bool( 'condition_confirmed' );

		echo '<div class="agt-sync-card"><h2>' . esc_html__( 'Condition', 'agt-sync-for-woocommerce' ) . '</h2>';

		if ( ! $confirmed ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Choose a default condition before syncing. Nothing publishes until you do — listing a used firearm as new is not something this plugin will guess at.', 'agt-sync-for-woocommerce' )
				. '</p></div>';
		}

		echo '<p>';
		printf(
			/* translators: %s: the product attribute name, e.g. pa_condition. */
			esc_html__( 'If a product has a %s attribute, its value is used. Otherwise the default below applies.', 'agt-sync-for-woocommerce' ),
			'<code>' . esc_html( Settings::str( 'attr_condition' ) ) . '</code>'
		);
		echo '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="agt-default-condition">' . esc_html__( 'Default condition', 'agt-sync-for-woocommerce' ) . '</label></th><td>';
		echo '<select name="default_condition" id="agt-default-condition" required>';
		echo '<option value="">' . esc_html__( '— Choose —', 'agt-sync-for-woocommerce' ) . '</option>';

		foreach ( $conditions as $id => $label ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $id,
				selected( Settings::int( 'default_condition' ), (int) $id, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Most dealers sell new inventory, so "New" is the usual choice — but pick deliberately.', 'agt-sync-for-woocommerce' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table></div>';
	}

	/**
	 * The category mapping table.
	 *
	 * @return void
	 */
	private function render_category_map(): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$options = Repository::category_options();
		$map     = Settings::category_map();

		echo '<div class="agt-sync-card"><h2>' . esc_html__( 'Category mapping', 'agt-sync-for-woocommerce' ) . '</h2>';

		if ( empty( $options ) ) {
			echo '<p>' . esc_html__( 'The American Gun Trader category list could not be loaded. Try again in a moment.', 'agt-sync-for-woocommerce' ) . '</p></div>';

			return;
		}

		echo '<p>' . esc_html__( 'A product will not publish until its category is mapped. Mapping a parent category covers everything beneath it.', 'agt-sync-for-woocommerce' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'WooCommerce category', 'agt-sync-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'American Gun Trader category', 'agt-sync-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$selected = isset( $map[ $term->term_id ] ) ? (int) $map[ $term->term_id ] : 0;

			echo '<tr><td>' . esc_html( $term->name ) . '</td><td>';
			printf( '<select name="category_map[%d]">', (int) $term->term_id );
			echo '<option value="0">' . esc_html__( '— Do not publish —', 'agt-sync-for-woocommerce' ) . '</option>';

			foreach ( $options as $id => $label ) {
				printf(
					'<option value="%d" %s>%s</option>',
					(int) $id,
					selected( $selected, (int) $id, false ),
					esc_html( $label )
				);
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Advanced settings.
	 *
	 * @return void
	 */
	private function render_advanced(): void {
		echo '<div class="agt-sync-card"><h2>' . esc_html__( 'Advanced', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$this->text_row( 'attr_manufacturer', __( 'Manufacturer attribute', 'agt-sync-for-woocommerce' ), Settings::str( 'attr_manufacturer' ) );
		$this->text_row( 'attr_caliber', __( 'Caliber attribute', 'agt-sync-for-woocommerce' ), Settings::str( 'attr_caliber' ) );
		$this->text_row( 'attr_condition', __( 'Condition attribute', 'agt-sync-for-woocommerce' ), Settings::str( 'attr_condition' ) );

		echo '<tr><th scope="row"><label for="agt-rate">' . esc_html__( 'Requests per minute', 'agt-sync-for-woocommerce' ) . '</label></th><td>';
		printf(
			'<input type="number" id="agt-rate" name="rate_limit_per_min" value="%d" min="1" max="120" class="small-text">',
			esc_attr( (string) Settings::int( 'rate_limit_per_min' ) )
		);
		echo '<p class="description">' . esc_html__( 'How fast this store is allowed to talk to American Gun Trader. Lower it if your host is small.', 'agt-sync-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="agt-batch">' . esc_html__( 'Products per batch', 'agt-sync-for-woocommerce' ) . '</label></th><td>';
		printf(
			'<input type="number" id="agt-batch" name="batch_size" value="%d" min="1" max="50" class="small-text">',
			esc_attr( (string) Settings::int( 'batch_size' ) )
		);
		echo '</td></tr>';

		echo '</tbody></table></div>';
	}

	/**
	 * The "sync everything now" button.
	 *
	 * @return void
	 */
	private function render_sync_all(): void {
		if ( ! Settings::ready_to_sync() ) {
			return;
		}

		echo '<div class="agt-sync-card"><h2>' . esc_html__( 'Sync everything now', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Queue every eligible product. This runs in the background, in small batches, and will not slow your store down.', 'agt-sync-for-woocommerce' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'agt_sync_sync_all' );
		echo '<input type="hidden" name="action" value="agt_sync_sync_all">';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Sync all products', 'agt-sync-for-woocommerce' ) . '</button>';
		echo '</form></div>';
	}

	/**
	 * Show the result of the last action.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of the result of a nonce-checked action.
		if ( ! isset( $_GET['agt_result'] ) ) {
			return;
		}

		$result  = sanitize_key( wp_unslash( $_GET['agt_result'] ) );
		$message = isset( $_GET['agt_message'] ) ? sanitize_text_field( wp_unslash( $_GET['agt_message'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'connected'    => array( 'success', __( 'Your store is connected to American Gun Trader.', 'agt-sync-for-woocommerce' ) ),
			'disconnected' => array( 'success', __( 'Your store has been disconnected. Your listings are still on American Gun Trader.', 'agt-sync-for-woocommerce' ) ),
			'saved'        => array( 'success', __( 'Settings saved.', 'agt-sync-for-woocommerce' ) ),
			'syncing'      => array( 'success', __( 'Your products are being synced in the background.', 'agt-sync-for-woocommerce' ) ),
		);

		if ( 'error' === $result ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( '' !== $message ? $message : __( 'Something went wrong.', 'agt-sync-for-woocommerce' ) )
			);

			return;
		}

		if ( isset( $messages[ $result ] ) ) {
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				esc_attr( $messages[ $result ][0] ),
				esc_html( $messages[ $result ][1] )
			);
		}
	}

	/**
	 * A checkbox row.
	 *
	 * @param string $name        The field name.
	 * @param string $label       The label.
	 * @param bool   $checked     Whether it is on.
	 * @param string $description Help text.
	 * @return void
	 */
	private function checkbox_row( string $name, string $label, bool $checked, string $description = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html__( 'Enabled', 'agt-sync-for-woocommerce' )
		);

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * A radio option.
	 *
	 * @param string $name    The field name.
	 * @param string $value   This option's value.
	 * @param string $label   The label.
	 * @param string $current The currently selected value.
	 * @return void
	 */
	private function radio( string $name, string $value, string $label, string $current ): void {
		printf(
			'<label class="agt-sync-radio"><input type="radio" name="%s" value="%s" %s> %s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $current, $value, false ),
			esc_html( $label )
		);
	}

	/**
	 * A text row.
	 *
	 * @param string $name  The field name.
	 * @param string $label The label.
	 * @param string $value The current value.
	 * @return void
	 */
	private function text_row( string $name, string $label, string $value ): void {
		printf(
			'<tr><th scope="row"><label for="agt-%1$s">%2$s</label></th><td><input type="text" id="agt-%1$s" name="%1$s" value="%3$s" class="regular-text"></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	/**
	 * Check the nonce and the capability, or die.
	 *
	 * @param string $action The nonce action.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'agt-sync-for-woocommerce' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Bounce back to the settings screen with a result.
	 *
	 * @param string $result  The result key.
	 * @param string $message An error message, if any.
	 * @return never
	 */
	private function redirect_with( string $result, string $message ) {
		$args = array(
			'page'       => self::SLUG,
			'agt_result' => $result,
		);

		if ( '' !== $message ) {
			$args['agt_message'] = $message;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
