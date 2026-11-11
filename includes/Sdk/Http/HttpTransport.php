<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\Http;

/**
 * Sends HTTP requests for the client. Implement it to use your framework's HTTP client
 * (Guzzle, Symfony HttpClient, a CMS's own) or a fake in tests.
 *
 * Return every HTTP answer, including 4xx and 5xx, as an HttpResponse. Throw (any exception) only when
 * there is no answer at all: DNS, TLS, connection refused, timeout. The client turns that into
 * SupertextException `transport_error`.
 */
interface HttpTransport
{
    public function send(HttpRequest $request): HttpResponse;
}
