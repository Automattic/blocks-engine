<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\SourceDeclarations;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\CssValueInspector;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredCarouselBlockGenerator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Generators\AuthoredMarqueeRecognizer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\AccordionPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonsContainerPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CodeWindowPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ColumnsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\CoverPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\DetailsPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\LogoPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\MediaTextPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\NavigationPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SocialLinksPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\SpacerPattern;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\TabsPattern;
use Automattic\BlocksEngine\PhpTransformer\Support\ShellLandmarkPolicy;
use DOMElement;

/** Converts flow containers through their ordered runtime, pattern, layout, and child strategies. */
final class FlowContainerElementConverter implements ElementConverter
{
    private readonly NavigationPattern $navigationPattern;

    public function __construct(
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier $sourceElementClassifier,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator $createBlock,
        private readonly \Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\ElementPresentationResolver $presentationResolver,
        private readonly FlowContainerElementContext $context,
        private readonly EmptyInteractiveFeatureShell $emptyFeatureShells,
        private readonly FormDispatcher $formDispatcher,
        private readonly FlankedSeparatorLowering $flankedSeparators,
        private readonly ImageLowering $imageLowering,
        private readonly AuthorLayoutLowering $authorLayout,
        private readonly GeneratedComponentLowering $generatedComponents,
        private readonly EmptyVisualRetention $emptyVisualRetention,
        private readonly HtmlTransformerSession $session,
        private readonly RuntimeIslandAnalyzer $runtimeIslands,
        private readonly MetadataGridConverter $metadataGrid,
        private readonly MediaGalleryLowering $mediaGalleries,
        private readonly NamePriceRowLowering $namePriceRows,
        private readonly InlineFlowLowering $inlineFlows,
        private readonly SearchBlockConverter $searchBlocks,
        private readonly ReadableFormControlBlockConverter $readableControls,
        private readonly AuthoredMarqueeRecognizer $marquees,
        private readonly AuthoredCarouselBlockGenerator $carousels,
        private readonly EmptyVisualSpacerLowering $emptyVisualSpacers
    )
    {
        $this->navigationPattern = new NavigationPattern($this->createBlock);
    }

    public function handles(string $tagName): bool
    {
        return ShellLandmarkPolicy::isFlowContainerTag($tagName);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks, bool $captureUnsupported = false): ConversionOutcome
    {
        // Autonomous dialog hosts have the same light-DOM container semantics
        // as div[role=dialog]. Keep them in the ordinary recognizer order rather
        // than flattening their children through transparent-wrapper lowering.
        $isCustomDialog = CustomElementRuntimeDependency::isCustomElementTag($tagName)
            && 'dialog' === SourceDom::role($element);
        if ( ! $this->handles($tagName) && ! $isCustomDialog ) {
            return ConversionOutcome::unhandled();
        }

        return $this->lower($element, $isCustomDialog ? 'div' : $tagName, $fallbacks);
    }

