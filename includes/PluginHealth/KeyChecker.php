<?php

declare(strict_types=1);

namespace Supertext\Wpml\PluginHealth;

/**
 * How the reporter checks the API key. Plugins use the bundled Supertext SDK: implement this interface,
 * or pass two callables to CallableKeyChecker:
 *
 *     new CallableKeyChecker(
 *         static fn (string $key) => (new Client($key))->checkApiKey(),
 *         [Protocol::class, 'apiKeyFingerprint'],
 *     )
 *
 * The reporter never talks to Supertext itself.
 */
interface KeyChecker
{
    /**
     * Checks the key against Supertext. May return a KeyCheck, any object with its public properties (the
     * SDK's ApiKeyCheck) or an array with its keys; see KeyCheck::from(). Anything else, or an exception,
     * becomes status `error`.
     *
     * @return KeyCheck|object|array<string, mixed>
     */
    public function check(#[\SensitiveParameter] string $apiKey): object|array;

    /** SHA-256 of the key (the SDK's apiKeyFingerprint()); '' for a blank key. */
    public function fingerprint(#[\SensitiveParameter] string $apiKey): string;
}
