<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\V1;

/**
 * The result of the automatic API key check (Client::checkApiKey(), spec/api-key-check.json).
 *
 * Plugins run the check on every settings save, when a key from the environment changed and on
 * "Test connection", and show the result to administrators. It never contains the key itself, only its
 * fingerprint (kept locally to notice a changed key; never sent anywhere).
 */
final class ApiKeyCheck implements \JsonSerializable
{
    /** 2xx: Supertext accepted the key. */
    public const OK = 'ok';
    /** 401/403: Supertext rejected the key. */
    public const INVALID = 'invalid';
    /** No key configured; nothing was sent. */
    public const MISSING = 'missing';
    /** No HTTP answer, or 5xx. */
    public const UNREACHABLE = 'unreachable';
    /** Any other answer (e.g. 429 after the retries, 404). */
    public const ERROR = 'error';

    /** Seconds the check waits for Supertext (or the client's request timeout, if shorter). */
    public const TIMEOUT = 10;

    /** Longest error code kept from Supertext's answer. */
    private const MAX_ERROR_CODE = 100;

    /**
     * @param string      $status      one of the constants of this class
     * @param string      $checkedAt   UTC ISO 8601 without fractions, e.g. "2026-10-13T08:00:00Z"
     * @param int|null    $httpStatus  the HTTP status Supertext answered with; null without an answer
     * @param string|null $errorCode   the answer's JSON `error_code` (at most 100 characters); null if none
     * @param string      $fingerprint Protocol::apiKeyFingerprint() of the key; '' for a blank key
     */
    public function __construct(
        public readonly string $status,
        public readonly string $checkedAt,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        public readonly string $fingerprint = '',
    ) {
    }

    /**
     * The check for a failed request: status, HTTP status and error code from the exception.
     */
    public static function fromError(\Throwable $error, string $checkedAt, string $fingerprint): self
    {
        if (!$error instanceof SupertextException) {
            return new self(self::ERROR, $checkedAt, null, null, $fingerprint);
        }
        $httpStatus = $error->status;
        $errorCode = $error->apiErrorCode === null || $error->apiErrorCode === '' ? null : Utf8::truncate($error->apiErrorCode, self::MAX_ERROR_CODE);
        $status = match (true) {
            $error->errorCode === SupertextException::MISSING_API_KEY => self::MISSING,
            $httpStatus === 401, $httpStatus === 403 => self::INVALID,
            $httpStatus === null, $httpStatus >= 500 => self::UNREACHABLE,
            default => self::ERROR,
        };

        return new self($status, $checkedAt, $httpStatus, $errorCode, $fingerprint);
    }

    /**
     * A stored check (as from toArray() or json_decode()), or null if it isn't one.
     */
    public static function fromArray(mixed $value): ?self
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!\is_array($value) || !\is_string($value['status'] ?? null) || !\is_string($value['checkedAt'] ?? null)) {
            return null;
        }
        $httpStatus = $value['httpStatus'] ?? null;
        $errorCode = $value['errorCode'] ?? null;
        $fingerprint = $value['fingerprint'] ?? null;

        return new self(
            $value['status'],
            $value['checkedAt'],
            \is_int($httpStatus) ? $httpStatus : (\is_float($httpStatus) ? (int) $httpStatus : null),
            \is_string($errorCode) ? $errorCode : null,
            \is_string($fingerprint) ? $fingerprint : '',
        );
    }

    /**
     * The stored form: status, checkedAt, httpStatus, errorCode, fingerprint.
     *
     * @return array{status: string, checkedAt: string, httpStatus: int|null, errorCode: string|null, fingerprint: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'checkedAt' => $this->checkedAt,
            'httpStatus' => $this->httpStatus,
            'errorCode' => $this->errorCode,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @return array{status: string, checkedAt: string, httpStatus: int|null, errorCode: string|null, fingerprint: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** "2026-10-13T08:00:00Z" for a Unix time in seconds (fractions dropped). */
    public static function timestamp(float $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) floor($time));
    }
}
