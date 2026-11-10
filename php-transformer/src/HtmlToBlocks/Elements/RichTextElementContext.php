<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
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
     * @param Closure(DOMElement): ?array<string, mixed>                                                     $authoredMarqueeBlock
     * @param Closure(DOMElement): bool                                                                      $hasEmptyVisualInlineChild
     * @param Closure(DOMElement): bool                                                                      $hasBoxChromeWrapperStyling
     * @param Closure(DOMElement): bool                                                                      $isRuntimeDomTarget
     * @param Closure(DOMElement): ?array<string, mixed>                                                     $imageBlockFromParagraph
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                   $mixedMediaLinkGroupFromParagraph
     * @param Closure(DOMElement): ?array<string, mixed>                                                     $compactLinkedIconTextRowFromParagraph
     * @param Closure(string): array<int, array<string, mixed>>                                              $convertText
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                   $emptyInlineGeometryBlock
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                   $mediaBlockFromTextlessElement
     */
    public function __construct(
        private readonly Closure $authoredMarqueeBlock,
        private readonly Closure $hasEmptyVisualInlineChild,
        private readonly Closure $hasBoxChromeWrapperStyling,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $imageBlockFromParagraph,
        private readonly Closure $mixedMediaLinkGroupFromParagraph,
        private readonly Closure $compactLinkedIconTextRowFromParagraph,
        private readonly Closure $convertText,
        private readonly PatternTreeConverter $tree,
        private readonly ?Closure $emptyInlineGeometryBlock = null,
        private readonly ?Closure $nativeDisclosureBlock = null,
        private readonly ?Closure $decorativeSvgBlock = null,
        private readonly ?Closure $mediaBlockFromTextlessElement = null,
        private readonly ?Closure $shouldPreserveEmptySourceText = null
    ) {
    }

    /** @return array<string, mixed>|null */
    public function decorativeSvgBlock(DOMElement $element): ?array
    {
        return null === $this->decorativeSvgBlock ? null : ($this->decorativeSvgBlock)($element);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function authoredMarqueeBlock(DOMElement $element): ?array
    {
        return ($this->authoredMarqueeBlock)($element);
    }

    public function shouldPreserveEmptySourceText(DOMElement $element): bool
    {
        return null !== $this->shouldPreserveEmptySourceText && ($this->shouldPreserveEmptySourceText)($element);
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

    /** @return array<string, mixed>|null */
    public function imageBlockFromParagraph(DOMElement $element): ?array
    {
        return ($this->imageBlockFromParagraph)($element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function mixedMediaLinkGroupFromParagraph(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->mixedMediaLinkGroupFromParagraph)($element, $fallbacks);
    }

    /** @return array<string, mixed>|null */
    public function compactLinkedIconTextRowFromParagraph(DOMElement $element): ?array
    {
        return ($this->compactLinkedIconTextRowFromParagraph)($element);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function convertText(string $text): array
    {
        return ($this->convertText)($text);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<int, array<string, mixed>>
     */
    public function convertChildren(DOMElement $element, array &$fallbacks, bool $captureUnsupported = false): array
    {
        return $this->tree->children($element, $fallbacks, $captureUnsupported);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function emptyInlineGeometryBlock(DOMElement $element, array &$fallbacks): ?array
    {
        return null === $this->emptyInlineGeometryBlock ? null : ($this->emptyInlineGeometryBlock)($element, $fallbacks);
    }

    /**
     * A span that wraps one toggle and the region it controls, as `core/details`.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function nativeDisclosureBlock(DOMElement $element, array &$fallbacks): ?array
    {
        return null === $this->nativeDisclosureBlock ? null : ($this->nativeDisclosureBlock)($element, $fallbacks);
    }

    /**
     * A heading or paragraph with no text whose only content is one media
     * element lowers it through the shared media routes (a paragraph takes only
     * the embed route) instead of keeping an inline image or a core/html island.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function mediaBlockFromTextlessElement(DOMElement $element, array &$fallbacks): ?array
    {
        return null === $this->mediaBlockFromTextlessElement ? null : ($this->mediaBlockFromTextlessElement)($element, $fallbacks);
    }
}
