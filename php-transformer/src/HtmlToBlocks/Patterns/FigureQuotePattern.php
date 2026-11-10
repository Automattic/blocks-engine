<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use DOMElement;

final class FigureQuotePattern implements PatternRecognizerInterface
{
    use PatternDomHelpersTrait;

    public function __construct(private readonly QuotePattern $quotes) {}

    public function recognize(DOMElement $element, PatternContext $context): ?PatternRecognitionResult
    {
        $blockquote = SourceDom::firstChildElement($element, 'blockquote');
        return $blockquote instanceof DOMElement ? $this->quotes->matchFigureBlockquote($element, $blockquote, $context) : null;
    }
}
