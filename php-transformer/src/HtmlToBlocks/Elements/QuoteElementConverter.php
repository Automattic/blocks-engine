<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use DOMElement;

/** Converts blockquotes through the quote pattern recognizer. */
final class QuoteElementConverter implements ElementConverter
{
    public function __construct(private readonly QuoteElementContext $context)
    {
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( 'blockquote' !== $tagName ) {
            return ConversionOutcome::unhandled();
        }

        return ConversionOutcome::handled($this->context->recognizeQuote($element, $fallbacks));
    }
}