    /**
     * Lowers an unknown or custom element (`bdt`, `x-panel`) in block position
     * the way a generic `div` lowers: it has no rendering of its own, so its
     * children convert in place and it is kept as a Group only when it wraps
     * several blocks or carries presentation, and vanishes when empty and
     * inert. The caller decides which unknown elements may take this path.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convertUnknownElement(DOMElement $element, array &$fallbacks): ConversionOutcome
    {
        return $this->lower($element, strtolower($element->tagName), $fallbacks);
    }

    /**
     * Lowers a known element whose own semantics yield nothing as the generic
     * container it instead acts as. A `picture` with no image candidate (no
     * `img`, no `source[srcset]`) renders no media, so the caller hands it here
     * and the tag gates inside lower() see `div` semantics. The caller decides
     * which elements may take this path.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    public function convertGenericContainer(DOMElement $element, array &$fallbacks): ConversionOutcome
    {
        return $this->lower($element, 'div', $fallbacks);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function lower(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( SourceDom::isDocumentVariantRoot($element) ) {
            return ConversionOutcome::handled($this->authorLayout->authorLayoutBlockFromElement($element, $fallbacks));
        }
        if (in_array($tagName, array('header', 'footer'), true)
            && SourceDom::hasClass($element, 'wp-block-template-part')
        ) {
            return ConversionOutcome::handled($this->group($element, $this->context->convertChildren($element, $fallbacks)));
        }
        $runtimeAppShell = $this->context->runtimeAppShellBlock($element, $fallbacks);
        if ( null !== $runtimeAppShell ) {
            return ConversionOutcome::handled($runtimeAppShell);
        }
        if ( $this->emptyFeatureShells->isEmptyInteractiveFeatureShell($element) ) {
            return ConversionOutcome::handled(null);
        }

        // A form is a semantic runtime boundary. Convert nested children before
        // text and layout recognizers can flatten it into static presentation.
        if ( $this->hasInlineFormWrapper($element) ) {
            return ConversionOutcome::handled($this->group($element, $this->context->convertChildren($element, $fallbacks)));
        }

        $marquee = $this->marquees->cssAuthoredMarqueeBlock($element);
        if ( null !== $marquee ) {
            return ConversionOutcome::handled($marquee);
        }

        $this->formDispatcher->capturePseudoFormFallback($element, $fallbacks);
        $block = $this->context->recognizePatterns($element, $fallbacks, array( TabsPattern::class ));
        if ( null !== $block ) {
            $this->session->rememberNativeTabControls($element);
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( SpacerPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        if ( SocialLinksPattern::hasSocialRowCandidate($element) ) {
            $block = $this->context->recognizePatterns($element, $fallbacks, array( SocialLinksPattern::class ));
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }

        $block = $this->flankedSeparators->blockFromElement($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        // A runtime-owned subtree keeps its SVG references live; only a static
        // one can be captured as media.
        if ( ! $this->runtimeIslands->isRuntimeDomTarget($element)
            && ! $this->runtimeIslands->hasRuntimeTargetInSubtree($element)
            && $this->sourceElementClassifier->hasExternalSvgFragmentDependencyBoundary($element)
        ) {
            return ConversionOutcome::handled($this->imageLowering->responsiveMediaBlock($element));
        }

        // Sibling img srcsets describe independent images, not alternatives
        // for one media unit. Responsive alternatives still own their carrier.
        if ( $this->sourceElementClassifier->hasResponsiveMediaAlternatives($element) && $this->sourceElementClassifier->hasGalleryMediaItems($element) ) {
            return ConversionOutcome::handled($this->imageLowering->responsiveMediaBlock($element));
        }
        $block = $this->carousels->convert($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        if ( $this->context->authorLayoutOwnership->isDirectChildOfAuthorOwnedLayout($element) && '' !== SourceDom::attr($element, 'role') ) {
            $claim = $this->semanticPatternClaim($element, $fallbacks);
            if ( null !== $claim ) {
                return $claim;
            }
            return ConversionOutcome::handled($this->authorLayout->authorLayoutBlockFromElement($element, $fallbacks));
        }
        if ( in_array($tagName, array( 'div', 'section', 'article' ), true) && ! $this->sourceElementClassifier->hasResponsiveImageSources($element) ) {
            $block = $this->context->recognizePatterns($element, $fallbacks, array( MediaTextPattern::class ));
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }
        if ( $this->context->hasMultipleRuntimeInlineTextTargets($element) ) {
            $block = $this->context->paragraphBlockFromInlineContentWrapper($element);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }

        // A flex/grid child may itself be an ordinary inline text run. Keep
        // its own box as the layout item while its addressable spans share one
        // editable RichText carrier instead of becoming stacked paragraphs.
        if ( $this->hasMultipleAddressableInlineChildren($element) && $this->context->authorLayoutOwnership->isDirectChildOfAuthorOwnedLayout($element) ) {
            $inlineRun = $this->context->inlineAddressableRunGroupBlock($element);
            if ( null !== $inlineRun ) return ConversionOutcome::handled($inlineRun);
        }

        // Editable background heroes must be considered before CSS layout ownership.
        $cover = $this->context->recognizePatterns($element, $fallbacks, array( CoverPattern::class ));
        if ( null !== $cover ) {
            return ConversionOutcome::handled($cover);
        }

        if ( 'button' !== strtolower(SourceDom::attr($element, 'role'))
            && ! SourceDom::hasClass($element, 'wp-block-columns')
            && ! $this->generatedComponents->isCandidate($element)
            && $this->context->authorLayoutOwnership->isAuthorOwnedLayout($element)
        ) {
            // The element's own CSS layout (a flex/grid list of disclosure
            // items) must not hide an accordion from its recognizer any more
            // than a parent's layout may (see below). A single disclosure item
            // laid out by its own CSS stays a layout: core/details' plain
            // summary cannot carry the trigger's box or icon. Unclaimed layouts
            // still lower to the CSS-owned layout block.
            $claim = $this->accordionClaim($element, $fallbacks);
            if ( null !== $claim ) {
                return $claim;
            }
            $navigation = $this->navigationClaim($element, $fallbacks);
            if ( null !== $navigation ) {
                return $navigation;
            }
            // Native core/buttons save markup is laid out by its own block
            // CSS. Its author flex rules restate that layout, so the native
            // block keeps each button (and its width class) editable.
            if ( SourceDom::hasClass($element, 'wp-block-buttons') && ! SourceDom::isDeviceVisibilityCarrier($element) ) {
                $buttons = $this->context->recognizePatterns($element, $fallbacks, array( ButtonsContainerPattern::class ));
                if ( null !== $buttons ) {
                    return ConversionOutcome::handled($buttons);
                }
            }
            $block = $this->context->proofBackedWrapperCoalescing($element, $fallbacks);
            return ConversionOutcome::handled($block ?? $this->authorLayout->authorLayoutBlockFromElement($element, $fallbacks));
        }

        if ( ! $this->generatedComponents->isCandidate($element)
            && $this->context->authorLayoutOwnership->isDirectChildOfAuthorOwnedLayout($element)
            && in_array($tagName, array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'main' ), true)
        ) {
            if ( 0 === SourceDom::childElementCount($element) && '' === trim($element->textContent) ) {
                // An out-of-flow layout item that paints nothing in any state
                // and takes no input is not a box the author can see or edit.
                if ( $this->context->isInertEmptyElement($element) ) {
                    return ConversionOutcome::handled(null);
                }
                if ( $this->emptyVisualRetention->shouldPreserveEmptyVisualElement($element) ) {
                    return ConversionOutcome::handled($this->emptyVisualSpacers->emptyVisualSpacerBlock($element));
                }
            }
            // A parent's CSS-authored layout must not hide a semantic widget
            // from its recognizer. These patterns decline structurally (item
            // floors, runtime-heavy descendants, disclosure wiring), so racing
            // them first costs plain layout children nothing; unclaimed
            // elements still lower to the CSS-owned layout block below.
            $claim = $this->semanticPatternClaim($element, $fallbacks);
            if ( null !== $claim ) {
                return $claim;
            }
            $navigation = $this->navigationClaim($element, $fallbacks);
            if ( null !== $navigation ) {
                return $navigation;
            }
            // A layout item holding one run of text and inline elements is one
            // box in the source, exactly as it is in flow: its run stays one
            // paragraph rather than splitting at each inline element.
            if ( ! $this->context->authorLayoutOwnership->isAuthorOwnedLayout($element) && 0 < SourceDom::childElementCount($element) ) {
                $block = $this->context->paragraphBlockFromInlineContentWrapper($element);
                if ( null !== $block ) {
                    return ConversionOutcome::handled($block);
                }
            }
            return ConversionOutcome::handled($this->authorLayout->authorLayoutBlockFromElement($element, $fallbacks));
        }

        $block = $this->context->recognizePatterns($element, $fallbacks, array( LogoPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $navigationSection = $this->navigationPattern->recognizeLabeledSection($element, $this->context->patternContext());
        if ( null !== $navigationSection ) {
            return ConversionOutcome::handled($navigationSection->block());
        }
        if ( ! $this->shouldDeferNavigationPatternToChildren($element) ) {
            // A broad container can contain a real social cluster plus unrelated
            // media. Let its children be converted instead of dropping that media.
            $patterns = array( AccordionPattern::class, NavigationPattern::class );
            if ( ! $this->sourceElementClassifier->hasCapturedMediaContent($element) ) {
                $patterns[] = SocialLinksPattern::class;
            }
            $block = $this->context->recognizePatterns($element, $fallbacks, $patterns);
            if ( null !== $block ) {
                return ConversionOutcome::handled($this->session->runtimeBehaviorState()->rememberAccordionDisclosureRoot($block, $element));
            }
        }

        if ( in_array($tagName, array( 'div', 'section', 'article' ), true) ) {
            $block = $this->metadataGrid->convert($element);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
            $block = $this->context->recognizePatterns($element, $fallbacks, array( DetailsPattern::class ));
            if ( null !== $block ) {
                $this->session->rememberNativeDisclosure($element);
                return ConversionOutcome::handled($block);
            }
            $block = $this->context->recognizePatterns($element, $fallbacks, array( CoverPattern::class ));
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }

        if ( $this->isClippedHorizontalTrack($element) ) {
            return ConversionOutcome::handled($this->authorLayout->authorLayoutBlockFromElement($element, $fallbacks));
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( ColumnsPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->mediaGalleries->mediaGalleryBlockFromElement($element, $fallbacks);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( CodeWindowPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }

        $block = $this->namePriceRows->namePriceRowBlockFromElement($element, $fallbacks);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->inlineFlows->inlineTokenGroupBlockFromElement($element, $fallbacks);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->searchBlocks->searchBlockFromStandaloneControl($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->visualTextWrapperBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->paragraphBlockFromInlineContentWrapper($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        // core/buttons replaces the host with its own root, which would drop
        // the capture device marker; a marked host stays its own carrier.
        $block = SourceDom::isDeviceVisibilityCarrier($element) ? null : $this->context->recognizePatterns($element, $fallbacks, array( ButtonsContainerPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        if ( 'select' === $tagName ) {
            $block = $this->readableControls->convert($element);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }
        if ( $this->generatedComponents->isCandidate($element) ) {
            $block = $this->generatedComponents->nativeOrGeneratedBlock($element, $fallbacks);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }
        $block = $this->inlineFlows->textFlowBlockFromElement($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }

        $children = $this->context->convertChildren($element, $fallbacks);
        if ( array() === $children && ! $this->sourceElementClassifier->hasDirectMediaChild($element) ) {
            $backgroundImage = $this->imageLowering->backgroundImageBlock($element);
            if ( null !== $backgroundImage ) {
                // A background group already owns the source element's box.
                if ( 'core/group' === ($backgroundImage['blockName'] ?? '') ) {
                    return ConversionOutcome::handled($backgroundImage);
                }
                $children[] = $backgroundImage;
            }
        }
        if ( 1 === count($children) ) {
            $block = $this->context->coalescedSingleGroupWrapper($element, $children[0]);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
            if ( $this->context->shouldPreserveWrapper($element, $tagName) || $this->context->authorLayoutOwnership->isDirectChildOfAuthorOwnedLayout($element) ) {
                return ConversionOutcome::handled($this->group($element, $children));
            }
            return ConversionOutcome::handled($children[0]);
        }
        if ( array() !== $children ) {
            return ConversionOutcome::handled($this->group($element, $children));
        }
        if ( $this->emptyVisualRetention->shouldPreserveEmptyVisualElement($element) ) {
            return ConversionOutcome::handled($this->emptyVisualSpacers->emptyVisualSpacerBlock($element));
        }
        return ConversionOutcome::handled(null);
    }

    private function shouldDeferNavigationPatternToChildren(DOMElement $element): bool
    {
        if ( 'nav' === strtolower($element->tagName) || ! $this->context->shouldPreserveWrapper($element) || $this->isVerifiedCollectionItemList($element) ) {
            return false;
        }
        if ( $this->isRepeatedLinkItemCluster($element) ) {
            return false;
        }

        $hasNavigationDescendant = false;
        foreach ( $element->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) {
                continue;
            }

            if ( in_array(strtolower($child->tagName), array( 'a', 'ul', 'ol' ), true) ) {
                return false;
            }

            $hasNavigationDescendant = $hasNavigationDescendant || 'nav' === strtolower($child->tagName) || $this->hasLinkOutsideDisclosurePanels($child, $element);
        }

        return $hasNavigationDescendant;
    }

    /**
     * A link inside a disclosure panel is panel content, such as a link in an
     * FAQ answer. It is not evidence of a navigation region, so it must not
     * stop the container's disclosure items from being recognized.
     */
    private function hasLinkOutsideDisclosurePanels(DOMElement $child, DOMElement $container): bool
    {
        foreach ( $child->getElementsByTagName('a') as $anchor ) {
            if ( ! $this->isInsideDisclosurePanel($anchor, $container) ) {
                return true;
            }
        }

        return false;
    }

