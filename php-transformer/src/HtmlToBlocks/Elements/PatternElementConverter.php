<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;
use InvalidArgumentException;

/** Offers elements to a bounded set of pattern recognizers. */
final class PatternElementConverter implements ElementConverter
{
    /** @param array<int, class-string> $patterns */
    public function __construct(
        private readonly PatternElementContext $context,
        private readonly array $patterns
    ) {
        if ( array() === $patterns ) {
            throw new InvalidArgumentException('PatternElementConverter requires at least one pattern.');
        }
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        $block = $this->context->recognizePatterns($element, $fallbacks, $this->patterns);

        return null === $block
            ? ConversionOutcome::unhandled()
            : ConversionOutcome::handled($block);
    }
}
