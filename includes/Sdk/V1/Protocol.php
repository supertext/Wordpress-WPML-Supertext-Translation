<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\V1;

/**
 * Constants and small protocol helpers of the Supertext AI file translation API v1, shared by the client
 * and integrations.
 */
final class Protocol
{
    public const LIVE_BASE_URL = 'https://api.supertext.com/v1/';

    /** Characters per document, safely below the API's limit of 1,000,000. */
    public const DEFAULT_MAX_CHARACTERS = 900000;

    /** Retries after HTTP 429: the API limits requests per second per key. */
    public const DEFAULT_MAX_RETRIES = 4;

    /** Longest wait before a retry, whether from Retry-After or the backoff. */
    private const MAX_DELAY_MS = 30000;

    private function __construct()
    {
    }

    /**
     * Value of the Authorization header for a key as an administrator pasted it.
     *
     * Supertext shows the key as "Supertext-Auth-Key <key>", so people paste it with or without the
     * prefix; exactly one prefix must be sent (none: HTTP 400, two: HTTP 401).
     *
     * @throws SupertextException missing_api_key for a blank key
     */
    public static function authHeader(#[\SensitiveParameter] string $apiKey): string
    {
        $key = trim((string) preg_replace('/^\s*Supertext-Auth-Key(?:\s+|$)/i', '', $apiKey));
        if ($key === '') {
            throw new SupertextException(SupertextException::MISSING_API_KEY);
        }

        return 'Supertext-Auth-Key ' . $key;
    }

    /**
     * SHA-256 (lower-case hex) of the key as authHeader() would send it; '' for a blank key.
     *
     * Plugins store it next to the key check to notice a changed key (e.g. one from the environment).
     * It is kept locally and never sent.
     */
    public static function apiKeyFingerprint(#[\SensitiveParameter] ?string $apiKey): string
    {
        $key = trim((string) preg_replace('/^\s*Supertext-Auth-Key(?:\s+|$)/i', '', $apiKey ?? ''));

        return $key === '' ? '' : hash('sha256', $key);
    }

    /**
     * Milliseconds to wait before retrying an HTTP 429.
     *
     * A Retry-After in seconds wins; otherwise 1, 2, 4, 8, 16 … s plus up to 249 ms jitter, so parallel
     * workers don't retry in lockstep. Either way at most 30 s. HTTP dates and garbage fall back to the backoff.
     *
     * @param int                   $attempt    0-based retry number
     * @param string|null           $retryAfter the Retry-After header, if any
     * @param (callable(int, int): int)|null $random random_int-like, for tests
     */
    public static function retryDelayMs(int $attempt, ?string $retryAfter = null, ?callable $random = null): int
    {
        $retryAfter = $retryAfter === null ? '' : trim($retryAfter, " \t\n\r\f");
        if (preg_match('/^[0-9]+(\.[0-9]+)?$/', $retryAfter) === 1) {
            $delay = (float) $retryAfter * 1000;
        } else {
            $jitter = $random !== null ? $random(0, 249) : random_int(0, 249);
            // Exponent capped so huge attempt numbers can't overflow; the result is capped anyway.
            $delay = 1000 * (2 ** min(max(0, $attempt), 15)) + $jitter;
        }

        return (int) min(self::MAX_DELAY_MS, round($delay));
    }
}
