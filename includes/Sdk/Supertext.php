<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk;

/**
 * Version-neutral facts about this SDK. Everything that talks to the API lives in a versioned namespace:
 * Supertext\Wpml\Sdk\V1 (a later API version gets Supertext\Wpml\Sdk\V2 next to it).
 */
final class Supertext
{
    /** This SDK's version (plugins pass it to Supertext Plugin Health as sdkVersion). The same number in every Supertext SDK. */
    public const SDK_VERSION = '0.5.0';

    private function __construct()
    {
    }
}
