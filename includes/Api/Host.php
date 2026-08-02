<?php
/**
 * Outbound host allowlist for American Gun Trader API / OAuth.
 *
 * @package AgtSync
 */

namespace AgtSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and validates {@see AGT_SYNC_API_BASE}.
 */
final class Host {

	/**
	 * Production and allowed public hosts (exact or subdomain).
	 *
	 * @var list<string>
	 */
	private const ALLOWED_HOST_SUFFIXES = array(
		'americanguntrader.com',
	);

	/**
	 * Effective API origin (scheme + host, no trailing slash).
	 *
	 * Honours `AGT_SYNC_API_BASE` when the host is allowlisted. Non-allowlisted
	 * overrides are ignored (production URL used) unless WP_DEBUG is on and the
	 * host is local — so a mis-set constant cannot point listing traffic at an
	 * attacker-controlled host.
	 *
	 * @return string
	 */
	public static function api_base(): string {
		$default = 'https://americanguntrader.com';
		$base    = defined( 'AGT_SYNC_API_BASE' ) ? (string) AGT_SYNC_API_BASE : $default;

		/**
		 * Filters whether an AGT API host is allowed.
		 *
		 * Return true/false to override the default allowlist, or null to use it.
		 *
		 * @param bool|null $allowed Null to use the default allowlist.
		 * @param string    $host    Hostname.
		 * @param string    $url     Full base URL.
		 */
		$filtered = apply_filters( 'agt_sync_allow_api_host', null, (string) wp_parse_url( $base, PHP_URL_HOST ), $base );

		if ( null !== $filtered ) {
			return $filtered ? untrailingslashit( $base ) : $default;
		}

		if ( self::is_allowed_base( $base ) ) {
			return untrailingslashit( $base );
		}

		return $default;
	}

	/**
	 * Whether a base URL may be used for OAuth / dealer API traffic.
	 *
	 * @param string $url Absolute base URL.
	 * @return bool
	 */
	public static function is_allowed_base( string $url ): bool {
		$url = untrailingslashit( $url );

		if ( '' === $url ) {
			return false;
		}

		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array( 'https', 'http' ), true ) ) {
			return false;
		}

		$host = strtolower( $host );

		foreach ( self::ALLOWED_HOST_SUFFIXES as $suffix ) {
			if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
				return true;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
				return true;
			}
			if ( str_ends_with( $host, '.test' ) || str_ends_with( $host, '.local' ) ) {
				return true;
			}
		}

		return false;
	}
}
