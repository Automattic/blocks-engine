<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternRecognizerRegistry;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\LinkUrlSanitizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/**
 * Explicit collaborator surface for {@see ButtonLinkDispatcher}.
 */
final class ButtonLinkDispatchContext
{
    public function __construct(
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly ?ElementPresentationResolver $presentationResolver = null,
        private readonly ?SourceBlockCreator $createBlock = null,
        private readonly ?PatternRecognizerRegistry $patternRecognizers = null,
        private readonly ?PatternContext $patternContext = null,
        private readonly ?RuntimeIslandAnalyzer $runtimeIslands = null,
        private readonly ?FormRuntimeIslandRecorder $formRuntimeIslandRecorder = null,
        private readonly ?ButtonLinkLeftovers $leftovers = null,
        private readonly Runtime $runtime = new Runtime()
    ) {
    }

    public function isRuntimeDomTarget(DOMElement $element): bool
    {
        return $this->runtimeIslands?->isRuntimeDomTarget($element) ?? false;
    }

    public function recordRuntimeControlIsland(DOMElement $element): void
    {
        $this->formRuntimeIslandRecorder?->recordControl($element);
    }

    /**
     * @return array<string, mixed>
     */
    public function htmlPreservationBlock(DOMElement $element): array
    {
        $html = $this->patternContext?->markupContext()?->safeFallbackHtml($element) ?? SourceDom::outerHtml($element);
        if ( ! $this->createBlock instanceof SourceBlockCreator ) {
            return array( 'blockName' => 'core/html', 'attrs' => array( 'content' => $html ) );
        }

        return $this->createBlock->createBlock('core/html', array( 'content' => $html ), array(), $element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @param array<int, class-string>         $patterns
     * @return array<string, mixed>|null
     */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array
    {
        if ( ! $this->patternRecognizers instanceof PatternRecognizerRegistry || ! $this->patternContext instanceof PatternContext ) {
            return null;
        }

        $result = $this->patternRecognizers->firstMatch($element, $this->patternContext, $patterns);
        if ( null === $result ) {
            return null;
        }

        $fallbacks = array_merge($fallbacks, $result->fallbacks());

        return $result->block();
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function linkedSvgLogoBlockFromAnchor(DOMElement $element, array &$fallbacks): ?array
    {
        if ( ! $this->isLinkedSvgLogoAnchor($element) ) {
            return null;
        }

        return $this->convertLinkWrapperGroup($element, $fallbacks);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function imageBlockFromAnchor(DOMElement $element): ?array
    {
        return $this->leftovers?->imageBlockFromAnchor($element);
    }

    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function convertLinkWrapperGroup(DOMElement $element, array &$fallbacks): ?array
    {
        return $this->leftovers?->convertLinkWrapperGroup($element, $fallbacks);
    }

    /**
     * @param array<int, string> $excludedProperties
     * @param array<int, string> $excludedGeometryProperties
     * @return array<string, mixed>
     */
    public function presentationAttributes(DOMElement $element, array $excludedProperties = array(), array $excludedGeometryProperties = array()): array
    {
        return $this->presentationResolver?->presentationAttributes($element, $excludedProperties, $excludedGeometryProperties) ?? array();
    }

    /**
     * @param array<string, mixed>             $attributes
     * @param array<int, array<string, mixed>> $innerBlocks
     * @return array<string, mixed>
     */
    public function createBlock(string $name, array $attributes = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array
    {
        if ( ! $this->createBlock instanceof SourceBlockCreator ) {
            return array( 'blockName' => $name, 'attrs' => $attributes, 'innerBlocks' => $innerBlocks );
        }

        return $this->createBlock->createBlock($name, $attributes, $innerBlocks, $sourceElement);
    }

    public function safeLinkUrl(string $href): string
    {
        return LinkUrlSanitizer::sanitize($href);
    }

    public function hasBlockContentChildren(DOMElement $element): bool
    {
        return $this->sourceElementClassifier->hasBlockContentChildren($element);
    }

    public function isInlineContentElement(string $tagName): bool
    {
        return $this->sourceElementClassifier->isInlineContentElement($tagName);
    }

    /**
     * @return array<string, mixed>
     */
    public function structuralPresentationDeclarations(DOMElement $element): array
    {
        return $this->presentationResolver?->structuralPresentationDeclarations($element) ?? array();
    }

    private function isLinkedSvgLogoAnchor(DOMElement $anchor): bool
    {
        if ( 0 === $anchor->getElementsByTagName('svg')->length
            || '' !== trim($this->runtime->stripAllTags(SourceDom::innerHtmlWithoutTags($anchor, array( 'svg' )))) ) {
            return false;
        }

        if ( $this->sourceElementClassifier->hasLogoBrandSignal($anchor) ) {
            return true;
        }

        foreach ( $anchor->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $this->sourceElementClassifier->hasLogoBrandSignal($descendant) ) {
                return true;
            }
        }

        return false;
    }
}
