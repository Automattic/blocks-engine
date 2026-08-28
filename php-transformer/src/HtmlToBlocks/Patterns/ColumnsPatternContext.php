<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class ColumnsPatternContext
{
    /** @param Closure(DOMElement): string $structuralStyle */
    public function __construct(private readonly Closure $structuralStyle)
    {
    }

    public function structuralStyle(DOMElement $element): string { return ($this->structuralStyle)($element); }
}
