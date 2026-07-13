<?php
/**
 * The multipart body builder.
 *
 * @package AgtSync
 */

declare(strict_types=1);

namespace AgtSync\Tests;

use AgtSync\Api\Multipart;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Hand-rolled because WordPress's HTTP API has no multipart encoder, which means
 * the header-injection guards are ours to get right.
 */
final class MultipartTest extends TestCase {

	/**
	 * Set up Brain Monkey and a WP_Filesystem double that reads real files.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Multipart::read() goes through $wp_filesystem. Give it one that just reads
		// the file, so tests can hand it real temp files without booting WordPress.
		$GLOBALS['wp_filesystem'] = new class() {
			public function get_contents( string $path ): string|false {
				return file_get_contents( $path );
			}
		};
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_filesystem'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Scalar fields are encoded as their own parts.
	 */
	public function test_it_encodes_fields(): void {
		$body = Multipart::build(
			array(
				'title' => 'Bolt Action Rifle',
				'price' => 1299.99,
			),
			array(),
			'BOUNDARY'
		);

		$this->assertStringContainsString( '--BOUNDARY', $body );
		$this->assertStringContainsString( 'name="title"', $body );
		$this->assertStringContainsString( 'Bolt Action Rifle', $body );
		$this->assertStringContainsString( 'name="price"', $body );
		$this->assertStringContainsString( '1299.99', $body );
		$this->assertStringEndsWith( "--BOUNDARY--\r\n", $body );
	}

	/**
	 * An array field is sent as name[], which is how PHP and Laravel expect a
	 * repeated field.
	 */
	public function test_it_encodes_array_fields(): void {
		$body = Multipart::build(
			array( 'applications' => array( 3, 7 ) ),
			array(),
			'BOUNDARY'
		);

		$this->assertStringContainsString( 'name="applications[]"', $body );
		$this->assertStringContainsString( '3', $body );
		$this->assertStringContainsString( '7', $body );
	}

	/**
	 * A file field name that carries more than one file is suffixed with [], or PHP
	 * parses only the last file and the server sees a scalar where it wants an
	 * array. This is the exact wire detail a mocked test misses and a real upload
	 * fails on ("The images must be an array."), so it is pinned here.
	 */
	public function test_repeated_file_field_names_get_the_array_suffix(): void {
		$one = tempnam( sys_get_temp_dir(), 'agt' );
		$two = tempnam( sys_get_temp_dir(), 'agt' );
		file_put_contents( $one, 'first' );
		file_put_contents( $two, 'second' );

		$body = Multipart::build(
			array(),
			array(
				array( 'name' => 'images', 'filename' => 'a.jpg', 'path' => $one, 'type' => 'image/jpeg' ),
				array( 'name' => 'images', 'filename' => 'b.jpg', 'path' => $two, 'type' => 'image/jpeg' ),
			),
			'B'
		);

		unlink( $one );
		unlink( $two );

		// Both files present, and both under the array name.
		$this->assertSame( 2, substr_count( $body, 'name="images[]"; filename=' ) );
		$this->assertStringNotContainsString( 'name="images";', $body );
	}

	/**
	 * A name already ending in [] is not double-suffixed. The Mapper sends
	 * `images[]` directly (so even a single file is an array to the server), and
	 * that must not become `images[][]`.
	 */
	public function test_a_name_already_ending_in_brackets_is_left_alone(): void {
		$file = tempnam( sys_get_temp_dir(), 'agt' );
		file_put_contents( $file, 'x' );

		$body = Multipart::build(
			array(),
			array(
				array( 'name' => 'images[]', 'filename' => 'a.jpg', 'path' => $file, 'type' => 'image/jpeg' ),
			),
			'B'
		);

		unlink( $file );

		$this->assertStringContainsString( 'name="images[]"; filename=', $body );
		$this->assertStringNotContainsString( 'images[][]', $body );
	}

	/**
	 * A booleans becomes 1/0, not "true"/"" — which is what a PHP backend reads
	 * as a boolean.
	 */
	public function test_it_encodes_booleans_as_one_and_zero(): void {
		$body = Multipart::build(
			array(
				'yes' => true,
				'no'  => false,
			),
			array(),
			'B'
		);

		$this->assertMatchesRegularExpression( '/name="yes"\r\n\r\n1\r\n/', $body );
		$this->assertMatchesRegularExpression( '/name="no"\r\n\r\n0\r\n/', $body );
	}

	/**
	 * A CRLF in a field name cannot inject its own headers into the body.
	 *
	 * This is the whole reason the sanitiser exists: a filename or a field name is
	 * attacker-controllable in the general case, and a newline in one would let it
	 * write arbitrary multipart headers.
	 */
	public function test_it_refuses_to_let_a_field_name_inject_headers(): void {
		$body = Multipart::build(
			array( "evil\r\nX-Injected: yes" => 'value' ),
			array(),
			'B'
		);

		$this->assertStringNotContainsString( 'X-Injected: yes' . "\r\n", $body );
		$this->assertStringContainsString( 'name="evilX-Injected: yes"', $body );
	}

	/**
	 * A quote in a field name cannot close the attribute early.
	 */
	public function test_it_strips_quotes_from_names(): void {
		$body = Multipart::build(
			array( 'a"b' => 'value' ),
			array(),
			'B'
		);

		$this->assertStringContainsString( 'name="ab"', $body );
	}

	/**
	 * A file that cannot be read is skipped rather than sent as an empty part —
	 * an empty image would be rejected by the API and look like a mystery.
	 */
	public function test_it_skips_an_unreadable_file(): void {
		$body = Multipart::build(
			array(),
			array(
				array(
					'name'     => 'images',
					'filename' => 'nope.jpg',
					'path'     => '/definitely/not/a/real/path.jpg',
					'type'     => 'image/jpeg',
				),
			),
			'B'
		);

		$this->assertStringNotContainsString( 'nope.jpg', $body );
	}
}
