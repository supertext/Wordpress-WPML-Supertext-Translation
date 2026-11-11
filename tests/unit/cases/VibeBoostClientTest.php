<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Wpml\Integrations\VibeBoost\Client;
use Supertext\Wpml\Tests\TestCase;

class VibeBoostClientTest extends TestCase {
	/**
	 * Stubs wp_remote_get to capture the request URL and return a canned response.
	 *
	 * @param int    $code         HTTP status.
	 * @param string $content_type Response content type.
	 * @param string $body         Response body.
	 * @return object Holds ->url and ->args after the call.
	 */
	private function stubResponse( int $code, string $content_type, string $body ) {
		$captured = new \stdClass();

		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) use ( $captured, $code, $content_type, $body ) {
				$captured->url  = $url;
				$captured->args = $args;
				return array(
					'response' => array( 'code' => $code ),
					'headers'  => array( 'content-type' => $content_type ),
					'body'     => $body,
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn( $r, $key ) => is_array( $r ) && isset( $r['headers'][ $key ] ) ? $r['headers'][ $key ] : ''
		);

		return $captured;
	}

	/** Parses the query string of a URL into an associative array. */
	private function queryOf( string $url ): array {
		$out = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $out );
		return $out;
	}

	/**
	 * The regression guard: the captured page URL (which itself has a query string
	 * ending in &st_preview=<token>) must survive intact inside the `url` parameter.
	 * A naive add_query_arg() left values un-encoded, which split the token off as a
	 * separate top-level parameter and lost it.
	 */
	public function test_capture_keeps_preview_token_inside_url_param(): void {
		$cap     = $this->stubResponse( 200, 'image/png', 'PNGDATA' );
		$preview = 'https://demo.example.com/wp-polylang-proxy/?p=267&st_preview=abc123-def456';

		$result = ( new Client() )->capture( $preview );

		$this->assertSame( 'PNGDATA', $result );

		$params = $this->queryOf( $cap->url );
		$this->assertArrayHasKey( 'url', $params );
		$this->assertSame( $preview, $params['url'], 'The full preview URL (with token) must round-trip as the url param.' );
		$this->assertArrayNotHasKey( 'st_preview', $params, 'The token must not leak out as a top-level parameter.' );
		$this->assertStringStartsWith( Client::ENDPOINT, $cap->url );
	}

	public function test_capture_sends_default_capture_options(): void {
		$cap = $this->stubResponse( 200, 'image/png', 'X' );

		( new Client() )->capture( 'https://x.example/' );

		$params = $this->queryOf( $cap->url );
		$this->assertSame( 'png', $params['format'] );
		$this->assertSame( '1280', $params['width'] );
		$this->assertSame( 'true', $params['fullPage'] );    // Whole page, not just the viewport.
		$this->assertSame( 'true', $params['hideCookies'] ); // Cookie/consent banner dismissed.
	}

	public function test_capture_options_override_defaults(): void {
		$cap = $this->stubResponse( 200, 'image/jpeg', 'X' );

		( new Client() )->capture( 'https://x.example/', array( 'format' => 'jpeg', 'width' => 800 ) );

		$params = $this->queryOf( $cap->url );
		$this->assertSame( 'jpeg', $params['format'] );
		$this->assertSame( '800', $params['width'] );
	}

	public function test_capture_returns_error_on_http_error(): void {
		$this->stubResponse( 500, 'text/plain', 'boom' );

		$result = ( new Client() )->capture( 'https://x.example/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'supertext_screenshot_http_error', $result->get_error_code() );
	}

	public function test_capture_returns_error_on_non_image_response(): void {
		$this->stubResponse( 200, 'text/html', '<html>not an image</html>' );

		$result = ( new Client() )->capture( 'https://x.example/' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'supertext_screenshot_bad_response', $result->get_error_code() );
	}

	public function test_capture_sends_bearer_auth_when_key_is_configured(): void {
		$cap = $this->stubResponse( 200, 'image/png', 'X' );

		( new Client( 'vibe_secret123' ) )->capture( 'https://x.example/' );

		$this->assertSame( 'Bearer vibe_secret123', $cap->args['headers']['Authorization'] ?? null );
	}

	public function test_capture_omits_auth_header_without_key(): void {
		$cap = $this->stubResponse( 200, 'image/png', 'X' );

		( new Client() )->capture( 'https://x.example/' );

		$this->assertArrayNotHasKey( 'Authorization', $cap->args['headers'] );
	}

	public function test_capture_returns_error_on_empty_url(): void {
		$result = ( new Client() )->capture( '   ' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'supertext_screenshot_no_url', $result->get_error_code() );
	}
}
