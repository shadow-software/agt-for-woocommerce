<?php
/**
 * The OAuth connect flow.
 *
 * @package AgtSync
 */

namespace AgtSync\Auth;

use AgtSync\Api\ApiException;
use AgtSync\Api\Client;
use AgtSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Connecting a store, without the merchant ever handling a credential.
 *
 * The plugin registers ITSELF with American Gun Trader (RFC 7591 dynamic client
 * registration), so there is no client id or secret to copy and paste, nothing
 * for us to issue by hand, and nothing to leak in a support thread. The merchant
 * clicks Connect, approves on americanguntrader.com, and comes back with a token.
 */
final class OAuthClient {

	/**
	 * Register this store as an OAuth client, unless it already is.
	 *
	 * @return void
	 * @throws ApiException When registration fails.
	 */
	public static function ensure_registered(): void {
		if ( Credentials::has_client() ) {
			return;
		}

		$response = wp_remote_post(
			self::oauth_url( '/register' ),
			array(
				'timeout'            => 30,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => array( 'Accept' => 'application/json' ),
				'body'               => array(
					'client_name'   => self::client_name(),
					'site_url'      => home_url(),
					'redirect_uris' => array( self::redirect_uri() ),
					'grant_types'   => array( 'authorization_code', 'refresh_token' ),
				),
				'user-agent'         => 'agt-sync-for-woocommerce/' . AGT_SYNC_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			$exception = ApiException::transport( $response->get_error_message() );

			throw $exception;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 201 !== $status || ! is_array( $decoded ) || empty( $decoded['client_id'] ) ) {
			$exception = ApiException::make(
				esc_html__( 'Could not register this store with American Gun Trader. Please try again.', 'agt-sync-for-woocommerce' ),
				$status,
				'registration_failed'
			);

			throw $exception;
		}

		Credentials::save_client(
			(string) $decoded['client_id'],
			isset( $decoded['client_secret'] ) ? (string) $decoded['client_secret'] : ''
		);

		Logger::info( 'Registered this store with American Gun Trader.' );
	}

	/**
	 * The URL to send the merchant to, to approve the connection.
	 *
	 * @return string
	 * @throws ApiException When the store cannot be registered.
	 */
	public static function authorize_url(): string {
		self::ensure_registered();

		$pkce = Pkce::begin();

		return add_query_arg(
			array(
				'client_id'             => Credentials::client_id(),
				'redirect_uri'          => rawurlencode( self::redirect_uri() ),
				'response_type'         => 'code',
				'state'                 => $pkce['state'],
				'code_challenge'        => $pkce['challenge'],
				'code_challenge_method' => 'S256',
			),
			self::oauth_url( '/authorize' )
		);
	}

	/**
	 * Handle the callback: swap the code for tokens, then fetch /me.
	 *
	 * @param string $code  The authorization code.
	 * @param string $state The state, to be checked against the one we issued.
	 * @return void
	 * @throws ApiException When the exchange fails.
	 */
	public static function complete( string $code, string $state ): void {
		$verifier = Pkce::complete( $state );

		if ( '' === $verifier ) {
			// The state did not match the pending flow. This callback did not come
			// from a connect this store started.
			$exception = ApiException::make(
				esc_html__( 'That connection request has expired or did not come from this site. Please click Connect again.', 'agt-sync-for-woocommerce' ),
				400,
				'state_mismatch'
			);

			throw $exception;
		}

		$response = wp_remote_post(
			self::oauth_url( '/token' ),
			array(
				'timeout'            => 30,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => array( 'Accept' => 'application/json' ),
				'body'               => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => Credentials::client_id(),
					'client_secret' => Credentials::client_secret(),
					'redirect_uri'  => self::redirect_uri(),
					'code_verifier' => $verifier,
				),
				'user-agent'         => 'agt-sync-for-woocommerce/' . AGT_SYNC_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			$exception = ApiException::transport( $response->get_error_message() );

			throw $exception;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status || ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			$message = is_array( $decoded ) && isset( $decoded['error_description'] ) && is_string( $decoded['error_description'] )
				? $decoded['error_description']
				: __( 'American Gun Trader would not complete the connection. Please try again.', 'agt-sync-for-woocommerce' );

			$exception = ApiException::make( esc_html( $message ), $status, 'token_exchange_failed' );

			throw $exception;
		}

		Credentials::save_tokens(
			(string) $decoded['access_token'],
			isset( $decoded['refresh_token'] ) ? (string) $decoded['refresh_token'] : '',
			isset( $decoded['expires_in'] ) ? (int) $decoded['expires_in'] : 3600,
			isset( $decoded['scope'] ) ? (string) $decoded['scope'] : ''
		);

		Logger::info( 'Connected this store to American Gun Trader.' );

		self::refresh_account();
	}

	/**
	 * Re-read the dealer's identity from /me and cache it.
	 *
	 * This is what tells the plugin whether the account can publish at all — and
	 * if not, exactly what the merchant has to fix. Called on connect and before
	 * a full sync, so a lapsed subscription is caught before a catalogue of
	 * doomed requests goes out.
	 *
	 * @return array<string,mixed> The account payload.
	 * @throws ApiException When the call fails.
	 */
	public static function refresh_account(): array {
		$client   = new Client();
		$response = $client->get( '/me' );

		$account = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

		Credentials::save_account( $account );

		return $account;
	}

	/**
	 * Disconnect: tell American Gun Trader to revoke the token, then forget it.
	 *
	 * Revoking server-side matters. Deleting our copy alone would leave a live
	 * token for this store sitting in AGT's database until it expired.
	 *
	 * @return void
	 */
	public static function disconnect(): void {
		$token     = Credentials::access_token();
		$client_id = Credentials::client_id();

		if ( '' !== $token && '' !== $client_id ) {
			// Best effort: if AGT is unreachable we still forget our copy, because
			// leaving the merchant unable to disconnect would be worse.
			$response = wp_remote_post(
				self::oauth_url( '/revoke' ),
				array(
					'timeout'            => 15,
					'redirection'        => 0,
					'reject_unsafe_urls' => true,
					'headers'            => array( 'Accept' => 'application/json' ),
					'body'               => array(
						'token'     => $token,
						'client_id' => $client_id,
					),
					'user-agent'         => 'agt-sync-for-woocommerce/' . AGT_SYNC_VERSION . '; ' . home_url(),
				)
			);

			if ( is_wp_error( $response ) ) {
				Logger::warn( 'Could not reach American Gun Trader to revoke the token; forgetting it locally anyway.' );
			}
		}

		Credentials::forget_tokens();

		Logger::info( 'Disconnected this store from American Gun Trader.' );
	}

	/**
	 * Where American Gun Trader sends the merchant back to.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin.php?page=agt-sync&agt_oauth=callback' );
	}

	/**
	 * How this store identifies itself at registration. The site NAME, so the
	 * merchant recognises it on the consent screen and in their AGT connections
	 * list.
	 *
	 * @return string
	 */
	private static function client_name(): string {
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$name = trim( $name );

		if ( '' === $name ) {
			$name = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		return mb_substr( $name . ' (WooCommerce)', 0, 120 );
	}

	/**
	 * Absolute URL for an OAuth endpoint.
	 *
	 * @param string $path OAuth path.
	 * @return string
	 */
	private static function oauth_url( string $path ): string {
		return rtrim( AGT_SYNC_API_BASE, '/' ) . '/oauth/dealer/' . ltrim( $path, '/' );
	}
}
