<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Closure;
use DOMElement;

final class MediaPatternContext
{
    /**
     * @param Closure(DOMElement): string $coverStyle
     * @param Closure(string): string $resolveImageUrl
     * @param Closure(DOMElement, array<int, string>): array<string, mixed> $mediaTextAttributes
     * @param Closure(DOMElement): array{width: ?float, height: ?float} $imageFixedPixelSize
     */
    public function __construct(
        private readonly Closure $coverStyle,
        private readonly Closure $resolveImageUrl,
        private readonly Closure $mediaTextAttributes,
        private readonly StyleResolver $styleResolver,
        private readonly Closure $imageFixedPixelSize
    ) {
    }

    public function coverStyle(DOMElement $element): string { return ($this->coverStyle)($element); }
    public function resolveImageUrl(string $url): string { return ($this->resolveImageUrl)($url); }
    /** @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function mediaTextAttributes(DOMElement $element, array $excludedGeometryProperties = array()): array { return ($this->mediaTextAttributes)($element, $excludedGeometryProperties); }
    public function mediaTextStyle(DOMElement $element): string { return $this->styleResolver->mediaTextPresentationStyle($element); }
    public function documentRootFontSize(DOMElement $element): ?float { return $this->styleResolver->documentRootFontSize($element); }
    /** @return array{width: ?float, height: ?float} */
    public function imageFixedPixelSize(DOMElement $image): array { return ($this->imageFixedPixelSize)($image); }
}
