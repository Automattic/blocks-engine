<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\QuotePattern;
use DOMElement;

/** Converts blockquotes through the quote pattern recognizer. */
final class QuoteElementConverter implements ElementConverter
{
    public function __construct(private readonly PatternTreeConverter $tree)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        if ( 'blockquote' !== $tagName ) {
            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->tree->patterns($element, $fallbacks, array( QuotePattern::class )));
    }
}
