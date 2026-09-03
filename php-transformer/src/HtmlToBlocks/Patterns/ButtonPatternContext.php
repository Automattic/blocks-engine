<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization;
use Closure;
use DOMElement;

final class ButtonPatternContext
{
    /**
     * @param Closure(DOMElement): ?array<string, mixed> $fileBlockFromAnchor
     * @param Closure(DOMElement): string $resolvedStyle
     * @param Closure(DOMElement, string): string $attribute
     * @param Closure(DOMElement): bool $isGridItem
     * @param Closure(DOMElement): PatternRecognitionResult $accessibleNameFallback
     */
    public function __construct(
        private readonly Closure $fileBlockFromAnchor,
        private readonly Closure $resolvedStyle,
        private readonly RichTextMaterialization $richTextMaterializer,
        private readonly Closure $attribute,
        private readonly Closure $isGridItem,
        private readonly Closure $accessibleNameFallback
    ) {
    }

    public function fileBlockFromAnchor(DOMElement $anchor): ?array { return ($this->fileBlockFromAnchor)($anchor); }
    public function resolvedStyle(DOMElement $element): string { return ($this->resolvedStyle)($element); }
    public function richText(DOMElement $element): string { return $this->richTextMaterializer->content($element); }
    public function materializeSvgImages(DOMElement $element, string $content): ?string { return $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content); }
    public function attribute(DOMElement $element, string $name): string { return ($this->attribute)($element, $name); }
    public function isGridItem(DOMElement $element): bool { return ($this->isGridItem)($element); }
    public function accessibleNameFallback(DOMElement $anchor): PatternRecognitionResult { return ($this->accessibleNameFallback)($anchor); }
}
