<?php
/**
 * RFC 7636 PKCE (S256).
 *
 * @package AgtSync
 */

namespace AgtSync\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Proof Key for Code Exchange.
 *
 * The verifier is generated here, kept in a short-lived transient, and sent only
 * when the code is redeemed. An attacker who intercepts the authorization code —
 * from the browser's history, a referrer header, a shared machine — cannot
 * exchange it for a token without also having the verifier, which never travels
 * through the browser.
 */
final class Pkce {

	/**
	 * How long a pending authorization may sit before it is abandoned.
	 */
	private const TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * The transient holding the pending flow's verifier + state.
	 */
	private const TRANSIENT = 'agt_sync_pkce';

	/**
	 * Start a flow: generate a verifier + state, stash them, and hand back the
	 * challenge and state for the authorize URL.
	 *
	 * @return array{challenge:string,state:string}
	 */
	public static function begin(): array {
		$verifier = self::random( 64 );
		$state    = self::random( 32 );

		set_transient(
			self::TRANSIENT,
			array(
				'verifier' => $verifier,
				'state'    => $state,
			),
			self::TTL
		);

		return array(
			'challenge' => self::challenge( $verifier ),
			'state'     => $state,
		);
	}

	/**
	 * Finish a flow: check the returned state against the one we issued and hand
	 * back the verifier. Returns '' if the state does not match, which means the
	 * callback did not come from the flow this store started — a CSRF attempt, or
	 * a stale tab.
	 *
	 * The pending flow is consumed either way, so a state cannot be replayed.
	 *
	 * @param string $state The state returned on the callback.
	 * @return string The verifier, or '' if the state did not match.
	 */
	public static function complete( string $state ): string {
		$pending = get_transient( self::TRANSIENT );
		delete_transient( self::TRANSIENT );

		if ( ! is_array( $pending ) || ! isset( $pending['verifier'], $pending['state'] ) ) {
			return '';
		}

		if ( ! hash_equals( (string) $pending['state'], $state ) ) {
			return '';
		}

		return (string) $pending['verifier'];
	}

	/**
	 * The S256 challenge for a verifier: base64url(sha256(verifier)).
	 *
	 * @param string $verifier The code verifier.
	 * @return string
	 */
	public static function challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by RFC 7636; this is a hash encoding, not obfuscation.
	}

	/**
	 * A URL-safe random string of at least 43 characters, as RFC 7636 requires.
	 *
	 * @param int $bytes Bytes of entropy.
	 * @return string
	 */
	private static function random( int $bytes ): string {
		$raw = rtrim( strtr( base64_encode( random_bytes( max( 32, $bytes ) ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding random bytes, not obfuscating.

		return substr( $raw, 0, 128 );
	}
}
