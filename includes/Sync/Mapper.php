<?php
/**
 * WooCommerce product -> American Gun Trader listing.
 *
 * @package AgtSync
 */

namespace AgtSync\Sync;

use AgtSync\Settings;
use AgtSync\Taxonomy\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The field map, and the rules that decide whether a product can be a listing at
 * all.
 *
 * The rules mirror American Gun Trader's own, so a product that cannot possibly
 * publish is caught here — with a message the merchant can act on — instead of
 * being sent, rejected, and turned into a cryptic 422 in a log.
 */
final class Mapper {

	/**
	 * AGT's limits. Kept in step with the API's /me limits payload, and with
	 * ListingRules on the server.
	 */
	public const TITLE_MAX       = 80;
	public const DESCRIPTION_MAX = 4000;
	public const DESCRIPTION_MIN = 80;
	public const PRICE_MIN       = 0.01;
	public const PRICE_MAX       = 9999999.99;
	public const IMAGES_MAX      = 10;
	public const WEIGHT_MAX      = 1000.0;

	/**
	 * Condition ids, as AGT defines them.
	 */
	public const CONDITION_NEW      = 1;
	public const CONDITION_USED     = 2;
	public const CONDITION_LIKE_NEW = 3;
	public const CONDITION_DAMAGED  = 4;

