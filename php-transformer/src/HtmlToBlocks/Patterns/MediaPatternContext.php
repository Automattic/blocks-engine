<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Closure;
use DOMElement;

final class MediaPatternContext
{
    /**
     * @param Closure(DOMElement): string $coverStyle
     * @param Closure(string): string $resolveImageUrl
     * @param Closure(DOMElement, array<int, string>): array<string, mixed> $mediaTextAttributes
     * @param Closure(DOMElement): string $mediaTextStyle
     */
    public function __construct(
        private readonly Closure $coverStyle,
        private readonly Closure $resolveImageUrl,
        private readonly Closure $mediaTextAttributes,
        private readonly Closure $mediaTextStyle
    ) {
    }

    public function coverStyle(DOMElement $element): string { return ($this->coverStyle)($element); }
    public function resolveImageUrl(string $url): string { return ($this->resolveImageUrl)($url); }
    /** @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function mediaTextAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array { return ($this->mediaTextAttributes)($element, $excludedGeometryProperties); }
    public function mediaTextStyle(DOMElement $element): string { return ($this->mediaTextStyle)($element); }
}
