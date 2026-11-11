<?php

declare(strict_types=1);

namespace Supertext\Wpml\PluginHealth;

/**
 * A KeyChecker from two callables, so a plugin needs no class of its own:
 *
 *     new CallableKeyChecker(
 *         static fn (string $key) => (new Client($key))->checkApiKey(),
 *         [Protocol::class, 'apiKeyFingerprint'],
 *     )
 */
final class CallableKeyChecker implements KeyChecker
{
    /** @var callable(string): mixed */
    private $check;

    /** @var callable(string): mixed */
    private $fingerprint;

    /**
     * @param callable(string): mixed $check       returns what KeyChecker::check() returns
     * @param callable(string): mixed $fingerprint returns the key's fingerprint as a string
     */
    public function __construct(callable $check, callable $fingerprint)
    {
        $this->check = $check;
        $this->fingerprint = $fingerprint;
    }

    /** Same as the constructor. */
    public static function fromCallables(callable $check, callable $fingerprint): self
    {
        return new self($check, $fingerprint);
    }

    /** @return KeyCheck|object|array<string, mixed> */
    public function check(#[\SensitiveParameter] string $apiKey): object|array
    {
        $result = ($this->check)($apiKey);
        if (\is_object($result) || \is_array($result)) {
            /** @var object|array<string, mixed> $result */
            return $result;
        }

        throw new \UnexpectedValueException('The key check returned ' . get_debug_type($result));
    }

    public function fingerprint(#[\SensitiveParameter] string $apiKey): string
    {
        $fingerprint = ($this->fingerprint)($apiKey);
        if (!\is_string($fingerprint)) {
            throw new \UnexpectedValueException('The key fingerprint is ' . get_debug_type($fingerprint) . ', not a string');
        }

        return $fingerprint;
    }
}
