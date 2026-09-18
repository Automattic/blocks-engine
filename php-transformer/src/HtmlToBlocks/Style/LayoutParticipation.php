<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use DOMElement;

/**
 * How a source element participates in its parent's formatting context, and
 * which emitted box therefore owns layout-participating declarations.
 *
 * Resolved once from the authored cascade. Conversion sites consume this
 * instead of re-deriving inline/flex-item/positioned from the tag that
 * happened to match.
 */
final class LayoutParticipation
{
    public const INLINE = 'inline';
    public const BLOCK = 'block';
    public const FLEX_ITEM = 'flex-item';
    public const GRID_ITEM = 'grid-item';

    public const BOX_BUTTONS_WRAPPER = 'buttons-wrapper';
    public const BOX_BUTTON_WRAPPER = 'button-wrapper';
    public const BOX_LINK = 'link';

    private const PHRASING_UA_INLINE = array(
        'a', 'abbr', 'b', 'bdi', 'bdo', 'cite', 'code', 'data', 'dfn', 'em',
        'i', 'kbd', 'label', 'mark', 'q', 's', 'samp', 'small', 'span',
        'strong', 'sub', 'sup', 'time', 'u', 'var',
    );

    private const UA_INLINE_BLOCK = array(
        'button', 'input', 'select', 'textarea', 'img',
    );

    public function __construct(
        public readonly string $context,
        public readonly string $sourceDisplay,
        public readonly bool $positioned,
        public readonly bool $sourceIsNativeControl = false
    ) {}

    public static function resolve(DOMElement $element, StyleResolver $styleResolver): self
    {
        $positioned = $styleResolver->hasChildOwnedPositionedOffsets($element);
        $parent = $element->parentNode;
        $parentDisplay = '';
        if ( $parent instanceof DOMElement ) {
            $parentDisplay = self::resolvedDisplay($parent, $styleResolver);
        }
        $sourceDisplay = self::resolvedDisplay($element, $styleResolver);
        if ( '' === $sourceDisplay ) {
            $tag = strtolower($element->tagName);
            $sourceDisplay = in_array($tag, self::PHRASING_UA_INLINE, true)
                ? 'inline'
                : ( in_array($tag, self::UA_INLINE_BLOCK, true) ? 'inline-block' : 'block' );
        }

        if ( in_array($parentDisplay, array( 'flex', 'inline-flex' ), true) ) {
            $context = self::FLEX_ITEM;
        } elseif ( in_array($parentDisplay, array( 'grid', 'inline-grid' ), true) ) {
            $context = self::GRID_ITEM;
        } elseif ( 'inline' === $sourceDisplay ) {
            $context = self::INLINE;
        } else {
            $context = self::BLOCK;
        }

        return new self(
            $context,
            $sourceDisplay,
            $positioned,
            in_array(strtolower($element->tagName), array( 'button', 'input' ), true)
        );
    }

    public function isLayoutItem(): bool
    {
        return in_array($this->context, array( self::FLEX_ITEM, self::GRID_ITEM ), true);
    }

    public function isInline(): bool
    {
        return self::INLINE === $this->context;
    }

    /**
     * Native buttons keep source classes on the inner save wrapper, which
     * would add a second padding box as a flex/grid item. Flatten both
     * synthesized wrappers so the link is the item. Anchors already project
     * onto the link, so the outer wrapper can remain the participating box
     * (with the direct-flex repair).
     */
    public function flattensInnerSaveWrapper(): bool
    {
        return $this->isLayoutItem() && $this->sourceIsNativeControl;
    }

    /**
     * Synthesized core/buttons must not generate a box when it would change
     * the source element's participation (inline / flattened flex item) or
     * steal a positioned child's containing block.
     */
    public function neutralizeButtonsWrapper(): bool
    {
        return $this->positioned || $this->isInline() || $this->flattensInnerSaveWrapper();
    }

    /**
     * The inner .wp-block-button save wrapper is extra relative to the source
     * control. Flatten it unless it is the positioning box for an out-of-flow
     * control — that box has to keep generating a containing-block child.
     */
    public function neutralizeButtonWrapper(): bool
    {
        return ! $this->positioned && ( $this->isInline() || $this->flattensInnerSaveWrapper() );
    }

    /**
     * Flex-item anchors keep the outer wrapper as the participating box.
     * Core's display:flex/gap on that wrapper would still change the parent
     * formatting context, so the wrapper is restated as display:block.
     */
    public function needsDirectFlexRepair(): bool
    {
        return $this->isLayoutItem() && ! $this->positioned && ! $this->flattensInnerSaveWrapper();
    }

    /**
     * The emitted box that actually participates in the parent's formatting
     * context after synthesized wrappers are neutralized or kept.
     */
    public function participatingBox(): string
    {
        if ( $this->positioned ) {
            return self::BOX_BUTTON_WRAPPER;
        }
        if ( $this->isInline() || $this->flattensInnerSaveWrapper() ) {
            return self::BOX_LINK;
        }

        return self::BOX_BUTTONS_WRAPPER;
    }

    /**
     * Layout-participating declarations a neutralized inner save wrapper can
     * no longer hold. Applied to the first descendant that still generates a
     * box — the control ({@see self::BOX_LINK}).
     *
     * `display: contents` does not remove the ancestor from the tree, so
     * core's `.wp-block-buttons .wp-block-button__link { width: 100% }` still
     * matches. `:where()` cannot outrank that descendant selector; these
     * declarations are therefore important. Author `!important` widths that
     * arrive later still win.
     */
    public static function transferredItemDeclarations(): string
    {
        return 'display:inline!important;width:fit-content!important;word-break:normal!important';
    }

    private static function resolvedDisplay(DOMElement $element, StyleResolver $styleResolver): string
    {
        $declarations = $styleResolver->cssDeclarations($styleResolver->controlSurfaceResolvedStyle($element));
        $display = CssValueInspector::comparable((string) ($declarations['display'] ?? ''));
        if ( '' !== $display ) {
            return $display;
        }

        return CssValueInspector::comparable(
            (string) ($styleResolver->structuralPresentationDeclarations($element)['display'] ?? '')
        );
    }
}
