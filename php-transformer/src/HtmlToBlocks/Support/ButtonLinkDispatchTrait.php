<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

use DOMElement;

trait ButtonLinkDispatchTrait
{
    /**
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertAnchorDispatchElement(DOMElement $element, array &$fallbacks): ?array
    {
        $linkedLogo = $this->linkedSvgLogoBlockFromAnchor($element, $fallbacks);
        if ( null !== $linkedLogo ) {
            return $linkedLogo;
        }

        $logo = $this->logoPattern->match(
            $element,
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->richTextContentWithMaterializedInlineStyles($sourceElement),
            fn (DOMElement $sourceElement): string => $this->restoreSvgCasing($this->outerHtml($sourceElement)),
            fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement)
        );
        if ( null !== $logo ) {
            return $logo;
        }

        $button = $this->buttonsPattern->matchAnchor(
            $element,
            fn (DOMElement $anchor): ?array => $this->fileBlockFromAnchor($anchor),
            fn (DOMElement $sourceElement, array $excludedGeometryProperties = array()): array => $this->presentationAttributes($sourceElement, $excludedGeometryProperties),
            fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->mergedPresentationStyle($sourceElement)),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content),
            fn (DOMElement $sourceElement, string $name): string => $this->attr($sourceElement, $name),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null, ?DOMElement $logicalSourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement, $logicalSourceElement)
        );
        if ( null !== $button ) {
            return $button;
        }

        if ( '' === trim($element->textContent ?? '') && '' !== $this->safeLinkUrl($this->attr($element, 'href')) && '' !== trim($this->attr($element, 'aria-label')) ) {
            return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $this->outerHtml($element) )), array(), $element);
        }

        if ( '' === trim($element->textContent ?? '') ) {
            return null;
        }

        if ( $this->hasBlockContentChildren($element) ) {
            $linkWrapper = $this->convertLinkWrapperGroup($element, $fallbacks);
            if ( null !== $linkWrapper ) {
                return $linkWrapper;
            }
        }

        // A non-button anchor has no native width support. Promote its source
        // presentation to the paragraph wrapper so generated geometry remains
        // attached to the rendered block rather than being silently discarded.
        return $this->createBlock('core/paragraph', array_merge($this->presentationAttributes($element), array( 'content' => $this->outerHtml($element) )), array(), $element);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function convertButtonDispatchElement(DOMElement $element): ?array
    {
        if ( $this->isRuntimeDomTarget($element) ) {
            $this->recordRuntimeControlIsland($element);
            return $this->htmlPreservationBlock($element);
        }

        return $this->buttonsPattern->matchButton(
            $element,
            fn (DOMElement $sourceElement): array => $this->presentationAttributes($sourceElement),
            fn (DOMElement $sourceElement): string => $this->resolveCssVariablesInValue($this->mergedPresentationStyle($sourceElement)),
            fn (DOMElement $sourceElement): string => $this->innerHtml($sourceElement),
            fn (DOMElement $sourceElement, string $content): ?string => $this->richTextContentWithMaterializedSvgImages($sourceElement, $content),
            fn (string $name, array $attrs = array(), array $innerBlocks = array(), ?DOMElement $sourceElement = null): array => $this->createBlock($name, $attrs, $innerBlocks, $sourceElement)
        );
    }
}
