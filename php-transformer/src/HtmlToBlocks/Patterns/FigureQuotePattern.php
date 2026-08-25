<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

final class FigureQuotePattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    public function __construct(private readonly QuotePattern $quotes) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $blockquote = $this->firstChildElement($element, 'blockquote');
        return $blockquote instanceof DOMElement ? $this->quotes->matchFigureBlockquote($element, $blockquote, $context) : null;
    }
}
