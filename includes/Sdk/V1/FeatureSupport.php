<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\V1;

/**
 * One language pair from `GET features` (Client::getFeatures()).
 */
final class FeatureSupport
{
    /**
     * @param list<string> $features e.g. "supports_politeness", "supports_glossary"
     */
    public function __construct(
        public readonly string $sourceLang,
        public readonly string $targetLang,
        public readonly array $features,
    ) {
    }
}
