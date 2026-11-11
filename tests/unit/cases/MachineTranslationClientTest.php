<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Wpml\Machine_Translation\Client;
use Supertext\Wpml\Machine_Translation\Ai_Settings;
use Supertext\Wpml\Wpml\Language;
use Supertext\Wpml\Sdk\V1\SupertextException;
use Supertext\Wpml\Tests\TestCase;

/**
 * AI client: languages without a Supertext code are refused before anything is sent.
 */
class MachineTranslationClientTest extends TestCase {
	public function test_unmapped_language_is_refused_without_a_request(): void {
		$called = false;
		Functions\when( 'wp_remote_request' )->alias(
			function () use ( &$called ) {
				$called = true;
				return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
			}
		);

		$client = new Client( array( 'api_key' => 'k' ) );
		$result = $client->translate_strings( array( 'Hello' ), new Language( 'gsw', 'Schwiizerdütsch', 'gsw' ), new Language( 'en', 'English', 'en_US' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'supertext_unsupported_language', $result->get_error_code() );
		$this->assertStringContainsString( 'Schwiizerdütsch (gsw)', $result->get_error_message() );
		$this->assertStringContainsString( 'Language mapping', $result->get_error_message() );
		$this->assertFalse( $called, 'nothing is sent to Supertext' );
	}

	public function test_mapping_resolves_the_target(): void {
		$language = new Language( 'de', 'Deutsch (Sie)', 'de_DE_formal' );

		$this->assertSame( 'de-AT', Ai_Settings::resolve_target( $language, array( 'de' => 'de-AT' ) ) );
		$this->assertSame( 'de-DE', Ai_Settings::resolve_target( $language, array( 'de' => '' ) ), 'unmapped: the default of the locale without its variant' );
		$this->assertSame( 'de-CH', Ai_Settings::resolve_target( new Language( 'ch', 'Schweiz', 'de_CH' ), array() ) );
		$this->assertSame( 'pt-BR', Ai_Settings::resolve_target( new Language( 'pt-br', 'Português', 'pt_BR' ), array() ), 'WPML code with a region' );
		$this->assertSame( 'zh-Hans', Ai_Settings::resolve_target( new Language( 'zh-hans', '简体中文', 'zh_CN' ), array() ) );

		$this->expectException( SupertextException::class );
		Ai_Settings::resolve_target( new Language( 'ie', 'English (Ireland)', 'en_IE' ), array( 'ie' => '' ) );
	}

	public function test_saved_options_keep_only_supertext_codes(): void {
		Functions\when( 'sanitize_text_field' )->returnArg();
		$clean = Ai_Settings::sanitize(
			array(
				'api_key'    => '  Supertext-Auth-Key k ',
				'endpoint'   => 'https://evil.test/v1/',
				'languages'  => array(
					'de' => 'de_ch',
					'en' => 'en-US',
					'es' => 'es-ES',
					'xx' => array( 'nope' ),
					'ie' => '',
				),
				'politeness' => array(
					'de' => 'more',
					'en' => 'more',
					'es' => 'sometimes',
					'ie' => 'less',
				),
				'review'     => array(
					'de' => '1',
					'en' => '0',
					'es' => true,
					'ie' => array( 'x' ),
				),
			)
		);

		$this->assertSame( array( 'de' => 'de-CH', 'en' => 'en-US', 'es' => '', 'xx' => '', 'ie' => '' ), $clean['languages'] );
		$this->assertSame( array( 'de' => 'more', 'en' => '', 'es' => '', 'ie' => 'less' ), $clean['politeness'] );
		$this->assertSame( array( 'de' => true, 'en' => false, 'es' => true, 'ie' => false ), $clean['review'] );
		$this->assertSame( 'k', $clean['api_key'], 'a pasted Supertext-Auth-Key prefix is stripped' );
		$this->assertSame( '', $clean['endpoint'], 'only the known environments' );
		$this->assertSame( 'https://api.staging.supertext.com/v1/', Ai_Settings::sanitize( array( 'endpoint' => 'https://api.staging.supertext.com/v1/' ) )['endpoint'] );
	}

	/**
	 * A fake Supertext API on wp_remote_request: records every call, echoes the uploaded
	 * document as its translation.
	 *
	 * @return array<int, array{method: string, url: string, agent: string}> The calls (by reference).
	 */
	private function fake_api(): array {
		$this->calls = array();
		$uploaded    = '';
		Functions\when( 'wp_remote_retrieve_headers' )->justReturn( array() );
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( &$uploaded ) {
				$this->calls[] = array(
					'method' => (string) $args['method'],
					'url'    => (string) $url,
					'agent'  => (string) ( $args['headers']['User-Agent'] ?? '' ),
				);
				$body = '{}';
				if ( 'POST' === $args['method'] ) {
					preg_match( '#<!DOCTYPE.*</html>#si', (string) $args['body'], $m );
					$uploaded = $m[0] ?? '';
					$body     = '{"file_id":"f1"}';
				} elseif ( str_ends_with( (string) $url, '/status' ) ) {
					$body = '{"status":"done"}';
				} elseif ( str_ends_with( (string) $url, '/translation' ) ) {
					$body = $uploaded;
				}
				return array( 'response' => array( 'code' => 200 ), 'body' => $body );
			}
		);
		return $this->calls;
	}

