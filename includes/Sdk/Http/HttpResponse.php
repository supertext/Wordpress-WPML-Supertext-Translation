<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\Http;

/**
 * An HTTP answer, whatever its status.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers header names in any case
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    /** Header value by case-insensitive name, null if absent. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