    private function isInsideDisclosurePanel(DOMElement $node, DOMElement $container): bool
    {
        $xpath = new \DOMXPath($container->ownerDocument);
        for ( $current = $node->parentNode; $current instanceof DOMElement && ! $current->isSameNode($container); $current = $current->parentNode ) {
            $previous = $current->previousSibling;
            while ( null !== $previous && ! $previous instanceof DOMElement ) {
                $previous = $previous->previousSibling;
            }
            if ( $previous instanceof DOMElement && $this->isDisclosureControlCarrier($previous) ) {
                return true;
            }
            $id = $current->getAttribute('id');
            if ( '' !== $id && 0 < $xpath->query('.//*[@aria-expanded][not(@aria-haspopup) or @aria-haspopup="false"][@aria-controls="' . str_replace('"', '', $id) . '"]', $container)->length ) {
                return true;
            }
        }

        return false;
    }

    /**
     * A disclosure control, or a heading that wraps one. A popup trigger
     * (`aria-haspopup`) opens a menu, whose links are navigation.
     */
    private function isDisclosureControlCarrier(DOMElement $element): bool
    {
        if ( $element->hasAttribute('aria-expanded') ) {
            return $this->isPlainDisclosureControl($element);
        }
        if ( 1 !== preg_match('/^h[1-6]$/', strtolower($element->tagName)) ) {
            return false;
        }
        foreach ( $element->getElementsByTagName('*') as $descendant ) {
            if ( $descendant instanceof DOMElement && $descendant->hasAttribute('aria-expanded') ) {
                return $this->isPlainDisclosureControl($descendant);
            }
        }

        return false;
    }

