<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class ColumnsPatternContext
{
    /**
     * @param Closure(DOMElement): string $structuralStyle Static author rules
     *        plus the inline style: the structural projection the columns
     *        guards read for the container itself.
     * @param (Closure(DOMElement): string)|null $referenceViewportStyle The
     *        resting cascade — inline, static AND media-conditional author
     *        rules that apply at the desktop reference viewport — used only to
     *        classify whether a child is laid out beside its siblings there.
     *        Never a presentation projection. Falls back to the structural
     *        style when the host supplies none.
     */
    public function __construct(
        private readonly Closure $structuralStyle,
        private readonly ?Closure $referenceViewportStyle = null
    ) {
    }

    public function structuralStyle(DOMElement $element): string { return ($this->structuralStyle)($element); }

    public function referenceViewportStyle(DOMElement $element): string
    {
        return null === $this->referenceViewportStyle
            ? ($this->structuralStyle)($element)
            : ($this->referenceViewportStyle)($element);
    }
}