	/**
	 * Should this product sync at all?
	 *
	 * @param \WC_Product $product The product.
	 * @return bool
	 */
	public static function is_syncable( \WC_Product $product ): bool {
		// A variable product is genuinely several listings — one per caliber, say —
		// and guessing which to publish would put a price in front of a buyer that
		// does not apply to what they picked. Skipped, and flagged in the admin.
		if ( $product->is_type( 'variable' ) ) {
			return false;
		}

		if ( ! $product->is_type( 'simple' ) ) {
			return false;
		}

		if ( 'publish' !== $product->get_status() ) {
			return false;
		}

		// Opt-in mode: only products the merchant explicitly ticked.
		if ( 'opt_in' === Settings::str( 'sync_mode' ) && '1' !== $product->get_meta( '_agt_sync_enabled' ) ) {
			return false;
		}

		// Either mode: an explicit opt-OUT always wins.
		if ( '0' === $product->get_meta( '_agt_sync_enabled' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Why a variable product was skipped — shown on the product screen so the
	 * merchant is not left wondering.
	 *
	 * @param \WC_Product $product The product.
	 * @return string '' if it is not skipped for a structural reason.
	 */
	public static function skip_reason( \WC_Product $product ): string {
		if ( $product->is_type( 'variable' ) ) {
			return __( 'Variable products are not supported yet. Each variation would need its own American Gun Trader listing.', 'agt-sync-for-woocommerce' );
		}

		if ( ! $product->is_type( 'simple' ) ) {
			return __( 'Only simple products can be published to American Gun Trader.', 'agt-sync-for-woocommerce' );
		}

		return '';
	}

	/**
	 * Build the listing payload for a product.
	 *
	 * @param \WC_Product $product The product.
	 * @return array{ok:bool,payload:array<string,mixed>,errors:array<int,string>}
	 */
	public static function to_listing( \WC_Product $product ): array {
		$errors = array();

		$title = self::title( $product );

		if ( '' === $title ) {
			$errors[] = __( 'The product needs a name.', 'agt-sync-for-woocommerce' );
		}

		$description = self::description( $product );

		if ( self::plain_length( $description ) < self::DESCRIPTION_MIN ) {
			$errors[] = sprintf(
				/* translators: %d: minimum number of characters. */
				__( 'The description must be at least %d characters so buyers know the condition and what is included.', 'agt-sync-for-woocommerce' ),
				self::DESCRIPTION_MIN
			);
		}

		$price = self::price( $product );

		if ( null === $price ) {
			$errors[] = __( 'The product needs a price.', 'agt-sync-for-woocommerce' );
		} elseif ( $price < self::PRICE_MIN || $price > self::PRICE_MAX ) {
			$errors[] = __( 'The price is outside the range American Gun Trader accepts.', 'agt-sync-for-woocommerce' );
		}

		$category_id = self::category_id( $product );

		if ( $category_id <= 0 ) {
			$errors[] = __( 'This product\'s category is not mapped to an American Gun Trader category yet.', 'agt-sync-for-woocommerce' );
		}

		$condition = self::condition( $product );

		if ( $condition <= 0 ) {
			$errors[] = __( 'No condition is set for this product, and no default has been chosen in the settings.', 'agt-sync-for-woocommerce' );
		}

		$manufacturer_id = self::manufacturer_id( $product );
		$caliber_id      = self::caliber_id( $product );

		// A firearm listing without a manufacturer or a caliber is useless to a
		// buyer, and AGT rejects it. Catch it here so the merchant gets a real
		// sentence instead of a 422.
		if ( $category_id > 0 && Repository::category_requires_manufacturer_and_caliber( $category_id ) ) {
			if ( $manufacturer_id <= 0 ) {
				$errors[] = __( 'Firearms need a manufacturer. Set one on the product, or map its brand to an American Gun Trader manufacturer.', 'agt-sync-for-woocommerce' );
			}

			if ( $caliber_id <= 0 ) {
				$errors[] = __( 'Firearms need a caliber. Set one on the product.', 'agt-sync-for-woocommerce' );
			}
		}

		$images = self::images( $product );

		if ( empty( $images ) ) {
			$errors[] = __( 'The product needs at least one photo.', 'agt-sync-for-woocommerce' );
		}

		$payload = array(
			'title'       => $title,
			'description' => $description,
			'price'       => $price,
			'condition'   => $condition,
			'category_id' => $category_id,
		);

		if ( $manufacturer_id > 0 ) {
			$payload['manufacturer_id'] = $manufacturer_id;
		}

		if ( $caliber_id > 0 ) {
			$payload['caliber_id'] = $caliber_id;
		}

		$weight = self::weight( $product );

		if ( null !== $weight ) {
			$payload['weight'] = $weight;
		}

		return array(
			'ok'      => empty( $errors ),
			'payload' => $payload,
			'errors'  => $errors,
		);
	}

	/**
	 * A hash of what we would send. If it matches what we last sent, there is
	 * nothing to do — which is what makes a merchant hammering Save on a product
	 * cost zero API calls.
	 *
	 * @param array<string,mixed> $payload The listing payload.
	 * @return string
	 */
	public static function payload_hash( array $payload ): string {
		ksort( $payload );

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * A hash of the product's image set, so images are only re-uploaded when they
	 * actually change. Re-sending ten photos on every price tweak would be brutal
	 * on a small host.
	 *
	 * @param \WC_Product $product The product.
	 * @return string
	 */
	public static function image_hash( \WC_Product $product ): string {
		$ids = self::image_ids( $product );

		return hash( 'sha256', implode( ',', $ids ) );
	}

	/**
	 * The attachment ids to publish: the featured image first, then the gallery.
	 *
	 * @param \WC_Product $product The product.
	 * @return array<int,int>
	 */
	public static function image_ids( \WC_Product $product ): array {
		$ids = array();

		$featured = (int) $product->get_image_id();

		if ( $featured > 0 ) {
			$ids[] = $featured;
		}

		foreach ( $product->get_gallery_image_ids() as $id ) {
			$id = (int) $id;

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return array_slice( $ids, 0, self::IMAGES_MAX );
	}

	/**
	 * The images, as files ready to upload.
	 *
	 * @param \WC_Product $product The product.
	 * @return array<int,array<string,string>>
	 */
	public static function images( \WC_Product $product ): array {
		$files = array();

		foreach ( self::image_ids( $product ) as $id ) {
			$path = get_attached_file( $id );

			if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
				continue;
			}

			$type = (string) get_post_mime_type( $id );

			// AGT accepts JPEG, PNG and WebP only.
			if ( ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				continue;
			}

			$files[] = array(
				'name'     => 'images',
				'filename' => basename( $path ),
				'path'     => $path,
				'type'     => $type,
			);
		}

		return $files;
	}

	/**
	 * The listing title: the product name, trimmed to AGT's limit and stripped of
	 * markup (a title is plain text there).
	 *
	 * @param \WC_Product $product The product.
	 * @return string
	 */
	public static function title( \WC_Product $product ): string {
		$title = wp_strip_all_tags( $product->get_name() );
		$title = trim( (string) preg_replace( '/\s+/', ' ', $title ) );

		if ( mb_strlen( $title ) > self::TITLE_MAX ) {
			$title = rtrim( mb_substr( $title, 0, self::TITLE_MAX - 1 ) ) . '…';
		}

		return $title;
	}

	/**
	 * The listing description: the full description, falling back to the short one.
	 *
	 * @param \WC_Product $product The product.
	 * @return string
	 */
	public static function description( \WC_Product $product ): string {
		$description = trim( $product->get_description() );

		if ( '' === $description ) {
			$description = trim( $product->get_short_description() );
		}

		// AGT keeps a small allowlist of tags and strips the rest, so send it clean.
		$description = wp_kses(
			$description,
			array(
				'p'      => array(),
				'br'     => array(),
				'strong' => array(),
				'em'     => array(),
				'u'      => array(),
				'ol'     => array(),
				'ul'     => array(),
				'li'     => array(),
				'h1'     => array(),
				'h2'     => array(),
				'h3'     => array(),
				'h4'     => array(),
				'h5'     => array(),
				'h6'     => array(),
			)
		);

		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX ) {
			$description = mb_substr( $description, 0, self::DESCRIPTION_MAX );
		}

		return $description;
	}

	/**
	 * The price to publish.
	 *
	 * 'current' (the default) sends what a buyer would actually pay today,
	 * including an active sale. A listing that shows more than the dealer's own
	 * store reads as bait-and-switch.
	 *
	 * @param \WC_Product $product The product.
	 * @return float|null
	 */
	public static function price( \WC_Product $product ): ?float {
		$source = Settings::str( 'price_source' );

		$regular = $product->get_regular_price();
		$current = $product->get_price();

		$raw = 'regular' === $source ? $regular : $current;

		// Fall back to the OTHER one, not the same one again. A product priced only
		// through a sale price has an empty regular price, and a listing with no price
		// is no use to anybody — better to publish the price a buyer would actually
		// pay than to refuse the product for having "no price".
		if ( '' === $raw || null === $raw ) {
			$raw = 'regular' === $source ? $current : $regular;
		}

		if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
			return null;
		}

		return round( (float) $raw, 2 );
	}

	/**
	 * The condition, from the product's attribute or the store default.
	 *
	 * Never guessed silently: if the attribute is absent and the merchant has not
	 * confirmed a default, this returns 0 and the product does not publish. A used
	 * trade-in listed as "New" is a real-world problem, not a cosmetic one.
	 *
	 * @param \WC_Product $product The product.
	 * @return int 0 if unknown.
	 */
	public static function condition( \WC_Product $product ): int {
		$attribute = $product->get_attribute( Settings::str( 'attr_condition' ) );

		if ( is_string( $attribute ) && '' !== trim( $attribute ) ) {
			$mapped = self::condition_from_text( $attribute );

			if ( $mapped > 0 ) {
				return $mapped;
			}
		}

		$default = Settings::int( 'default_condition' );

		return Settings::bool( 'condition_confirmed' ) ? $default : 0;
	}

	/**
	 * Map an attribute value like "Like New" or "NIB" to an AGT condition id.
	 *
	 * Filterable so a merchant with unusual attribute values can extend it without
	 * forking the plugin.
	 *
	 * @param string $text The attribute value.
	 * @return int 0 if it matches nothing.
	 */
	public static function condition_from_text( string $text ): int {
		$text = strtolower( trim( $text ) );

		/**
		 * Filters the map from a product's condition attribute to an AGT condition id.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,int> $map  Lowercase attribute value => AGT condition id.
		 * @param string            $text The value being mapped.
		 */
		$map = apply_filters(
			'agt_sync_condition_map',
			array(
				'new'        => self::CONDITION_NEW,
				'brand new'  => self::CONDITION_NEW,
				'nib'        => self::CONDITION_NEW,
				'new in box' => self::CONDITION_NEW,
				'like new'   => self::CONDITION_LIKE_NEW,
				'excellent'  => self::CONDITION_LIKE_NEW,
				'as new'     => self::CONDITION_LIKE_NEW,
				'used'       => self::CONDITION_USED,
				'good'       => self::CONDITION_USED,
				'very good'  => self::CONDITION_USED,
				'fair'       => self::CONDITION_USED,
				'pre-owned'  => self::CONDITION_USED,
				'damaged'    => self::CONDITION_DAMAGED,
				'parts'      => self::CONDITION_DAMAGED,
				'gunsmith'   => self::CONDITION_DAMAGED,
				'for parts'  => self::CONDITION_DAMAGED,
			),
			$text
		);

		return isset( $map[ $text ] ) ? (int) $map[ $text ] : 0;
	}

	/**
	 * The AGT category for a product, from the merchant's category mapping.
	 *
	 * A product in several categories takes the first that IS mapped, so a
	 * merchant does not have to map every last one.
	 *
	 * @param \WC_Product $product The product.
	 * @return int 0 if nothing maps.
	 */
	public static function category_id( \WC_Product $product ): int {
		$map = Settings::category_map();

		if ( empty( $map ) ) {
			return 0;
		}

		$term_ids = $product->get_category_ids();

		foreach ( $term_ids as $term_id ) {
			$term_id = (int) $term_id;

			if ( isset( $map[ $term_id ] ) ) {
				return (int) $map[ $term_id ];
			}
		}

		// Nothing mapped directly; try the parents, so mapping a top-level category
		// covers everything under it.
		foreach ( $term_ids as $term_id ) {
			$ancestors = get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' );

			foreach ( $ancestors as $ancestor ) {
				$ancestor = (int) $ancestor;

				if ( isset( $map[ $ancestor ] ) ) {
					return (int) $map[ $ancestor ];
				}
			}
		}

		return 0;
	}

	/**
	 * The AGT manufacturer id, from the product's brand/manufacturer attribute.
	 *
	 * @param \WC_Product $product The product.
	 * @return int 0 if unknown.
	 */
	public static function manufacturer_id( \WC_Product $product ): int {
		$value = $product->get_attribute( Settings::str( 'attr_manufacturer' ) );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return 0;
		}

		// An attribute can hold several terms, comma-separated. Take the first.
		$first = trim( (string) strtok( $value, ',' ) );

		return Repository::manufacturer_id_by_name( $first );
	}

	/**
	 * The AGT caliber id, from the product's caliber attribute.
	 *
	 * @param \WC_Product $product The product.
	 * @return int 0 if unknown.
	 */
	public static function caliber_id( \WC_Product $product ): int {
		$value = $product->get_attribute( Settings::str( 'attr_caliber' ) );

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return 0;
		}

		$first = trim( (string) strtok( $value, ',' ) );

		return Repository::caliber_id_by_name( $first );
	}

