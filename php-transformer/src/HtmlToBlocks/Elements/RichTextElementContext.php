<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see RichTextElementConverter}.
 *
 * Heading and paragraph conversion depends on the transformer's rich-text
 * materialization cluster (inline styles, inline SVG image objects, the
 * core/html fallback predicate) plus a small amount of layout inspection.
 * Enumerating those operations here is the boundary: the converter cannot
 * reach transformer state that is not on this list.
 */
final class RichTextElementContext
{
    /**
     * @param Closure(DOMElement): array<string, mixed>                                                      $htmlPreservationBlock
     * @param Closure(DOMElement): ?array<string, mixed>                                                     $authoredMarqueeBlock
     * @param Closure(DOMElement): bool                                                                      $hasEmptyVisualInlineChild
     * @param Closure(DOMElement): bool                                                                      $hasBoxChromeWrapperStyling
     * @param Closure(DOMElement): bool                                                                      $isRuntimeDomTarget
     * @param Closure(string): array<int, array<string, mixed>>                                              $convertText
     * @param Closure(DOMElement, array<int, array<string, mixed>>, bool): array<int, array<string, mixed>>   $convertChildren
     */
    public function __construct(
        private readonly ElementPresentationResolver $presentationResolver,
        private readonly SourceBlockCreator $createBlock,
        private readonly RichTextMaterialization $richTextMaterializer,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $authoredMarqueeBlock,
        private readonly Closure $hasEmptyVisualInlineChild,
        private readonly Closure $hasBoxChromeWrapperStyling,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $convertText,
        private readonly Runtime $runtime,
        private readonly Closure $convertChildren
    ) {
    }

    /**
     * @param array<int, string> $excludedProperties
     * @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedProperties = array(), array $excludedGeometryProperties = array()): array
    {
        return $this->presentationResolver->presentationAttributes($element, $excludedProperties, $excludedGeometryProperties);
    }

    /**
     * @param array<string, mixed>             $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        return $this->createBlock->createBlock($name, $attributes, $innerBlocks, $sourceElement);
    }

    /**
     * @param array<int, string> $excludedTags
     */
    public function richTextContent(DOMElement $element, array $excludedTags = array()): string
    {
        return $this->richTextMaterializer->content($element, $excludedTags);
    }

    public function headingRichTextContent(string $content): string
    {
        return $this->richTextMaterializer->headingContent($content);
    }

    public function richTextWithMaterializedSvgImages(DOMElement $element, string $content): ?string
    {
        return $this->richTextMaterializer->contentWithMaterializedSvgImages($element, $content);
    }

    public function requiresHtmlFallback(string $content): bool
    {
        return $this->richTextMaterializer->requiresHtmlFallbackWithoutNativeSvgImageObjects($content);
    }

    public function containsNativeSvgImageObject(string $content): bool
    {
        return $this->richTextMaterializer->containsNativeSvgImageObject($content);
    }

    /**
     * @return array<string, mixed>
     */
    public function htmlPreservationBlock(DOMElement $element): array
    {
        return ($this->htmlPreservationBlock)($element);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function authoredMarqueeBlock(DOMElement $element): ?array
    {
        return ($this->authoredMarqueeBlock)($element);
    }

    public function hasEmptyVisualInlineChild(DOMElement $element): bool
    {
        return ($this->hasEmptyVisualInlineChild)($element);
    }

    public function hasBoxChromeWrapperStyling(DOMElement $element): bool
    {
        return ($this->hasBoxChromeWrapperStyling)($element);
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return ($this->isRuntimeDomTarget)($element);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function convertText(string $text): array
    {
        return ($this->convertText)($text);
    }

    public function stripAllTags(string $html): string
    {
        return $this->runtime->stripAllTags($html);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    public function convertChildren(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): array
    {
        return ($this->convertChildren)($element, $fallbacks, $captureUnsupported);
    }
}
