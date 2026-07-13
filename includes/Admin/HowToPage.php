<?php
/**
 * The "How it works" screen.
 *
 * @package AgtSync
 */

namespace AgtSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What this plugin does, in plain language.
 *
 * This exists for two audiences, and they need the same thing.
 *
 * A merchant needs to know what will happen to their catalogue before they turn
 * it on — especially that deleting a product removes a live listing.
 *
 * A WordPress.org reviewer cannot get past the Connect button: the plugin needs a
 * real American Gun Trader dealer account with an approved FFL, and they will not
 * have one. Without this page the plugin is a login wall with nothing behind it,
 * which is not a reviewable thing. So the whole flow is written out here, and the
 * page says plainly how to get a test account.
 */
final class HowToPage {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<div class="agt-sync-card agt-sync-howto">';

		echo '<h2>' . esc_html__( 'What this plugin does', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'It publishes your WooCommerce products as listings on American Gun Trader, and keeps the two in step. The point of it is this: when a gun sells on American Gun Trader, the matching WooCommerce product is set out of stock automatically — so you cannot sell the same firearm twice, to two people, on two sites.', 'agt-sync-for-woocommerce' ) . '</p>';

		echo '<h2>' . esc_html__( 'What you need', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<ul class="agt-sync-list">';
		echo '<li>' . esc_html__( 'A free American Gun Trader account.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'An approved FFL on that account.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'An active dealer subscription.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'A complete address on that account, with a city chosen from the dropdown — listings take their location from your account, not from the product.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '</ul>';

		echo '<h2>' . esc_html__( 'How to connect', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<ol class="agt-sync-list">';
		echo '<li>' . esc_html__( 'Click "Connect to American Gun Trader" on the Settings tab.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'You are taken to americanguntrader.com and asked to log in.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'American Gun Trader shows you which store is asking, and what it will be able to do. You approve it.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'You land back here, connected. There is nothing to copy and paste, and your store never sees your American Gun Trader password.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '</ol>';

		echo '<h2>' . esc_html__( 'How to set it up', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<ol class="agt-sync-list">';
		echo '<li>' . esc_html__( 'Choose a default condition. Nothing publishes until you do — listing a used firearm as new is not something this plugin will guess at.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Map your WooCommerce categories to American Gun Trader categories. A product will not publish until its category is mapped. Mapping a parent category covers everything beneath it.', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Turn syncing on, and optionally click "Sync all products".', 'agt-sync-for-woocommerce' ) . '</li>';
		echo '</ol>';

		echo '<h2>' . esc_html__( 'What happens afterwards', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat striped agt-sync-behaviour"><tbody>';

		$rows = array(
			array(
				__( 'You edit a product', 'agt-sync-for-woocommerce' ),
				__( 'The listing is updated. Approved dealers publish with no review queue, so a price change stays live rather than pulling the listing down.', 'agt-sync-for-woocommerce' ),
			),
			array(
				__( 'A gun sells on American Gun Trader', 'agt-sync-for-woocommerce' ),
				__( 'The WooCommerce product is set out of stock, and a note is added to it saying why. This is the whole point of the plugin.', 'agt-sync-for-woocommerce' ),
			),
			array(
				__( 'A gun sells in your store', 'agt-sync-for-woocommerce' ),
				__( 'Its American Gun Trader listing is removed, so it is not offered to two buyers. Restocking the product brings the listing back.', 'agt-sync-for-woocommerce' ),
			),
			array(
				__( 'You trash a product', 'agt-sync-for-woocommerce' ),
				__( 'Its listing is removed from American Gun Trader. Restoring the product from the trash brings the listing back — removal is reversible on both sides. You can switch this off in the settings.', 'agt-sync-for-woocommerce' ),
			),
			array(
				__( 'A product will not publish', 'agt-sync-for-woocommerce' ),
				__( 'The AGT Sync box on that product tells you exactly why — a description under 80 characters, no photo, an unmapped category, or a firearm with no manufacturer or caliber.', 'agt-sync-for-woocommerce' ),
			),
			array(
				__( 'You disconnect', 'agt-sync-for-woocommerce' ),
				__( 'Syncing stops. Your listings stay on American Gun Trader until you remove them there.', 'agt-sync-for-woocommerce' ),
			),
		);

		foreach ( $rows as $row ) {
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'What is sent, and what is not', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Only the product information needed to build the listing: its title, description, price, condition, weight, category, manufacturer, caliber and photos — plus your site address, once, at connection time, so you can recognise and revoke this store later.', 'agt-sync-for-woocommerce' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'No customer data, no order data, and no payment data ever leaves your store.', 'agt-sync-for-woocommerce' ) . '</strong> ' . esc_html__( 'The plugin does not read your orders or your customers.', 'agt-sync-for-woocommerce' ) . '</p>';

		echo '<h2>' . esc_html__( 'Not supported yet', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Variable products. One variable product would be several American Gun Trader listings — one per caliber, say — and guessing which to publish would put a price in front of a buyer that does not apply to what they picked. Variable products are skipped and flagged.', 'agt-sync-for-woocommerce' ) . '</p>';

		echo '<h2>' . esc_html__( 'Reviewing this plugin?', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'The connect flow needs a real American Gun Trader dealer account with an approved FFL, so it cannot be exercised without one. We will happily provide a sandbox dealer account — email support@shadowsoftware.com and we will set one up.', 'agt-sync-for-woocommerce' ) . '</p>';

		echo '<h2>' . esc_html__( 'Links', 'agt-sync-for-woocommerce' ) . '</h2>';
		echo '<ul class="agt-sync-list">';

		$links = array(
			array( 'https://americanguntrader.com/integrations/woocommerce', __( 'AGT Sync on American Gun Trader', 'agt-sync-for-woocommerce' ) ),
			array( 'https://github.com/shadow-software/agt-sync-for-woocommerce', __( 'Source code on GitHub', 'agt-sync-for-woocommerce' ) ),
			array( 'https://github.com/shadow-software/agt-sync-for-woocommerce/issues', __( 'Report a bug or request a feature', 'agt-sync-for-woocommerce' ) ),
			array( 'https://shadowsoftware.com/', __( 'Shadow Software, the developers', 'agt-sync-for-woocommerce' ) ),
			array( 'https://americanguntrader.com/privacy', __( 'American Gun Trader privacy policy', 'agt-sync-for-woocommerce' ) ),
			array( 'https://americanguntrader.com/terms', __( 'American Gun Trader terms', 'agt-sync-for-woocommerce' ) ),
		);

		foreach ( $links as $link ) {
			printf(
				'<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
				esc_url( $link[0] ),
				esc_html( $link[1] )
			);
		}

		echo '</ul>';

		echo '</div>';
	}
}
