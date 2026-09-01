<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class LogoPatternContext
{
    /**
     * @param Closure(DOMElement): string $richText
     * @param Closure(DOMElement, string): ?string $materializeSvgImages
     */
    public function __construct(
        private readonly Closure $richText,
        private readonly Closure $materializeSvgImages
    ) {
    }

    public function richText(DOMElement $element): string { return ($this->richText)($element); }
    public function materializeSvgImages(DOMElement $element, string $content): ?string { return ($this->materializeSvgImages)($element, $content); }
}
