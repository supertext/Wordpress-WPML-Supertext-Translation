<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Wpml\PluginHealth\KeyCheck;
use Supertext\Wpml\Admin\Settings;
use Supertext\Wpml\Reporting\Commando;
use Supertext\Wpml\Reporting\Reporting;
use Supertext\Wpml\Sdk\Http\HttpRequest;
use Supertext\Wpml\Sdk\Http\HttpResponse;
use Supertext\Wpml\Sdk\Http\HttpTransport;
use Supertext\Wpml\Tests\TestCase;

/**
 * WordPress side of the Commando Center: options, ping, messages, once-per-request.
 */
class CommandoTest extends TestCase {
	/** @var array<string, mixed> */
	private $options = array();

	/** @var array<int, HttpRequest> */
	public $requests = array();

	protected function setUp(): void {
		parent::setUp();
		putenv( 'SUPERTEXT_REPORT_URL=http://127.0.0.1:9/report' );
		Commando::reset();
		$this->options = array(
			'supertext_wpml_ai' => array(
				'api_key'  => 'the-key',
				'endpoint' => '',
			),
		);
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://www.example.ch/' );
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://www.example.ch/wp-json/' . $path );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'add_action' )->justReturn( true );

		$test                = $this;
		Commando::$transport = new class( $test ) implements HttpTransport {
			private $test;

			public function __construct( $test ) {
				$this->test = $test;
			}

			public function send( HttpRequest $request ): HttpResponse {
				$this->test->requests[] = $request;
				return str_contains( $request->url, '/report' )
					? new HttpResponse( 202, '{"ok":true}', array() )
					: new HttpResponse( 200, '[]', array() );
			}
		};
	}

	protected function tearDown(): void {
		Commando::$transport = null;
		Commando::reset();
		putenv( 'SUPERTEXT_REPORT_URL' );
		parent::tearDown();
	}

	public function test_after_settings_saved_checks_the_stored_key_and_stores_the_state(): void {
		$check = Commando::after_settings_saved();

		$this->assertSame( KeyCheck::OK, $check->status );
		$this->assertStringStartsWith( 'https://api.supertext.com/v1/features', $this->requests[0]->url );
		$this->assertSame( 'http://127.0.0.1:9/report', $this->requests[1]->url );
		$this->assertIsString( $this->options[ Commando::STATE_OPTION ] );
		$this->assertStringNotContainsString( 'the-key', $this->options[ Commando::STATE_OPTION ] );
		$this->assertSame( KeyCheck::OK, Commando::last_check()->status );

		$this->assertNull( Commando::after_settings_saved(), 'once per request' );
	}

	public function test_the_key_being_saved_and_its_environment_are_used(): void {
		Commando::after_settings_saved( 'new-key', 'https://api.staging.supertext.com/v1/' );

		$this->assertStringStartsWith( 'https://api.staging.supertext.com/v1/features', $this->requests[0]->url );
		$this->assertSame( 'Supertext-Auth-Key new-key', $this->requests[0]->headers['Authorization'] );
	}

	public function test_version_reports_off_sends_no_report(): void {
		$this->options['supertext_wpml_settings'] = array( 'version_reports' => false );

		Commando::after_settings_saved();

		$this->assertCount( 1, $this->requests, 'only the key check' );
	}

	public function test_ping_answers_with_the_sdk_response(): void {
		$request = new class() {
			public function get_param( $name ) {
				return 'challenge' === $name ? 'abcdefghijklmnop' : null;
			}
		};
		$response = Commando::handle_ping( $request );

		$this->assertSame( 200, $response->status );
		$this->assertSame( array( 'ok' => true, 'plugin' => 'supertext-wordpress-wpml', 'challenge' => 'abcdefghijklmnop' ), $response->data );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );

