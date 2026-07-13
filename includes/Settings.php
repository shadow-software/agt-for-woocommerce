<?php
/**
 * Plugin settings, in one option row.
 *
 * @package AgtSync
 */

namespace AgtSync;

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessors over the settings option.
 */
final class Settings {

	/**
	 * The option name.
	 */
	public const OPTION = 'agt_sync_settings';

	/**
	 * Defaults. Every one of these is a deliberate choice, not a placeholder.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Syncing is OFF until the merchant turns it on. Connecting a store must
			// never, by itself, publish a catalogue.
			'enabled'             => false,

			// Sync every eligible product, or only ones explicitly opted in.
			'sync_mode'           => 'all', // Either 'all' or 'opt_in'.

			// Which price goes to AGT. 'current' includes an active sale, which is
			// what a buyer would actually pay; 'regular' ignores sales.
			'price_source'        => 'current', // Either 'current' or 'regular'.

			// The condition for a product with no pa_condition attribute. Confirmed by
			// the merchant on first run — a wrong condition on a firearm is a
			// real-world problem, so we never silently guess.
			'default_condition'   => 0, // 0 = not chosen yet; 1 New, 2 Used, 3 Like New, 4 Damaged
			'condition_confirmed' => false,

			// Trashing a product removes its listing; restoring it brings the listing
			// back. A merchant who would rather remove listings by hand sets 'unlink'.
			'delete_behavior'     => 'delete', // Either 'delete' or 'unlink'.

			// Mark the AGT listing sold when the product goes out of stock.
			'mark_sold_on_oos'    => true,

			// Requests per minute the plugin allows ITSELF. AGT's own ceiling is far
			// higher (120/min); staying well under it means a well-behaved store never
			// sees a 429 at all. A merchant on tiny shared hosting can lower this.
			'rate_limit_per_min'  => 60,

			// How many products one background job handles. Small enough that a
			// 5,000-product catalogue becomes many short jobs instead of one that
			// times out.
			'batch_size'          => 20,

			// WooCommerce category term id => AGT category id.
			'category_map'        => array(),

			// Product attribute slugs to read the mapped values from.
			'attr_condition'      => 'pa_condition',
			'attr_manufacturer'   => 'pa_manufacturer',
			'attr_caliber'        => 'pa_caliber',
		);
	}

	/**
	 * The whole settings array, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default_value Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default_value = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * A setting as a bool.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function bool( string $key ): bool {
		return (bool) self::get( $key, false );
	}

	/**
	 * A setting as an int.
	 *
	 * @param string $key Setting key.
	 * @return int
	 */
	public static function int( string $key ): int {
		return (int) self::get( $key, 0 );
	}

	/**
	 * A setting as a string.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function str( string $key ): string {
		$value = self::get( $key, '' );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The WooCommerce term id => AGT category id map.
	 *
	 * @return array<int,int>
	 */
	public static function category_map(): array {
		$map = self::get( 'category_map', array() );

		if ( ! is_array( $map ) ) {
			return array();
		}

		$clean = array();

		foreach ( $map as $term_id => $agt_id ) {
			$term_id = (int) $term_id;
			$agt_id  = (int) $agt_id;

			if ( $term_id > 0 && $agt_id > 0 ) {
				$clean[ $term_id ] = $agt_id;
			}
		}

		return $clean;
	}

	/**
	 * Persist a partial update.
	 *
	 * @param array<string,mixed> $changes Keys to merge over the current settings.
	 * @return void
	 */
	public static function update( array $changes ): void {
		update_option( self::OPTION, array_merge( self::all(), $changes ), false );
	}

	/**
	 * Is the plugin configured well enough to actually sync?
	 *
	 * Connected, switched on, and the merchant has confirmed a default condition —
	 * publishing a used trade-in as "New" because we guessed is not acceptable.
	 *
	 * @return bool
	 */
	public static function ready_to_sync(): bool {
		return self::bool( 'enabled' )
			&& self::bool( 'condition_confirmed' )
			&& \AgtSync\Auth\Credentials::is_connected();
	}
}