	/** @var array<int, array{method: string, url: string, agent: string}> */
	private $calls = array();

	/**
	 * @param array<int, array{method: string, url: string, agent: string}> $calls
	 * @return array<string, list<string>> host => methods
	 */
	private static function by_host( array $calls ): array {
		$hosts = array();
		foreach ( $calls as $call ) {
			$hosts[ (string) parse_url( $call['url'], PHP_URL_HOST ) ][] = $call['method'];
		}
		return $hosts;
	}

	public function test_review_languages_go_through_the_review_service(): void {
		$this->fake_api();
		$client = new Client(
			array(
				'api_key'    => 'k',
				'endpoint'   => 'https://api.test/v1/',
				'review'     => array( 'de' => true, 'fr' => false ),
				'review_url' => 'https://review.test/v1/',
			)
		);
		$en = new Language( 'en', 'English', 'en_US' );

		$de = $client->translate_strings( array( 'Hello' ), new Language( 'de', 'Deutsch', 'de_CH' ), $en );
		$fr = $client->translate_strings( array( 'Hello' ), new Language( 'fr', 'Français', 'fr_CH' ), $en );
		$it = $client->translate_strings( array( 'Hello' ), new Language( 'it', 'Italiano', 'it_CH' ), $en );

		$this->assertSame( array( 'Hello' ), $de );
		$this->assertSame( array( 'Hello' ), $fr );
		$this->assertSame( array( 'Hello' ), $it );
		$hosts = self::by_host( $this->calls );
		$this->assertSame( array( 'POST', 'GET', 'GET', 'DELETE' ), $hosts['review.test'] ?? null, 'German: upload, status, download and delete through the review service' );
		$this->assertSame( array( 'POST', 'GET', 'GET', 'DELETE', 'POST', 'GET', 'GET', 'DELETE' ), $hosts['api.test'] ?? null, 'French (review off) and Italian (not set): directly to Supertext' );
		$this->assertStringStartsWith( 'https://review.test/v1/translate/ai/file', $this->calls[0]['url'] );
		$this->assertStringContainsString( 'supertext-wordpress-wpml/', $this->calls[0]['agent'], 'the plugin and its version in the User-Agent' );

		// The key check always goes directly to Supertext.
		$this->calls = array();
		$client->is_api_key_valid();
		$this->assertSame( array( 'api.test' ), array_keys( self::by_host( $this->calls ) ) );
	}

	public function test_empty_review_url_turns_review_off(): void {
		$this->fake_api();
		$de = new Language( 'de', 'Deutsch', 'de_CH' );
		foreach ( array( '', 'https://api.test/v1' ) as $review_url ) {
			$client = new Client(
				array(
					'api_key'    => 'k',
					'endpoint'   => 'https://api.test/v1/',
					'review'     => array( 'de' => true ),
					'review_url' => $review_url,
				)
			);
			$this->assertSame( 'https://api.test/v1/', $client->route_for( $de ), var_export( $review_url, true ) );
		}
		$client->translate_strings( array( 'Hello' ), $de );
		$this->assertSame( array( 'api.test' ), array_keys( self::by_host( $this->calls ) ) );
	}

	public function test_the_default_is_supertexts_review_service(): void {
		putenv( 'SUPERTEXT_REVIEW_URL' );
		$this->assertSame( Client::REVIEW_URL, Client::review_url() );
		$this->assertSame( 'https://localizationreview-production.up.railway.app/v1/', Client::REVIEW_URL );
		$client = new Client( array( 'api_key' => 'k', 'review' => array( 'de' => true ) ) );
		$this->assertSame( Client::REVIEW_URL, $client->route_for( new Language( 'de', 'Deutsch', 'de_CH' ) ) );
		$this->assertSame( Client::DEFAULT_ROUTE, $client->route_for( new Language( 'fr', 'Français', 'fr_CH' ) ), 'review off by default' );

		putenv( 'SUPERTEXT_REVIEW_URL=' );
		try {
			$this->assertSame( '', Client::review_url(), 'SUPERTEXT_REVIEW_URL="" turns review off' );
			putenv( 'SUPERTEXT_REVIEW_URL=http://localhost:3000/v1/' );
			$this->assertSame( 'http://localhost:3000/v1/', Client::review_url() );
		} finally {
			putenv( 'SUPERTEXT_REVIEW_URL' );
		}
	}

	public function test_new_sdk_errors_have_messages(): void {
		$feature = Client::to_wp_error( new SupertextException( SupertextException::UNSUPPORTED_FEATURE, feature: 'politeness' ) );
		$this->assertSame( 'supertext_unsupported_feature', $feature->get_error_code() );
		$this->assertStringContainsString( 'politeness', $feature->get_error_message() );

		$auth = Client::to_wp_error( new SupertextException( SupertextException::AUTHENTICATION_FAILURE, status: 401 ) );
		$this->assertStringContainsString( 'https://www.supertext.com/person/en/account/signin', $auth->get_error_message() );
		$this->assertStringContainsString( 'https://www.supertext.com/en/integrations/api', $auth->get_error_message() );
	}
}
