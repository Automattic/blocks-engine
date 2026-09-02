<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

final class QuotePatternContext
{
    /**
     * @param Closure(DOMElement): string $citationFromElement
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Closure $citationFromElement,
        private readonly Runtime $runtime
    ) {
    }

    public function citationFromElement(DOMElement $element): string { return ($this->citationFromElement)($element); }
    public function stripAllTags(string $html): string { return $this->runtime->stripAllTags($html); }
    public function isInlineContentElement(string $tagName): bool { return $this->sourceElementClassifier->isInlineContentElement($tagName); }
}
