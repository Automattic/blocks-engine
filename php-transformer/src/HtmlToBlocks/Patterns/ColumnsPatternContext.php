<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Closure;
use DOMElement;

final class ColumnsPatternContext
{
    /**
     * @param Closure(DOMElement): string $structuralStyle Static author rules
     *        plus the inline style: the structural projection the columns
     *        guards read for the container itself.
     */
    public function __construct(
        private readonly Closure $structuralStyle,
        private readonly StyleResolver $styleResolver
    ) {
    }

    public function structuralStyle(DOMElement $element): string { return ($this->structuralStyle)($element); }

    public function isSingleRowAtMobile(DOMElement $element): bool
    {
        return $this->styleResolver->isSingleFlexRowAtMobile($element);
    }

    public function referenceViewportStyle(DOMElement $element): string
    {
        return $this->styleResolver->controlSurfaceResolvedStyle($element);
    }
}
