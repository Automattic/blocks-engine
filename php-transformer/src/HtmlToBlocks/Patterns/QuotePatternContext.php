<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class QuotePatternContext
{
    /**
     * @param Closure(DOMElement): string $citationFromElement
     * @param Closure(string): string $stripAllTags
     * @param Closure(string): bool $isInlineContentElement
     */
    public function __construct(
        private readonly Closure $citationFromElement,
        private readonly Closure $stripAllTags,
        private readonly Closure $isInlineContentElement
    ) {
    }

    public function citationFromElement(DOMElement $element): string { return ($this->citationFromElement)($element); }
    public function stripAllTags(string $html): string { return ($this->stripAllTags)($html); }
    public function isInlineContentElement(string $tagName): bool { return ($this->isInlineContentElement)($tagName); }
}
