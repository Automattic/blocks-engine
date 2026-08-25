<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class QuotePatternContext
{
    /**
     * @param Closure(DOMElement): string $citationFromElement
     * @param Closure(DOMElement, array<int, string>): string $innerHtmlWithoutTags
     * @param Closure(string): string $stripAllTags
     * @param Closure(string): bool $isInlineContentElement
     */
    public function __construct(
        private readonly Closure $citationFromElement,
        private readonly Closure $innerHtmlWithoutTags,
        private readonly Closure $stripAllTags,
        private readonly Closure $isInlineContentElement
    ) {
    }

    public function citationFromElement(DOMElement $element): string { return ($this->citationFromElement)($element); }
    /** @param array<int, string> $excludedTags */
    public function innerHtmlWithoutTags(DOMElement $element, array $excludedTags): string { return ($this->innerHtmlWithoutTags)($element, $excludedTags); }
    public function stripAllTags(string $html): string { return ($this->stripAllTags)($html); }
    public function isInlineContentElement(string $tagName): bool { return ($this->isInlineContentElement)($tagName); }
}
