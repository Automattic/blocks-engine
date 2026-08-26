<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class MarkupPatternContext
{
    /**
     * @param Closure(DOMElement): string $safeFallbackHtml
     * @param Closure(string): string $escapeHtml
     */
    public function __construct(
        private readonly Closure $safeFallbackHtml,
        private readonly Closure $escapeHtml
    ) {
    }

    public function safeFallbackHtml(DOMElement $element): string { return ($this->safeFallbackHtml)($element); }
    public function escapeHtml(string $text): string { return ($this->escapeHtml)($text); }
}
