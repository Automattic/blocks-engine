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
                $children[] = $this->blocks->createBlock('core/paragraph', array_merge($this->styleResolver->presentationAttributes($caption), array( 'content' => $captionHtml )), array(), $caption);
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