    private function isPlainDisclosureControl(DOMElement $control): bool
    {
        return in_array(strtolower(trim($control->getAttribute('aria-haspopup'))), array('', 'false'), true);
    }

    private function isVerifiedCollectionItemList(DOMElement $element): bool
    {
        $children = array();
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement ) {
                $children[] = $child;
            }
        }
        if ( 1 === count($children) ) {
            $nested = array();
            foreach ( $children[0]->childNodes as $child ) {
                if ( $child instanceof DOMElement ) {
                    $nested[] = $child;
                }
            }
            if ( count($nested) >= 2 ) {
                $children = $nested;
            }
        }
        if ( count($children) < 2 ) {
            return false;
        }
        foreach ( $children as $child ) {
            if ( ! $child->hasAttribute('data-blocks-engine-collection-item-marker') ) {
                return false;
            }
        }

        return true;
    }

    private function isRepeatedLinkItemCluster(DOMElement $element): bool
    {
        $items = 0;
        foreach ( $element->childNodes as $child ) {
            if ( XML_TEXT_NODE === $child->nodeType && '' !== trim($child->textContent ?? '') ) {
                return false;
            }
            if ( ! $child instanceof DOMElement ) {
                continue;
            }
            $anchors = $child->getElementsByTagName('a');
            if ( 1 !== $anchors->length ) {
                return false;
            }
            $anchor = $anchors->item(0);
            if ( ! $anchor instanceof DOMElement || ! SourceDom::anchorIsHeadingWrapped($anchor, $child) ) {
                return false;
            }
            $href = trim(SourceDom::attr($anchor, 'href'));
            $label = trim(preg_replace('/\s+/', ' ', $anchor->textContent ?? '') ?? '');
            if ( '' === $href || '' === $label || str_starts_with($href, '#') ) {
                return false;
            }
            ++$items;
        }

        return 3 <= $items;
    }

    private function hasMultipleAddressableInlineChildren(DOMElement $element): bool
    {
        $count = 0;
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && '' !== trim($child->getAttribute('id')) && ++$count > 1) return true;
        }
        return false;
    }

    /**
     * Races the semantic/interactive recognizers that own an element outright
     * when they match, mirroring the bookkeeping of their late race below.
     *
     * Returns null when no recognizer claims the element, so the caller can
     * fall through to its own lowering (the CSS-owned layout block).
     *
     * @param array<int, array<string, mixed>> $fallbacks
     */
    private function semanticPatternClaim(DOMElement $element, array &$fallbacks): ?ConversionOutcome
    {
        $accordion = $this->accordionClaim($element, $fallbacks);
        if ( null !== $accordion ) {
            return $accordion;
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( DetailsPattern::class ));
        if ( null !== $block ) {
            $this->session->rememberNativeDisclosure($element);
            return ConversionOutcome::handled($block);
        }
        return null;
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function accordionClaim(DOMElement $element, array &$fallbacks): ?ConversionOutcome
    {
        if ( $this->shouldDeferNavigationPatternToChildren($element) ) {
            return null;
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( AccordionPattern::class ));
        return null === $block ? null : ConversionOutcome::handled($this->session->runtimeBehaviorState()->rememberAccordionDisclosureRoot($block, $element));
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    private function navigationClaim(DOMElement $element, array &$fallbacks): ?ConversionOutcome
    {
        if ( $this->shouldDeferNavigationPatternToChildren($element)
            || ! $this->navigationPattern->claimsBeforeAuthorOwnedLayout($element)
        ) {
            return null;
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( NavigationPattern::class ));
        if ( null === $block ) {
            return null;
        }

        return ConversionOutcome::handled($this->session->runtimeBehaviorState()->rememberAccordionDisclosureRoot($block, $element));
    }

    /** @param array<int, array<string, mixed>> $children @return array<string, mixed> */
    private function group(DOMElement $element, array $children): array
    {
        return $this->createBlock->createBlock('core/group', $this->presentationResolver->presentationAttributes($element), $children, $element);
    }

    private function hasInlineFormWrapper(DOMElement $element): bool
    {
        foreach ( $element->getElementsByTagName('form') as $form ) {
            for ( $parent = $form->parentNode; $parent instanceof DOMElement && ! $parent->isSameNode($element); $parent = $parent->parentNode ) {
                if ( 'span' === strtolower($parent->tagName) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isClippedHorizontalTrack(DOMElement $element): bool
    {
        if ( ! in_array(CssValueInspector::declared(SourceDeclarations::inline($element), 'display'), array( 'flex', 'inline-flex' ), true) ) {
            return false;
        }
        $structural = $this->presentationResolver->structuralPresentationDeclarations($element);
        if ( in_array(CssValueInspector::declared($structural, 'flex-direction'), array( 'column', 'column-reverse' ), true)
            || in_array(CssValueInspector::declared($structural, 'flex-wrap'), array( 'wrap', 'wrap-reverse' ), true)
        ) {
            return false;
        }

        // A strip item keeps its width: it refuses to shrink or fixes a pixel width.
        $isStripItem = static fn (array $declarations): bool => '0' === CssValueInspector::declared($declarations, 'flex-shrink')
            || 1 === preg_match('/^[1-9]\d*(?:\.\d+)?px$/D', CssValueInspector::declared($declarations, 'width'));
        $stripChildren = 0;
        foreach ( $element->childNodes as $child ) {
            if ( $child instanceof DOMElement
                && ( $isStripItem(SourceDeclarations::inline($child)) || $isStripItem($this->presentationResolver->structuralPresentationDeclarations($child)) )
            ) {
                ++$stripChildren;
            }
        }
        if ( 2 > $stripChildren ) {
            return false;
        }

        for ( $node = $element; $node instanceof DOMElement; $node = $node->parentNode ) {
            $declarations = array_merge(
                $this->presentationResolver->authorStructuralDeclarations($node),
                $this->presentationResolver->structuralPresentationDeclarations($node)
            );
            foreach ( array( 'overflow', 'overflow-x' ) as $property ) {
                if ( in_array(CssValueInspector::declared($declarations, $property), array( 'hidden', 'clip' ), true) ) {
                    return true;
                }
            }
        }

        return false;
    }
}
