<?php
/**
 * The cached American Gun Trader taxonomy.
 *
 * @package AgtSync
 */

namespace AgtSync\Taxonomy;

use AgtSync\Api\ApiException;
use AgtSync\Api\Client;
use AgtSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Categories, manufacturers, calibers, applications and conditions, as American
 * Gun Trader defines them.
 *
 * Cached for a day: these lists change rarely, and a store rebuilding a category
 * dropdown should not cost an API call. The API also ETags the response, so even
 * the daily refresh usually costs nothing.
 */
final class Repository {

	/**
	 * The cache key.
	 */
	private const TRANSIENT = 'agt_sync_taxonomy';

	/**
	 * How long a cached copy is good for.
	 */
	private const TTL = DAY_IN_SECONDS;

	/**
	 * The whole taxonomy, from cache if we have it.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array<string,mixed>
	 */
	public static function all( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		try {
			$client   = new Client();
			$response = $client->get( '/taxonomy' );

			$taxonomy = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

			if ( ! empty( $taxonomy ) ) {
				set_transient( self::TRANSIENT, $taxonomy, self::TTL );
			}

			return $taxonomy;
		} catch ( ApiException $e ) {
			Logger::warn( 'Could not refresh the American Gun Trader taxonomy: ' . $e->getMessage() );

			// Serve whatever we last had rather than breaking the settings screen.
			$cached = get_transient( self::TRANSIENT );

			return is_array( $cached ) ? $cached : array();
		}
	}

	/**
	 * Every category.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function categories(): array {
		$taxonomy = self::all();

		return isset( $taxonomy['categories'] ) && is_array( $taxonomy['categories'] ) ? $taxonomy['categories'] : array();
	}

	/**
	 * One category, by AGT id.
	 *
	 * @param int $id AGT category id.
	 * @return array<string,mixed>|null
	 */
	public static function category( int $id ): ?array {
		foreach ( self::categories() as $category ) {
			if ( isset( $category['id'] ) && (int) $category['id'] === $id ) {
				return $category;
			}
		}

		return null;
	}

	/**
	 * Does this category demand a manufacturer AND a caliber? True for anything in
	 * the firearms tree — publishing a rifle without either would be rejected, so
	 * the plugin checks before it sends.
	 *
	 * @param int $id AGT category id.
	 * @return bool
	 */
	public static function category_requires_manufacturer_and_caliber( int $id ): bool {
		$category = self::category( $id );

		return is_array( $category ) && ! empty( $category['requires_manufacturer_and_caliber'] );
	}

	/**
	 * Categories as a flat id => name list, with the tree shown by indentation, for
	 * a <select>.
	 *
	 * @return array<int,string>
	 */
	public static function category_options(): array {
		$categories = self::categories();

		$by_parent = array();

		foreach ( $categories as $category ) {
			$parent_id                 = isset( $category['parent_id'] ) ? (int) $category['parent_id'] : 0;
			$by_parent[ $parent_id ][] = $category;
		}

		$options = array();

		self::walk_categories( $by_parent, 0, 0, $options );

		return $options;
	}

	/**
	 * Depth-first walk of the category tree, building indented labels.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $by_parent Categories grouped by parent id.
	 * @param int                                       $parent_id Parent id to expand.
	 * @param int                                       $depth     Current depth.
	 * @param array<int,string>                         $options   Accumulator, by reference.
	 * @return void
	 */
	private static function walk_categories( array $by_parent, int $parent_id, int $depth, array &$options ): void {
		if ( ! isset( $by_parent[ $parent_id ] ) || $depth > 6 ) {
			return;
		}

		foreach ( $by_parent[ $parent_id ] as $category ) {
			$id   = isset( $category['id'] ) ? (int) $category['id'] : 0;
			$name = isset( $category['name'] ) ? (string) $category['name'] : '';

			if ( $id <= 0 ) {
				continue;
			}

			$options[ $id ] = str_repeat( '— ', $depth ) . $name;

			self::walk_categories( $by_parent, $id, $depth + 1, $options );
		}
	}

	/**
	 * Manufacturers, id => name.
	 *
	 * @return array<int,string>
	 */
	public static function manufacturers(): array {
		return self::flat( 'manufacturers', 'name' );
	}

	/**
	 * Calibers, id => name.
	 *
	 * @return array<int,string>
	 */
	public static function calibers(): array {
		return self::flat( 'calibers', 'name' );
	}

	/**
	 * Applications (tags like Hunting, Tactical), id => name.
	 *
	 * @return array<int,string>
	 */
	public static function applications(): array {
		return self::flat( 'applications', 'name' );
	}

	/**
	 * Conditions, id => label.
	 *
	 * @return array<int,string>
	 */
	public static function conditions(): array {
		return self::flat( 'conditions', 'label' );
	}

	/**
	 * Find a manufacturer id by name, case-insensitively. Returns 0 if unknown.
	 *
	 * @param string $name The manufacturer name from the product attribute.
	 * @return int
	 */
	public static function manufacturer_id_by_name( string $name ): int {
		return self::id_by_name( self::manufacturers(), $name );
	}

	/**
	 * Find a caliber id by name, case-insensitively. Returns 0 if unknown.
	 *
	 * @param string $name The caliber name from the product attribute.
	 * @return int
	 */
	public static function caliber_id_by_name( string $name ): int {
		return self::id_by_name( self::calibers(), $name );
	}

	/**
	 * Forget the cached taxonomy.
	 *
	 * @return void
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * A section of the taxonomy as id => label.
	 *
	 * @param string $section The taxonomy key.
	 * @param string $label   The field to use as the label.
	 * @return array<int,string>
	 */
	private static function flat( string $section, string $label ): array {
		$taxonomy = self::all();

		if ( ! isset( $taxonomy[ $section ] ) || ! is_array( $taxonomy[ $section ] ) ) {
			return array();
		}

		$flat = array();

		foreach ( $taxonomy[ $section ] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
				continue;
			}

			$flat[ (int) $item['id'] ] = isset( $item[ $label ] ) ? (string) $item[ $label ] : '';
		}

		return $flat;
	}

	/**
	 * Match a name against an id => name list, loosely.
	 *
	 * A merchant's attribute says "Smith & Wesson"; AGT might have "Smith and
	 * Wesson". Compare on letters and digits only so the obvious matches land, and
	 * leave anything genuinely ambiguous to the merchant's explicit mapping.
	 *
	 * @param array<int,string> $haystack id => name.
	 * @param string            $needle   The name to find.
	 * @return int The id, or 0.
	 */
	private static function id_by_name( array $haystack, string $needle ): int {
		$needle = self::normalize( $needle );

		if ( '' === $needle ) {
			return 0;
		}

		foreach ( $haystack as $id => $name ) {
			if ( self::normalize( $name ) === $needle ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Reduce a name to letters and digits, lowercased.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private static function normalize( string $value ): string {
		$value = strtolower( $value );
		$value = str_replace( array( ' and ', '&' ), '', $value );

		return (string) preg_replace( '/[^a-z0-9]/', '', $value );
	}
}
