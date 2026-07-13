<?php
/**
 * A multipart/form-data body builder.
 *
 * @package AgtSync
 */

namespace AgtSync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's HTTP API has no multipart encoder, and images cannot be sent any
 * other way, so build the body by hand.
 *
 * Deliberately reads each file with WP_Filesystem rather than file_get_contents,
 * and never trusts the caller's filename: a filename is attacker-controlled data
 * in the general case, and a CRLF in one would let it inject its own headers into
 * the body.
 */
final class Multipart {

	/**
	 * Build the body.
	 *
	 * @param array<string,mixed>             $fields   Scalar fields; arrays are sent as name[].
	 * @param array<int,array<string,string>> $files    Each: name, filename, path, type.
	 * @param string                          $boundary The boundary.
	 * @return string
	 */
	public static function build( array $fields, array $files, string $boundary ): string {
		$eol  = "\r\n";
		$body = '';

		foreach ( $fields as $name => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$body .= '--' . $boundary . $eol;
					$body .= 'Content-Disposition: form-data; name="' . self::sanitize( (string) $name ) . '[]"' . $eol . $eol;
					$body .= self::scalar( $item ) . $eol;
				}

				continue;
			}

			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . self::sanitize( (string) $name ) . '"' . $eol . $eol;
			$body .= self::scalar( $value ) . $eol;
		}

		// A field name that appears on more than one file must be sent as `name[]`, or
		// PHP parses only the last one and the server sees a single file where it
		// expects an array. The dealer API validates `images` as an array, so its ten
		// photos have to arrive as `images[]` — this is exactly the wire detail a
		// mocked unit test misses and a real upload does not.
		$name_counts = array();

		foreach ( $files as $file ) {
			$raw_name                 = (string) ( $file['name'] ?? 'file' );
			$name_counts[ $raw_name ] = ( $name_counts[ $raw_name ] ?? 0 ) + 1;
		}

		foreach ( $files as $file ) {
			$contents = self::read( (string) ( $file['path'] ?? '' ) );

			if ( '' === $contents ) {
				continue;
			}

			$raw_name = (string) ( $file['name'] ?? 'file' );
			$name     = self::sanitize( $raw_name );

			// Suffix [] when this name carries more than one file and does not already
			// have it — matching how PHP expects a repeated field.
			if ( ( $name_counts[ $raw_name ] ?? 0 ) > 1 && ! str_ends_with( $name, '[]' ) ) {
				$name .= '[]';
			}

			$filename = self::sanitize( (string) ( $file['filename'] ?? 'upload.jpg' ) );
			$type     = self::sanitize( (string) ( $file['type'] ?? 'application/octet-stream' ) );

			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $filename . '"' . $eol;
			$body .= 'Content-Type: ' . $type . $eol . $eol;
			$body .= $contents . $eol;
		}

		$body .= '--' . $boundary . '--' . $eol;

		return $body;
	}

	/**
	 * Read a file through WP_Filesystem.
	 *
	 * @param string $path Absolute path.
	 * @return string The contents, or '' if unreadable.
	 */
	private static function read( string $path ): string {
		global $wp_filesystem;

		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			return '';
		}

		$contents = $wp_filesystem->get_contents( $path );

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Strip anything that could break out of a header line.
	 *
	 * A CR or LF in a field name or filename would let the caller inject its own
	 * multipart headers, and a quote would end the attribute early.
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private static function sanitize( string $value ): string {
		return str_replace( array( "\r", "\n", '"' ), '', $value );
	}

	/**
	 * Render a scalar for the body.
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
