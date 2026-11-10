<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Elements;

use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Classification\SourceElementClassifier;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style\StyleResolver;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\TableClassificationPolicy;
use DOMElement;

/**
 * Decides whether a synthesized image figure must stay transparent to the
 * inline flow and percentage sizing around its source image.
 */
final class SyntheticImageFigureFlow
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly TableClassificationPolicy $tableClassificationPolicy,
        private readonly LayoutTableLowering $layoutTables
    ) {
    }

    /**
     * Whether a synthesized image figure must be transparent to inline flow
     * and percentage sizing. A block source image keeps its ordinary figure;
     * its own box, rather than inline alignment, determines its placement.
     */
    public function syntheticImageFigureFollowsInlineFlow(DOMElement $element): bool
    {
        $display = $this->styleResolver->resolvedDeclaration($element, 'display');
        if ( '' !== $display && ! in_array($display, array( 'inline', 'inline-block' ), true) ) {
            return false;
        }

        // An inline source wrapper is transparent to percentage sizing. Do
        // not introduce a figure containing block when lowering its image.
        $parent = $element->parentNode;
        if ($parent instanceof DOMElement
            && $this->sourceElementClassifier->isInlineSourceElement(strtolower($parent->tagName))
            && in_array($this->styleResolver->resolvedDeclaration($parent, 'display'), array('', 'inline'), true)) {
            return true;
        }

        // An inline-block wrapper (a link, a span) is a line-level box around
        // an inline image. Blockifying the image would leave the wrapper with
        // no line box, so its baseline moves to the margin edge and the strut
        // adds descent below.
        if ($parent instanceof DOMElement
            && 'inline-block' === $this->styleResolver->resolvedDeclaration($parent, 'display')) {
            return true;
        }

        // A link that is a flex or grid item is the item itself. Its in-flow
        // image still sits on the link's own line, so the strut sizes the link.
        if ($parent instanceof DOMElement && 'a' === strtolower($parent->tagName) && $this->styleResolver->imageIsFlexOrGridItem($parent)
            && ! in_array($this->styleResolver->resolvedDeclaration($element, 'position'), array( 'absolute', 'fixed' ), true)
            && in_array($this->styleResolver->resolvedDeclaration($element, 'float'), array( '', 'none' ), true)) {
            return true;
        }

        $table = SourceDom::ancestorElement($element, 'table');
        if ($table instanceof DOMElement && $this->tableClassificationPolicy->lowersToColumns($table) && $this->layoutTables->layoutTableUsesAutoTracks($table)) {
            return true;
        }

        // text-align inherits, so the alignment that governs this image can be
        // declared on any ancestor above the inline wrappers it sits in.
        $node = $element->parentNode;
        for ( $depth = 0; $depth < 8 && $node instanceof DOMElement; ++$depth ) {
            $align = $this->styleResolver->resolvedDeclaration($node, 'text-align');
            if ( '' !== $align ) {
                return in_array($align, array( 'right', 'center', 'end' ), true);
            }
            $node = $node->parentNode;
        }

        return false;
    }
}