		$this->options['supertext_wpml_settings'] = array( 'version_reports' => false );
		$this->assertSame( 404, Commando::handle_ping( $request )->status );
	}

	public function test_messages_carry_both_links_for_invalid_and_missing(): void {
		foreach ( array( KeyCheck::INVALID, KeyCheck::MISSING ) as $status ) {
			$html = Commando::message( new KeyCheck( $status, '2026-10-14T08:00:00Z' ), true );
			$this->assertSame( 'error', $html['type'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/person/en/account/signin" target="_blank" rel="noopener"', $html['text'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/en/integrations/api" target="_blank" rel="noopener"', $html['text'] );

			$plain = Commando::message( new KeyCheck( $status, '2026-10-14T08:00:00Z' ), false );
			$this->assertStringNotContainsString( '<a', $plain['text'] );
			$this->assertStringContainsString( 'https://www.supertext.com/en/integrations/api', $plain['text'] );
		}
		$this->assertSame( 'success', Commando::message( new KeyCheck( KeyCheck::OK, '2026-10-14T08:00:00Z' ), true )['type'] );
		$error = Commando::message( new KeyCheck( KeyCheck::ERROR, '2026-10-14T08:00:00Z', 500 ), true );
		$this->assertStringContainsString( '(500)', $error['text'] );
	}

	public function test_saving_the_ai_settings_checks_the_key(): void {
		\Supertext\Wpml\Machine_Translation\Ai_Settings::after_save( array(), array( 'api_key' => 'b' ) );
		$this->assertNotEmpty( $this->requests, 'the key check and the report run when the AI settings are saved' );
		$this->assertNotNull( Commando::last_check() );
	}

	public function test_authentication_failure_wins_over_on_request(): void {
		Commando::after_settings_saved();
		Commando::reset();
		$this->requests = array();

		Commando::authentication_failed( 401 );
		Commando::on_request();
		Commando::run_deferred();

		$this->assertSame( KeyCheck::INVALID, Commando::last_check()->status );
		$this->assertCount( 1, $this->requests, 'the api_key_check report, no request to Supertext' );
	}

	public function test_professional_orders_credentials_are_checked_and_reported_by_name(): void {
		$this->options['supertext_wpml_settings'] = array(
			'human_email'   => 'shop@example.ch',
			'human_api_key' => 'order-key',
		);

		$checks = Commando::test_connection();

		$this->assertSame( array( Reporting::KEY_AI, Reporting::KEY_ORDERS ), array_keys( $checks ) );
		$this->assertSame( KeyCheck::OK, $checks[ Reporting::KEY_ORDERS ]->status );
		$this->assertSame( 'https://www.supertext.com/api/v1/util/ordertype/' . Settings::service_id(), $this->requests[1]->url );
		$this->assertSame( KeyCheck::OK, Commando::last_check( Reporting::KEY_ORDERS )->status );
		$this->assertCount( 2, Commando::last_checks() );
	}

	public function test_orders_messages_point_to_the_account_settings(): void {
		foreach ( array( KeyCheck::INVALID, KeyCheck::MISSING ) as $status ) {
			$html = Commando::message( new KeyCheck( $status, '2026-10-14T08:00:00Z' ), true, Reporting::KEY_ORDERS );
			$this->assertSame( 'error', $html['type'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/services/customer/accountsettings" target="_blank" rel="noopener"', $html['text'] );
			$this->assertStringContainsString( 'href="https://www.supertext.com/person/en/account/signin" target="_blank" rel="noopener"', $html['text'] );
			$this->assertStringContainsString( 'Order API Key', $html['text'] );
		}
		$ok = Commando::message( new KeyCheck( KeyCheck::OK, '2026-10-14T08:00:00Z' ), true, Reporting::KEY_ORDERS );
		$this->assertSame( 'success', $ok['type'] );
	}

	public function test_installed_extensions_lists_plugins_and_themes_without_paths(): void {
		$theme = new class() {
			private $data;
			public function __construct( array $data = array( 'Name' => 'Twenty Twenty-Five', 'Version' => '1.2' ) ) {
				$this->data = $data;
			}
			public function get( $key ) {
				return $this->data[ $key ] ?? '';
			}
		};
		Functions\when( 'get_plugins' )->justReturn(
			array(
				'sitepress-multilingual-cms/sitepress.php' => array( 'Name' => 'WPML Multilingual CMS', 'Version' => '4.8.1' ),
				'akismet/akismet.php'       => array( 'Name' => 'Akismet Anti-spam', 'Version' => '5.3' ),
			)
		);
		Functions\when( 'is_plugin_active' )->alias( static fn( $file ) => 'sitepress-multilingual-cms/sitepress.php' === $file );
		Functions\when( 'get_mu_plugins' )->justReturn( array( 'host.php' => array( 'Name' => '', 'Version' => '' ) ) );
		Functions\when( 'get_dropins' )->justReturn( array() );
		Functions\when( 'wp_get_themes' )->justReturn( array( 'twentytwentyfive' => $theme ) );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfive' );
		Functions\when( 'get_template' )->justReturn( 'twentytwentyfive' );

		$this->assertSame(
			array(
				array( 'name' => 'WPML Multilingual CMS', 'version' => '4.8.1', 'active' => true, 'type' => 'plugin' ),
				array( 'name' => 'Akismet Anti-spam', 'version' => '5.3', 'active' => false, 'type' => 'plugin' ),
				array( 'name' => 'host.php', 'version' => '', 'active' => true, 'type' => 'mu-plugin' ),
				array( 'name' => 'Twenty Twenty-Five', 'version' => '1.2', 'active' => true, 'type' => 'theme' ),
			),
			Commando::installed_extensions()
		);

		// Without WPML: the plugins only, no languages.
		$this->assertSame( array( 'status' => 'sent', 'count' => 4, 'languages' => 0 ), Commando::send_extensions() );
		$report = json_decode( (string) end( $this->requests )->body, true );
		$this->assertSame( 'extensions', $report['event'] );
		$this->assertCount( 4, $report['extensions'] );
		$this->assertSame( array(), $report['languages'] );

		$this->options['supertext_wpml_settings'] = array( 'version_reports' => false );
		$this->requests                               = array();
		$this->assertSame( array( 'status' => 'off', 'count' => 0, 'languages' => 0 ), Commando::send_extensions() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_language_mapping_sends_each_locale_with_its_supertext_code(): void {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			define( 'ICL_SITEPRESS_VERSION', '4.8.1' );
		}
		add_filter( 'wpml_active_languages', '__return_null' );
		$lang = static fn( string $code, string $locale ) => array( 'code' => $code, 'native_name' => $code, 'default_locale' => $locale );
		$list = array(
			'de'     => $lang( 'de', 'de_CH' ),
			'fr'     => $lang( 'fr', 'fr_FR' ),
			'ie'     => $lang( 'ie', 'en_IE' ),
			'formal' => $lang( 'formal', 'de_DE_formal' ),
		);
		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value = null ) => 'wpml_active_languages' === $tag ? $list : $value );
		\Supertext\Wpml\Wpml\Wpml::reset();
		// The administrator mapped fr to fr-CH; en_IE has no mapping and no default.
		$this->options['supertext_wpml_ai']['languages'] = array( 'fr' => 'fr-CH' );

		$this->assertSame(
			array(
				array( 'code' => 'de_CH', 'supertext' => 'de-CH' ),
				array( 'code' => 'fr_FR', 'supertext' => 'fr-CH' ),
				array( 'code' => 'en_IE', 'supertext' => null ),
				array( 'code' => 'de_DE_formal', 'supertext' => 'de-DE' ),
			),
			Commando::language_mapping()
		);

		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'get_mu_plugins' )->justReturn( array() );
		Functions\when( 'get_dropins' )->justReturn( array() );
		Functions\when( 'wp_get_themes' )->justReturn( array() );
		Functions\when( 'get_stylesheet' )->justReturn( '' );
		Functions\when( 'get_template' )->justReturn( '' );
		$this->assertSame( array( 'status' => 'sent', 'count' => 0, 'languages' => 4 ), Commando::send_extensions() );
		$report = json_decode( (string) end( $this->requests )->body, true );
		$this->assertSame( 'extensions', $report['event'] );
		$this->assertSame( array( 'code' => 'en_IE', 'supertext' => null ), $report['languages'][2] );
	}

	public function test_extensions_messages(): void {
		$this->assertSame( array( 'type' => 'success', 'text' => 'Sent: 12 plugins and themes, 3 languages.' ), Commando::extensions_message( 'sent', 12, 3 ) );
		$this->assertSame( 'warning', Commando::extensions_message( 'off', 0 )['type'] );
		$this->assertStringContainsString( 'Send version information to Supertext', Commando::extensions_message( 'off', 0 )['text'] );
		$this->assertSame( 'error', Commando::extensions_message( 'failed', 0 )['type'] );
	}
}
