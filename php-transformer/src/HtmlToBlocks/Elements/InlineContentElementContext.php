<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see InlineContentElementConverter}. */
final class InlineContentElementContext
{
    /**
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, array<int, class-string>): ?array<string, mixed> $recognizePatterns
     * @param Closure(DOMElement): bool $isRuntimeDomTarget
     * @param Closure(DOMElement): array<string, mixed> $htmlPreservationBlock
     * @param Closure(DOMElement): ?array<string, mixed> $inlineSvgTextGroupBlock
     * @param Closure(DOMElement): bool $ownsPositioningGeometry
     * @param Closure(DOMElement, array<int, array<string, mixed>>&): ?array<string, mixed> $positionedInlineCarrierBlock
     * @param Closure(DOMElement): bool $hasAuthorSemanticMarker
     * @param Closure(DOMElement): string $innerHtml
     * @param Closure(string): bool $richTextContentHasStructuralHtml
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement): array<string, mixed> $createBlock
     * @param Closure(DOMElement): string $richTextMarker
     * @param Closure(DOMElement): bool $hasBlockContentChildren
     * @param Closure(DOMElement): array<string, string> $richTextInlineVisualDeclarations
     * @param Closure(DOMElement): ?string $dynamicTextContent
     * @param Closure(DOMElement): string $outerHtml
     * @param Closure(DOMElement, string): ?DOMElement $ancestorElement
     * @param Closure(DOMElement): bool $isStructuralListItem
     * @param Closure(DOMElement): bool $shouldPreserveEmptyVisualElement
     * @param Closure(DOMElement): array<string, mixed> $emptyVisualSpacerBlock
     */
    public function __construct(
        private readonly Closure $recognizePatterns,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $inlineSvgTextGroupBlock,
        private readonly Closure $ownsPositioningGeometry,
        private readonly Closure $positionedInlineCarrierBlock,
        private readonly Closure $hasAuthorSemanticMarker,
        private readonly Closure $innerHtml,
        private readonly Closure $richTextContentHasStructuralHtml,
        private readonly Closure $convertChildren,
        private readonly Closure $createBlock,
        private readonly Closure $richTextMarker,
        private readonly Closure $hasBlockContentChildren,
        private readonly Closure $richTextInlineVisualDeclarations,
        private readonly Closure $dynamicTextContent,
        private readonly Closure $outerHtml,
        private readonly Closure $ancestorElement,
        private readonly Closure $isStructuralListItem,
        private readonly Closure $shouldPreserveEmptyVisualElement,
        private readonly Closure $emptyVisualSpacerBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @param array<int, class-string> $patterns @return array<string, mixed>|null */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array { return ($this->recognizePatterns)($element, $fallbacks, $patterns); }
    public function isRuntimeDomTarget(DOMElement $element): bool { return ($this->isRuntimeDomTarget)($element); }
    /** @return array<string, mixed> */
    public function htmlPreservationBlock(DOMElement $element): array { return ($this->htmlPreservationBlock)($element); }
    /** @return array<string, mixed>|null */
    public function inlineSvgTextGroupBlock(DOMElement $element): ?array { return ($this->inlineSvgTextGroupBlock)($element); }
    public function ownsPositioningGeometry(DOMElement $element): bool { return ($this->ownsPositioningGeometry)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function positionedInlineCarrierBlock(DOMElement $element, array &$fallbacks): ?array { return ($this->positionedInlineCarrierBlock)($element, $fallbacks); }
    public function hasAuthorSemanticMarker(DOMElement $element): bool { return ($this->hasAuthorSemanticMarker)($element); }
    public function innerHtml(DOMElement $element): string { return ($this->innerHtml)($element); }
    public function richTextContentHasStructuralHtml(string $content): bool { return ($this->richTextContentHasStructuralHtml)($content); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks): array { return ($this->convertChildren)($element, $fallbacks, true); }
    /** @param array<string, mixed> $attributes @param array<int, array<string, mixed>> $innerBlocks @return array<string, mixed> */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array { return ($this->createBlock)($name, $attributes, $innerBlocks, $sourceElement); }
    public function richTextMarker(DOMElement $element): string { return ($this->richTextMarker)($element); }
    public function hasBlockContentChildren(DOMElement $element): bool { return ($this->hasBlockContentChildren)($element); }
    /** @return array<string, string> */
    public function richTextInlineVisualDeclarations(DOMElement $element): array { return ($this->richTextInlineVisualDeclarations)($element); }
    public function dynamicTextContent(DOMElement $element): ?string { return ($this->dynamicTextContent)($element); }
    public function outerHtml(DOMElement $element): string { return ($this->outerHtml)($element); }
    public function ancestorElement(DOMElement $element, string $tagName): ?DOMElement { return ($this->ancestorElement)($element, $tagName); }
    public function isStructuralListItem(DOMElement $element): bool { return ($this->isStructuralListItem)($element); }
    public function shouldPreserveEmptyVisualElement(DOMElement $element): bool { return ($this->shouldPreserveEmptyVisualElement)($element); }
    /** @return array<string, mixed> */
    public function emptyVisualSpacerBlock(DOMElement $element): array { return ($this->emptyVisualSpacerBlock)($element); }
}
