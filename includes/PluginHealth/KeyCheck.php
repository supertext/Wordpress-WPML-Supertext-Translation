<?php

declare(strict_types=1);

namespace Supertext\Wpml\PluginHealth;

/**
 * The result of the Supertext SDK's API key check, as the reporter stores and reports it. Same fields as
 * the SDK's ApiKeyCheck; KeyCheck::from() converts one (under whatever namespace the plugin bundles it).
 *
 * It never contains the key itself, only its fingerprint (kept locally to notice a changed key; never sent).
 */
final class KeyCheck implements \JsonSerializable
{
    /** 2xx: Supertext accepted the key. */
    public const OK = 'ok';
    /** 401/403: Supertext rejected the key. */
    public const INVALID = 'invalid';
    /** No key configured; nothing was sent. */
    public const MISSING = 'missing';
    /** No HTTP answer, or 5xx. */
    public const UNREACHABLE = 'unreachable';
    /** Any other answer, or a key checker that failed. */
    public const ERROR = 'error';

    /**
     * @param string      $status      ok, invalid, missing, unreachable or error (the SDK's statuses)
     * @param string      $checkedAt   UTC ISO 8601 without fractions, e.g. "2026-10-13T08:00:00Z"
     * @param int|null    $httpStatus  the HTTP status Supertext answered with; null without an answer
     * @param string|null $errorCode   Supertext's `error_code`; null if none
     * @param string      $fingerprint SHA-256 of the key (the SDK's apiKeyFingerprint()); '' for a blank key
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
     * A key check from a KeyCheck, any object with its public properties (e.g. the SDK's ApiKeyCheck), or
     * an array with its keys (a stored check); null if the value has no string status and checkedAt.
     */
    public static function from(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (\is_object($value)) {
            $value = get_object_vars($value); // public properties only
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
            \is_int($httpStatus) ? $httpStatus : (\is_float($httpStatus) && floor($httpStatus) === $httpStatus ? (int) $httpStatus : null),
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
