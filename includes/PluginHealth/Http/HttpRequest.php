<?php

declare(strict_types=1);

namespace Supertext\Wpml\PluginHealth\Http;

/**
 * One HTTP request, as handed to an HttpTransport.
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param float                 $timeout seconds for the whole request
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly float $timeout,
    ) {
    }
}
