<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use DOMElement;

use Closure;


final class QuotePatternContext
{
    /**
     * @param Closure(DOMElement): string $citationFromElement
     */
    public function __construct(
        private readonly Closure $citationFromElement
    ) {
    }

    public function citationFromElement(DOMElement $element): string { return ($this->citationFromElement)($element); }

}
