<?php
/**
 * Admin notices.
 *
 * @package AgtSync
 */

namespace AgtSync\Admin;

use AgtSync\Auth\Credentials;
use AgtSync\Settings;
use AgtSync\Sync\LinkMap;

defined( 'ABSPATH' ) || exit;

/**
 * Tell the merchant when something needs them.
 *
 * Deliberately quiet: three conditions, each of which genuinely means their
 * inventory is not doing what they think it is. No upsells, no "rate us", no
 * nagging — that is what gets a plugin thrown out of the directory, and it is
 * obnoxious besides.
 */
final class Notices {

	/**
	 * Register.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render whichever notice applies.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Do not shout on our own settings screen; it says all of this in place.
		$screen = get_current_screen();

		if ( $screen instanceof \WP_Screen && false !== strpos( $screen->id, SettingsPage::SLUG ) ) {
			return;
		}

		if ( ! Credentials::is_connected() ) {
			return;
		}

		// The connection is live but the account cannot publish — a lapsed
		// subscription, or an incomplete address. Every product would fail.
		if ( ! Credentials::can_publish() ) {
			$this->notice(
				'error',
				__( 'Your American Gun Trader account cannot publish listings right now, so nothing is syncing.', 'agt-sync-for-woocommerce' ),
				__( 'Fix this', 'agt-sync-for-woocommerce' )
			);

			return;
		}

		// Connected, but the merchant never finished the setup.
		if ( ! Settings::ready_to_sync() ) {
			$this->notice(
				'warning',
				__( 'AGT Sync is connected but not finished. Choose a default condition and map your categories to start publishing.', 'agt-sync-for-woocommerce' ),
				__( 'Finish setting it up', 'agt-sync-for-woocommerce' )
			);

			return;
		}

		// Products that will not publish. This is the one that matters most: the
		// merchant believes these guns are listed, and they are not.
		$counts = LinkMap::counts();
		$errors = isset( $counts[ LinkMap::STATE_ERROR ] ) ? (int) $counts[ LinkMap::STATE_ERROR ] : 0;

		if ( $errors > 0 ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: %d: number of products. */
					_n(
						'%d product could not be published to American Gun Trader.',
						'%d products could not be published to American Gun Trader.',
						$errors,
						'agt-sync-for-woocommerce'
					),
					$errors
				),
				__( 'See why', 'agt-sync-for-woocommerce' )
			);
		}
	}

	/**
	 * Print one notice.
	 *
	 * @param string $level   error|warning.
	 * @param string $message The message.
	 * @param string $action  The link text.
	 * @return void
	 */
	private function notice( string $level, string $message, string $action ): void {
		printf(
			'<div class="notice notice-%s"><p>%s <a href="%s">%s</a></p></div>',
			esc_attr( $level ),
			esc_html( $message ),
			esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ),
			esc_html( $action )
		);
	}
}
