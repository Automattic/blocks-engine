<?php
declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssRuleAnalyzer;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Support\SourceDom;
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

    private const AUTHORED_INLINE_LEVEL = array(
        'inline-block', 'inline-flex', 'inline-grid', 'inline-table',
    );

    private const UA_INLINE_BLOCK = array(
        'button', 'input', 'select', 'textarea', 'img',
    );

    public function __construct(
        public readonly string $context,
        public readonly string $sourceDisplay,
        public readonly bool $positioned,
        public readonly bool $sourceIsNativeControl = false,
        public readonly bool $sourceWrappersAreNative = false,
        public readonly bool $intrinsicRatioItem = false
    ) {}

    public static function resolve(DOMElement $element, StyleResolver $styleResolver): self
    {
        $positioned = $styleResolver->hasChildOwnedPositionedOffsets($element);
        $parent = $element->parentNode;
        $parentDisplays = array();
        if ( $parent instanceof DOMElement ) {
            $parentDisplays[] = self::resolvedDisplay($parent, $styleResolver);
            // Synthesized wrappers must preserve item participation even when
            // the parent only establishes that context at another breakpoint,
            // including its unconditional one: a bar hidden at the reference
            // viewport is still a flex container wherever it shows.
            $base = $styleResolver->declaredPresentation($parent, 'display')->base();
            $displays = '' === $base ? array() : array( $base );
            foreach ( array_merge($displays, array_values($styleResolver->conditionalDisplayRules($parent))) as $display ) {
                $parentDisplays[] = CssValueInspector::comparable(
                    $styleResolver->customProperties->resolve($display, $parent)
                );
            }
        }
        $sourceDisplay = self::resolvedDisplay($element, $styleResolver);
        $authoredDisplay = $sourceDisplay;
        if ( '' === $sourceDisplay ) {
            $tag = strtolower($element->tagName);
            $sourceDisplay = in_array($tag, self::PHRASING_UA_INLINE, true)
                ? 'inline'
                : ( in_array($tag, self::UA_INLINE_BLOCK, true) ? 'inline-block' : 'block' );
        }

        // A link lowered together with its sole native button represents the
        // button's box, not the surrounding inline anchor's line box.
        if ( 'inline' === $sourceDisplay && 'a' === strtolower($element->tagName) && 1 === $element->childElementCount ) {
            $child = $element->firstElementChild;
            if ( $child instanceof DOMElement && 'button' === strtolower($child->tagName) ) {
                $sourceDisplay = self::resolvedDisplay($child, $styleResolver) ?: 'inline-block';
            }
        }

        if ( array_intersect($parentDisplays, array( 'flex', 'inline-flex' )) ) {
            $context = self::FLEX_ITEM;
        } elseif ( array_intersect($parentDisplays, array( 'grid', 'inline-grid' )) ) {
            $context = self::GRID_ITEM;
        } elseif ( 'inline' === $sourceDisplay || (
            $authoredDisplay === $sourceDisplay
            && in_array(strtolower($element->tagName), array( 'a', 'button', 'input' ), true)
            && in_array($sourceDisplay, self::AUTHORED_INLINE_LEVEL, true)
            && ! self::cannotShareLine($element, $styleResolver)
        ) ) {
            // An authored inline-level box shares line boxes with its
            // siblings in block flow; a block-level wrapper would put each
            // control on its own line. Source presentation wrappers (for
            // example a div[role=button] around a link) retain their canonical
            // carrier and spacing rather than transferring their box to the link.
            $context = self::INLINE;
        } else {
            $context = self::BLOCK;
        }

        return new self(
            $context,
            $sourceDisplay,
            $positioned,
            in_array(strtolower($element->tagName), array( 'button', 'input' ), true),
            self::hasNativeButtonSaveWrappers($element),
            self::isIntrinsicRatioColumnItem($element, $styleResolver, $context)
        );
    }

    /**
     * Intrinsic ratio sizing transfers the content's block size back to its
     * inline size. Keep that computation on the original flex item, not on a
     * shrink-wrapped carrier with a different percentage containing block.
     */
    private static function isIntrinsicRatioColumnItem(DOMElement $element, StyleResolver $resolver, string $context): bool
    {
        if ( self::FLEX_ITEM !== $context || ! $element->parentNode instanceof DOMElement ) {
            return false;
        }
        $style = $resolver->resolvedSourceStateDeclarations($element);
        $parent = $resolver->resolvedSourceStateDeclarations($element->parentNode);
        return str_starts_with(CssValueInspector::declared($parent, 'flex-direction'), 'column')
            && in_array(CssValueInspector::declared($style, 'width'), array('min-content', 'max-content', 'fit-content'), true)
            && str_contains((string) ($style['min-width'] ?? ''), '%')
            && ! in_array(CssValueInspector::comparable((string) ($style['aspect-ratio'] ?? 'auto')), array('', 'auto'), true);
    }

    /**
     * Source markup that is already core's button save output
     * (`.wp-block-buttons > .wp-block-button > link`) keeps both wrappers as
     * the source's own boxes. None of them is synthesized, so nothing moves
     * the link's own geometry onto a wrapper.
     */
    private static function hasNativeButtonSaveWrappers(DOMElement $element): bool
    {
        $button = $element->parentNode;
        $buttons = $button instanceof DOMElement ? $button->parentNode : null;

        return $button instanceof DOMElement && $buttons instanceof DOMElement
            && SourceDom::hasClass($button, 'wp-block-button')
            && SourceDom::hasClass($buttons, 'wp-block-buttons');
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
        if ( $this->intrinsicRatioItem || $this->sourceWrappersAreNative || $this->isInline() || $this->flattensInnerSaveWrapper() ) {
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
     * matches, as does core's link `height:100%`. Restore auto height so the
     * participating link sizes intrinsically (or stretches via the source flex
     * alignment), rather than resolving core's percentage against the row.
     * `:where()` cannot outrank those selectors; these
     * declarations are therefore important. Author `!important` sizes that
     * arrive later still win. The width and height resets live in
     * EngineSupportCss axis rules, which spare author-sized buttons.
     */
    public static function transferredItemDeclarations(): string
    {
        return 'display:inline!important;word-break:normal!important';
    }

    /**
     * When the neutralized inner wrapper still carries a source flex/inline-flex
     * class, that class cannot create a formatting context (`display:contents`).
     * Transfer the container onto the link — the first box that still generates
     * — and inherit gap/alignment the wrapper computed from those source
     * classes. `display:inline` would blockify as a flex item of the parent
     * row and stack img+text.
     */
    public static function transferredFlexContainerDeclarations(): string
    {
        return 'display:flex!important;align-items:inherit;justify-content:inherit;flex-direction:inherit;flex-wrap:inherit;gap:inherit;column-gap:inherit;row-gap:inherit;word-break:normal!important';
    }

    /**
     * A transferred flex link without its own padding inherits the wrapper's,
     * so core `.wp-element-button` padding cannot add a constant
     * document-height offset. Source padding projected onto the link (its
     * inline block style) is the control's own and stays authoritative.
     */
    public static function transferredFlexContainerPaddingRule(string $containerSelectors): string
    {
        return ':where(' . $containerSelectors . ')>:where(.wp-block-button__link:not([style*="padding"])){padding:inherit!important}';
    }

    /**
     * The counterpart to {@see self::transferredItemDeclarations()} for a
     * positioned control ({@see self::BOX_BUTTON_WRAPPER}): the inner
     * `.wp-block-button` save wrapper keeps generating a box — it is the
     * containing-block child a positioned descendant needs — so shrink-to-fit
     * stays on that box instead of transferring to the link. No `core`
     * descendant selector targets `.wp-block-button` itself, so this needs no
     * `!important` the way the transferred link declarations do.
     */
    public static function retainedWrapperBoxDeclarations(): string
    {
        return 'width:fit-content';
    }

    /**
     * The link inside a retained {@see self::retainedWrapperBoxDeclarations()}
     * wrapper keeps ordinary button chrome (a block-level box that fills that
     * wrapper) instead of the flattened case's `display:inline` transfer,
     * because its own box already owns shrink-to-fit.
     */
    public static function retainedWrapperLinkDeclarations(): string
    {
        return 'display:block;word-break:normal';
    }

    /**
     * The core/button `width` preset (25/50/75/100) an authored `width`
     * declaration resolves to — the single decision point for the block's
     * width attribute. `ButtonsPattern` and `ButtonStyleResolver` used to
     * derive this independently from the same resolved style string (a
     * boundary-anchored single-declaration match, and a decimal-tolerant
     * `100%`-only fallback embedded in paint resolution); both rules
     * collapse into this one function in cascade order so a permissively
     * formatted authored width is not silently lost.
     *
     * Sizing a converted control is the same "what box does this authored
     * control have" question {@see self::resolve()} answers for display
     * participation, so it lives on this seam rather than a third owner.
     * core/button's `dimensions.width` support is
     * `__experimentalSkipSerialization`, so the resolved preset never
     * produces CSS on its own — {@see SourceBlockAttributeProjector::registerButtonWidth()}
     * is the explicit collaborator that projects it.
     */
    public static function widthPreset(string $resolvedStyle): ?int
    {
        if ( preg_match('/^(25|50|75|100)%$/D', SourceDeclarations::parse($resolvedStyle)['width'] ?? '', $matches) === 1 ) {
            return (int) $matches[1];
        }

        $width = '';
        foreach ( CssRuleAnalyzer::declarationList($resolvedStyle) as [ 'name' => $name, 'value' => $value ] ) {
            if ( 'width' === $name && '' !== $value ) {
                $width = preg_replace('/\s+/', ' ', $value) ?? $value;
            }
        }

        return 1 === preg_match('/^100(?:\.0+)?%$/', $width) ? 100 : null;
    }

    /**
     * An authored inline-level box still cannot share a line when it is a
     * float or out of flow, which makes it block-level (CSS 2.1 section 9.7),
     * or when it is authored to fill the whole line (`width: 100%`).
     */
    private static function cannotShareLine(DOMElement $element, StyleResolver $styleResolver): bool
    {
        $declarations = $styleResolver->authorViewport->reference($element, array( 'float', 'position' ));
        $float = CssValueInspector::declared($declarations, 'float');
        $position = CssValueInspector::declared($declarations, 'position');
        $width = CssValueInspector::comparable((string) (SourceDeclarations::parse($styleResolver->controlSurfaceResolvedStyle($element))['width'] ?? ''));

        return ( '' !== $float && 'none' !== $float ) || in_array($position, array( 'absolute', 'fixed' ), true) || 100 === self::widthPreset('width:' . $width);
    }

    private static function resolvedDisplay(DOMElement $element, StyleResolver $styleResolver): string
    {
        $declarations = SourceDeclarations::parse($styleResolver->controlSurfaceResolvedStyle($element));
        // A declared `var(--d)` (Wix states container display this way) classifies
        // only after expansion against the element's cascaded custom properties;
        // the raw reference matches neither flex nor grid.
        $display = CssValueInspector::comparable(
            $styleResolver->customProperties->resolve((string) ($declarations['display'] ?? ''), $element)
        );
        if ( '' !== $display ) {
            return $display;
        }

        return CssValueInspector::comparable(
            $styleResolver->customProperties->resolve(
                (string) ($styleResolver->structuralPresentationDeclarations($element)['display'] ?? ''),
                $element
            )
        );
    }
}
