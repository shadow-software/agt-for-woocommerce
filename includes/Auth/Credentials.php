<?php
/**
 * Where the store's American Gun Trader credentials live.
 *
 * @package AgtSync
 */

namespace AgtSync\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * The OAuth client registration and tokens, in wp_options.
 *
 * WordPress has no secret store, so this is where a credential can go — the same
 * place WooCommerce keeps its own API keys. Both options are stored with
 * autoload OFF so they are not loaded into memory on every single page request,
 * and are never exposed to the front end.
 *
 * The access token is short-lived (an hour) and the refresh token rotates on
 * every use, which bounds what a leaked one is worth.
 */
final class Credentials {

	/**
	 * The registered client (client_id + secret) for this store.
	 */
	private const CLIENT_OPTION = 'agt_sync_client';

	/**
	 * The current token pair.
	 */
	private const TOKEN_OPTION = 'agt_sync_tokens';

	/**
	 * The dealer's identity, cached from /me.
	 */
	private const ACCOUNT_OPTION = 'agt_sync_account';

	/**
	 * Save the client registration.
	 *
	 * @param string $client_id     The client id.
	 * @param string $client_secret The client secret.
	 * @return void
	 */
	public static function save_client( string $client_id, string $client_secret ): void {
		update_option(
			self::CLIENT_OPTION,
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			),
			false
		);
	}

	/**
	 * The registered client id, or ''.
	 *
	 * @return string
	 */
	public static function client_id(): string {
		$client = get_option( self::CLIENT_OPTION, array() );

		return is_array( $client ) && isset( $client['client_id'] ) ? (string) $client['client_id'] : '';
	}

	/**
	 * The registered client secret, or ''.
	 *
	 * @return string
	 */
	public static function client_secret(): string {
		$client = get_option( self::CLIENT_OPTION, array() );

		return is_array( $client ) && isset( $client['client_secret'] ) ? (string) $client['client_secret'] : '';
	}

	/**
	 * Has this store registered a client yet?
	 *
	 * @return bool
	 */
	public static function has_client(): bool {
		return '' !== self::client_id();
	}

	/**
	 * Store a fresh token pair.
	 *
	 * @param string $access_token  The access token.
	 * @param string $refresh_token The refresh token.
	 * @param int    $expires_in    Access token lifetime in seconds.
	 * @param string $scope         Space-separated granted scopes.
	 * @return void
	 */
	public static function save_tokens( string $access_token, string $refresh_token, int $expires_in, string $scope = '' ): void {
		update_option(
			self::TOKEN_OPTION,
			array(
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				// Refresh a minute early so a request never races the expiry.
				'expires_at'    => time() + max( 60, $expires_in ) - 60,
				'scope'         => $scope,
			),
			false
		);
	}

	/**
	 * The current access token, or ''.
	 *
	 * @return string
	 */
	public static function access_token(): string {
		$tokens = get_option( self::TOKEN_OPTION, array() );

		return is_array( $tokens ) && isset( $tokens['access_token'] ) ? (string) $tokens['access_token'] : '';
	}

	/**
	 * The current refresh token, or ''.
	 *
	 * @return string
	 */
	public static function refresh_token(): string {
		$tokens = get_option( self::TOKEN_OPTION, array() );

		return is_array( $tokens ) && isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : '';
	}

	/**
	 * Has the access token expired (or is it about to)?
	 *
	 * @return bool
	 */
	public static function access_token_expired(): bool {
		$tokens = get_option( self::TOKEN_OPTION, array() );

		if ( ! is_array( $tokens ) || ! isset( $tokens['expires_at'] ) ) {
			return true;
		}

		return time() >= (int) $tokens['expires_at'];
	}

	/**
	 * Is this store connected to an American Gun Trader account?
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		return '' !== self::refresh_token();
	}

	/**
	 * Cache the dealer's identity from /me.
	 *
	 * @param array<string,mixed> $account The /me payload.
	 * @return void
	 */
	public static function save_account( array $account ): void {
		update_option( self::ACCOUNT_OPTION, $account, false );
	}

	/**
	 * The cached dealer identity.
	 *
	 * @return array<string,mixed>
	 */
	public static function account(): array {
		$account = get_option( self::ACCOUNT_OPTION, array() );

		return is_array( $account ) ? $account : array();
	}

	/**
	 * Can this dealer publish right now? Mirrors /me's can_publish.
	 *
	 * @return bool
	 */
	public static function can_publish(): bool {
		$account = self::account();

		return ! empty( $account['can_publish'] );
	}

	/**
	 * Forget the tokens (but keep the client registration, so reconnecting does
	 * not have to register a second client for the same store).
	 *
	 * @return void
	 */
	public static function forget_tokens(): void {
		delete_option( self::TOKEN_OPTION );
		delete_option( self::ACCOUNT_OPTION );
	}

	/**
	 * Forget everything. Used on disconnect and on uninstall.
	 *
	 * @return void
	 */
	public static function forget_all(): void {
		delete_option( self::CLIENT_OPTION );
		delete_option( self::TOKEN_OPTION );
		delete_option( self::ACCOUNT_OPTION );
	}
}
