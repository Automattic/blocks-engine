<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\PatternTreeConverter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Session\HtmlTransformerSession;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\SourceBlockCreator;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\EmptyElementFootprint;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\WordPress\Runtime;
use DOMElement;

/** Lowers a figure no media, gallery, code or quote converter claimed. */
final class GenericFigureLowering
{
    public function __construct(
        private readonly PatternTreeConverter $tree,
        private readonly SourceBlockCreator $blocks,
        private readonly StyleResolver $styleResolver,
        private readonly Runtime $runtime,
        private readonly EmptyVisualRetentionPolicy $emptyVisualRetention,
        private readonly EmptyVisualSpacerLowering $emptyVisualSpacers,
        private readonly EmptyElementFootprint $emptyElementFootprint,
        private readonly HtmlTransformerSession $session
    ) {
    }

    /**
     * Convert a figure that wraps non-media content (table, code, multiple
     * elements, or text) into the closest faithful native block(s).
     *
     * The figcaption is consumed as a trailing caption paragraph so it is never
     * emitted as a separate orphan fallback. A figure with a single child and no
     * caption unwraps to that child; otherwise the children plus caption are
     * preserved inside a core/group that carries the figure's presentation.
     *
     * @param array<int, array<string, mixed>> $fallbacks
     * @return array<string, mixed>|null
     */
    public function block(DOMElement $figure, array &$fallbacks): ?array
    {
        $children = $this->tree->childrenWithoutTags($figure, $fallbacks, array( 'figcaption' ));

        $caption = SourceDom::firstChildElement($figure, 'figcaption');
        if ( $caption instanceof DOMElement ) {
            $captionHtml = $this->styleResolver->innerHtml($caption);
            if ( $this->runtime->hasVisibleText($captionHtml) ) {
                $presentation = $this->styleResolver->presentationAttributes($caption);
                // Only a separately lowered quotation introduces a new caption
                // paragraph here. Keep existing generic figure lowering unchanged.
                $figureDeclarations = $this->styleResolver->structuralPresentationDeclarations($figure);
                $parentDeclarations = $figure->parentNode instanceof DOMElement
                    ? $this->styleResolver->structuralPresentationDeclarations($figure->parentNode)
                    : array();
                $separateQuote = 1 === count($children)
                    && 'core/quote' === ($children[0]['blockName'] ?? '')
                    && SourceDom::firstChildElement($figure, 'blockquote') instanceof DOMElement
                    && in_array($parentDeclarations['display'] ?? '', array('flex', 'inline-flex'), true)
                    && (isset($figureDeclarations['flex']) || isset($figureDeclarations['flex-basis']));
                // The native paragraph no longer matches figcaption selectors.
                // Retain the caption's text presentation on its new carrier.
                $typography = $this->styleResolver->styleAttributeMapper()->map(
                    array_intersect_key($this->styleResolver->structuralPresentationDeclarations($caption), array_flip(array(
                        'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'color',
                    ))),
                    fn (string $value): string => $this->styleResolver->customProperties->resolve($value, $caption)
                );
                // map() also returns mapper bookkeeping (attrs/leftover), not
                // block attributes. Only its style belongs on the paragraph.
                // A figcaption has no UA paragraph margins. Supply that
                // default before merging presentation, so authored caption
                // spacing still wins and the new carrier stays block-level.
                $attrs = $separateQuote
                    ? array_replace_recursive(
                        array('style' => array('spacing' => array('margin' => array('top' => '0', 'bottom' => '0')))),
                        array('style' => $typography['style'] ?? array()),
                        $presentation,
                        array('content' => $captionHtml)
                    )
                    : array_merge($presentation, array('content' => $captionHtml));
                $children[] = $this->blocks->createBlock('core/paragraph', $attrs, array(), $caption);
            } elseif ( $this->emptyVisualRetention->shouldPreserveEmptyVisualElement($caption) ) {
                // An empty caption can still hold space (padding, border,
                // height) that the content after its figure depends on.
                $children[] = $this->emptyVisualSpacers->emptyVisualSpacerBlock($caption);
            }
        }

        if ( array() === $children ) {
            if ( $this->emptyElementFootprint->shouldPreserveEmptyVisualFigure($figure, SourceDom::renderedTextContent($figure), $this->session->sourceStyleResolutionState()->pseudoElementRules()) ) {
                return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($figure), array(), $figure);
            }

            return null;
        }

        if ( 1 === count($children) && array() === $this->styleResolver->presentationAttributes($figure) ) {
            return $children[0];
        }

        return $this->blocks->createBlock('core/group', $this->styleResolver->presentationAttributes($figure), $children, $figure);
    }
}
