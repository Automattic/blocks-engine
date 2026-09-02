<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Closure;
use DOMElement;

/**
 * Explicit collaborator surface for {@see ButtonLinkDispatcher}.
 */
final class ButtonLinkDispatchContext
{
    /**
     * @param Closure(DOMElement): bool                                                                      $isRuntimeDomTarget
     * @param Closure(DOMElement): void                                                                      $recordRuntimeControlIsland
     * @param Closure(DOMElement): array<string, mixed>                                                      $htmlPreservationBlock
     * @param Closure(DOMElement, array<int, array<string, mixed>>, array<int, class-string>): ?array<string, mixed> $recognizePatterns
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                    $linkedSvgLogoBlockFromAnchor
     * @param Closure(DOMElement): ?array<string, mixed>                                                     $imageBlockFromAnchor
     * @param Closure(DOMElement, array<int, array<string, mixed>>): ?array<string, mixed>                    $convertLinkWrapperGroup
     * @param Closure(DOMElement, array<int, string>, array<int, string>): array<string, mixed>               $presentationAttributes
     * @param Closure(string, array<string, mixed>, array<int, array<string, mixed>>, ?DOMElement): array<string, mixed> $createBlock
     * @param Closure(string): string                                                                        $safeLinkUrl
     * @param Closure(DOMElement): bool                                                                      $hasBlockContentChildren
     * @param Closure(DOMElement): array<string, mixed>                                                      $structuralPresentationDeclarations
     */
    public function __construct(
        private readonly Closure $isRuntimeDomTarget,
        private readonly Closure $recordRuntimeControlIsland,
        private readonly Closure $htmlPreservationBlock,
        private readonly Closure $recognizePatterns,
        private readonly Closure $linkedSvgLogoBlockFromAnchor,
        private readonly Closure $imageBlockFromAnchor,
        private readonly Closure $convertLinkWrapperGroup,
        private readonly Closure $presentationAttributes,
        private readonly Closure $createBlock,
        private readonly Closure $safeLinkUrl,
        private readonly Closure $hasBlockContentChildren,
        private readonly Closure $structuralPresentationDeclarations
    ) {
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return ($this->isRuntimeDomTarget)($element);
    }

    public function recordRuntimeControlIsland(DOMElement $element): void
    {
        ($this->recordRuntimeControlIsland)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function htmlPreservationBlock(DOMElement $element): array
    {
        return ($this->htmlPreservationBlock)($element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, class-string>         $patterns
     * @return array<string, mixed>|null
     */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array
    {
        return ($this->recognizePatterns)($element, $fallbacks, $patterns);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function linkedSvgLogoBlockFromAnchor(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->linkedSvgLogoBlockFromAnchor)($element, $fallbacks);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function imageBlockFromAnchor(DOMElement $element): ?array
    {
        return ($this->imageBlockFromAnchor)($element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convertLinkWrapperGroup(DOMElement $element, array &$fallbacks): ?array
    {
        return ($this->convertLinkWrapperGroup)($element, $fallbacks);
    }

    /**
     * @param array<int, string> $excludedProperties
     * @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedProperties = array(), array $excludedGeometryProperties = array()): array
    {
        return ($this->presentationAttributes)($element, $excludedProperties, $excludedGeometryProperties);
    }

    /**
     * @param array<string, mixed>             $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        return ($this->createBlock)($name, $attributes, $innerBlocks, $sourceElement);
    }

    public function safeLinkUrl(string $href): string
    {
        return ($this->safeLinkUrl)($href);
    }

    public function hasBlockContentChildren(DOMElement $element): bool
    {
        return ($this->hasBlockContentChildren)($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function structuralPresentationDeclarations(DOMElement $element): array
    {
        return ($this->structuralPresentationDeclarations)($element);
    }
}
