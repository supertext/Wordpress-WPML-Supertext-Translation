<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Wpml\Preview\Draft_Preview;
use Supertext\Wpml\Tests\TestCase;

class DraftPreviewTest extends TestCase {
	/**
	 * Stubs get_post_meta to serve a fixed meta map (key => value).
	 *
	 * @param array<string, mixed> $meta Meta values by key.
	 * @return void
	 */
	private function stubMeta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key, $single = false ) => $meta[ $key ] ?? ''
		);
	}

	/** Deterministic add_query_arg / home_url so preview_url is assertable. */
	private function stubUrlBuilders(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '/' ) => 'https://example.com' . $path );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				$sep = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';
				return $url . $sep . http_build_query( $args );
			}
		);
	}

	/** Parses the query string of a URL into an associative array. */
	private function queryOf( string $url ): array {
		$out = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $out );
		return $out;
	}

	// --- is_valid ------------------------------------------------------------

	public function test_is_valid_true_for_matching_enabled_unexpired_token(): void {
		$this->stubMeta(
			array(
				Draft_Preview::META_TOKEN   => 'secret-token',
				Draft_Preview::META_ENABLED => 1,
				Draft_Preview::META_EXPIRES => time() + 3600,
			)
		);

		$this->assertTrue( Draft_Preview::is_valid( 10, 'secret-token' ) );
	}

	public function test_is_valid_true_when_no_expiry_set(): void {
		$this->stubMeta(
			array(
				Draft_Preview::META_TOKEN   => 'secret-token',
				Draft_Preview::META_ENABLED => 1,
				Draft_Preview::META_EXPIRES => 0,
			)
		);

		$this->assertTrue( Draft_Preview::is_valid( 10, 'secret-token' ) );
	}

	public function test_is_valid_false_for_wrong_token(): void {
		$this->stubMeta(
			array(
				Draft_Preview::META_TOKEN   => 'secret-token',
				Draft_Preview::META_ENABLED => 1,
				Draft_Preview::META_EXPIRES => time() + 3600,
			)
		);

		$this->assertFalse( Draft_Preview::is_valid( 10, 'wrong-token' ) );
	}

	public function test_is_valid_false_when_no_token_stored(): void {
		$this->stubMeta( array() );

		$this->assertFalse( Draft_Preview::is_valid( 10, 'anything' ) );
	}

	public function test_is_valid_false_when_disabled(): void {
		$this->stubMeta(
			array(
				Draft_Preview::META_TOKEN   => 'secret-token',
				Draft_Preview::META_ENABLED => '',
				Draft_Preview::META_EXPIRES => time() + 3600,
			)
		);

		$this->assertFalse( Draft_Preview::is_valid( 10, 'secret-token' ) );
	}

	public function test_is_valid_false_when_expired(): void {
		$this->stubMeta(
			array(
				Draft_Preview::META_TOKEN   => 'secret-token',
				Draft_Preview::META_ENABLED => 1,
				Draft_Preview::META_EXPIRES => time() - 10,
			)
		);

		$this->assertFalse( Draft_Preview::is_valid( 10, 'secret-token' ) );
	}

	// --- preview_url ---------------------------------------------------------

	public function test_preview_url_uses_p_for_posts(): void {
		$this->stubUrlBuilders();

		$url    = Draft_Preview::preview_url( new \WP_Post( 123, 'post' ), 'tok' );
		$params = $this->queryOf( $url );

		$this->assertSame( '123', $params['p'] );
		$this->assertSame( 'tok', $params['st_preview'] );
		$this->assertArrayNotHasKey( 'page_id', $params );
	}

	public function test_preview_url_uses_page_id_for_pages(): void {
		$this->stubUrlBuilders();

		$url    = Draft_Preview::preview_url( new \WP_Post( 123, 'page' ), 'tok' );
		$params = $this->queryOf( $url );

		$this->assertSame( '123', $params['page_id'] );
		$this->assertSame( 'tok', $params['st_preview'] );
		$this->assertArrayNotHasKey( 'p', $params );
	}

	public function test_preview_url_empty_token_returns_empty_string(): void {
		$this->assertSame( '', Draft_Preview::preview_url( new \WP_Post( 123, 'post' ), '' ) );
	}
}
