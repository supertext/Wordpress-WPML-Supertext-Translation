<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\Http;

/**
 * No HTTP answer (thrown by CurlTransport; the client reports it as `transport_error`).
 */
final class TransportException extends \RuntimeException
{
}
