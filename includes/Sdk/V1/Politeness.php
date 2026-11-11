<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\V1;

/**
 * Form of address in the translation, for languages that have one.
 */
final class Politeness
{
    /** Let Supertext decide (nothing is sent). */
    public const DEFAULT = 'default';

    /** Formal: Sie, vous, Lei. */
    public const MORE = 'more';

    /** Informal: du, tu. */
    public const LESS = 'less';

    public const ALL = [self::DEFAULT, self::MORE, self::LESS];

    private function __construct()
    {
    }
}
