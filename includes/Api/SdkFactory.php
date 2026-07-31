<?php
/**
 * Builds a configured AGT PHP SDK client from the store's OAuth token.
 *
 * The hand-rolled {@see Client} still drives sync today. New code and the
 * eventual cut-over should go through this factory so the OpenAPI-generated
 * client (shadow-software/agt-php-sdk) is the single source of truth for the
 * dealer API surface.
 *
 * @package AgtSync
 */

namespace AgtSync\Api;

use ShadowSoftware\Agt\Api\DealerAccountApi;
use ShadowSoftware\Agt\Api\DealerListingApi;
use ShadowSoftware\Agt\Configuration;

defined( 'ABSPATH' ) || exit;

/**
 * Factory for OpenAPI-generated dealer API clients.
 */
final class SdkFactory {

	/**
	 * Shared Configuration for the dealer API.
	 *
	 * @param string $access_token Bearer token from OAuth.
	 * @return Configuration
	 */
	public static function configuration( $access_token ) {
		return Configuration::getDefaultConfiguration()
			->setHost( untrailingslashit( AGT_SYNC_API_BASE ) )
			->setAccessToken( (string) $access_token );
	}

	/**
	 * Listings API client.
	 *
	 * @param string $access_token Bearer token from OAuth.
	 * @return DealerListingApi
	 */
	public static function listings( $access_token ) {
		return new DealerListingApi( null, self::configuration( $access_token ) );
	}

	/**
	 * Account API client.
	 *
	 * @param string $access_token Bearer token from OAuth.
	 * @return DealerAccountApi
	 */
	public static function account( $access_token ) {
		return new DealerAccountApi( null, self::configuration( $access_token ) );
	}
}
