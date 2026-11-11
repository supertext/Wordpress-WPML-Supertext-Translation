<?php

declare(strict_types=1);

namespace Supertext\Wpml\Sdk\V1;

/**
 * One text to translate: plain text (escaped on the way out, line breaks kept) or an HTML fragment.
 *
 * Send a whole paragraph, heading or field as one segment, with formatting and links as inline tags
 * (`<b>`, `<i>`, `<a href>`): Supertext translates every segment on its own, so splitting a sentence
 * at its formatting breaks the translation.
 */
final class Segment
{
    public function __construct(
        public readonly string $text,
        public readonly bool $html = false,
    ) {
    }

    public static function text(string $text): self
    {
        return new self($text, false);
    }

    public static function html(string $html): self
    {
        return new self($html, true);
    }

    /** Empty or only ASCII whitespace: not sent, comes back unchanged. */
    public function isBlank(): bool
    {
        return trim($this->text, " \t\n\r\f") === '';
    }
}
