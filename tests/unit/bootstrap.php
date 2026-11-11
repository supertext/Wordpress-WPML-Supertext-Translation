<?php
/**
 * PHPUnit bootstrap for the Supertext for WPML unit tests.
 *
 * Loads Composer (PHPUnit + Brain Monkey), defines the few constants the plugin
 * files expect, stubs WP_Error, and requires the plugin classes under test.
 *
 * @package Supertext_Wpml
 */

require __DIR__ . '/vendor/autoload.php';

// Let plugin files past their `defined( 'ABSPATH' ) || exit;` guard.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'SUPERTEXT_WPML_VERSION' ) ) {
	define( 'SUPERTEXT_WPML_VERSION', 'test' );
}
if ( ! defined( 'SUPERTEXT_WPML_FILE' ) ) {
	define( 'SUPERTEXT_WPML_FILE', __DIR__ . '/plugin.php' );
}

// Minimal WP_Post stub (tests only read ->ID and ->post_type).
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID        = 0;
		public $post_type = 'post';

		public function __construct( $id = 0, $type = 'post' ) {
			$this->ID        = $id;
			$this->post_type = $type;
		}
	}
}

// Minimal WP_Error stub (the plugin only uses these methods).
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function has_errors() {
			return '' !== $this->code;
		}

		public function add_data( $data, $code = '' ) {
			$this->data = $data;
		}
	}
}

// Minimal WP_REST_Response stub (the ping handler only sets data, status and headers).
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public $status;
		public $headers = array();

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}
	}
}

$root = dirname( __DIR__, 2 );

require_once $root . '/includes/Integrations/YooTheme/Layout.php';
require_once $root . '/includes/Human_Translation/Callback.php';
require_once $root . '/includes/Human_Translation/Client.php';
require_once $root . '/includes/Human_Translation/Writeback.php';
require_once $root . '/includes/Human_Translation/Services.php';
require_once $root . '/includes/Admin/Settings.php';
require_once $root . '/includes/Preview/Draft_Preview.php';
require_once $root . '/includes/Integrations/VibeBoost/Client.php';
// The plugin's other classes and the bundled Supertext SDK (includes/Sdk, namespace Supertext\Wpml\Sdk).
spl_autoload_register(
	static function ( string $class ) use ( $root ): void {
		$prefix = 'Supertext\\Wpml\\';
		if ( 0 === strpos( $class, $prefix ) ) {
			$file = $root . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);
require_once $root . '/includes/Machine_Translation/Language_Codes.php';

require_once __DIR__ . '/TestCase.php';
