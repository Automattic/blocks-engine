<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternContext;
use Closure;
use DOMElement;

/** Explicit transformer collaborator surface for {@see FlowContainerElementConverter}. */
final class FlowContainerElementContext
{
    public function __construct(
        private readonly Closure $runtimeAppShellBlock,
        private readonly Closure $isEmptyInteractiveFeatureShell,
        private readonly Closure $capturePseudoFormFallback,
        private readonly Closure $recognizePatterns,
        private readonly Closure $flankedSeparatorBlock,
        private readonly Closure $capturedMediaLayoutBlock,
        private readonly Closure $hasResponsiveImageSources,
        private readonly Closure $hasGalleryMediaItems,
        private readonly Closure $responsiveMediaBlock,
        private readonly Closure $isDirectChildOfAuthorOwnedLayout,
        private readonly Closure $authorLayoutBlock,
        private readonly Closure $hasMultipleRuntimeInlineTextTargets,
        private readonly Closure $paragraphBlockFromInlineContentWrapper,
        private readonly Closure $isGeneratedComponentCandidate,
        private readonly Closure $isAuthorOwnedLayout,
        private readonly Closure $proofBackedWrapperCoalescing,
        private readonly Closure $shouldPreserveEmptyVisualElement,
        private readonly Closure $emptyVisualElementAttributes,
        private readonly Closure $createBlock,
        private readonly PatternContext $patternContext,
        private readonly Closure $shouldDeferNavigationPatternToChildren,
        private readonly Closure $rememberAccordionDisclosureRoot,
        private readonly Closure $metadataGridBlock,
        private readonly Closure $rememberNativeDisclosureRoot,
        private readonly Closure $mediaGalleryBlock,
        private readonly Closure $namePriceRowBlock,
        private readonly Closure $inlineTokenGroupBlock,
        private readonly Closure $visualTextWrapperBlock,
        private readonly Closure $standaloneSearchBlock,
        private readonly Closure $readableFormControlBlock,
        private readonly Closure $authoredCarouselBlock,
        private readonly Closure $generatedComponentBlock,
        private readonly Closure $textFlowBlock,
        private readonly Closure $convertChildren,
        private readonly Closure $hasDirectMediaChild,
        private readonly Closure $backgroundImageBlock,
        private readonly Closure $coalescedSingleGroupWrapper,
        private readonly Closure $shouldPreserveWrapper,
        private readonly Closure $presentationAttributes,
        private readonly Closure $emptyVisualSpacerBlock
    ) {
    }

    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function runtimeAppShellBlock(DOMElement $element, array &$fallbacks): ?array { return ($this->runtimeAppShellBlock)($element, $fallbacks); }
    public function isEmptyInteractiveFeatureShell(DOMElement $element): bool { return ($this->isEmptyInteractiveFeatureShell)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks */
    public function capturePseudoFormFallback(DOMElement $element, array &$fallbacks): void { ($this->capturePseudoFormFallback)($element, $fallbacks); }
    /** @param array<int, array<string, mixed>> $fallbacks @param array<int, class-string> $patterns @return array<string, mixed>|null */
    public function recognizePatterns(DOMElement $element, array &$fallbacks, array $patterns): ?array { return ($this->recognizePatterns)($element, $fallbacks, $patterns); }
    /** @return array<string, mixed>|null */
    public function flankedSeparatorBlock(DOMElement $element): ?array { return ($this->flankedSeparatorBlock)($element); }
    /** @return array<string, mixed>|null */
    public function capturedMediaLayoutBlock(DOMElement $element): ?array { return ($this->capturedMediaLayoutBlock)($element); }
    public function hasResponsiveImageSources(DOMElement $element): bool { return ($this->hasResponsiveImageSources)($element); }
    public function hasGalleryMediaItems(DOMElement $element): bool { return ($this->hasGalleryMediaItems)($element); }
    /** @return array<string, mixed> */
    public function responsiveMediaBlock(DOMElement $element): array { return ($this->responsiveMediaBlock)($element); }
    public function isDirectChildOfAuthorOwnedLayout(DOMElement $element): bool { return ($this->isDirectChildOfAuthorOwnedLayout)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed> */
    public function authorLayoutBlock(DOMElement $element, array &$fallbacks): array { return ($this->authorLayoutBlock)($element, $fallbacks); }
    public function hasMultipleRuntimeInlineTextTargets(DOMElement $element): bool { return ($this->hasMultipleRuntimeInlineTextTargets)($element); }
    /** @return array<string, mixed>|null */
    public function paragraphBlockFromInlineContentWrapper(DOMElement $element): ?array { return ($this->paragraphBlockFromInlineContentWrapper)($element); }
    public function isGeneratedComponentCandidate(DOMElement $element): bool { return ($this->isGeneratedComponentCandidate)($element); }
    public function isAuthorOwnedLayout(DOMElement $element): bool { return ($this->isAuthorOwnedLayout)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function proofBackedWrapperCoalescing(DOMElement $element, array &$fallbacks): ?array { return ($this->proofBackedWrapperCoalescing)($element, $fallbacks); }
    public function shouldPreserveEmptyVisualElement(DOMElement $element): bool { return ($this->shouldPreserveEmptyVisualElement)($element); }
    /** @return array<string, mixed> */
    public function emptyVisualElementAttributes(DOMElement $element): array { return ($this->emptyVisualElementAttributes)($element); }
    /** @param array<string, mixed> $attributes @param array<int, array<string, mixed>> $innerBlocks @return array<string, mixed> */
    public function createBlock(string $name, array $attributes, array $innerBlocks, ?DOMElement $sourceElement): array { return ($this->createBlock)($name, $attributes, $innerBlocks, $sourceElement); }
    public function patternContext(): PatternContext { return $this->patternContext; }
    public function shouldDeferNavigationPatternToChildren(DOMElement $element): bool { return ($this->shouldDeferNavigationPatternToChildren)($element); }
    /** @param array<string, mixed> $block @return array<string, mixed> */
    public function rememberAccordionDisclosureRoot(array $block, DOMElement $element): array { return ($this->rememberAccordionDisclosureRoot)($block, $element); }
    /** @return array<string, mixed>|null */
    public function metadataGridBlock(DOMElement $element): ?array { return ($this->metadataGridBlock)($element); }
    public function rememberNativeDisclosureRoot(DOMElement $element): void { ($this->rememberNativeDisclosureRoot)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function mediaGalleryBlock(DOMElement $element, array &$fallbacks): ?array { return ($this->mediaGalleryBlock)($element, $fallbacks); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function namePriceRowBlock(DOMElement $element, array &$fallbacks): ?array { return ($this->namePriceRowBlock)($element, $fallbacks); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<string, mixed>|null */
    public function inlineTokenGroupBlock(DOMElement $element, array &$fallbacks): ?array { return ($this->inlineTokenGroupBlock)($element, $fallbacks); }
    /** @return array<string, mixed>|null */
    public function visualTextWrapperBlock(DOMElement $element): ?array { return ($this->visualTextWrapperBlock)($element); }
    /** @return array<string, mixed>|null */
    public function standaloneSearchBlock(DOMElement $element): ?array { return ($this->standaloneSearchBlock)($element); }
    /** @return array<string, mixed>|null */
    public function readableFormControlBlock(DOMElement $element): ?array { return ($this->readableFormControlBlock)($element); }
    /** @return array<string, mixed>|null */
    public function authoredCarouselBlock(DOMElement $element): ?array { return ($this->authoredCarouselBlock)($element); }
    /** @return array<string, mixed>|null */
    public function generatedComponentBlock(DOMElement $element): ?array { return ($this->generatedComponentBlock)($element); }
    /** @return array<string, mixed>|null */
    public function textFlowBlock(DOMElement $element): ?array { return ($this->textFlowBlock)($element); }
    /** @param array<int, array<string, mixed>> $fallbacks @return array<int, array<string, mixed>> */
    public function convertChildren(DOMElement $element, array &$fallbacks): array { return ($this->convertChildren)($element, $fallbacks); }
    public function hasDirectMediaChild(DOMElement $element): bool { return ($this->hasDirectMediaChild)($element); }
    /** @return array<string, mixed>|null */
    public function backgroundImageBlock(DOMElement $element): ?array { return ($this->backgroundImageBlock)($element); }
    /** @param array<string, mixed> $child @return array<string, mixed>|null */
    public function coalescedSingleGroupWrapper(DOMElement $element, array $child): ?array { return ($this->coalescedSingleGroupWrapper)($element, $child); }
    public function shouldPreserveWrapper(DOMElement $element): bool { return ($this->shouldPreserveWrapper)($element); }
    /** @return array<string, mixed> */
    public function presentationAttributes(DOMElement $element): array { return ($this->presentationAttributes)($element); }
    /** @return array<string, mixed> */
    public function emptyVisualSpacerBlock(DOMElement $element): array { return ($this->emptyVisualSpacerBlock)($element); }
}