	/**
	 * The weight, converted to pounds (what AGT expects).
	 *
	 * @param \WC_Product $product The product.
	 * @return float|null
	 */
	public static function weight( \WC_Product $product ): ?float {
		$weight = $product->get_weight();

		if ( '' === $weight || null === $weight || ! is_numeric( $weight ) ) {
			return null;
		}

		$weight = (float) $weight;
		$unit   = strtolower( (string) get_option( 'woocommerce_weight_unit', 'lbs' ) );

		switch ( $unit ) {
			case 'kg':
				$weight *= 2.20462;
				break;
			case 'g':
				$weight *= 0.00220462;
				break;
			case 'oz':
				$weight *= 0.0625;
				break;
		}

		$weight = round( $weight, 4 );

		if ( $weight <= 0 || $weight > self::WEIGHT_MAX ) {
			return null;
		}

		return $weight;
	}

	/**
	 * Plain-text length of a description, once markup is gone. "<p></p><p></p>" is
	 * empty however many bytes it is.
	 *
	 * @param string $html The description.
	 * @return int
	 */
	public static function plain_length( string $html ): int {
		$plain = wp_strip_all_tags( $html );
		$plain = html_entity_decode( $plain, ENT_QUOTES, 'UTF-8' );

		return mb_strlen( trim( $plain ) );
	}
}
