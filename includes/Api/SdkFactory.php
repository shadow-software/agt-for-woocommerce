<?php
/**
 * Builds a configured AGT PHP SDK client from the store's OAuth token.
 *
 * All dealer API traffic goes through {@see Client}, which uses the Packagist
 * package shadow-software/agt-php-sdk (Guzzle + generated Api classes).
 *
 * @package AgtSync
 */

namespace AgtSync\Api;

use AgtSync\Auth\Credentials;
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
	 * Host includes `/api/v1/dealer` — matching the SDK's OpenAPI servers entry.
	 *
	 * @param string|null $access_token Bearer token; defaults to stored OAuth token.
	 * @return Configuration
	 */
	public static function configuration( $access_token = null ) {
		$token = null !== $access_token ? (string) $access_token : Credentials::access_token();
		$host  = untrailingslashit( AGT_SYNC_API_BASE ) . '/api/v1/dealer';

		$config = Configuration::getDefaultConfiguration()
			->setHost( $host )
			->setUserAgent( 'agt-sync-for-woocommerce/' . AGT_SYNC_VERSION . '; ' . home_url( '/' ) );

		if ( '' !== $token ) {
			$config->setAccessToken( $token );
		}

		return $config;
	}

	/**
	 * Listings API client.
	 *
	 * @param string|null $access_token Bearer token.
	 * @return DealerListingApi
	 */
	public static function listings( $access_token = null ) {
		return new DealerListingApi( null, self::configuration( $access_token ) );
	}

	/**
	 * Account API client.
	 *
	 * @param string|null $access_token Bearer token.
	 * @return DealerAccountApi
	 */
	public static function account( $access_token = null ) {
		return new DealerAccountApi( null, self::configuration( $access_token ) );
	}
}
