<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\RichText\RichTextMaterialization;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
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
     * @param Closure(DOMElement, array<int, array<string, mixed>>&, bool): array<int, array<string, mixed>> $convertChildren
     * @param Closure(DOMElement): string $richTextMarker
     * @param Closure(DOMElement): ?string $dynamicTextContent
     * @param Closure(DOMElement, string): ?DOMElement $ancestorElement
     * @param Closure(DOMElement): bool $isStructuralListItem
     * @param Closure(DOMElement): bool $shouldPreserveEmptyVisualElement
     * @param Closure(DOMElement): array<string, mixed> $emptyVisualSpacerBlock
     */
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly Closure $recognizePatterns,
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $inlineSvgTextGroupBlock,
        private readonly Closure $ownsPositioningGeometry,
        private readonly Closure $positionedInlineCarrierBlock,
        private readonly Closure $hasAuthorSemanticMarker,
        private readonly RichTextMaterialization $richTextMaterializer,
        private readonly Closure $convertChildren,
        private readonly SourceBlockCreator $createBlock,
        private readonly Closure $richTextMarker,
        private readonly Closure $dynamicTextContent,
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
    public function richTextContentHasStructuralHtml(string $content): bool { return $this->richTextMaterializer->hasStructuralHtml($content); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks): array { return ($this->convertChildren)($element, $fallbacks, true); }
    /** @param array<string, mixed> $attributes @param array<int, array<string, mixed>> $innerBlocks @return array<string, mixed> */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array { return $this->createBlock->createBlock($name, $attributes, $innerBlocks, $sourceElement); }
    public function richTextMarker(DOMElement $element): string { return ($this->richTextMarker)($element); }
    public function hasBlockContentChildren(DOMElement $element): bool { return $this->sourceElementClassifier->hasBlockContentChildren($element); }
    /** @return array<string, string> */
    public function richTextInlineVisualDeclarations(DOMElement $element): array { return $this->richTextMaterializer->inlineVisualDeclarations($element); }
    public function dynamicTextContent(DOMElement $element): ?string { return ($this->dynamicTextContent)($element); }
    public function ancestorElement(DOMElement $element, string $tagName): ?DOMElement { return ($this->ancestorElement)($element, $tagName); }
    public function isStructuralListItem(DOMElement $element): bool { return ($this->isStructuralListItem)($element); }
    public function shouldPreserveEmptyVisualElement(DOMElement $element): bool { return ($this->shouldPreserveEmptyVisualElement)($element); }
    /** @return array<string, mixed> */
    public function emptyVisualSpacerBlock(DOMElement $element): array { return ($this->emptyVisualSpacerBlock)($element); }
}
