<?php

declare(strict_types=1);

namespace Supertext\Wpml\PluginHealth;

/**
 * The answer of a plugin's public ping endpoint (InstallationReporter::pingResponse()). Send it as it is.
 */
final class PingResponse
{
    /**
     * @param int                   $status  200, 400 or 404
     * @param array<string, string> $headers Content-Type and Cache-Control
     * @param string                $body    JSON
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
