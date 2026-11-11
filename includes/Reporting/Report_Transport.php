<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Reporting;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\PluginHealth\Http\HttpRequest;
use Supertext\Wpml\PluginHealth\Http\HttpResponse;
use Supertext\Wpml\PluginHealth\Http\HttpTransport;
use Supertext\Wpml\Sdk\Http\HttpRequest as SdkRequest;
use Supertext\Wpml\Sdk\Http\HttpTransport as SdkTransport;

/**
 * Sends Supertext Plugin Health's reports through the same transport as the
 * SDK's requests (WordPress' HTTP API, Machine_Translation\Wp_Http_Transport;
 * a fake in tests). The two libraries have the same HTTP shapes in their own
 * namespaces; this only converts between them. A missing answer stays an
 * exception, which Plugin Health records as a failed report.
 *
 * @since 0.1.0
 */
final class Report_Transport implements HttpTransport {
	/**
	 * Constructor.
	 *
	 * @param SdkTransport $transport The SDK transport that sends the request.
	 */
	public function __construct( private readonly SdkTransport $transport ) {
	}

	/**
	 * Sends one report.
	 *
	 * @param HttpRequest $request Plugin Health's request.
	 * @return HttpResponse
	 */
	public function send( HttpRequest $request ): HttpResponse {
		$response = $this->transport->send(
			new SdkRequest( $request->method, $request->url, $request->headers, $request->body, $request->timeout )
		);

		return new HttpResponse( $response->status, $response->body, $response->headers );
	}
}
