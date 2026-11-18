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
    /** The figure is transparent to its source line and carries leaf positioning. */
    public const INLINE = 'inline';
    /** The figure is transparent only so the image keeps its line-box strut. */
    public const STRUT = 'strut';
    /** The figure keeps the ordinary block box of core/image. */
    public const BOX = 'box';

    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly SourceElementClassifier $sourceElementClassifier,
        private readonly TableClassificationPolicy $tableClassificationPolicy,
        private readonly LayoutTableLowering $layoutTables
    ) {
    }

    /**
     * How a synthesized image figure relates to its source line: INLINE when
     * it must be transparent to inline flow and percentage sizing, STRUT when
     * an ordinary unlinked inline image only needs to keep its line box, and
     * BOX for a block source image whose own box determines its placement.
     */
    public function syntheticImageFigureFlow(DOMElement $element): string
    {
        $display = $this->styleResolver->resolvedDeclaration($element, 'display');
        if ( '' !== $display && ! in_array($display, array( 'inline', 'inline-block' ), true) ) {
            return self::BOX;
        }
        if ( $this->followsInlineFlow($element) ) {
            return self::INLINE;
        }
        if ( null !== $this->governingTextAlign($element) ) {
            return self::BOX;
        }

        return $this->keepsLineStrut($element) ? self::STRUT : self::BOX;
    }

    private function followsInlineFlow(DOMElement $element): bool
    {
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

        $align = $this->governingTextAlign($element);

        return null !== $align && in_array($align, array( 'left', 'right', 'center', 'start', 'end' ), true);
    }

    /**
     * text-align inherits, so the alignment that governs this image can be
     * declared on any ancestor above the inline wrappers it sits in.
     */
    private function governingTextAlign(DOMElement $element): ?string
    {
        $node = $element->parentNode;
        for ( $depth = 0; $depth < 8 && $node instanceof DOMElement; ++$depth ) {
            $align = $this->styleResolver->resolvedDeclaration($node, 'text-align');
            if ( '' !== $align ) {
                return $align;
            }
            $node = $node->parentNode;
        }

        return null;
    }

    /**
     * An unlinked inline source image also creates a line box in an ordinary
     * block parent, without text-align or a link. Keep its baseline strut
     * rather than letting image support turn the replaced leaf into a block.
     * This figure owns no source positioning, so it is a separate marker from
     * the INLINE carrier. Materialized SVG icons keep their figure boxes, so
     * image-only author rules never reach them through this marker.
     */
    private function keepsLineStrut(DOMElement $element): bool
    {
        if ( 'img' !== strtolower($element->tagName) ) {
            return false;
        }
        // Blockified items and out-of-flow images have no such source strut.
        // A positioned source parent may shrink-wrap its contents at another
        // breakpoint. Keep a real figure box in that sizing chain; a static
        // viewport sample alone does not describe the source's layout contract.
        $parent = $element->parentNode;
        if ($parent instanceof DOMElement) {
            $positions = $this->styleResolver->authorDeclaredValuesAtAnyViewport($parent, array( 'position' ))['position'] ?? array();
            if (array() !== array_intersect($positions, array( 'absolute', 'fixed' ))) {
                return false;
            }
        }
        $floats = $this->styleResolver->authorDeclaredValuesAtAnyViewport($element, array( 'float' ))['float'] ?? array();
        if ( array() !== array_diff($floats, array( '', 'none' )) ) {
            return false;
        }
        return ! $this->styleResolver->imageIsFlexOrGridItem($element)
            && ! in_array($this->styleResolver->resolvedDeclaration($element, 'position'), array( 'absolute', 'fixed' ), true)
            && in_array($this->styleResolver->resolvedDeclaration($element, 'float'), array( '', 'none' ), true);
    }
}
