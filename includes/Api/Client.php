<?php
/**
 * The American Gun Trader API client.
 *
 * @package AgtSync
 */

namespace AgtSync\Api;

use AgtSync\Auth\Credentials;
use AgtSync\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Every call to American Gun Trader goes through here.
 *
 * Uses wp_remote_* (never cURL directly), refreshes the access token when it has
 * expired, honours the server's rate limit, and turns a failure into an
 * ApiException the sync engine can reason about.
 */
final class Client {

	/**
	 * Per-request timeout, in seconds. Long enough for a multi-megabyte image
	 * upload on a slow host, short enough not to hold a cron worker forever.
	 */
	private const TIMEOUT = 30;

	/**
	 * A lock so ten concurrent jobs do not all try to refresh the same rotating
	 * refresh token — the first would win and invalidate the other nine.
	 */
	private const REFRESH_LOCK = 'agt_sync_refreshing';

	/**
	 * GET.
	 *
	 * @param string               $path  API path, e.g. '/listings'.
	 * @param array<string,scalar> $query Query parameters.
	 * @return array<string,mixed>
	 * @throws ApiException On failure.
	 */
	public function get( string $path, array $query = array() ): array {
		return $this->request( 'GET', $path, array( 'query' => $query ) );
	}

	/**
	 * POST a JSON body.
	 *
	 * @param string               $path    API path.
	 * @param array<string,mixed>  $body    The payload.
	 * @param array<string,string> $headers Extra headers.
	 * @return array<string,mixed>
	 * @throws ApiException On failure.
	 */
	public function post( string $path, array $body = array(), array $headers = array() ): array {
		return $this->request(
			'POST',
			$path,
			array(
				'json'    => $body,
				'headers' => $headers,
			)
		);
	}

	/**
	 * PATCH a JSON body.
	 *
	 * @param string              $path API path.
	 * @param array<string,mixed> $body The payload.
	 * @return array<string,mixed>
	 * @throws ApiException On failure.
	 */
	public function patch( string $path, array $body = array() ): array {
		return $this->request( 'PATCH', $path, array( 'json' => $body ) );
	}

	/**
	 * DELETE.
	 *
	 * @param string $path API path.
	 * @return array<string,mixed>
	 * @throws ApiException On failure.
	 */
	public function delete( string $path ): array {
		return $this->request( 'DELETE', $path );
	}

	/**
	 * POST a multipart body — the only way to send images.
	 *
	 * @param string                          $path    API path.
	 * @param array<string,mixed>             $fields  Scalar fields.
	 * @param array<int,array<string,string>> $files   Each: name, filename, path, type.
	 * @param array<string,string>            $headers Extra headers.
	 * @return array<string,mixed>
	 * @throws ApiException On failure.
	 */
	public function post_multipart( string $path, array $fields, array $files, array $headers = array() ): array {
		$boundary = wp_generate_password( 24, false );
		$body     = Multipart::build( $fields, $files, $boundary );

		$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

		return $this->request(
			'POST',
			$path,
			array(
				'raw_body' => $body,
				'headers'  => $headers,
			)
		);
	}

	/**
	 * Perform a request, refreshing the token first if it has expired.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   API path.
	 * @param array<string,mixed> $args   query|json|raw_body|headers.
	 * @return array<string,mixed>
	 * @throws ApiException On failure.
	 */
	private function request( string $method, string $path, array $args = array() ): array {
		if ( ! RateLimit::consume() ) {
			throw new ApiException(
				esc_html__( 'Local rate limit reached; the sync will continue shortly.', 'agt-sync-for-woocommerce' ),
				429,
				'local_rate_limit',
				array(),
				RateLimit::seconds_until_refill()
			);
		}

		if ( Credentials::access_token_expired() && Credentials::is_connected() ) {
			$this->refresh_access_token();
		}

		$response = $this->send( $method, $path, $args );

		// A 401 with a live refresh token means the access token was revoked or the
		// clock drifted. Refresh once and retry — but only once, or a genuinely dead
		// connection would loop.
		if ( 401 === $response['status'] && Credentials::is_connected() ) {
			$this->refresh_access_token();
			$response = $this->send( $method, $path, $args );
		}

		return $this->handle( $response );
	}

