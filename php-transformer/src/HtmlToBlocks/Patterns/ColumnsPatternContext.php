<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use DOMElement;

final class ColumnsPatternContext
{
    public function __construct(
        private readonly StyleResolver $styleResolver
    ) {
    }

    /**
     * Static author rules plus the inline style: the structural projection
     * the columns guards read for the container itself.
     */
    public function structuralStyle(DOMElement $element): string
    {
        return $this->styleResolver->cssDeclarationString($this->styleResolver->structuralPresentationDeclarations($element));
    }

    public function isSingleRowAtMobile(DOMElement $element): bool
    {
        return $this->styleResolver->isSingleFlexRowAtMobile($element);
    }

    public function referenceViewportStyle(DOMElement $element): string
    {
        return $this->styleResolver->controlSurfaceResolvedStyle($element);
    }
}
