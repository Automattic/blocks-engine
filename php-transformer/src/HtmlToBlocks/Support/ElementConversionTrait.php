<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support;

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
use DOMElement;

/**
 * Conversion for the element families that convertElement() dispatches to.
 *
 * Each method owns one subject and answers the same question: which block,
 * if any, represents this source element. They are grouped here so the
 * dispatch chain stays readable and each family can be read on its own.
 */
trait ElementConversionTrait
{
    /**
     * Convert one standalone SVG into its materialized image, preserved markup, or runtime island.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertSvgElement(DOMElement $element, array &$fallbacks): ?array
    {
        if ( $this->isInertHiddenSvgStorage($element) ) {
            return null;
        }
        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            $html = $this->sanitizeInlineSvgMarkup($element);
            if ( $this->isSafeSvgContent($html) ) {
                return $this->createBlock('core/html', array( 'content' => $this->svgMaterializer->restoreSvgCasing($this->svgMaterializer->ensureInlineSvgBoxStyle($html, $element)) ), array(), $element);
            }
        }

        // Imported inline SVGs are never routed through core/icon: that block
        // is dynamic and keyed on a registered icon slug, not arbitrary SVG.
        // Passive self-contained SVGs can be represented by core/image using
        // a data:image/svg+xml source; the rest stay faithful core/html.
        if ( $this->svgMaterializer->isSafeDecorativeSvgElement($element) ) {
            // Faithfully preserve any inline SVG that carries real drawable
            // artwork — icons, diagrams, illustrations — even when it is
            // marked aria-hidden / role=presentation. aria-hidden hides the
            // graphic from the accessibility tree; it does NOT mean the
            // artwork is visually disposable. WordPress cannot reconstruct
            // arbitrary vector artwork from CSS, so routing such an SVG into
            // the visual-layer group (empty) or dropping it (return null)
            // silently erased every shape — service icons collapsed to empty
            // blocks and pipe/boiler diagrams to whitespace + comments.
            //
            // A proven positioned visual layer can collapse to its CSS-owned
            // carrier. Stretching alone is not evidence that artwork is
            // recreated elsewhere; preserve drawable stretched SVGs.
            $isDecorativeChrome = $this->isVisualLayerElement($element);
            if ( ! $isDecorativeChrome && $this->svgHasDrawableContent($element) ) {
                if ( $this->svgMaterializer->svgNeedsPhrasingHost($element) ) {
                    $imageMarkup = $this->svgMaterializer->inlineSvgRichTextImageMarkup($element);
                    if ( null !== $imageMarkup ) {
                        return $this->createBlock('core/paragraph', array( 'content' => $imageMarkup ), array(), $element);
                    }
                }
                $svgBlock = $this->svgMaterializer->inlineSvgBlockFromElement($element);
                if ( null !== $svgBlock ) {
                    return $svgBlock;
                }
            }
            if ( $this->isVisualLayerElement($element) ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array(), $element);
            }
            return null;
        }

        if ( $this->svgMaterializer->svgNeedsPhrasingHost($element) ) {
            $imageMarkup = $this->svgMaterializer->inlineSvgRichTextImageMarkup($element);
            if ( null !== $imageMarkup ) {
                return $this->createBlock('core/paragraph', array( 'content' => $imageMarkup ), array(), $element);
            }
        }

        $svgBlock = $this->svgMaterializer->inlineSvgBlockFromElement($element);
        if ( null !== $svgBlock ) {
            return $svgBlock;
        }

        $this->captureInlineSvgFallback($element, $fallbacks);
        return null;
    }

    /**
     * Convert one phrasing-content element: its inline descendants become a paragraph, a heading promotion, or the block its materialized content requires.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertInlineContentElement(DOMElement $element, array &$fallbacks): ?array
    {
        $socialLinks = $this->recognizePatterns($element, $fallbacks, array(SocialLinksPattern::class));
        if ( null !== $socialLinks ) {
            return $socialLinks;
        }

        if ( $this->runtimeIslands->isRuntimeDomTarget($element) ) {
            return $this->htmlPreservationBlock($element);
        }

        $inlineSvgTextGroup = $this->inlineSvgTextGroupBlockFromElement($element);
        if ( null !== $inlineSvgTextGroup ) {
            return $inlineSvgTextGroup;
        }

        if ( $this->ownsPositioningGeometry($element) ) {
            $carrier = $this->positionedInlineCarrierBlock($element, $fallbacks);
            if ( null !== $carrier ) {
                return $carrier;
            }
        }

        if ( $this->hasAuthorSemanticMarker($element) ) {
            $content = $this->innerHtml($element);
            if ( '' !== trim($this->runtime->stripAllTags($content)) ) {
                if ( $this->richTextContentHasStructuralHtml($content) ) {
                    $children = $this->convertChildren($element, $fallbacks, true);
                    if ( array() !== $children ) {
                        return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
                    }
                }
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), array(
                    $this->createBlock('core/paragraph', array( 'content' => $content )),
                ), $element);
            }
        }

        $richTextMarker = $this->richTextMarkerForElement($element);
        if ( '' !== $richTextMarker ) {
            // RichText only accepts phrasing content. Keep a selector-addressed
            // inline wrapper editable when it contains layout/content blocks by
            // lowering its children instead of storing structural HTML in content.
            if ( $this->hasBlockContentChildren($element) || $this->richTextContentHasStructuralHtml($this->innerHtml($element)) ) {
                $children = $this->convertChildren($element, $fallbacks, true);
                if ( array() !== $children ) {
                    return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
                }
            }
            $content = $this->innerHtml($element);
            if ( '' !== trim($this->runtime->stripAllTags($content)) ) {
                $declarations = $this->richTextInlineVisualDeclarations($element);
                if ( 'transparent' === strtolower((string) ($declarations['-webkit-text-fill-color'] ?? '')) ) {
                    $declarations['color'] = 'transparent';
                }
                $declarations['--blocks-engine-richtext-marker'] = $richTextMarker;
                return $this->createBlock('core/paragraph', array(
                    'content' => '<mark style="' . htmlspecialchars($this->styleResolver->cssDeclarationString($declarations), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $content . '</mark>',
                ), array(), $element);
            }
        }

        $dynamicText = $this->dynamicTextContent($element);
        if ( null !== $dynamicText ) {
            return $this->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($element), array( 'content' => $this->runtime->escapeHtml($dynamicText) )), array(), $element);
        }

        $content = $this->outerHtml($element);
        if ( '' === trim($this->runtime->stripAllTags($content)) ) {
            $children = $this->convertChildren($element, $fallbacks, true);
            if ( 1 === count($children) ) {
                if ( array() !== $this->styleResolver->presentationAttributes($element) ) {
                    return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
                }
                return $children[0];
            }
            if ( array() !== $children ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }

            if ( $this->shouldPreserveEmptyVisualElement($element) ) {
                return $this->emptyVisualSpacerBlock($element);
            }

            return null;
        }

        $listItem = $this->ancestorElement($element, 'li');
        $sourceElement = $this->richTextContentHasStructuralHtml($content)
            || ($listItem instanceof DOMElement && $this->isStructuralListItem($listItem))
            ? $element
            : null;
        return $this->createBlock('core/paragraph', array( 'content' => $content ), array(), $sourceElement);
    }

    /**
     * Convert one flow container: a runtime app shell, a recognized
     * pattern, an author-owned layout, or the group that carries its
     * converted children.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    private function convertFlowContainerElement(DOMElement $element, array &$fallbacks): ?array
    {
        $tagName = strtolower($element->tagName);

        if ( $this->runtimeIslands->shouldPreserveRuntimeAppShell($element) ) {
            $targets = $this->runtimeIslands->runtimeTargetsInSubtree($element, 8);
            $this->runtimeIslands->recordRuntimeIsland($element, 'app_shell', 'runtime_app_shell', 'client_script_execution', array(
                'events'          => $this->eventMetadata($element),
                'target_count'    => count($targets),
                'targets'         => $targets,
                'app_shell_signals' => $this->runtimeIslands->runtimeAppShellSignals($element),
                'required_scripts' => $this->requiredScriptsForElement($element),
            ));

            return $this->htmlPreservationBlock($element);
        }

        if ( $this->isEmptyInteractiveFeatureShell($element) ) {
            return null;
        }

        $this->formDispatcher->capturePseudoFormFallback($element, $fallbacks);

        $spacer = $this->recognizePatterns($element, $fallbacks, array(SpacerPattern::class));
        if ( null !== $spacer ) {
            return $spacer;
        }

        // Explicit social clusters are commonly authored as flex rows. Their
        // core/social-links representation is more specific than generic
        // author-owned layout preservation and carries the source presentation
        // attributes itself, so recognize it before `display:flex` sends the
        // container down the generic layout path.
        if ( SocialLinksPattern::isExplicitSocialCluster($element) ) {
            $socialLinks = $this->recognizePatterns($element, $fallbacks, array(SocialLinksPattern::class));
            if ( null !== $socialLinks ) {
                return $socialLinks;
            }
        }

        $flankedSeparator = $this->flankedSeparatorBlockFromElement($element);
        if ( null !== $flankedSeparator ) {
            return $flankedSeparator;
        }

        $capturedMediaLayout = $this->capturedMediaLayoutBoundaryBlock($element);
        if ( null !== $capturedMediaLayout ) {
            return $capturedMediaLayout;
        }

        // A gallery can only contain native image blocks. Preserve the
        // complete media collection in the responsive-media companion before
        // author-layout recognition can create an invalid core/gallery child.
        if ( $this->hasResponsiveImageSources($element) && $this->hasGalleryMediaItems($element) ) {
            return $this->responsiveMediaBlock($element);
        }

        if ( $this->isDirectChildOfAuthorOwnedLayout($element) && '' !== $this->attr($element, 'role') ) {
            return $this->authorLayoutBlockFromElement($element, $fallbacks);
        }

        if ( in_array($tagName, array( 'div', 'section', 'article' ), true) && ! $this->hasResponsiveImageSources($element) ) {
            // A strict two-pane media/text candidate is a more specific
            // recognition than generic author-owned layout preservation:
            // media-text candidates are by definition authored flex/grid
            // containers, so they must be recognized before the layout is
            // demoted to a css-owned core/group.
            $mediaText = $this->recognizePatterns($element, $fallbacks, array(MediaTextPattern::class));
            if ( null !== $mediaText ) {
                return $mediaText;
            }
        }

        // Keep safe phrasing runs together before generic flex/grid preservation can split
        // selector-addressed inline targets into block-level children. The recognizer rejects
        // children with independent layout geometry, so structural inline items still fall
        // through to the author-owned layout path below.
        if ( $this->hasMultipleRuntimeInlineTextTargets($element) ) {
            $inlineContent = $this->paragraphBlockFromInlineContentWrapper($element);
            if ( null !== $inlineContent ) {
                return $inlineContent;
            }
        }

        if ( 'button' !== strtolower($this->attr($element, 'role'))
            && ! $this->hasClass($element, 'wp-block-columns')
            && ! $this->isGeneratedComponentCandidate($element)
            && $this->isAuthorOwnedLayout($element)
        ) {
            $proofBacked = $this->proofBackedWrapperCoalescing($element, $fallbacks);
            if (null !== $proofBacked) return $proofBacked;
            return $this->authorLayoutBlockFromElement($element, $fallbacks);
        }

        // A direct child of an author-owned layout is itself a layout item.
        // Keep its semantic container instead of allowing a core Group to
        // contribute flow layout defaults to the author-owned parent.
        if ( ! $this->isGeneratedComponentCandidate($element) && $this->isDirectChildOfAuthorOwnedLayout($element) && in_array($tagName, array( 'div', 'section', 'article', 'aside', 'header', 'footer', 'main' ), true) ) {
            if ( 0 === $this->childElementCount($element) && '' === trim($element->textContent) && $this->shouldPreserveEmptyVisualElement($element) ) {
                return $this->createBlock('core/group', $this->emptyVisualElementAttributes($element), array(), $element);
            }
            return $this->authorLayoutBlockFromElement($element, $fallbacks);
        }

        $logo = $this->recognizePatterns($element, $fallbacks, array(LogoPattern::class));
        if ( null !== $logo ) {
            return $logo;
        }

        $navigationSection = $this->navigationSectionBlockFromElement($element);
        if ( null !== $navigationSection ) {
            return $navigationSection;
        }

        if ( ! $this->shouldDeferNavigationPatternToChildren($element) ) {
            $navigation = $this->recognizePatterns($element, $fallbacks, array(AccordionPattern::class, SocialLinksPattern::class, NavigationPattern::class));
            if ( null !== $navigation ) {
                return $this->rememberAccordionDisclosureRoot($navigation, $element);
            }
        }

        if ( in_array($tagName, array( 'div', 'section', 'article' ), true) ) {
            $metadataGrid = $this->metadataGridBlockFromElement($element);
            if ( null !== $metadataGrid ) {
                return $metadataGrid;
            }

            $disclosure = $this->recognizePatterns($element, $fallbacks, array(DetailsPattern::class));
            if ( null !== $disclosure ) {
                $this->runtimeBehavior()->rememberNativeDisclosureRoot($element->getNodePath() ?? '');

                return $disclosure;
            }

            $cover = $this->recognizePatterns($element, $fallbacks, array(CoverPattern::class));
            if ( null !== $cover ) {
                return $cover;
            }

            // core/media-text is dispatched earlier in this method, before
            // author-owned layout preservation — its candidates are by
            // definition authored flex/grid containers.
        }

        $columns = $this->recognizePatterns($element, $fallbacks, array(ColumnsPattern::class));
        if ( null !== $columns ) {
            return $columns;
        }

        $gallery = $this->mediaGalleryBlockFromElement($element, $fallbacks);
        if ( null !== $gallery ) {
            return $gallery;
        }

        $codeWindow = $this->recognizePatterns($element, $fallbacks, array(CodeWindowPattern::class));
        if ( null !== $codeWindow ) {
            return $codeWindow;
        }

        $namePriceRow = $this->namePriceRowBlockFromElement($element, $fallbacks);
        if ( null !== $namePriceRow ) {
            return $namePriceRow;
        }

        $inlineTokenGroup = $this->inlineTokenGroupBlockFromElement($element, $fallbacks);
        if ( null !== $inlineTokenGroup ) {
            return $inlineTokenGroup;
        }

        $visualTextWrapper = $this->visualTextWrapperBlockFromElement($element);
        if ( null !== $visualTextWrapper ) {
            return $visualTextWrapper;
        }

        $inlineContent = $this->paragraphBlockFromInlineContentWrapper($element);
        if ( null !== $inlineContent ) {
            return $inlineContent;
        }

        $standaloneSearch = $this->searchBlockConverter->searchBlockFromStandaloneControl($element);
        if ( null !== $standaloneSearch ) {
            return $standaloneSearch;
        }

        $buttons = $this->recognizePatterns($element, $fallbacks, array(ButtonsContainerPattern::class));
        if ( null !== $buttons ) {
            return $buttons;
        }

        // A select's option text is not prose. Route it before generic text
        // flow can flatten the control into a paragraph.
        if ( 'select' === $tagName ) {
            $selectBlock = $this->readableFormControlBlockConverter->convert($element);
            if ( null !== $selectBlock ) {
                return $selectBlock;
            }
        }

        if ( $this->isGeneratedComponentCandidate($element) ) {
            $generated = $this->fallbackEmitter()->maybeGenerateCustomBlock($element, $this->generatedBlocks(), true, true);
            if ( null !== $generated ) {
                return $this->generatedComponentBlock($generated, $element);
            }
        }

        $textFlow = $this->textFlowBlockFromElement($element);
        if ( null !== $textFlow ) {
            return $textFlow;
        }

        $children = $this->convertChildren($element, $fallbacks, true);
        if ( array() === $children && ! $this->hasDirectMediaChild($element) ) {
            $backgroundImage = $this->backgroundImageBlockFromElement($element);
            if ( null !== $backgroundImage ) {
                $children[] = $backgroundImage;
            }
        }
        if ( 1 === count($children) ) {
            $coalesced = $this->coalescedSingleGroupWrapper($element, $children[0]);
            if ( null !== $coalesced ) {
                return $coalesced;
            }
            if ( $this->shouldPreserveWrapper($element) || $this->isDirectChildOfAuthorOwnedLayout($element) ) {
                return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
            }
            return $children[0];
        }
        if ( array() !== $children ) {
            return $this->createBlock('core/group', $this->styleResolver->presentationAttributes($element), $children, $element);
        }
        if ( $this->shouldPreserveEmptyVisualElement($element) ) {
            return $this->emptyVisualSpacerBlock($element);
        }
        return null;
    }
}
