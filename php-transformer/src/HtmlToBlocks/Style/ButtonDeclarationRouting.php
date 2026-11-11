<?php

declare(strict_types=1);

namespace Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Style;

use Automattic\BlocksEngine\PhpTransformer\Css\CssLength;
use Automattic\BlocksEngine\PhpTransformer\Css\CssStylesheetTransformer;
use Automattic\BlocksEngine\PhpTransformer\Css\CssValueSplitter;
use Automattic\BlocksEngine\PhpTransformer\HtmlToBlocks\Patterns\ButtonStyleResolver;
use DOMElement;

/**
 * Routes a lowered button's authored declarations between the core/button
 * wrapper and its painted link.
 */
final class ButtonDeclarationRouting
{
    public function __construct(
        private readonly StyleResolver $styleResolver,
        private readonly AuthorSelectorSemanticPreparer $semanticPreparer
    ) {
    }

    /**
     * @param list<DOMElement>|null $controls
     * @return array{string, string, string}
     */
    public function splitDirectButtonGeometryDeclarations(string $body, string $prelude = '', ?AuthorStylesheetProjectionContext $context = null, ?array $controls = null): array
    {
        $controls ??= $this->buttonSizeSubjects($prelude, $context);
        $placement = array();
        $geometry = array();
        $inner = array();
        $placementVars = $this->buttonPlacementCustomProperties($body);
        $wrapperOwned = array(
            // Float participation belongs to the outer box in the source
            // formatting context, never to the painted descendant link.
            'float', 'clear', 'position', 'top', 'right', 'bottom', 'left', 'z-index',
            'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
            'grid-area', 'grid-column', 'grid-row',
            'grid-column-start', 'grid-column-end', 'grid-row-start', 'grid-row-end',
            'align-self', 'justify-self', 'order',
                    // Flex sizing is item participation, like the `align-self` and
                    // `order` above it: it describes how the box behaves inside the
                    // author's flex container. These declarations travel with
                    // LayoutParticipation::participatingBox() so neutralizing a
                    // wrapper cannot drop them.
            'flex', 'flex-grow', 'flex-shrink', 'flex-basis',
        );
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( $this->isCollapsedButtonKeywordWidth($name, $value) && ! $this->buttonSubjectsKeepIntrinsicRatio($controls) ) {
                continue;
            }
            if ( 'min-width' === $name && $this->percentageResolvesAgainstButtonWrapper($value, $controls) ) {
                // The wrapper resolves the percentage against the source
                // container; the link then fills at least that wrapper.
                $geometry[] = $declaration;
                $inner[] = self::wrapperFillingMinimumWidth($value);
                continue;
            }
            $places = '' !== $name && false !== $colon && $this->declarationPlacesTheButton($name, $placementVars);
            $owned = '' !== $name && false !== $colon && in_array($name, $wrapperOwned, true)
                && ! $this->isButtonControlBoxSize($name, $value, $controls);
            if ( $this->releasesButtonWrapperWidth($name, $value, $controls) ) {
                $geometry[] = $declaration;
            }
            if ( $places ) {
                $placement[] = $declaration;
            } elseif ( $owned ) {
                $geometry[] = $this->withLinkBoxFlexBasis($name, $value, $declaration, $body, $controls);
            } else {
                $inner[] = $declaration;
            }
        }
        return array( implode(';', $placement), implode(';', $geometry), implode(';', $inner) );
    }

    /**
     * @param list<DOMElement>|null $controls
     * @return array{string, string}
     */
    public function splitButtonPresentationDeclarations(string $body, string $prelude = '', ?AuthorStylesheetProjectionContext $context = null, ?array $controls = null): array
    {
        $controls ??= $this->buttonSizeSubjects($prelude, $context);
        $layout = array();
        $control = array();
        $placementVars = $this->buttonPlacementCustomProperties($body);
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            $name = strtolower(trim(false === $colon ? $declaration : substr($declaration, 0, $colon)));
            $value = false === $colon ? '' : trim(substr($declaration, $colon + 1));
            if ( '' === $name || false === $colon ) {
                $control[] = $declaration;
                continue;
            }
            if ( $this->isCollapsedButtonKeywordWidth($name, $value) ) {
                continue;
            }
            if ( $this->declarationPlacesTheButton($name, $placementVars)
                || ( $this->isButtonWrapperLayoutProperty($name) && ! $this->isButtonControlBoxSize($name, $value, $controls) )
            ) {
                $layout[] = $this->withLinkBoxFlexBasis($name, $value, $declaration, $body, $controls);
            } else {
                if ( $this->releasesButtonWrapperWidth($name, $value, $controls) ) {
                    $layout[] = $declaration;
                }
                $control[] = $declaration;
            }
        }
        return array( implode(';', $layout), implode(';', $control) );
    }

    /** @return list<DOMElement> */
    public function buttonSizeSubjects(string $prelude, ?AuthorStylesheetProjectionContext $context): array
    {
        if ( null === $context ) {
            return array();
        }
        $controls = array();
        foreach ( CssStylesheetTransformer::splitSelectorList($prelude) ?? array() as $selector ) {
            $parsed = $context->sourceStyles->parsedSelector($selector);
            if ( ! $parsed['supported'] ) {
                continue;
            }
            foreach ( $this->semanticPreparer->matchingSourceElements($context->authorStyles, $selector, $parsed) as $element ) {
                if ( '' !== $context->selectorProjections->controlMarker($element->getNodePath() ?? '') ) {
                    $controls[] = $element;
                }
            }
        }
        return $controls;
    }

    /** @param list<DOMElement> $controls */
    public function isButtonControlBoxSize(string $property, string $value, array $controls = array()): bool
    {
        if ( ! in_array($property, array( 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height' ), true) ) {
            return false;
        }
        // A shared buttons bar has no outer wrapper per anchor to size.
        $shared = array() !== $controls;
        foreach ($controls as $element) {
            $shared = $shared && ButtonStyleResolver::sharesAnchorContainer($element, $this->styleResolver);
        }
        if ($shared) return true;
        // Definite out-of-flow heights and proven padding/border sizing need
        // link ownership. Percentage, auto and fill sizes keep the wrapper chain.
        if ( in_array($property, array( 'height', 'min-height', 'max-height' ), true) ) {
            if ( array() === $controls ) {
                return false;
            }
            foreach ( $controls as $element ) {
                $resolved = $this->styleResolver->resolveCssVariablesInValue($value, $element);
                $rootFontSize = $this->styleResolver->documentRootFontSize($element) ?? 16.0;
                $size = CssLength::toPx($resolved, $rootFontSize);
                if ( null === $size || ! (new ButtonStyleResolver())->heightNeedsControlBox($this->styleResolver->resolvedSourceStateDeclarations($element), $size, $rootFontSize) ) {
                    return false;
                }
            }
            return true;
        }
        if ( $this->isButtonLinkCompetingBoxSize($property, $value, $controls) ) {
            return true;
        }
        $value = strtolower(CssValueInspector::withoutImportant($value));
        return in_array($value, array( 'min-content', 'max-content', 'fit-content', 'content' ), true);
    }

    /**
     * A `width:auto` release competes on the link, but when the wrapper chain
     * participates in the source layout it can also carry the base width. The
     * source box is auto-sized at that point, so every carrier is released.
     *
     * @param list<DOMElement> $controls
     */
    private function releasesButtonWrapperWidth(string $property, string $value, array $controls): bool
    {
        if ( 'width' !== $property || ! $this->isButtonLinkCompetingBoxSize($property, $value, $controls) || 'auto' !== CssValueInspector::comparable($value) ) {
            return false;
        }
        foreach ( $controls as $control ) {
            if ( LayoutParticipation::BOX_LINK !== LayoutParticipation::resolve($control, $this->styleResolver)->participatingBox() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A box-size declaration the projection keeps on the link so it can compete
     * there: on a flex or grid item, `width:auto` releases the stretched size
     * and a `min-width` constrains the rendered box, so both describe the link
     * itself. The compatibility restatement must carry them like every other
     * authored declaration — a boosted base rule declaring the stretched width
     * would otherwise beat a conditional override restated only at its authored
     * weight, at every viewport.
     *
     * @param list<DOMElement> $controls
     */
    public function isButtonLinkCompetingBoxSize(string $property, string $value, array $controls = array()): bool
    {
        if ( ! in_array($property, array( 'width', 'min-width', 'max-width' ), true) || ! $this->buttonSubjectsAreLayoutItems($controls) ) {
            return false;
        }
        // Auto must compete with intrinsic widths on the same link; the
        // minimum constrains that box rather than only its outer carrier.
        if ( 'auto' === CssValueInspector::comparable($value) ) {
            return true;
        }
        return 'min-width' === $property && ! $this->percentageResolvesAgainstButtonWrapper($value, $controls);
    }

    /** @param list<DOMElement> $controls */
    private function buttonSubjectsKeepIntrinsicRatio(array $controls): bool
    {
        if ( array() === $controls ) return false;
        foreach ( $controls as $control ) {
            if ( ! LayoutParticipation::resolve($control, $this->styleResolver)->intrinsicRatioItem ) return false;
        }
        return true;
    }

    private static function wrapperFillingMinimumWidth(string $value): string
    {
        return 'min-width:100%' . ( CssValueInspector::isImportant($value) ? '!important' : '' );
    }

    /**
     * A percentage resolves against the containing block. When the outer
     * wrapper stays the participating box, the link's containing block is
     * that shrink-wrapped wrapper rather than the source flex or grid
     * container, so the percentage belongs on the wrapper chain.
     *
     * @param list<DOMElement> $controls
     */
    private function percentageResolvesAgainstButtonWrapper(string $value, array $controls): bool
    {
        foreach ( $controls as $control ) {
            $resolved = $this->styleResolver->resolveCssVariablesInValue($value, $control);
            if ( ! str_contains($resolved, '%') ) {
                continue;
            }
            if ( LayoutParticipation::BOX_LINK !== LayoutParticipation::resolve($control, $this->styleResolver)->participatingBox() ) {
                return true;
            }
        }
        return false;
    }

    /** @param list<DOMElement> $controls */
    private function buttonSubjectsAreLayoutItems(array $controls): bool
    {
        if ( array() === $controls ) return false;
        foreach ( $controls as $control ) {
            if ( ! LayoutParticipation::resolve($control, $this->styleResolver)->isLayoutItem() ) return false;
        }
        return true;
    }

    public function isCollapsedButtonKeywordWidth(string $property, string $value): bool
    {
        $value = strtolower(CssValueInspector::withoutImportant($value));
        if ( 'min-content' !== $value ) {
            return false;
        }
        return in_array($property, array( 'width', 'min-width', 'max-width' ), true)
            || (str_starts_with($property, '--') && str_contains($property, 'width'));
    }

    private function isButtonWrapperLayoutProperty(string $property): bool
    {
        return $this->isButtonPlacementProperty($property)
            || in_array($property, array(
                'align-content', 'align-items', 'align-self', 'clear', 'display', 'float',
                'flex', 'flex-basis', 'flex-direction', 'flex-flow', 'flex-grow', 'flex-shrink',
                'flex-wrap', 'gap', 'grid', 'grid-area', 'grid-auto-columns', 'grid-auto-flow',
                'grid-auto-rows', 'grid-column', 'grid-row', 'grid-template', 'grid-template-areas',
                'grid-template-columns', 'grid-template-rows', 'isolation', 'justify-content',
                'justify-items', 'justify-self', 'order', 'overflow', 'overflow-x', 'overflow-y',
                'place-content', 'place-items', 'place-self', 'position', 'top', 'right', 'bottom',
                'left', 'z-index', 'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
            ), true);
    }

    private function isButtonPlacementProperty(string $property): bool
    {
        return in_array($property, array(
            'translate',
            'rotate',
            'scale',
            'transform',
            '-webkit-transform',
            'transform-origin',
            'offset',
        ), true) || str_starts_with($property, 'offset-');
    }

    /**
     * Custom properties consumed by a placement declaration belong on the same
     * box. `translate: var(--shift)` on the wrapper is inert if `--shift` is
     * left on the inner link.
     *
     * @return array<string, true>
     */
    private function buttonPlacementCustomProperties(string $body): array
    {
        $referenced = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $declaration ) {
            $colon = strpos($declaration, ':');
            if ( false === $colon ) {
                continue;
            }
            $name = strtolower(trim(substr($declaration, 0, $colon)));
            $value = trim(substr($declaration, $colon + 1));
            if ( ! $this->isButtonPlacementProperty($name) ) {
                continue;
            }
            if ( preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches) ) {
                foreach ( $matches[1] as $property ) {
                    $referenced[strtolower($property)] = true;
                }
            }
        }

        return $referenced;
    }


    /**
     * A flex item's padding and border count toward its hypothetical main
     * size: `flex:1 0 0` siblings with `padding:16px 24px` split a row evenly
     * because each starts from its 48px padding box. A lowered button moves
     * that padding to its link while the core/buttons wrapper takes the flex
     * declaration, so the wrapper would start from zero. Restate the basis on
     * the wrapper as the source item's outer basis.
     *
     * @param list<DOMElement> $controls
     */
    private function withLinkBoxFlexBasis(string $name, string $value, string $declaration, string $body, array $controls): string
    {
        if ( ! in_array($name, array( 'flex', 'flex-basis' ), true) || array() === $controls ) {
            return $declaration;
        }
        $basis = 'flex' === $name ? self::flexShorthandBasis(CssValueInspector::withoutImportant($value)) : CssValueInspector::withoutImportant($value);
        if ( null === $basis || 1 !== preg_match('/^(?:-?[0-9.]+[a-z%]*|calc\(.*\))$/i', $basis) ) {
            return $declaration;
        }
        $restated = null;
        foreach ( $controls as $control ) {
            $participation = LayoutParticipation::resolve($control, $this->styleResolver);
            if ( LayoutParticipation::FLEX_ITEM !== $participation->context || LayoutParticipation::BOX_BUTTONS_WRAPPER !== $participation->participatingBox() ) {
                return $declaration;
            }
            $outer = $this->outerFlexBasis($control, $basis, $body);
            if ( null === $outer || ( null !== $restated && $restated !== $outer ) ) {
                return $declaration;
            }
            $restated = $outer;
        }
        return $declaration . ';flex-basis:' . $restated . ( CssValueInspector::isImportant($value) ? '!important' : '' );
    }

    /** The basis a `flex` shorthand sets, or null for keywords that keep the item's size. */
    private static function flexShorthandBasis(string $value): ?string
    {
        $parts = CssValueSplitter::splitTopLevelWhitespace(trim($value));
        $numbers = array_values(array_filter($parts, static fn (string $part): bool => 1 === preg_match('/^[0-9.]+$/', $part)));
        $lengths = array_values(array_diff($parts, $numbers));
        if ( 1 === count($lengths) && count($parts) <= 3 ) {
            return $lengths[0];
        }
        // `flex: <grow> [<shrink>]` sets a zero basis; three unitless numbers
        // end in an explicit zero basis.
        return array() === $lengths && in_array(count($parts), array( 1, 2, 3 ), true) ? ( 3 === count($parts) ? $parts[2] : '0%' ) : null;
    }

    /** The control's basis plus the main-axis padding and border its link now paints. */
    private function outerFlexBasis(DOMElement $control, string $basis, string $body): ?string
    {
        $resolved = $this->styleResolver->resolvedSourceStateDeclarations($control);
        $parent = $control->parentNode;
        $flow = $parent instanceof DOMElement ? $this->styleResolver->resolvedSourceStateDeclarations($parent) : array();
        $direction = strtolower(($flow['flex-direction'] ?? '') . ' ' . ($flow['flex-flow'] ?? ''));
        $sides = str_contains($direction, 'column') ? array( 0, 2 ) : array( 3, 1 );
        $declared = array();
        foreach ( CssValueSplitter::splitTopLevel($body, array( ';' )) as $item ) {
            $colon = strpos($item, ':');
            if ( false !== $colon ) {
                $declared[strtolower(trim(substr($item, 0, $colon)))] = trim(substr($item, $colon + 1));
            }
        }
        $source = array_merge($resolved, $declared);
        $padding = CssValueInspector::expandBoxShorthand(CssValueInspector::withoutImportant($source['padding'] ?? '0'));
        $border = array( '0', '0', '0', '0' );
        foreach ( array( 'top', 'right', 'bottom', 'left' ) as $index => $side ) {
            if ( isset($source['padding-' . $side]) ) {
                $padding[$index] = CssValueInspector::withoutImportant($source['padding-' . $side]);
            }
            $width = null;
            $style = 'none';
            foreach ( array( 'border', 'border-' . $side ) as $shorthand ) {
                foreach ( CssValueSplitter::splitTopLevelWhitespace(CssValueInspector::withoutImportant($source[$shorthand] ?? '')) as $token ) {
                    if ( CssValueInspector::isBorderWidthToken($token) ) {
                        $width = $token;
                    } elseif ( 1 === preg_match('/^(none|hidden|solid|dashed|dotted|double|groove|ridge|inset|outset)$/i', $token) ) {
                        $style = strtolower($token);
                    }
                }
            }
            $width = isset($source['border-width']) ? CssValueInspector::expandBoxShorthand(CssValueInspector::withoutImportant($source['border-width']))[$index] : $width;
            $width = $source['border-' . $side . '-width'] ?? $width;
            $style = isset($source['border-style']) ? strtolower(CssValueInspector::expandBoxShorthand(CssValueInspector::withoutImportant($source['border-style']))[$index]) : $style;
            $style = strtolower($source['border-' . $side . '-style'] ?? $style);
            if ( null !== $width && ! in_array($style, array( 'none', 'hidden' ), true) ) {
                $border[$index] = CssValueInspector::withoutImportant($width);
            }
        }
        $terms = array();
        foreach ( $sides as $index ) {
            foreach ( array( $padding[$index], $border[$index] ) as $length ) {
                $length = trim($length);
                if ( '' === $length || 1 === preg_match('/^0(?:[a-z%]+)?$/i', $length) ) {
                    continue;
                }
                if ( 1 !== preg_match('/^(?:[0-9.]+[a-z%]+|calc\(.*\))$/i', $length) ) {
                    return null;
                }
                $terms[] = $length;
            }
        }
        if ( array() === $terms ) {
            return null;
        }
        $box = implode(' + ', $terms);
        $zero = 1 === preg_match('/^0(?:[a-z%]+)?$/i', $basis);
        if ( 'border-box' === strtolower(trim($source['box-sizing'] ?? '')) ) {
            return $zero ? 'calc(' . $box . ')' : 'max(' . $basis . ', calc(' . $box . '))';
        }
        return 'calc(' . ( $zero ? '' : $basis . ' + ' ) . $box . ')';
    }

    /** @param array<string, true> $placementVars */
    private function declarationPlacesTheButton(string $property, array $placementVars): bool
    {
        return $this->isButtonPlacementProperty($property) || isset($placementVars[$property]);
    }
}
