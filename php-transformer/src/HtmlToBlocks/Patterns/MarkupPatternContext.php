<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

final class MarkupPatternContext
{
    /**
     * @param Closure(DOMElement): string $safeFallbackHtml
     */
    public function __construct(
        private readonly Closure $safeFallbackHtml,
        private readonly Runtime $runtime
    ) {
    }

    public function safeFallbackHtml(DOMElement $element): string { return ($this->safeFallbackHtml)($element); }
    public function escapeHtml(string $text): string { return $this->runtime->escapeHtml($text); }
}
