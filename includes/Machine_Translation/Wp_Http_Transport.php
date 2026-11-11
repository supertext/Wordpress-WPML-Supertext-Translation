<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Sdk\Http\HttpRequest;
use Supertext\Wpml\Sdk\Http\HttpResponse;
use Supertext\Wpml\Sdk\Http\HttpTransport;
use Supertext\Wpml\Sdk\Http\TransportException;

/**
 * Sends the Supertext SDK's requests through the WordPress HTTP API
 * (`wp_remote_request()`), so WordPress' proxy settings and HTTP filters apply.
 *
 * Every HTTP answer, 4xx and 5xx included, goes back to the SDK; only a missing
 * answer (WP_Error) is an exception. The request headers pass through the
 * `supertext_wpml_auth_headers` filter, as before the SDK.
 *
 * @since 0.1.0
 */
class Wp_Http_Transport implements HttpTransport {
	/**
	 * The Supertext API key, passed to the headers filter.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key The Supertext API key (without prefix).
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Sends one request.
	 *
	 * @param HttpRequest $request The SDK's request.
	 * @return HttpResponse
	 * @throws TransportException When WordPress gets no answer.
	 */
	public function send( HttpRequest $request ): HttpResponse {
		/**
		 * Filters the headers sent to Supertext (Authorization, Accept, User-Agent, …).
		 *
		 * @param array<string, string> $headers Request headers.
		 * @param string                $api_key The API key.
		 */
		$headers = apply_filters( 'supertext_wpml_auth_headers', $request->headers, $this->api_key );

		$response = wp_remote_request(
			$request->url,
			array(
				'method'  => $request->method,
				'headers' => $headers,
				'body'    => $request->body,
				'timeout' => $request->timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->debug_log( sprintf( '%s %s -> transport error: %s', $request->method, $request->url, $response->get_error_message() ) );
			throw new TransportException( $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status >= 400 ) {
			$this->debug_log( sprintf( '%s %s -> HTTP %d | %s', $request->method, $request->url, $status, substr( $body, 0, 1000 ) ) );
		}

		$out = array();
		foreach ( (array) wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return new HttpResponse( $status, $body, $out );
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on (never the API key: the SDK keeps it out of URLs and bodies).
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-wpml] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
