<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

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
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\ShellLandmarkPolicy;
use DOMElement;

/** Converts flow containers through their ordered runtime, pattern, layout, and child strategies. */
final class FlowContainerElementConverter implements ElementConverter
{
    public function __construct(private readonly FlowContainerElementContext $context)
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

        $this->context->capturePseudoFormFallback($element, $fallbacks);
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

        if ( $this->context->hasResponsiveImageSources($element) && $this->context->hasGalleryMediaItems($element) ) {
            return ConversionOutcome::handled($this->context->responsiveMediaBlock($element));
        }
        if ( $this->context->isDirectChildOfAuthorOwnedLayout($element) && '' !== $this->context->attr($element, 'role') ) {
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

        if ( 'button' !== strtolower($this->context->attr($element, 'role'))
            && ! $this->context->hasClass($element, 'wp-block-columns')
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
            if ( 0 === $this->context->childElementCount($element) && '' === trim($element->textContent) && $this->context->shouldPreserveEmptyVisualElement($element) ) {
                return ConversionOutcome::handled($this->context->createBlock('core/group', $this->context->emptyVisualElementAttributes($element), array(), $element));
            }
            return ConversionOutcome::handled($this->context->authorLayoutBlock($element, $fallbacks));
        }

        $block = $this->context->recognizePatterns($element, $fallbacks, array( LogoPattern::class ));
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->navigationSectionBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        if ( ! $this->context->shouldDeferNavigationPatternToChildren($element) ) {
            $block = $this->context->recognizePatterns($element, $fallbacks, array( AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class ));
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
        $block = $this->context->visualTextWrapperBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->paragraphBlockFromInlineContentWrapper($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
        }
        $block = $this->context->standaloneSearchBlock($element);
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
        $block = $this->context->authoredCarouselBlock($element);
        if ( null !== $block ) {
            return ConversionOutcome::handled($block);
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

    /** @param array<int, array<string, mixed>> $children @return array<string, mixed> */
    private function group(DOMElement $element, array $children): array
    {
        return $this->context->createBlock('core/group', $this->context->presentationAttributes($element), $children, $element);
    }
}