	/**
	 * Fire one HTTP request.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   API path.
	 * @param array<string,mixed> $args   query|json|raw_body|headers.
	 * @return array{status:int,body:string,headers:array<string,string>}
	 * @throws ApiException On a transport failure.
	 */
	private function send( string $method, string $path, array $args ): array {
		$url = $this->url( $path );

		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'strval', $args['query'] ), $url );
		}

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();

		$headers['Accept'] = 'application/json';

		$token = Credentials::access_token();

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$body = null;

		if ( isset( $args['raw_body'] ) ) {
			$body = $args['raw_body'];
		} elseif ( isset( $args['json'] ) ) {
			$headers['Content-Type'] = 'application/json';
			$body                    = wp_json_encode( $args['json'] );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'             => $method,
				'timeout'            => self::TIMEOUT,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => $headers,
				'body'               => $body,
				'user-agent'         => 'agt-sync-for-woocommerce/' . AGT_SYNC_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new ApiException( esc_html( $response->get_error_message() ), 0, 'transport_error' );
		}

		$raw_headers = wp_remote_retrieve_headers( $response );
		$header_map  = array();

		if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
			foreach ( (array) $raw_headers->getAll() as $key => $value ) {
				$header_map[ strtolower( (string) $key ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
			}
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $header_map,
		);
	}

	/**
	 * Turn a response into decoded data, or throw.
	 *
	 * @param array{status:int,body:string,headers:array<string,string>} $response The response.
	 * @return array<string,mixed>
	 * @throws ApiException When the status is not 2xx.
	 */
	private function handle( array $response ): array {
		$status = $response['status'];

		$decoded = json_decode( $response['body'], true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $status >= 200 && $status < 300 ) {
			return $decoded;
		}

		if ( 429 === $status ) {
			RateLimit::back_off();
		}

		$retry_after = isset( $response['headers']['retry-after'] )
			? (int) $response['headers']['retry-after']
			: 0;

		$message = '';

		if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			$message = $decoded['message'];
		} elseif ( isset( $decoded['error_description'] ) && is_string( $decoded['error_description'] ) ) {
			$message = $decoded['error_description'];
		} elseif ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			$message = $decoded['error'];
		} else {
			/* translators: %d: HTTP status code. */
			$message = sprintf( esc_html__( 'American Gun Trader returned an unexpected response (HTTP %d).', 'agt-sync-for-woocommerce' ), $status );
		}

		$error_code = isset( $decoded['error'] ) && is_string( $decoded['error'] ) ? $decoded['error'] : '';

		$errors = isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ? $decoded['errors'] : array();

		throw new ApiException( esc_html( $message ), $status, $error_code, $errors, $retry_after );
	}

	/**
	 * Swap the refresh token for a new pair.
	 *
	 * Single-flight: refresh tokens ROTATE, so if two background jobs refresh at
	 * once the second would present a token the first had already burned and the
	 * store would be disconnected. The loser of the lock waits for the winner and
	 * then just uses the new token.
	 *
	 * @return void
	 * @throws ApiException When the refresh fails.
	 */
	private function refresh_access_token(): void {
		$refresh = Credentials::refresh_token();

		if ( '' === $refresh ) {
			throw new ApiException(
				esc_html__( 'This store is not connected to American Gun Trader.', 'agt-sync-for-woocommerce' ),
				401,
				'not_connected'
			);
		}

		// Someone else is already refreshing. Wait briefly for them, then reuse
		// whatever they got rather than burning the rotating token ourselves.
		if ( get_transient( self::REFRESH_LOCK ) ) {
			for ( $i = 0; $i < 10; $i++ ) {
				usleep( 300000 ); // 0.3s.

				if ( ! get_transient( self::REFRESH_LOCK ) && ! Credentials::access_token_expired() ) {
					return;
				}
			}

			// The other refresh never finished. Fall through and try ourselves.
		}

		set_transient( self::REFRESH_LOCK, 1, 30 );

		try {
			$response = wp_remote_post(
				$this->oauth_url( '/token' ),
				array(
					'timeout'            => self::TIMEOUT,
					'redirection'        => 0,
					'reject_unsafe_urls' => true,
					'headers'            => array( 'Accept' => 'application/json' ),
					'body'               => array(
						'grant_type'    => 'refresh_token',
						'refresh_token' => $refresh,
						'client_id'     => Credentials::client_id(),
					),
					'user-agent'         => 'agt-sync-for-woocommerce/' . AGT_SYNC_VERSION . '; ' . home_url(),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new ApiException( esc_html( $response->get_error_message() ), 0, 'transport_error' );
			}

			$status  = (int) wp_remote_retrieve_response_code( $response );
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $status || ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
				// The refresh token is dead: revoked, expired, or the dealer's
				// subscription lapsed. Drop the tokens so the merchant is prompted to
				// reconnect rather than the plugin retrying forever against a wall.
				Credentials::forget_tokens();

				Logger::error( 'Could not refresh the American Gun Trader token; the store has been disconnected.' );

				throw new ApiException(
					esc_html__( 'Your American Gun Trader connection has expired. Please reconnect your store.', 'agt-sync-for-woocommerce' ),
					401,
					'refresh_failed'
				);
			}

			Credentials::save_tokens(
				(string) $decoded['access_token'],
				isset( $decoded['refresh_token'] ) ? (string) $decoded['refresh_token'] : $refresh,
				isset( $decoded['expires_in'] ) ? (int) $decoded['expires_in'] : 3600,
				isset( $decoded['scope'] ) ? (string) $decoded['scope'] : ''
			);
		} finally {
			delete_transient( self::REFRESH_LOCK );
		}
	}

	/**
	 * Absolute URL for a dealer API path.
	 *
	 * @param string $path API path.
	 * @return string
	 */
	private function url( string $path ): string {
		return rtrim( AGT_SYNC_API_BASE, '/' ) . '/api/v1/dealer/' . ltrim( $path, '/' );
	}

	/**
	 * Absolute URL for an OAuth endpoint.
	 *
	 * @param string $path OAuth path.
	 * @return string
	 */
	private function oauth_url( string $path ): string {
		return rtrim( AGT_SYNC_API_BASE, '/' ) . '/oauth/dealer/' . ltrim( $path, '/' );
	}
}
