<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

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
    public function __construct(
        private readonly FlowContainerElementContext $context,
        private readonly NavigationPattern $navigationPattern = new NavigationPattern()
    )
    {
    }

    public function handles(string $tagName): bool
    {
        return ShellLandmarkPolicy::isFlowContainerTag($tagName);
    }

    /** @param array<int, array<string, mixed>> $fallbacks */
    public function convert(DOMElement $element, string $tagName, array &$fallbacks): ConversionOutcome
    {
        if ( ! $this->handles($tagName) ) {
            return ConversionOutcome::unhandled();
        }

        $runtimeAppShell = $this->context->runtimeAppShellBlock($element, $fallbacks);
        if ( null !== $runtimeAppShell ) {
            return ConversionOutcome::handled($runtimeAppShell);
        }
        if ( $this->context->isEmptyInteractiveFeatureShell($element) ) {
            return ConversionOutcome::handled(null);
        }

        // A form is a semantic runtime boundary. Convert nested children before
        // text and layout recognizers can flatten it into static presentation.
        if ( $this->hasInlineFormWrapper($element) ) {
            return ConversionOutcome::handled($this->group($element, $this->context->convertChildren($element, $fallbacks)));
        }

        $marquee = $this->context->cssAuthoredMarqueeBlock($element);
        if ( null !== $marquee ) {
            return ConversionOutcome::handled($marquee);
        }

        $this->context->capturePseudoFormFallback($element, $fallbacks);
        $block = $this->context->recognizePatterns($element, $fallbacks, array( TabsPattern::class ));
        if ( null !== $block ) {
            $this->context->rememberNativeTabControls($element);
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( SpacerPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        if ( SocialLinksPattern::isExplicitSocialCluster($element) ) {
            $block = $this->context->recognizePatterns($element, $fallbacks, array( SocialLinksPattern::class ));
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }

        $block = $this->context->flankedSeparatorBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->capturedMediaLayoutBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        if ( $this->context->hasExternalSvgFragmentDependencyBoundary($element) ) {
            return ConversionOutcome::handled($this->context->responsiveMediaBlock($element));
        }

        if ( $this->context->hasResponsiveImageSources($element) && $this->context->hasGalleryMediaItems($element) ) {
            return ConversionOutcome::handled($this->context->responsiveMediaBlock($element));
        }
        $block = $this->context->authoredCarouselBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        if ( $this->context->isDirectChildOfAuthorOwnedLayout($element) && '' !== SourceDom::attr($element, 'role') ) {
            $claim = $this->semanticPatternClaim($element, $fallbacks);
            if ( null !== $claim ) {
                return $claim;
            }
            return ConversionOutcome::handled($this->context->authorLayoutBlock($element, $fallbacks));
        }
        if ( in_array($tagName, array( 'div', 'section', 'article' ), true) && ! $this->context->hasResponsiveImageSources($element) ) {
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

        if ( 'button' !== strtolower(SourceDom::attr($element, 'role'))
            && ! SourceDom::hasClass($element, 'wp-block-columns')
            && ! $this->context->isGeneratedComponentCandidate($element)
            && $this->context->isAuthorOwnedLayout($element)
        ) {
            $block = $this->context->proofBackedWrapperCoalescing($element, $fallbacks);
            return ConversionOutcome::handled($block ?? $this->context->authorLayoutBlock($element, $fallbacks));
        }

        if ( ! $this->context->isGeneratedComponentCandidate($element)
            && $this->context->isDirectChildOfAuthorOwnedLayout($element)
            && in_array($tagName, array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'main' ), true)
        ) {
            if ( 0 === SourceDom::childElementCount($element) && '' === trim($element->textContent) && $this->context->shouldPreserveEmptyVisualElement($element) ) {
                return ConversionOutcome::handled($this->context->emptyVisualSpacerBlock($element));
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
            return ConversionOutcome::handled($this->context->authorLayoutBlock($element, $fallbacks));
        }

        $block = $this->context->recognizePatterns($element, $fallbacks, array( LogoPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $navigationSection = $this->navigationPattern->recognizeLabeledSection($element, $this->context->patternContext());
        if ( null !== $navigationSection ) {
            return ConversionOutcome::handled($navigationSection->block());
        }
        if ( ! $this->context->shouldDeferNavigationPatternToChildren($element) ) {
            // A broad container can contain a real social cluster plus unrelated
            // media. Let its children be converted instead of dropping that media.
            $patterns = array( AccordionPattern::class, NavigationPattern::class );
            if ( ! $this->context->hasCapturedMediaContent($element) ) {
                $patterns[] = SocialLinksPattern::class;
            }
            $block = $this->context->recognizePatterns($element, $fallbacks, $patterns);
            if ( null !== $block ) {
                return ConversionOutcome::handled($this->context->rememberAccordionDisclosureRoot($block, $element));
            }
        }

        if ( in_array($tagName, array( 'div', 'section', 'article' ), true) ) {
            $block = $this->context->metadataGridBlock($element);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
            $block = $this->context->recognizePatterns($element, $fallbacks, array( DetailsPattern::class ));
            if ( null !== $block ) {
                $this->context->rememberNativeDisclosureRoot($element);
                return ConversionOutcome::handled($block);
            }
            $block = $this->context->recognizePatterns($element, $fallbacks, array( CoverPattern::class ));
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }

        $block = $this->context->recognizePatterns($element, $fallbacks, array( ColumnsPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->mediaGalleryBlock($element, $fallbacks);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( CodeWindowPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }

        $block = $this->context->namePriceRowBlock($element, $fallbacks);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->inlineTokenGroupBlock($element, $fallbacks);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->standaloneSearchBlock($element);
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
        $block = $this->context->recognizePatterns($element, $fallbacks, array( ButtonsContainerPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        if ( 'select' === $tagName ) {
            $block = $this->context->readableFormControlBlock($element);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }
        if ( $this->context->isGeneratedComponentCandidate($element) ) {
            $block = $this->context->generatedComponentBlock($element);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
        }
        $block = $this->context->textFlowBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }

        $children = $this->context->convertChildren($element, $fallbacks);
        if ( array() === $children && ! $this->context->hasDirectMediaChild($element) ) {
            $backgroundImage = $this->context->backgroundImageBlock($element);
            if ( null !== $backgroundImage ) {
                $children[] = $backgroundImage;
            }
        }
        if ( 1 === count($children) ) {
            $block = $this->context->coalescedSingleGroupWrapper($element, $children[0]);
            if ( null !== $block ) {
                return ConversionOutcome::handled($block);
            }
            if ( $this->context->shouldPreserveWrapper($element) || $this->context->isDirectChildOfAuthorOwnedLayout($element) ) {
                return ConversionOutcome::handled($this->group($element, $children));
            }
            return ConversionOutcome::handled($children[0]);
        }
        if ( array() !== $children ) {
            return ConversionOutcome::handled($this->group($element, $children));
        }
        if ( $this->context->shouldPreserveEmptyVisualElement($element) ) {
            return ConversionOutcome::handled($this->context->emptyVisualSpacerBlock($element));
        }
        return ConversionOutcome::handled(null);
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
        if ( ! $this->context->shouldDeferNavigationPatternToChildren($element) ) {
            $block = $this->context->recognizePatterns($element, $fallbacks, array( AccordionPattern::class ));
            if ( null !== $block ) {
                return ConversionOutcome::handled($this->context->rememberAccordionDisclosureRoot($block, $element));
            }
        }
        $block = $this->context->recognizePatterns($element, $fallbacks, array( DetailsPattern::class ));
        if ( null !== $block ) {
            $this->context->rememberNativeDisclosureRoot($element);
            return ConversionOutcome::handled($block);
        }
        return null;
    }

    /** @param array<int, array<string, mixed>> $children @return array<string, mixed> */
    private function group(DOMElement $element, array $children): array
    {
        return $this->context->createBlock('core/group', $this->context->presentationAttributes($element), $children, $element);
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
}
